// k6 load test: each VU is one full table (1 host + 5 players) playing rounds,
// every player holding a Reverb WebSocket subscribed to private-game.{code},
// like the real SPA.
//   k6 run loadtest/tables.js                                     -> smoke: 1 table, 2 rounds
//   k6 run -e MODE=ramp --out csv=loadtest/raw.csv loadtest/tables.js -> ramp tables until it breaks
//   python loadtest/analyze.py loadtest/raw.csv                   -> per-30s table of where it broke
// The API rate-limits per client IP (guest entry 40/hour, 600 req/min overall),
// so from a single machine the ramp is capped at ~6 new tables an hour and
// ~9 tables of traffic. A real capacity number needs load from several IPs.
import http from 'k6/http';
import { check } from 'k6';
import { WebSocket } from 'k6/websockets';
import { setTimeout } from 'k6/timers';
import { Counter, Rate, Trend } from 'k6/metrics';

const BASE = __ENV.BASE || 'https://game.hincheu.online/api';
// Public key, baked into the SPA bundle (VITE_REVERB_APP_KEY).
const WS = __ENV.WS || `wss://game.hincheu.online/app/${__ENV.REVERB_KEY || '25bdec370a759e2c3a65371560bc0d66'}?protocol=7&client=js&version=8.4.0`;
const PLAYERS = 6;
const ROUNDS = Number(__ENV.ROUNDS || (__ENV.MODE ? Infinity : 2));

const wsSubscribed = new Rate('ws_subscribed');     // socket connected + private channel authorized
const wsDropped = new Counter('ws_dropped');        // subscribed socket closed by the server mid-game
const wsMissed = new Counter('ws_missed_results');  // a player never got the round's SpinResult
const resultLag = new Trend('ws_result_lag', true); // host POSTs /stop -> player receives SpinResult

export const options = __ENV.MODE === 'ramp'
  ? {
      scenarios: {
        tables: {
          executor: 'ramping-vus',
          startVUs: 0,
          stages: [
            { duration: '1m', target: 10 },  //  60 players
            { duration: '2m', target: 10 },
            { duration: '1m', target: 25 },  // 150
            { duration: '2m', target: 25 },
            { duration: '1m', target: 50 },  // 300
            { duration: '2m', target: 50 },
            { duration: '1m', target: 100 }, // 600
            { duration: '2m', target: 100 },
            { duration: '1m', target: 150 }, // 900
            { duration: '2m', target: 150 },
            { duration: '1m', target: 200 }, // 1200
            { duration: '2m', target: 200 },
            { duration: '1m', target: 250 }, // 1500
            { duration: '2m', target: 250 },
            { duration: '30s', target: 0 },
          ],
          gracefulRampDown: '30s',
        },
      },
      thresholds: {
        http_req_failed: [{ threshold: 'rate<0.05', abortOnFail: true, delayAbortEval: '30s' }],
        http_req_duration: [{ threshold: 'p(95)<2000', abortOnFail: true, delayAbortEval: '30s' }],
        ws_subscribed: [{ threshold: 'rate>0.95', abortOnFail: true, delayAbortEval: '30s' }],
        ws_result_lag: ['p(95)<2000'],
      },
    }
  : __ENV.MODE === 'steady'
    ? { vus: Number(__ENV.VUS || 40), duration: __ENV.DUR || '90s' }
    : { vus: 1, iterations: 1 };

const wait = (s) => new Promise((r) => setTimeout(r, s * 1000));

function params(p, name) {
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
  if (p.token) headers.Authorization = `Bearer ${p.token}`;
  return { headers, tags: { name } };
}

function ok(res, name) {
  check(res, { [`${name} 2xx`]: (r) => r.status >= 200 && r.status < 300 });
  return res;
}

const call = (method, p, path, body, name) =>
  ok(http.request(method, `${BASE}${path}`, body ? JSON.stringify(body) : null, params(p, name)), name);

const acall = async (method, p, path, body, name) =>
  ok(await http.asyncRequest(method, `${BASE}${path}`, body ? JSON.stringify(body) : null, params(p, name)), name);

function setUp() {
  const players = [];
  for (let i = 0; i < PLAYERS; i++) {
    const p = {};
    const res = call('POST', p, '/guest', { name: `loadtest-${__VU}-${i}` }, 'guest');
    if (res.status !== 201) return null;
    p.token = res.json('token');
    players.push(p);
  }
  const room = call('POST', players[0], '/games', { bet_amount: 100, max_players: PLAYERS }, 'create room');
  if (room.status !== 201) return null;
  const code = room.json('code');
  for (const p of players.slice(1)) call('POST', p, `/games/${code}/join`, {}, 'join');
  return { players, code, stopAt: 0, closing: false };
}

// Pusher protocol, as Echo speaks it: wait for the socket id, sign the private
// channel through the API, subscribe, answer the server's pings.
function connect(t, p) {
  const channel = `private-game.${t.code}`;
  return new Promise((resolve) => {
    let settled = false;
    const settle = (good) => {
      if (settled) return;
      settled = true;
      wsSubscribed.add(good);
      resolve(good);
    };
    const ws = new WebSocket(WS);
    p.ws = ws;
    setTimeout(() => settle(false), 10000);
    ws.onmessage = async (e) => {
      const m = JSON.parse(e.data);
      if (m.event === 'pusher:connection_established') {
        const socket_id = JSON.parse(m.data).socket_id;
        const res = await acall('POST', p, '/broadcasting/auth', { socket_id, channel_name: channel }, 'ws auth');
        if (res.status !== 200) return settle(false);
        ws.send(JSON.stringify({ event: 'pusher:subscribe', data: { auth: res.json('auth'), channel } }));
      } else if (m.event === 'pusher_internal:subscription_succeeded') {
        settle(true);
      } else if (m.event === 'pusher:ping') {
        ws.send('{"event":"pusher:pong","data":{}}');
      } else if (m.event === 'SpinResult' && t.stopAt) {
        resultLag.add(Date.now() - t.stopAt);
        p.gotResult = true;
      }
    };
    ws.onerror = () => settle(false);
    ws.onclose = () => {
      if (settled && !t.closing) wsDropped.add(1);
      settle(false);
    };
  });
}

// One round, ~22 s: betting window with bets and polls, spin, results.
async function round(t) {
  const [host, ...guests] = t.players;
  await acall('POST', host, `/games/${t.code}/open-betting`, null, 'open betting');
  for (const p of guests) {
    await acall('GET', p, `/games/${t.code}`, null, 'poll room');
    await acall('POST', p, `/games/${t.code}/bets`, { animal_slot: 1 + Math.floor(Math.random() * 6) }, 'bet');
    await wait(1);
  }
  for (const p of guests) {
    await acall('POST', p, `/games/${t.code}/bets`, { animal_slot: 1 + Math.floor(Math.random() * 6) }, 'bet');
    await wait(1);
  }
  await acall('POST', host, `/games/${t.code}/spin`, null, 'spin');
  await wait(4);
  for (const p of t.players) p.gotResult = false;
  t.stopAt = Date.now();
  await acall('POST', host, `/games/${t.code}/stop`, null, 'stop');
  for (const p of t.players) await acall('GET', p, `/games/${t.code}`, null, 'poll room');
  await wait(6);
  for (const p of t.players) if (p.ws.readyState === 1 && !p.gotResult) wsMissed.add(1);
  t.stopAt = 0;
}

// The VU keeps its table for the whole run (sockets stay open across rounds);
// ramp-down interrupts it, smoke mode stops after ROUNDS.
export default async function () {
  const t = setUp();
  if (!t) return wait(5);
  
  await Promise.all(t.players.map((p) => connect(t, p)));
  for (let i = 0; i < ROUNDS; i++) await round(t);
  t.closing = true;
  for (const p of t.players) p.ws.close();
}
