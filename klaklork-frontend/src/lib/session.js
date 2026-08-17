/**
 * Browser-side session storage.
 *
 * The player never logs in — they just type a name. We keep the API token and a
 * few breadcrumbs in localStorage so closing the tab (or an accidental refresh)
 * doesn't lose the player's identity, balance, or the room they were sitting in.
 */

const TOKEN_KEY = 'klaklouk.token'
const NAME_KEY  = 'klaklouk.name'
const ROOM_KEY  = 'klaklouk.room'

function read(key) {
  try { return localStorage.getItem(key) } catch { return null }
}
function write(key, value) {
  try {
    if (value == null) localStorage.removeItem(key)
    else localStorage.setItem(key, value)
  } catch { /* private mode / storage disabled — session just won't persist */ }
}

export const getToken = () => read(TOKEN_KEY)
export const setToken = (t) => write(TOKEN_KEY, t)

/** Last name used, so the entry screen can pre-fill it after a session ends. */
export const getLastName = () => read(NAME_KEY)
export const setLastName = (n) => write(NAME_KEY, n)

/**
 * What a room code is allowed to look like — mirrors GameRoom::CODE_PATTERN on
 * the API. Codes reach us from places we do not control (an invite link, a
 * typed field, a stale localStorage entry), and they get interpolated into
 * request paths and channel names, so they are checked before they are used
 * rather than after.
 */
const CODE_RE = /^[A-HJ-NP-Za-hj-np-z2-9]{6}$/

export const isValidRoomCode = (code) => typeof code === 'string' && CODE_RE.test(code)

/** Last room the player was in, used for the "rejoin" prompt in the lobby. */
export const getLastRoom = () => {
  const code = read(ROOM_KEY)
  return isValidRoomCode(code) ? code.toUpperCase() : null
}
export const setLastRoom = (code) => write(ROOM_KEY, isValidRoomCode(code) ? code.toUpperCase() : null)
export const clearLastRoom = () => write(ROOM_KEY, null)

/** Wipe the session (keeps the remembered name for convenience). */
export function clearSession() {
  write(TOKEN_KEY, null)
  write(ROOM_KEY, null)
}
