import { defineStore } from 'pinia'
import api from '@/lib/api'

export const useGameStore = defineStore('game', {
  state: () => ({
    room: null,
    myBets: {},        // { slotNumber: totalAmount }
    result: null,      // [1,3,5]  — final 3 slots
    status: null,      // waiting | betting | spinning | finished
    players: [],
    allBets: [],
    toasts: [],
  }),
  getters: {
    isHost: (s) => (userId) => s.room?.host_user_id === userId,
    totalBet: (s) => Object.values(s.myBets).reduce((a, b) => a + b, 0),
  },
  actions: {
    setRoom(room) {
      this.room   = room
      this.status = room.status
      this.players = room.players || []
      this.allBets = room.bets   || []
      // Restore my bets from server
      this.myBets = {}
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
    addBet(betData) {
      this.allBets.push(betData)
    },
    setSpinning() {
      this.status = 'spinning'
      this.result = null
    },
    setResult(result, bets) {
      this.status = 'finished'
      this.result = result
      this.allBets = bets
    },
    openBetting() {
      this.status = 'betting'
    },
    addToast(msg, type = 'info') {
      const id = Date.now()
      this.toasts.push({ id, msg, type })
      setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id) }, 4000)
    },
    reset() {
      this.room = null; this.myBets = {}; this.result = null
      this.status = null; this.players = []; this.allBets = []; this.toasts = []
    },
  },
})
