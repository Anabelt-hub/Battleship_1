// --- CONFIGURATION & STATE ---
const SHIP_SEQUENCE = [5, 4, 3]; // Carrier, Battleship, Destroyer
let currentBaseUrl = "https://battleship-1-qpm6.onrender.com"; // Default server

let gameId = null;
let playerId = null;
let gameStatus = "waiting_setup";
let isPlacementMode = false;
let selectedShips = []; // Now stores 12 total coordinates (5+4+3)
let currentPlacementIndex = 0; 
let pollHandle = null;

// --- VIEW MANAGEMENT (The 6 Screens) ---
function navigateTo(screenId) {
    document.querySelectorAll('.screen').forEach(s => s.classList.add('hidden'));
    document.getElementById(screenId).classList.remove('hidden');
}

// --- SCREEN 1: SERVER SELECTION ---
document.getElementById('btnConnectServer').onclick = () => {
    currentBaseUrl = document.getElementById('serverSelect').value;
    document.getElementById('activeServerUrl').textContent = `Connected: ${currentBaseUrl}`;
    navigateTo('screen-login');
};

// --- UNIVERSAL THEME TOGGLE ---
document.getElementById('themeToggle').onclick = () => {
    document.body.classList.toggle('light-theme');
};

// --- SCREEN 2: LOGIN ---
document.getElementById('btnLogin').onclick = async () => {
    try {
        await ensurePlayer(); // Uses existing login logic to create or retrieve ID
        navigateTo('screen-lobby');
        await refreshLobby(); // Load the list of games
    } catch (err) {
        setStatus(`Login failed: ${err.message}`);
    }
};

// --- SCREEN 3: LOBBY (Create/Join) ---
async function refreshLobby() {
    const listEl = document.getElementById('gameList');
    try {
        // professor requirement: show list of active/finished games
        // Note: This requires a GET /api/games endpoint on the server
        const res = await fetch(`${currentBaseUrl}/api/games`);
        const games = await safeJson(res);
        
        listEl.innerHTML = games.map(g => `
            <div class="game-item">
                <span>Room #${g.game_id} (${g.status})</span>
                ${g.status !== 'finished' ? 
                    `<button onclick="joinGameById(${g.game_id})">Join</button>` : 
                    '<span>Closed</span>'}
            </div>
        `).join('') || '<p class="hint">No active signals found.</p>';
    } catch (err) {
        listEl.innerHTML = '<p class="hint">Unable to retrieve game list.</p>';
    }
}

// Search Join logic
document.getElementById('btnSearchJoin').onclick = () => {
    const id = document.getElementById('searchGameId').value;
    if (id) joinGameById(id);
};

// --- SCREEN 4: SHIP PLACEMENT (5, 4, and 3 squares) ---
function handlePlacementClick(row, col) {
    if (!isPlacementMode) return;
    
    const neededLength = SHIP_SEQUENCE[currentPlacementIndex];
    const key = `${row},${col}`;
    
    // Simple coordinate-based selection for demo; 
    // real logic would check for straight lines
    if (!selectedShips.some(s => s.row === row && s.col === col)) {
        selectedShips.push({ row, col });
    }

    // Logic for transitioning between ships
    const totalPlaced = selectedShips.length;
    const goal = SHIP_SEQUENCE.slice(0, currentPlacementIndex + 1).reduce((a, b) => a + b, 0);

    if (totalPlaced === goal) {
        currentPlacementIndex++;
        if (currentPlacementIndex < SHIP_SEQUENCE.length) {
            updatePlacementInstructions(`Place your next ship (${SHIP_SEQUENCE[currentPlacementIndex]} squares)`);
        } else {
            updatePlacementInstructions("Fleet stationed. Confirm deployment.");
            document.getElementById('btnConfirmPlacement').disabled = false;
        }
    }
    renderPlacementBoard();
}

function updatePlacementInstructions(msg) {
    document.getElementById('placementInstructions').textContent = msg;
}

// --- SCREEN 5: THE BATTLE ---
async function refreshGameState(showLogs = false) {
    if (!gameId || !playerId) return;

    // Standard polling logic maintained
    const gameRes = await fetch(`${currentBaseUrl}/api/games/${gameId}`);
    const gameData = await safeJson(gameRes);

    if (gameData.status === "playing") {
        navigateTo('screen-game');
        updateTurnIndicator(gameData.current_turn_player_id);
    } else if (gameData.status === "finished") {
        showFinalSummary(gameData);
    }
    // Update labels and grid (A-J, 1-10)
    renderActiveBoards(gameData);
}

function updateTurnIndicator(turnId) {
    const indicator = document.getElementById('turnIndicator');
    if (Number(turnId) === Number(playerId)) {
        indicator.textContent = "YOUR TURN: FIRE WHEN READY";
        indicator.style.color = "#2ecc71";
    } else {
        indicator.textContent = "OPPONENT'S TURN: BRACE FOR IMPACT";
        indicator.style.color = "#ff5c5c";
    }
}

// --- SCREEN 6: SUMMARY ---
function showFinalSummary(gameData) {
    stopPolling();
    navigateTo('screen-summary');
    const isWin = Number(gameData.winner_id) === Number(playerId);
    document.getElementById('missionResult').textContent = isWin ? "VICTORY" : "DEFEAT";
    document.getElementById('missionResult').style.color = isWin ? "#2ecc71" : "#ff5c5c";
    
    // Summary screen shows static view of boards and logs
    updateStatsBox(playerId);
}

// --- DISCONNECT ---
document.getElementById('btnDisconnect').onclick = () => {
    currentBaseUrl = "";
    playerId = null;
    gameId = null;
    stopPolling();
    navigateTo('screen-server');
    document.getElementById('activeServerUrl').textContent = "Not Connected";
};

// Return to Lobby
document.getElementById('btnReturnLobby').onclick = () => {
    navigateTo('screen-lobby');
    refreshLobby();
};
