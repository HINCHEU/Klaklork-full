import { defineStore } from 'pinia'
import api from '@/lib/api'

export const useGameStore = defineStore('game', {
  state: () => ({
    room: null,
    myBets: {},        // { slotNumber: totalAmount }
    result: null,      // [1,3,5]  — final 3 slots (preserved for display after round ends)
    status: null,      // waiting | betting | spinning | finished
    players: [],
    allBets: [],
    settlement: null,  // { host: {id,name,net,balance}, players: [{id,name,net,balance}] }
    roundHistory: [],  // [{ result: [1,3,5], roundAt: Date }] last 10 rounds
    toasts: [],
  }),
  getters: {
    isHost: (s) => (userId) => s.room?.host_user_id === userId,
    totalBet: (s) => Object.values(s.myBets).reduce((a, b) => a + b, 0),
  },
  actions: {
    setRoom(room) {
      this.room    = room
      this.status  = room.status
      this.players = room.players || []
      this.allBets = room.bets   || []
      this.myBets  = {}
      // Keep the last result (and who paid whom) visible when rejoining a room
      this.result = room.status === 'finished' ? (room.result || null) : null
      this.settlement = room.status === 'finished' ? this.deriveSettlement(this.allBets) : null
    },
    setMyBets(bets, userId) {
      this.myBets = {}
      bets.filter(b => b.user_id === userId).forEach(b => {
        this.myBets[b.animal_slot] = (this.myBets[b.animal_slot] || 0) + b.amount
      })
    },
    addPlayerJoined(player) {
      if (!this.players.find(p => p.id === player.id)) this.players.push(player)
    },
    removePlayer(playerId) {
      this.players = this.players.filter(p => p.id !== playerId)
    },
    addBet(betData) {
      // Merge duplicate slot+user bets in the live feed for cleanliness
      const idx = this.allBets.findIndex(
        b => b.user?.id === betData.user?.id && b.animal_slot === betData.animal_slot
      )
      if (idx !== -1) {
        this.allBets[idx] = { ...this.allBets[idx], amount: (this.allBets[idx].amount || 0) + (betData.amount || 0) }
      } else {
        this.allBets.push(betData)
      }
    },
    setSpinning() {
      this.status = 'spinning'
      this.result = null
    },
    setResult(result, bets, settlement = null) {
      this.status = 'finished'
      this.result = result
      this.allBets = bets
      this.applySettlement(settlement || this.deriveSettlement(bets))
      // Store in round history (keep last 10)
      this.roundHistory.unshift({ result, timestamp: new Date() })
      if (this.roundHistory.length > 10) this.roundHistory.pop()
    },
    /**
     * Rebuild the settlement from stored bets, for clients that arrive after the
     * SpinResult broadcast (reconnect, or a websocket event the poll had to cover).
     * The round is zero-sum, so the banker's net is the negative of all player nets.
     */
    deriveSettlement(bets) {
      if (!bets?.length || !this.room) return null
      const byPlayer = new Map()
      bets.forEach(b => {
        const id = b.user?.id ?? b.user_id
        if (id == null) return
        const entry = byPlayer.get(id) || { id, name: b.user?.name || '', net: 0, balance: null }
        entry.net += b.won_amount || 0
        byPlayer.set(id, entry)
      })
      const players = [...byPlayer.values()].map(p => ({
        ...p,
        balance: this.players.find(x => x.id === p.id)?.balance ?? p.balance,
      }))
      const hostId = this.room.host_user_id
      const host   = this.players.find(p => p.id === hostId)
      return {
        host: {
          id: hostId,
          name: host?.name || 'Host',
          net: -players.reduce((sum, p) => sum + p.net, 0),
          balance: host?.balance ?? null,
        },
        players,
      }
    },
    /** Apply the host↔players money transfer to every balance shown in the room. */
    applySettlement(settlement) {
      this.settlement = settlement || null
      if (!settlement) return
      const fresh = new Map()
      if (settlement.host) fresh.set(settlement.host.id, settlement.host.balance)
      ;(settlement.players || []).forEach(p => fresh.set(p.id, p.balance))
      this.players = this.players.map(p => fresh.has(p.id) ? { ...p, balance: fresh.get(p.id) } : p)
    },
    openBetting() {
      this.status  = 'betting'
      this.result  = null
      this.myBets  = {}
      this.allBets = []
      this.settlement = null
    },
    resetForNewRound() {
      this.status  = 'waiting'
      this.myBets  = {}
      this.allBets = []
      // Keep result visible momentarily — caller should clear after delay
    },
    addToast(msg, type = 'info') {
      const id = Date.now()
      this.toasts.push({ id, msg, type })
      setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id) }, 4000)
    },
    reset() {
      this.room = null; this.myBets = {}; this.result = null
      this.status = null; this.players = []; this.allBets = []
      this.settlement = null
      this.roundHistory = []; this.toasts = []
    },
  },
})
