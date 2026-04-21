// --- CONFIGURATION & STATE ---
const SIZE = 10;
const SHIP_SEQUENCE = [5, 4, 3]; // Specific lengths required for demo
let currentBaseUrl = "https://battleship-1-qpm6.onrender.com"; 

let gameId = null;
let playerId = null;
let gameStatus = "waiting_setup";
let isPlacementMode = false;
let selectedShips = []; // Stores 12 total coordinates (5+4+3)
let currentPlacementIndex = 0; 
let pollHandle = null;

// --- DOM ELEMENTS ---
const statusEl = document.getElementById("status");
const logEl = document.getElementById("log");

// --- VIEW MANAGEMENT (The 6 Screens) ---
function navigateTo(screenId) {
    document.querySelectorAll('.screen').forEach(s => s.classList.add('hidden'));
    document.getElementById(screenId).classList.remove('hidden');
}

// --- SCREEN 1: SERVER SELECTION ---
document.getElementById('btnConnectServer').onclick = async () => {
    currentBaseUrl = document.getElementById('serverSelect').value;
    document.getElementById('activeServerUrl').textContent = `Connected: ${currentBaseUrl}`;
    
    try {
        const res = await fetch(`${currentBaseUrl}/api/health`);
        if (res.ok) {
            navigateTo('screen-login');
        } else {
            alert("Tactical uplink failed: Server offline.");
        }
    } catch (err) {
        alert("Connection Error: Unable to reach command server.");
    }
};

// --- UNIVERSAL THEME TOGGLE ---
document.getElementById('themeToggle').onclick = () => {
    document.body.classList.toggle('light-theme');
};

// --- SCREEN 2: LOGIN ---
document.getElementById('btnLogin').onclick = async () => {
    try {
        await ensurePlayer();
        navigateTo('screen-lobby');
        await refreshLobby();
    } catch (err) {
        alert(`Login failed: ${err.message}`);
    }
};

// --- SCREEN 3: LOBBY (Create/Join) ---
document.getElementById('btnCreateRoom').onclick = async () => {
    try {
        const grid = document.getElementById('gridSizeInput').value;
        const maxP = document.getElementById('maxPlayersInput').value;

        const res = await fetch(`${currentBaseUrl}/api/games`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                grid_size: parseInt(grid),
                max_players: parseInt(maxP),
                creator_id: playerId
            })
        });
        const data = await safeJson(res);
        if (!res.ok) throw new Error(data.message);

        gameId = data.game_id;
        await joinGameById(gameId);
    } catch (err) {
        alert(`Creation failed: ${err.message}`);
    }
};

async function refreshLobby() {
    const listEl = document.getElementById('gameList');
    try {
        const res = await fetch(`${currentBaseUrl}/api/games`);
        const games = await safeJson(res);
        
        listEl.innerHTML = games.map(g => `
            <div class="game-item">
                <span>Room #${g.game_id} (${g.status})</span>
                ${g.status !== 'finished' ? 
                    `<button class="success" onclick="joinGameById(${g.game_id})">Join</button>` : 
                    '<span class="muted">Closed</span>'}
            </div>
        `).join('') || '<p class="hint">No active signals found.</p>';
    } catch (err) {
        listEl.innerHTML = '<p class="hint">Error retrieving signals.</p>';
    }
}

async function joinGameById(id) {
    try {
        const res = await fetch(`${currentBaseUrl}/api/games/${id}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId })
        });
        if (!res.ok) {
            const data = await safeJson(res);
            throw new Error(data.message);
        }
        gameId = id;
        localStorage.setItem("currentGameId", String(gameId));
        startPlacementMode();
    } catch (err) {
        alert(`Join Error: ${err.message}`);
    }
}

// --- SCREEN 4: SHIP PLACEMENT ---
function startPlacementMode() {
    isPlacementMode = true;
    selectedShips = [];
    currentPlacementIndex = 0;
    navigateTo('screen-placement');
    updatePlacementInstructions(`Place your Carrier (5 squares)`);
    renderPlacementBoard();
}

function renderPlacementBoard() {
    const board = document.getElementById("placementBoard");
    board.innerHTML = "";
    
    // Add Labels (A-J, 1-10)
    board.style.gridTemplateColumns = `repeat(${SIZE + 1}, 36px)`;
    
    for (let r = -1; r < SIZE; r++) {
        for (let c = -1; c < SIZE; c++) {
            const cell = document.createElement("div");
            if (r === -1 && c === -1) { /* Corner empty */ }
            else if (r === -1) { cell.textContent = c + 1; cell.className = "grid-label"; }
            else if (c === -1) { cell.textContent = String.fromCharCode(65 + r); cell.className = "grid-label"; }
            else {
                const btn = document.createElement("button");
                btn.className = "cell";
                if (selectedShips.some(s => s.row === r && s.col === c)) btn.classList.add("ship");
                btn.onclick = () => handlePlacementClick(r, c);
                cell.appendChild(btn);
            }
            board.appendChild(cell);
        }
    }
}

function handlePlacementClick(row, col) {
    if (!isPlacementMode) return;
    if (selectedShips.some(s => s.row === row && s.col === col)) return;

    selectedShips.push({ row, col });

    const currentGoal = SHIP_SEQUENCE.slice(0, currentPlacementIndex + 1).reduce((a, b) => a + b, 0);

    if (selectedShips.length === currentGoal) {
        currentPlacementIndex++;
        if (currentPlacementIndex < SHIP_SEQUENCE.length) {
            updatePlacementInstructions(`Place your next ship (${SHIP_SEQUENCE[currentPlacementIndex]} squares)`);
        } else {
            updatePlacementInstructions("Fleet stationed. Ready for confirmation.");
            document.getElementById('btnConfirmPlacement').disabled = false;
        }
    }
    renderPlacementBoard();
}

function updatePlacementInstructions(msg) {
    document.getElementById('placementInstructions').textContent = msg;
}

document.getElementById('btnConfirmPlacement').onclick = async () => {
    try {
        const res = await fetch(`${currentBaseUrl}/api/games/${gameId}/place`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId, ships: selectedShips })
        });
        if (!res.ok) throw new Error("Deployment rejected.");
        
        navigateTo('screen-game');
        startPolling();
    } catch (err) {
        alert(err.message);
    }
};

document.getElementById('btnResetPlacement').onclick = () => startPlacementMode();

// --- SCREEN 5: THE BATTLE ---
async function refreshGameState() {
    if (!gameId || !playerId) return;

    const gameRes = await fetch(`${currentBaseUrl}/api/games/${gameId}`);
    const gameData = await safeJson(gameRes);

    if (gameData.status === "playing") {
        updateTurnIndicator(gameData.current_turn_player_id);
    } else if (gameData.status === "finished") {
        showFinalSummary(gameData);
    }
    renderActiveBoards(gameData);
}

function updateTurnIndicator(turnId) {
    const indicator = document.getElementById('turnIndicator');
    if (Number(turnId) === Number(playerId)) {
        indicator.textContent = "YOUR TURN: FIRE WHEN READY";
        indicator.style.color = "#2ecc71";
    } else {
        indicator.textContent = "OPPONENT TURN: BRACING FOR IMPACT";
        indicator.style.color = "#ff5c5c";
    }
}

async function renderActiveBoards(gameData) {
    const movesRes = await fetch(`${currentBaseUrl}/api/games/${gameId}/moves`);
    const movesData = await safeJson(movesRes);
    const moves = movesData.moves || [];

    renderGrid("activePlayerBoard", moves, true);
    renderGrid("activeEnemyBoard", moves, false);
}

function renderGrid(containerId, moves, isPlayer) {
    const board = document.getElementById(containerId);
    board.innerHTML = "";
    board.style.gridTemplateColumns = `repeat(${SIZE + 1}, 36px)`;

    const shotMap = new Map();
    moves.forEach(m => {
        const key = `${m.row},${m.col}`;
        if (isPlayer && Number(m.player_id) !== Number(playerId)) shotMap.set(key, m.result);
        if (!isPlayer && Number(m.player_id) === Number(playerId)) shotMap.set(key, m.result);
    });

    for (let r = -1; r < SIZE; r++) {
        for (let c = -1; c < SIZE; c++) {
            const cell = document.createElement("div");
            if (r === -1 || c === -1) {
                cell.className = "grid-label";
                if (r === -1 && c !== -1) cell.textContent = c + 1;
                if (c === -1 && r !== -1) cell.textContent = String.fromCharCode(65 + r);
            } else {
                const item = document.createElement("button");
                item.className = "cell";
                const key = `${r},${c}`;
                if (isPlayer && selectedShips.some(s => s.row === r && s.col === c)) item.classList.add("ship");
                if (shotMap.has(key)) item.classList.add(shotMap.get(key));
                if (!isPlayer) item.onclick = () => firePhasers(r, c);
                cell.appendChild(item);
            }
            board.appendChild(cell);
        }
    }
}

async function firePhasers(row, col) {
    try {
        const res = await fetch(`${currentBaseUrl}/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId, row, col })
        });
        const data = await safeJson(res);
        if (!res.ok) { addToLog(data.message, "miss"); return; }
        
        addToLog(`[${data.result.toUpperCase()}] Sector ${String.fromCharCode(65+row)}-${col+1}`, data.result);
        await refreshGameState();
    } catch (err) { console.error(err); }
}

// --- SCREEN 6: SUMMARY ---
function showFinalSummary(gameData) {
    stopPolling();
    navigateTo('screen-summary');
    const isWin = Number(gameData.winner_id) === Number(playerId);
    document.getElementById('missionResult').textContent = isWin ? "MISSION ACCOMPLISHED" : "MISSION FAILURE";
    document.getElementById('missionResult').style.color = isWin ? "#2ecc71" : "#ff5c5c";
    updateStatsBox(playerId);
}

// --- HELPERS ---
async function ensurePlayer() {
    const name = document.getElementById("playerName").value || "Captain_Gabbie";
    const res = await fetch(`${currentBaseUrl}/api/players`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username: name })
    });
    const data = await safeJson(res);
    if (res.status === 409) {
         playerId = Number(localStorage.getItem("currentPlayerId"));
    } else {
         playerId = data.player_id;
         localStorage.setItem("currentPlayerId", playerId);
    }
    localStorage.setItem("persistentPlayerName", name);
}

async function updateStatsBox(id) {
    const res = await fetch(`${currentBaseUrl}/api/players/${id}/stats`);
    const stats = await res.json();
    const container = document.getElementById("stats-container");
    container.innerHTML = `
        <div class="panel">
            <h3>TACTICAL ARCHIVE</h3>
            <p>WON: ${stats.wins}</p>
            <p>LOST: ${stats.losses}</p>
            <p>ACCURACY: ${(stats.accuracy * 100).toFixed(1)}%</p>
        </div>`;
}

function addToLog(msg, type) {
    const entry = document.createElement("div");
    entry.className = type === "hit" ? "hitTxt" : "missTxt";
    entry.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
    logEl.prepend(entry);
}

function startPolling() { stopPolling(); pollHandle = setInterval(refreshGameState, 1500); }
function stopPolling() { if (pollHandle) clearInterval(pollHandle); pollHandle = null; }
async function safeJson(r) { const t = await r.text(); try { return JSON.parse(t); } catch { return {message: t}; } }

document.getElementById('btnReturnLobby').onclick = () => { navigateTo('screen-lobby'); refreshLobby(); };
document.getElementById('btnDisconnect').onclick = () => { location.reload(); };

window.addEventListener("load", () => {
    const savedName = localStorage.getItem("persistentPlayerName");
    if (savedName) document.getElementById("playerName").value = savedName;
});
