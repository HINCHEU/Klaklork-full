// k6 load test: each VU is one full table (1 host + 5 players) playing rounds.
//   k6 run loadtest/tables.js                      -> smoke: 1 table, 2 rounds
//   k6 run -e MODE=ramp -e SPOOF=1 loadtest/tables.js -> ramp tables until it breaks
// SPOOF=1 gives every player its own X-Forwarded-For address; it only takes
// effect while the API trusts the caller as a proxy (TRUSTED_PROXIES=*).
import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE = __ENV.BASE || 'https://game.hincheu.online/api';
const SPOOF = __ENV.SPOOF === '1';
const PLAYERS = 6;

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
      },
    }
  : __ENV.MODE === 'steady'
    ? { vus: Number(__ENV.VUS || 40), duration: __ENV.DUR || '90s' }
    : { vus: 1, iterations: 2 };

let table = null; // per VU: [{ token, ip }], index 0 is the host, plus the room code

function params(p, name) {
  const headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
  if (p.token) headers.Authorization = `Bearer ${p.token}`;
  if (SPOOF) headers['X-Forwarded-For'] = p.ip;
  return { headers, tags: { name } };
}

function call(method, p, path, body, name) {
  const res = http.request(method, `${BASE}${path}`, body ? JSON.stringify(body) : null, params(p, name));
  check(res, { [`${name} 2xx`]: (r) => r.status >= 200 && r.status < 300 });
  return res;
}

function setUp() {
  const players = [];
  for (let i = 0; i < PLAYERS; i++) {
    const p = { ip: `10.${(__VU >> 8) & 255}.${__VU & 255}.${i + 1}` };
    const res = call('POST', p, '/guest', { name: `loadtest-${__VU}-${i}` }, 'guest');
    if (res.status !== 201) return null;
    p.token = res.json('token');
    players.push(p);
  }
  const room = call('POST', players[0], '/games', { bet_amount: 100, max_players: PLAYERS }, 'create room');
  if (room.status !== 201) return null;
  const code = room.json('code');
  for (const p of players.slice(1)) call('POST', p, `/games/${code}/join`, {}, 'join');
  return { players, code };
}

// One round, ~22 s: betting window with bets and polls, spin, results.
export default function () {
  if (!table) table = setUp();
  if (!table) { sleep(5); return; }
  const { players, code } = table;
  const [host, ...guests] = players;

  call('POST', host, `/games/${code}/open-betting`, null, 'open betting');
  for (const p of guests) {
    call('GET', p, `/games/${code}`, null, 'poll room');
    call('POST', p, `/games/${code}/bets`, { animal_slot: 1 + Math.floor(Math.random() * 6) }, 'bet');
    sleep(1);
  }
  for (const p of guests) {
    call('POST', p, `/games/${code}/bets`, { animal_slot: 1 + Math.floor(Math.random() * 6) }, 'bet');
    sleep(1);
  }
  call('POST', host, `/games/${code}/spin`, null, 'spin');
  sleep(4);
  call('POST', host, `/games/${code}/stop`, null, 'stop');
  for (const p of players) call('GET', p, `/games/${code}`, null, 'poll room');
  sleep(6);
}
