/**
 * Game audio — the same cues the original single-player game used:
 *   click.wav  → a bet is placed
 *   music.m4a  → loops while the slots are rolling
 *   happy.mp3  → you won the round
 *
 * Files live in public/audio, so they're served straight from the site root.
 * Every call is fire-and-forget: browsers reject play() until the user has
 * interacted with the page, and a muted player is never a reason to break the game.
 */

const MUTE_KEY = 'klaklouk.muted'

const SOURCES = {
  click: { src: '/audio/click.wav', volume: 0.6 },
  music: { src: '/audio/music.m4a', volume: 0.35, loop: true },
  win:   { src: '/audio/happy.mp3', volume: 0.8 },
}

const cache = {}

let muted = (() => {
  try { return localStorage.getItem(MUTE_KEY) === '1' } catch { return false }
})()

/** Audio elements are built on first use so the 1 MB music track isn't fetched up front. */
function get(name) {
  if (!cache[name]) {
    const { src, volume, loop } = SOURCES[name]
    const el = new Audio(src)
    el.volume = volume
    el.loop = !!loop
    el.preload = 'auto'
    cache[name] = el
  }
  return cache[name]
}

function play(name) {
  if (muted) return
  try {
    const el = get(name)
    el.currentTime = 0
    el.play()?.catch(() => {})   // autoplay blocked until first interaction
  } catch { /* audio unsupported — game still plays fine */ }
}

function stop(name) {
  const el = cache[name]
  if (!el) return
  try { el.pause(); el.currentTime = 0 } catch {}
}

export const playClick  = () => play('click')
export const playWin    = () => play('win')
export const startMusic = () => play('music')
export const stopMusic  = () => stop('music')

export const isMuted = () => muted

/** Toggle sound for this browser; returns the new muted state. */
export function toggleMute() {
  muted = !muted
  try { localStorage.setItem(MUTE_KEY, muted ? '1' : '0') } catch {}
  if (muted) Object.keys(SOURCES).forEach(stop)
  return muted
}
