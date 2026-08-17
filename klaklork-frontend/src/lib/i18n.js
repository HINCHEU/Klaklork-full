import { ref, watch } from 'vue'

/**
 * Tiny i18n layer — English and Khmer.
 *
 * `t()` reads the reactive `locale` ref, so any component that calls it in its
 * template re-renders when the language changes. The choice is remembered per
 * browser and mirrored onto <html lang> so the Khmer font kicks in.
 */

const LOCALE_KEY = 'klaklouk.locale'

export const LOCALES = [
  { code: 'en', label: 'EN',   name: 'English' },
  { code: 'km', label: 'ខ្មែរ', name: 'ភាសាខ្មែរ' },
]

const messages = {
  en: {
    lang: { switchTo: 'Switch to Khmer' },

    common: {
      close: 'Close', cancel: 'Cancel', save: 'Save', saving: 'Saving…',
      back: '← Back', exit: 'Exit', refresh: '🔄 Refresh', you: '(you)',
      players: 'players', perClick: '{amount} ៛ / click', riel: '{amount} ៛',
    },

    status: {
      waiting: 'waiting', betting: 'betting', spinning: 'spinning', finished: 'finished',
    },

    enter: {
      tagline: 'The classic Cambodian animal betting game — now multiplayer. No account, no password. Just pick a name and play.',
      playMoneyPill: '🎲 Play money only — for fun with friends, never for cash',
      subtitle: 'Multiplayer Betting Game',
      title: "What's your name?",
      cardSubtitle: 'Pick a name and jump straight into a game',
      nameLabel: 'Display name',
      namePlaceholder: 'e.g. Sokha',
      nameHint: '2–20 characters. Other players will see this name.',
      submit: '🎰 Enter the Game',
      submitting: 'Entering…',
      howItWorks: 'how it works',
      startingNote: 'You start with {amount} ៛. Your name and balance stay in this browser — close the tab and come back any time.',
      featureMultiplayer: '🎮 Multiplayer',
      featureRealtime: '⚡ Real-time',
      featureNoSignup: '🚀 No sign-up',
      footer: 'Kla Klouk · ខ្លាឃ្លោក — a free game for friends. Play money only.',
      errTooShort: 'Please enter at least 2 characters',
      errFailed: 'Could not start the game. Is the server running?',
      statSymbols: 'Animal Symbols',
      statPlayers: 'Players / Room',
      statMultiplier: 'Max Multiplier',
    },

    lobby: {
      badge: 'LOBBY',
      title: 'Game Rooms',
      subtitle: 'Create a private room or jump into an existing game',
      resumeTitle: 'You were playing in room {code}',
      resumeMeta: '{count}/{max} players · {status}',
      dismiss: 'Dismiss',
      rejoin: 'Rejoin →',
      openRooms: '🌐 Open Rooms',
      hostLine: 'Host:',
      emptyTitle: 'No open rooms',
      emptyBody: 'Be the first to create a game!',
      createTitle: '✨ Create Room',
      betPerClick: 'Bet per click (៛)',
      betCasual: '100 ៛ — casual',
      betStandard: '500 ៛ — standard',
      betHigh: '1,000 ៛ — high stakes',
      betWhale: '5,000 ៛ — whale',
      betInsane: '10,000 ៛ — insane',
      maxPlayers: 'Max players',
      bankerNote: "🏦 You'll be the banker: you pay the winners from your balance and collect every losing bet.",
      createBtn: '🏠 Create Room',
      creating: 'Creating…',
      joinTitle: '🔗 Join by Code',
      joinPlaceholder: 'Enter room code (e.g. ABC123)',
      joinBtn: '🎲 Join Room',
      joining: 'Joining…',
      howTitle: '📖 How to Play',
      how1: "The host creates a room and is the banker — they don't bet",
      how2: 'Players click animal symbols to place bets (stacks per click)',
      how3: 'Host spins — 3 random symbols are revealed',
      how4: 'Winners are paid by the banker; losing bets go to the banker',
      renameTitle: '✏️ Change your name',
      renameError: 'Could not change name',
      errCreate: 'Failed to create room',
      errJoin: 'Failed to join room',
      errCodeFormat: 'Room codes are 6 letters and numbers.',
      changeNameTip: 'Click to change your name',
      privateRoom: 'Private room',
      lockedBadge: 'Private',
      passwordPlaceholder: 'Room password',
      privateHint: 'Players will need this password to join. At least 4 characters.',
      publicHint: 'Anyone with the room code can join.',
      errPasswordRequired: 'This room is private — enter its password.',
      errWrongPassword: 'Wrong password. Try again.',
    },

    room: {
      loading: 'Loading room…',
      lockedTitle: 'This room is private',
      lockedBody: 'Room {code} needs a password to join.',
      lockedSubmit: '🔓 Join Room',
      soundOn: 'Sound on', soundOff: 'Sound off',
      copied: '✅ Copied!',
      resultSlots: '🎲 Result Slots',
      rolling: 'Rolling…',
      roundComplete: '✓ Round complete',
      collectedFrom: 'collected from players',
      paidOutTo: 'paid out to players',
      board: '🐾 Betting Board',
      youAreBanker: '🏦 You are the banker',
      clear: '✕ Clear',
      atRisk: '{amount} ៛ at risk',
      staked: '{amount} ៛ staked',
      openBetting: '🟢 Open Betting',
      spin: '🎰 SPIN!',
      stop: '🛑 STOP!',
      onTheTable: '🏦 On the table: {amount} ៛',
      totalBet: 'Total bet: {amount} ៛',
      waitingForBets: 'Waiting for players to bet…',
      clickToBet: 'Click animals to bet',
      history: '📜 Round History ({count})',
      waitingTitle: 'Waiting for host',
      waitingBody: 'Host will open betting soon…',
      bankerTitle: "You're the banker",
      bankerBody: 'You pay every winner and collect every losing bet',
      placeBets: 'Place your bets!',
      perClickNote: '{amount} ៛ per click',
      rollingTitle: 'Rolling…',
      rollingBody: 'Hold on to your bets!',
      resultsIn: 'Results are in!',
      waitingNextRound: 'Waiting for next round…',
      playersTitle: '👥 Players {count}/{max}',
      settlement: '🧾 Round Settlement',
      banker: '(banker)',
      betsPlaced: '📊 Bets Placed',
      win1: 'WIN! 🎉', win2: '2× WIN! ✨', win3: '3× WIN! 🔥', lost: '❌ Lost',
      popupBreakEven: 'Break even',
      popupBreakEvenBody: 'Nothing won, nothing lost 😌',
      popupCollected: 'Collected from the players 🏦',
      popupPaidOut: 'Paid out to the winners 💸',
      popupWin: 'You win! 🤩',
      popupLose: 'Better luck next round 😑',
      popupClose: 'បិទ · Close',
    },

    toast: {
      joined: '{name} joined',
      left: '{name} left',
      bettingOpen: 'Betting is open! 🎯',
      bankerCannotBet: 'You are the banker — you pay the winners 🏦',
      insufficient: 'Insufficient balance!',
      betFailed: 'Bet failed',
      collected: '+{amount} ៛ collected from players 🏦',
      paidOut: '{amount} ៛ paid out to players 💸',
      noBets: 'No bets this round',
      playerWon: '+{amount} ៛ from the banker! 🎉',
      playerLost: '{amount} ៛ to the banker 😢',
      breakEven: 'Break even!',
      roomGone: 'That room no longer exists',
      joinFailed: 'Could not join this room',
      spinFailed: 'Spin failed',
      stopFailed: 'Stop failed',
      error: 'Error',
      tooFast: 'Slow down a moment — too many requests. Try again shortly.',
      tooFastIn: 'Slow down a moment — try again in {seconds}s.',
    },

    responsible: {
      compactTitle: '🎲 Just for fun',
      compactBody1: "Every ៛ here is <strong>play money</strong> — nothing can be cashed in or out. It's a game to enjoy with friends, nothing more.",
      compactBody2: 'Real gambling is a different story: the odds are built against you (<strong>~8%</strong> of every bet goes to the banker in this very game), losses add up fast, and chasing them can turn into an addiction. <strong>If it ever stops being fun, stop.</strong>',
      fullTitle: '🎲 For entertainment only',
      fullIntro: 'This is a <b>play-money game</b> — a way to hang out with friends, chill, and enjoy a bit of luck together. There is no deposit, no withdrawal, no prize: the ៛ on screen can never become real money.',
      divider: 'but real gambling is not a game',
      risk1Title: 'The odds are built against you',
      risk1Body: 'even in this exact game, the banker keeps about 8% of everything staked over time. A lucky night never changes the long run.',
      risk2Title: 'Chasing losses makes it worse',
      risk2Body: '"one more round to win it back" is how a small loss quietly turns into a big one.',
      risk3Title: 'It can become an addiction',
      risk3Body: 'gambling hooks the brain like a drug. It costs people their savings, their sleep, and the trust of their family — and it can happen to anyone.',
      risk4Title: 'Never bet what you need',
      risk4Body: 'money for food, rent, school or family is never spare money.',
      risk5Title: 'Keep it fun',
      risk5Body: 'set a limit before you start, take breaks, and walk away while you are still enjoying it.',
      help: 'If gambling is affecting your money, your sleep or the people around you, talk to someone you trust or a health worker. Free, confidential help is also available online at',
      helpMid: '(many languages) and',
      helpEnd: '. This game is not intended for under-18s.',
    },
  },

  km: {
    lang: { switchTo: 'ប្តូរទៅភាសាអង់គ្លេស' },

    common: {
      close: 'បិទ', cancel: 'បោះបង់', save: 'រក្សាទុក', saving: 'កំពុងរក្សាទុក…',
      back: '← ត្រឡប់', exit: 'ចាកចេញ', refresh: '🔄 ផ្ទុកឡើងវិញ', you: '(អ្នក)',
      players: 'អ្នកលេង', perClick: '{amount} ៛ / ចុចម្តង', riel: '{amount} ៛',
    },

    status: {
      waiting: 'រង់ចាំ', betting: 'កំពុងចាក់', spinning: 'កំពុងបង្វិល', finished: 'ចប់ជុំ',
    },

    enter: {
      tagline: 'ល្បែងខ្លាឃ្លោកបែបខ្មែរ — ឥឡូវលេងជាមួយគ្នាបានច្រើននាក់។ មិនត្រូវការគណនី មិនត្រូវការពាក្យសម្ងាត់ គ្រាន់តែដាក់ឈ្មោះ រួចលេងបាន។',
      playMoneyPill: '🎲 លុយក្លែងក្លាយប៉ុណ្ណោះ — លេងកម្សាន្តជាមួយមិត្ត មិនមែនលុយពិត',
      subtitle: 'ល្បែងកម្សាន្តលេងជាមួយគ្នា',
      title: 'តើអ្នកឈ្មោះអ្វី?',
      cardSubtitle: 'ដាក់ឈ្មោះ រួចចូលលេងភ្លាម',
      nameLabel: 'ឈ្មោះបង្ហាញ',
      namePlaceholder: 'ឧ. សុខា',
      nameHint: 'ពី ២ ដល់ ២០ តួអក្សរ។ អ្នកលេងផ្សេងទៀតនឹងឃើញឈ្មោះនេះ។',
      submit: '🎰 ចូលលេង',
      submitting: 'កំពុងចូល…',
      howItWorks: 'របៀបលេង',
      startingNote: 'អ្នកចាប់ផ្តើមដោយមាន {amount} ៛។ ឈ្មោះ និងសមតុល្យរបស់អ្នករក្សាទុកក្នុងកម្មវិធីរុករកនេះ — បិទផ្ទាំង រួចត្រឡប់មកវិញពេលណាក៏បាន។',
      featureMultiplayer: '🎮 លេងច្រើននាក់',
      featureRealtime: '⚡ ភ្លាមៗ',
      featureNoSignup: '🚀 មិនចាំបាច់ចុះឈ្មោះ',
      footer: 'ខ្លាឃ្លោក — ល្បែងកម្សាន្តឥតគិតថ្លៃសម្រាប់មិត្តភក្តិ។ លុយក្លែងក្លាយប៉ុណ្ណោះ។',
      errTooShort: 'សូមបញ្ចូលយ៉ាងតិច ២ តួអក្សរ',
      errFailed: 'មិនអាចចាប់ផ្តើមបានទេ។ តើម៉ាស៊ីនមេដំណើរការឬនៅ?',
      statSymbols: 'រូបសត្វ',
      statPlayers: 'អ្នកលេង / បន្ទប់',
      statMultiplier: 'គុណអតិបរមា',
    },

    lobby: {
      badge: 'បន្ទប់រង់ចាំ',
      title: 'បន្ទប់លេង',
      subtitle: 'បង្កើតបន្ទប់ថ្មី ឬចូលរួមបន្ទប់ដែលមានស្រាប់',
      resumeTitle: 'អ្នកកំពុងលេងក្នុងបន្ទប់ {code}',
      resumeMeta: 'អ្នកលេង {count}/{max} · {status}',
      dismiss: 'បោះបង់',
      rejoin: 'ចូលវិញ →',
      openRooms: '🌐 បន្ទប់ដែលកំពុងបើក',
      hostLine: 'ម្ចាស់បន្ទប់៖',
      emptyTitle: 'មិនមានបន្ទប់បើកទេ',
      emptyBody: 'ចាប់ផ្តើមបង្កើតបន្ទប់ដំបូងគេ!',
      createTitle: '✨ បង្កើតបន្ទប់',
      betPerClick: 'ចំនួនចាក់ក្នុងមួយចុច (៛)',
      betCasual: '១០០ ៛ — លេងលេង',
      betStandard: '៥០០ ៛ — ធម្មតា',
      betHigh: '១,០០០ ៛ — ធំបន្តិច',
      betWhale: '៥,០០០ ៛ — ធំ',
      betInsane: '១០,០០០ ៛ — ធំខ្លាំង',
      maxPlayers: 'អ្នកលេងអតិបរមា',
      bankerNote: '🏦 អ្នកនឹងក្លាយជាម្ចាស់ការ៖ អ្នកបង់ឱ្យអ្នកឈ្នះពីសមតុល្យរបស់អ្នក ហើយទទួលលុយពីអ្នកចាញ់។',
      createBtn: '🏠 បង្កើតបន្ទប់',
      creating: 'កំពុងបង្កើត…',
      joinTitle: '🔗 ចូលដោយលេខកូដ',
      joinPlaceholder: 'បញ្ចូលលេខកូដបន្ទប់ (ឧ. ABC123)',
      joinBtn: '🎲 ចូលរួម',
      joining: 'កំពុងចូល…',
      howTitle: '📖 របៀបលេង',
      how1: 'ម្ចាស់បន្ទប់ជាម្ចាស់ការ — គាត់មិនចាក់ទេ',
      how2: 'អ្នកលេងចុចលើរូបសត្វដើម្បីចាក់ (ចុចម្តងបន្ថែមម្តង)',
      how3: 'ម្ចាស់បន្ទប់បង្វិល — រូបសត្វ ៣ ចេញមក',
      how4: 'អ្នកឈ្នះទទួលលុយពីម្ចាស់ការ ឯអ្នកចាញ់បាត់លុយទៅម្ចាស់ការ',
      renameTitle: '✏️ ប្តូរឈ្មោះ',
      renameError: 'មិនអាចប្តូរឈ្មោះបានទេ',
      errCreate: 'បង្កើតបន្ទប់មិនបានទេ',
      errJoin: 'ចូលរួមបន្ទប់មិនបានទេ',
      errCodeFormat: 'លេខកូដបន្ទប់មាន ៦ តួអក្សរ និងលេខ។',
      changeNameTip: 'ចុចដើម្បីប្តូរឈ្មោះ',
      privateRoom: 'បន្ទប់ឯកជន',
      lockedBadge: 'ឯកជន',
      passwordPlaceholder: 'ពាក្យសម្ងាត់បន្ទប់',
      privateHint: 'អ្នកលេងត្រូវការពាក្យសម្ងាត់នេះដើម្បីចូលរួម។ យ៉ាងតិច ៤ តួ។',
      publicHint: 'អ្នកណាដែលមានលេខកូដបន្ទប់អាចចូលរួមបាន។',
      errPasswordRequired: 'បន្ទប់នេះជាឯកជន — សូមបញ្ចូលពាក្យសម្ងាត់។',
      errWrongPassword: 'ពាក្យសម្ងាត់មិនត្រឹមត្រូវ។ សូមព្យាយាមម្តងទៀត។',
    },

    room: {
      loading: 'កំពុងផ្ទុកបន្ទប់…',
      lockedTitle: 'បន្ទប់នេះជាបន្ទប់ឯកជន',
      lockedBody: 'បន្ទប់ {code} ត្រូវការពាក្យសម្ងាត់ដើម្បីចូលរួម។',
      lockedSubmit: '🔓 ចូលរួមបន្ទប់',
      soundOn: 'បើកសំឡេង', soundOff: 'បិទសំឡេង',
      copied: '✅ ចម្លងរួច!',
      resultSlots: '🎲 លទ្ធផល',
      rolling: 'កំពុងបង្វិល…',
      roundComplete: '✓ ចប់ជុំ',
      collectedFrom: 'ទទួលបានពីអ្នកលេង',
      paidOutTo: 'បង់ឱ្យអ្នកលេង',
      board: '🐾 ក្តារចាក់',
      youAreBanker: '🏦 អ្នកជាម្ចាស់ការ',
      clear: '✕ សម្អាត',
      atRisk: '{amount} ៛ ប្រឈម',
      staked: '{amount} ៛ បានចាក់',
      openBetting: '🟢 បើកឱ្យចាក់',
      spin: '🎰 បង្វិល!',
      stop: '🛑 ឈប់!',
      onTheTable: '🏦 លើក្តារ៖ {amount} ៛',
      totalBet: 'ចាក់សរុប៖ {amount} ៛',
      waitingForBets: 'កំពុងរង់ចាំអ្នកលេងចាក់…',
      clickToBet: 'ចុចលើរូបសត្វដើម្បីចាក់',
      history: '📜 ប្រវត្តិជុំ ({count})',
      waitingTitle: 'រង់ចាំម្ចាស់បន្ទប់',
      waitingBody: 'ម្ចាស់បន្ទប់នឹងបើកឱ្យចាក់ក្នុងពេលឆាប់ៗ…',
      bankerTitle: 'អ្នកជាម្ចាស់ការ',
      bankerBody: 'អ្នកបង់ឱ្យអ្នកឈ្នះ ហើយទទួលលុយពីអ្នកចាញ់',
      placeBets: 'សូមចាក់!',
      perClickNote: '{amount} ៛ ក្នុងមួយចុច',
      rollingTitle: 'កំពុងបង្វិល…',
      rollingBody: 'រង់ចាំបន្តិច!',
      resultsIn: 'លទ្ធផលចេញហើយ!',
      waitingNextRound: 'រង់ចាំជុំបន្ទាប់…',
      playersTitle: '👥 អ្នកលេង {count}/{max}',
      settlement: '🧾 ការទូទាត់ជុំនេះ',
      banker: '(មេ)',
      betsPlaced: '📊 ការចាក់',
      win1: 'ឈ្នះ! 🎉', win2: 'ឈ្នះ ២ដង! ✨', win3: 'ឈ្នះ ៣ដង! 🔥', lost: '❌ ចាញ់',
      popupBreakEven: 'ស្មើដើម',
      popupBreakEvenBody: 'មិនឈ្នះ មិនចាញ់ 😌',
      popupCollected: 'ទទួលបានពីអ្នកលេង 🏦',
      popupPaidOut: 'បង់ឱ្យអ្នកឈ្នះ 💸',
      popupWin: 'អ្នកឈ្នះ! 🤩',
      popupLose: 'សំណាងជុំក្រោយ 😑',
      popupClose: 'បិទ · Close',
    },

    toast: {
      joined: '{name} បានចូលរួម',
      left: '{name} បានចាកចេញ',
      bettingOpen: 'បើកឱ្យចាក់ហើយ! 🎯',
      bankerCannotBet: 'អ្នកជាម្ចាស់ការ — អ្នកបង់ឱ្យអ្នកឈ្នះ 🏦',
      insufficient: 'សមតុល្យមិនគ្រប់គ្រាន់!',
      betFailed: 'ចាក់មិនបានទេ',
      collected: '+{amount} ៛ ទទួលបានពីអ្នកលេង 🏦',
      paidOut: '{amount} ៛ បង់ឱ្យអ្នកលេង 💸',
      noBets: 'ជុំនេះគ្មានអ្នកចាក់ទេ',
      playerWon: '+{amount} ៛ ពីម្ចាស់ការ! 🎉',
      playerLost: '{amount} ៛ ទៅម្ចាស់ការ 😢',
      breakEven: 'ស្មើដើម!',
      roomGone: 'បន្ទប់នេះលែងមានហើយ',
      joinFailed: 'ចូលរួមបន្ទប់នេះមិនបានទេ',
      spinFailed: 'បង្វិលមិនបានទេ',
      stopFailed: 'ឈប់មិនបានទេ',
      error: 'មានបញ្ហា',
      tooFast: 'សូមបន្ថយល្បឿន — សំណើច្រើនពេក។ សូមព្យាយាមម្តងទៀតក្នុងពេលឆាប់ៗ។',
      tooFastIn: 'សូមបន្ថយល្បឿន — សូមព្យាយាមម្តងទៀតក្នុងរយៈពេល {seconds} វិនាទី។',
    },

    responsible: {
      compactTitle: '🎲 លេងកម្សាន្តតែប៉ុណ្ណោះ',
      compactBody1: 'រាល់ ៛ នៅទីនេះគឺ <strong>លុយក្លែងក្លាយ</strong> — មិនអាចដកឬដាក់លុយពិតបានទេ។ វាគ្រាន់តែជាល្បែងកម្សាន្តជាមួយមិត្តភក្តិប៉ុណ្ណោះ។',
      compactBody2: 'ល្បែងស៊ីសងពិតគឺខុសគ្នា៖ ឱកាសត្រូវបានរៀបចំទាស់នឹងអ្នក (<strong>~៨%</strong> នៃលុយចាក់ទាំងអស់ធ្លាក់ទៅម្ចាស់ការ សូម្បីតែក្នុងល្បែងនេះ) ការចាញ់កើនឡើងលឿន ហើយការដេញតាមសងវិញអាចប្រែក្លាយជាការញៀន។ <strong>បើលែងសប្បាយទៀត សូមឈប់។</strong>',
      fullTitle: '🎲 សម្រាប់កម្សាន្តតែប៉ុណ្ណោះ',
      fullIntro: 'នេះជា <b>ល្បែងលុយក្លែងក្លាយ</b> — ជាមធ្យោបាយកម្សាន្តជាមួយមិត្តភក្តិ សប្បាយៗជាមួយគ្នា។ គ្មានការដាក់លុយ គ្មានការដកលុយ គ្មានរង្វាន់៖ ៛ នៅលើអេក្រង់មិនអាចក្លាយជាលុយពិតបានទេ។',
      divider: 'ប៉ុន្តែល្បែងស៊ីសងពិតមិនមែនជាការលេងទេ',
      risk1Title: 'ឱកាសត្រូវបានរៀបចំទាស់នឹងអ្នក',
      risk1Body: 'សូម្បីតែក្នុងល្បែងនេះ ម្ចាស់វង់(មេ) ទទួលបានប្រហែល ៨% នៃលុយចាក់ទាំងអស់តាមរយៈពេលវែង។ ការឈ្នះមួយយប់មិនប្តូរលទ្ធផលរយៈពេលវែងទេ។',
      risk2Title: 'ការដេញតាមសងវិញធ្វើឱ្យអាក្រក់ជាង',
      risk2Body: 'ពាក្យថា "ចាក់មួយជុំទៀតដើម្បីយកសងវិញ" គឺជារបៀបដែលការចាញ់តិចតួចក្លាយជាការចាញ់ធំ។',
      risk3Title: 'វាអាចក្លាយជាការញៀន',
      risk3Body: 'ល្បែងស៊ីសងទាក់ខួរក្បាលដូចគ្រឿងញៀន។ វាធ្វើឱ្យមនុស្សបាត់បង់ប្រាក់សន្សំ បាត់ដំណេក និងបាត់ទំនុកចិត្តពីគ្រួសារ — ហើយវាអាចកើតឡើងលើនរណាក៏បាន។',
      risk4Title: 'កុំចាក់លុយដែលអ្នកត្រូវការ',
      risk4Body: 'លុយសម្រាប់អាហារ ជួលផ្ទះ សាលារៀន ឬគ្រួសារ មិនមែនជាលុយសល់ទេ។',
      risk5Title: 'រក្សាឱ្យវានៅជាការកម្សាន្ត',
      risk5Body: 'កំណត់ដែនកំណត់មុនចាប់ផ្តើម សម្រាកខ្លះ ហើយឈប់ពេលអ្នកនៅតែសប្បាយ។',
      help: 'ប្រសិនបើល្បែងស៊ីសងកំពុងប៉ះពាល់ដល់ប្រាក់កាស ដំណេក ឬមនុស្សជុំវិញអ្នក សូមនិយាយជាមួយអ្នកដែលអ្នកទុកចិត្ត ឬបុគ្គលិកសុខាភិបាល។ ជំនួយឥតគិតថ្លៃ និងសម្ងាត់ក៏មានតាមអនឡាញផងដែរនៅ',
      helpMid: '(មានច្រើនភាសា) និង',
      helpEnd: '។ ល្បែងនេះមិនសម្រាប់អ្នកអាយុក្រោម ១៨ ឆ្នាំទេ។',
    },
  },
}

function detect() {
  try {
    const nav = navigator.language || ''
    if (nav.toLowerCase().startsWith('km')) return 'km'
  } catch { /* no navigator */ }
  return 'en'
}

function stored() {
  try {
    const v = localStorage.getItem(LOCALE_KEY)
    return messages[v] ? v : null
  } catch { return null }
}

export const locale = ref(stored() || detect())

function apply(code) {
  try { localStorage.setItem(LOCALE_KEY, code) } catch { /* storage disabled */ }
  if (typeof document !== 'undefined') document.documentElement.lang = code
}
apply(locale.value)
watch(locale, apply)

export function setLocale(code) {
  if (messages[code]) locale.value = code
}

export function toggleLocale() {
  setLocale(locale.value === 'en' ? 'km' : 'en')
}

/** t('room.spin') / t('common.riel', { amount: '1,000' }) — falls back to English. */
export function t(key, params) {
  const pick = (dict) => key.split('.').reduce((o, k) => (o == null ? o : o[k]), dict)
  let text = pick(messages[locale.value])
  if (text == null) text = pick(messages.en)
  if (text == null) return key
  if (params) {
    for (const [k, v] of Object.entries(params)) text = text.split(`{${k}}`).join(v)
  }
  return text
}

export function useI18n() {
  return { t, locale, setLocale, toggleLocale, LOCALES }
}
