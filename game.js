// --- CONFIGURATION & STATE ---
const SIZE = 10;
const SHIP_SEQUENCE = [5, 4, 3]; 
let currentBaseUrl = "https://battleship-1-qpm6.onrender.com"; 

let gameId = null;
let playerId = null;
let gameStatus = "waiting_setup";
let isPlacementMode = false;
let selectedShips = []; 
let currentPlacementIndex = 0; 
let pollHandle = null;
let lastMoveCount = 0; 
let playerMap = {}; 

// --- DOM ELEMENTS ---
const statusEl = document.getElementById("status");
const logEl = document.getElementById("log");

// --- VIEW MANAGEMENT & PERSISTENCE ---
function navigateTo(screenId) {
    document.querySelectorAll('.screen').forEach(s => s.classList.add('hidden'));
    const target = document.getElementById(screenId);
    if (target) target.classList.remove('hidden');
    
    sessionStorage.setItem('currentView', screenId);
    sessionStorage.setItem('currentServer', currentBaseUrl);
    updateNavButtons(screenId);
}

function updateNavButtons(view) {
    const dsc = document.getElementById('nav-disconnect');
    const lgo = document.getElementById('nav-logout');
    const lby = document.getElementById('nav-lobby');

    if (dsc) dsc.classList.toggle('hidden', view === 'screen-server');
    if (lgo) lgo.classList.toggle('hidden', view === 'screen-server' || view === 'screen-login');
    if (lby) lby.classList.toggle('hidden', view === 'screen-server' || view === 'screen-login' || view === 'screen-lobby');
}

// --- SCREEN 1: SERVER SELECTION ---
document.getElementById('themeToggle').onclick = () => {
    document.body.classList.toggle('light-theme');
};

document.getElementById('btnConnectServer').onclick = async () => {
    currentBaseUrl = document.getElementById('serverSelect').value;
    document.getElementById('activeServerUrl').textContent = `Connected: ${currentBaseUrl}`;
    
    try {
        const res = await fetch(`${currentBaseUrl}/api/health`);
        if (res.ok) {
            navigateTo('screen-login');
        } else {
            alert("Uplink failed: Server rejected request.");
        }
    } catch (err) {
        alert("Network Error: Could not reach server.");
    }
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

// --- SCREEN 3: LOBBY ---
setInterval(() => {
    const lobby = document.getElementById('screen-lobby');
    if (lobby && !lobby.classList.contains('hidden')) {
        refreshLobby();
    }
}, 3000);

document.getElementById('btnCreateRoom').onclick = async () => {
    try {
        const grid = document.getElementById('gridSizeInput').value || 10;
        const maxP = document.getElementById('maxPlayersInput').value || 2;

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
        await joinGameById(data.game_id); 
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

// --- SCREEN 4: PLACEMENT ---
async function joinGameById(id) {
    try {
        const res = await fetch(`${currentBaseUrl}/api/games/${id}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId })
        });
        if (!res.ok) throw new Error((await safeJson(res)).message);
        gameId = id;
        sessionStorage.setItem('currentGameId', gameId);
        startPlacementMode();
    } catch (err) {
        alert(`Join Error: ${err.message}`);
    }
}

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
    if(!board) return;
    board.innerHTML = "";
    board.style.gridTemplateColumns = `repeat(${SIZE + 1}, 32px)`;
    
    for (let r = -1; r < SIZE; r++) {
        for (let c = -1; c < SIZE; c++) {
            const cell = document.createElement("div");
            if (r === -1 && c === -1) {} 
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
    if (!isPlacementMode || selectedShips.some(s => s.row === row && s.col === col)) return;
    selectedShips.push({ row, col });
    const currentGoal = SHIP_SEQUENCE.slice(0, currentPlacementIndex + 1).reduce((a, b) => a + b, 0);
    if (selectedShips.length === currentGoal) {
        currentPlacementIndex++;
        if (currentPlacementIndex < SHIP_SEQUENCE.length) {
            updatePlacementInstructions(`Place your next ship (${SHIP_SEQUENCE[currentPlacementIndex]} squares)`);
        } else {
            updatePlacementInstructions("Fleet stationed. Ready for confirmation.");
            const confirmBtn = document.getElementById('btnConfirmPlacement');
            if (confirmBtn) confirmBtn.disabled = false;
        }
    }
    renderPlacementBoard();
}

// --- SCREEN 5: THE BATTLE ---
async function firePhasers(row, col) {
    const cell = document.getElementById(`enemy-cell-${row}-${col}`);
    if (!cell || cell.classList.contains('hit') || cell.classList.contains('miss')) return;

    try {
        const res = await fetch(`${currentBaseUrl}/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId, row, col })
        });
        const data = await safeJson(res);
        if (!res.ok) { addToLog(data.message, "miss"); return; }

        const resultClass = data.result; 
        cell.classList.add(resultClass);
        cell.style.backgroundColor = (resultClass === 'hit') ? 'var(--hit)' : 'var(--miss)';

        addToLog(`Fired at ${String.fromCharCode(65+row)}-${col+1} (${data.result.toUpperCase()})`, data.result, playerId);
        
        setTimeout(() => { refreshGameState(); }, 300); 
    } catch (err) { console.error(err); }
}

async function renderActiveBoards(gameData) {
    const movesRes = await fetch(`${currentBaseUrl}/api/games/${gameId}/moves?t=${Date.now()}`);
    const movesData = await safeJson(movesRes);
    const moves = movesData.moves || [];

    if (moves.length > lastMoveCount) {
        moves.slice(lastMoveCount).forEach(m => {
            if (Number(m.player_id) !== Number(playerId)) {
                addToLog(`Fired at ${String.fromCharCode(65 + m.row)}-${m.col + 1} (${m.result.toUpperCase()})`, m.result, m.player_id);
            }
        });
        lastMoveCount = moves.length;
    }

    const myMoves = moves.filter(m => Number(m.player_id) === Number(playerId));
    const hits = myMoves.filter(m => m.result === 'hit').length;
    const misses = myMoves.filter(m => m.result === 'miss').length;
    const accuracy = myMoves.length > 0 ? ((hits / myMoves.length) * 100).toFixed(1) : "0.0";

    const hitsEl = document.getElementById('liveHits');
    const missesEl = document.getElementById('liveMisses');
    const accuracyEl = document.getElementById('liveAccuracy');
    if (hitsEl) hitsEl.textContent = hits;
    if (missesEl) missesEl.textContent = misses;
    if (accuracyEl) accuracyEl.textContent = `${accuracy}%`;

    renderGrid("activePlayerBoard", moves, true, "player-cell");
    renderGrid("activeEnemyBoard", moves, false, "enemy-cell");
}

function renderGrid(containerId, moves, isPlayer, idPrefix) {
    const board = document.getElementById(containerId);
    if (!board) return;
    board.innerHTML = "";
    board.style.gridTemplateColumns = `repeat(${SIZE + 1}, 28px)`;

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
                item.id = `${idPrefix}-${r}-${c}`;
                const key = `${r},${c}`;
                if (isPlayer && selectedShips.some(s => s.row === r && s.col === c)) item.classList.add("ship");
                if (shotMap.has(key)) {
                    const status = shotMap.get(key);
                    item.classList.add(status);
                    item.style.backgroundColor = (status === 'hit') ? 'var(--hit)' : 'var(--miss)';
                }
                if (!isPlayer) item.onclick = () => firePhasers(r, c);
                cell.appendChild(item);
            }
            board.appendChild(cell);
        }
    }
}

// --- GLOBAL LOAD & NAVIGATION ---
window.addEventListener("load", () => {
    const savedView = sessionStorage.getItem('currentView');
    const savedServer = sessionStorage.getItem('currentServer');
    const savedGameId = sessionStorage.getItem('currentGameId');
    const savedShips = sessionStorage.getItem('persistentShips');

    if (savedServer) {
        currentBaseUrl = savedServer;
        document.getElementById('activeServerUrl').textContent = `Connected: ${currentBaseUrl}`;
    }
    if (savedShips) selectedShips = JSON.parse(savedShips);
    if (savedGameId) {
        gameId = Number(savedGameId);
        startPolling();
    }
    if (savedView) navigateTo(savedView);
    else navigateTo('screen-server');

    const savedName = localStorage.getItem("persistentPlayerName");
    if (savedName) {
        const pInput = document.getElementById("playerName");
        if (pInput) pInput.value = savedName;
    }
});

// Navigation handlers
document.getElementById('nav-disconnect').onclick = () => { sessionStorage.clear(); location.reload(); };
document.getElementById('nav-logout').onclick = () => { navigateTo('screen-login'); };
document.getElementById('nav-lobby').onclick = () => { navigateTo('screen-lobby'); refreshLobby(); };

async function ensurePlayer() {
    const name = document.getElementById("playerName").value || "Captain_Gabbie";
    const res = await fetch(`${currentBaseUrl}/api/players`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username: name })
    });
    const data = await safeJson(res);
    playerId = (res.status === 409) ? Number(localStorage.getItem("currentPlayerId")) : data.player_id;
    localStorage.setItem("currentPlayerId", playerId);
    localStorage.setItem("persistentPlayerName", name);
}

document.getElementById('btnConfirmPlacement').onclick = async () => {
    try {
        const res = await fetch(`${currentBaseUrl}/api/games/${gameId}/place`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId, ships: selectedShips })
        });
        if (!res.ok) throw new Error("Deployment rejected.");
        sessionStorage.setItem('persistentShips', JSON.stringify(selectedShips));
        navigateTo('screen-game');
        startPolling();
    } catch (err) { alert(err.message); }
};

async function refreshGameState() {
    if (!gameId || !playerId) return;
    const gameRes = await fetch(`${currentBaseUrl}/api/games/${gameId}`);
    const gameData = await safeJson(gameRes);
    if (gameData.players) {
        gameData.players.forEach(p => {
            playerMap[p.player_id] = p.username || (Number(p.player_id) === Number(playerId) ? "You" : "Opponent");
        });
    }
    if (gameData.status === "finished") showFinalSummary(gameData);
    else updateTurnIndicator(gameData.current_turn_player_id);
    renderActiveBoards(gameData);
}

function showFinalSummary(gameData) {
    stopPolling();
    navigateTo('screen-summary');
    const isWin = Number(gameData.winner_id) === Number(playerId);
    const resEl = document.getElementById('missionResult');
    if (resEl) {
        resEl.textContent = isWin ? "MISSION ACCOMPLISHED" : "MISSION FAILURE";
        resEl.style.color = isWin ? "#2ecc71" : "#ff5c5c";
    }
}

function updateTurnIndicator(turnId) {
    const ind = document.getElementById('turnIndicator');
    if (!ind) return;
    const myTurn = Number(turnId) === Number(playerId);
    ind.textContent = myTurn ? "YOUR TURN: FIRE WHEN READY" : "OPPONENT TURN: BRACING FOR IMPACT";
    ind.style.color = myTurn ? "#2ecc71" : "#ff5c5c";
}

function updatePlacementInstructions(msg) { document.getElementById('placementInstructions').textContent = msg; }

function addToLog(msg, type, actorId = null) {
    const entry = document.createElement("div");
    const name = actorId ? (playerMap[actorId] || "Unknown") : "System";
    entry.className = type === "hit" ? "hitTxt" : "missTxt";
    entry.innerHTML = `<span style="color:var(--muted)">[${new Date().toLocaleTimeString()}]</span> <strong style="color:var(--accent)">${name}:</strong> ${msg}`;
    logEl.prepend(entry);
}

function startPolling() { stopPolling(); pollHandle = setInterval(refreshGameState, 1500); }
function stopPolling() { if (pollHandle) clearInterval(pollHandle); pollHandle = null; }
async function safeJson(r) { const t = await r.text(); try { return JSON.parse(t); } catch { return {message: t}; } }

document.getElementById('btnResetPlacement').onclick = () => startPlacementMode();
document.getElementById('btnReturnLobby').onclick = () => { navigateTo('screen-lobby'); refreshLobby(); };
document.getElementById('btnDisconnect').onclick = () => { sessionStorage.clear(); location.reload(); };
