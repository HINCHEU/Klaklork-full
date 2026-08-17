# Kla Klouk Game Implementation Plan

This document outlines the planned implementation for the Kla Klouk multiplayer game, which consists of a Laravel Backend API and a Vue 3 Frontend.

## User Review Required

> [!IMPORTANT]
> Please review this implementation plan. Since the current codebase includes basic database models (GameRoom, Bet, User), we need to confirm if we should use Laravel Reverb or Pusher for real-time multiplayer functionality (WebSockets).

## Open Questions

> [!WARNING]
> 1. Do you want to use WebSockets (e.g., Pusher or Laravel Reverb) for real-time game state updates (joining, betting, rolling)?
> 2. Are there any specific design preferences or CSS frameworks (like TailwindCSS) you want to use for the frontend? The current setup uses Vanilla CSS.

## Proposed Changes

### Backend (Laravel API)
The backend will handle game state, validation, and database interactions.

#### [NEW] `routes/api.php`
- Add routes for authentication (login/register).
- Add routes for game management:
  - `POST /rooms` - Create a new game room.
  - `POST /rooms/{code}/join` - Join an existing room.
  - `POST /rooms/{code}/bet` - Place a bet.
  - `POST /rooms/{code}/roll` - Host rolls the dice and generates results.

#### [NEW] `app/Http/Controllers/GameController.php`
- Implement game logic corresponding to the routes above.

#### [NEW] `app/Events/GameStateUpdated.php`
- Broadcast event to notify all players in a room about new joins, bets, or dice rolls.

---

### Frontend (Vue 3 + Vite)
The frontend will provide a dynamic and interactive UI for the players.

#### [MODIFY] `src/App.vue`
- Setup routing view or dynamic component rendering for different game states.

#### [NEW] `src/components/Home.vue`
- UI to create a new room or enter a room code to join.

#### [NEW] `src/components/GameRoom.vue`
- The main game interface showing:
  - The Kla Klouk betting board (6 symbols).
  - Current players in the room.
  - Host controls (Start Game, Roll Dice).

#### [NEW] `src/services/api.js`
- Axios or Fetch wrappers for communicating with the Laravel API.

## Verification Plan

### Automated Tests
- Feature tests in Laravel for Room Creation, Joining, and Betting logic.

### Manual Verification
- Start the Laravel dev server and Vue dev server.
- Open multiple browser tabs to simulate multiple users.
- Verify that a user can create a room, another user can join via the code, both can place bets, and the host can roll the dice with results correctly distributed.
