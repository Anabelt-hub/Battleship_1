// --- CONFIGURATION & STATE ---
const DEFAULT_SIZE = 10;
const SHIP_SEQUENCE = [5, 4, 3];

let currentBaseUrl = "https://battleship-1-qpm6.onrender.com";

let gameId = null;
let playerId = null;
let gameStatus = "waiting_setup";
let isPlacementMode = false;
let selectedShips = [];
let placedShips = [];
let currentPlacementIndex = 0;
let currentPlacementDirection = "horizontal";
let pollHandle = null;
let lastMoveCount = 0;
let playerMap = {};
let currentGridSize = DEFAULT_SIZE;
let persistentShotMarks = {};

// --- DOM ELEMENTS ---
const statusEl = document.getElementById("status");
const logEl = document.getElementById("log");

// --- SERVER-SPECIFIC STORAGE FIX ---
function normalizeBaseUrl(url) {
    return String(url || "").replace(/\/+$/, "");
}

function serverKey(name) {
    return `${name}:${normalizeBaseUrl(currentBaseUrl)}`;
}

function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
}

function getBoardSize() {
    return Number(currentGridSize) || DEFAULT_SIZE;
}

function getShotMarkKey(boardType, row, col) {
    return `${boardType}:${Number(row)},${Number(col)}`;
}

function loadPersistentShotMarks() {
    try {
        persistentShotMarks = JSON.parse(localStorage.getItem(serverKey("persistentShotMarks")) || "{}");
    } catch {
        persistentShotMarks = {};
    }
}

function savePersistentShotMarks() {
    localStorage.setItem(serverKey("persistentShotMarks"), JSON.stringify(persistentShotMarks || {}));
}

function setPersistentShotMark(boardType, row, col, result) {
    persistentShotMarks[getShotMarkKey(boardType, row, col)] = result;
    savePersistentShotMarks();
}

function getPersistentShotMark(boardType, row, col) {
    return persistentShotMarks[getShotMarkKey(boardType, row, col)] || null;
}

function persistSession() {
    localStorage.setItem("currentServer", normalizeBaseUrl(currentBaseUrl));
    localStorage.setItem(serverKey("currentView"), document.querySelector(".screen:not(.hidden)")?.id || "screen-server");
    localStorage.setItem(serverKey("currentGameId"), gameId ? String(gameId) : "");
    localStorage.setItem(serverKey("currentPlayerId"), playerId ? String(playerId) : "");
    localStorage.setItem(serverKey("currentGridSize"), String(getBoardSize()));
    localStorage.setItem(serverKey("persistentShips"), JSON.stringify(selectedShips || []));
    localStorage.setItem(serverKey("persistentPlacedShips"), JSON.stringify(placedShips || []));
    localStorage.setItem(serverKey("currentPlacementDirection"), currentPlacementDirection || "horizontal");
    savePersistentShotMarks();
}

function clearGameSessionOnly() {
    localStorage.removeItem(serverKey("currentView"));
    localStorage.removeItem(serverKey("currentGameId"));
    localStorage.removeItem(serverKey("currentGridSize"));
    localStorage.removeItem(serverKey("persistentShips"));
    localStorage.removeItem(serverKey("persistentPlacedShips"));
    localStorage.removeItem(serverKey("currentPlacementDirection"));
    localStorage.removeItem(serverKey("persistentShotMarks"));

    gameId = null;
    currentGridSize = DEFAULT_SIZE;
    selectedShips = [];
    placedShips = [];
    currentPlacementIndex = 0;
    currentPlacementDirection = "horizontal";
    lastMoveCount = 0;
    playerMap = {};
    persistentShotMarks = {};
    stopPolling();

    if (logEl) logEl.innerHTML = "";
}

function clearSessionState() {
    clearGameSessionOnly();
    playerId = null;
    localStorage.removeItem(serverKey("currentPlayerId"));
}

// --- VIEW MANAGEMENT ---
function navigateTo(screenId) {
    document.querySelectorAll(".screen").forEach(s => s.classList.add("hidden"));
    const target = document.getElementById(screenId);
    if (target) target.classList.remove("hidden");
    updateNavButtons(screenId);
    persistSession();
}

function updateNavButtons(view) {
    const dsc = document.getElementById("nav-disconnect");
    const lgo = document.getElementById("nav-logout");
    const lby = document.getElementById("nav-lobby");

    if (dsc) dsc.classList.toggle("hidden", view === "screen-server");
    if (lgo) lgo.classList.toggle("hidden", view === "screen-server" || view === "screen-login");
    if (lby) lby.classList.toggle("hidden", view === "screen-server" || view === "screen-login" || view === "screen-lobby");
}

async function safeJson(r) {
    const t = await r.text();
    try {
        return JSON.parse(t);
    } catch {
        return { message: t };
    }
}

// --- SERVER SELECTION ---
document.getElementById("themeToggle").onclick = () => {
    document.body.classList.toggle("light-theme");
};

document.getElementById("btnConnectServer").onclick = async () => {
    const selectedUrl = normalizeBaseUrl(document.getElementById("serverSelect").value);
    currentBaseUrl = selectedUrl;

    document.getElementById("activeServerUrl").textContent = `Connected: ${currentBaseUrl}`;

    // important: do not reuse game/player state across servers
    gameId = null;
    playerId = Number(localStorage.getItem(serverKey("currentPlayerId"))) || null;
    clearGameSessionOnly();

    try {
        const res = await fetch(`${currentBaseUrl}/api/health`);

        if (res.ok) {
            setStatus("Connected. Please log in for this server.");
            navigateTo("screen-login");
        } else {
            alert("Uplink failed: Server rejected request.");
        }
    } catch (err) {
        console.error("Server connection failed:", err);
        alert("Network Error: Could not reach server.");
    }
};

// --- LOGIN ---
document.getElementById("btnLogin").onclick = async () => {
    try {
        await ensurePlayer();
        navigateTo("screen-lobby");
        await refreshLobby();
    } catch (err) {
        console.error("Login failed:", err);
        alert(`Login failed: ${err.message}`);
    }
};

async function ensurePlayer() {
    const input = document.getElementById("playerName");
    let name = input?.value?.trim() || "Captain_Gabbie";

    // Keep names backend-safe
    name = name.replace(/[^a-zA-Z0-9_]/g, "_").slice(0, 24);
    if (!name) name = "Captain";

    localStorage.setItem("persistentPlayerName", name);

    const savedForThisServer = localStorage.getItem(serverKey("currentPlayerId"));
    if (savedForThisServer && !playerId) {
        playerId = Number(savedForThisServer);
    }

    // Try normal username first, then retry with unique suffix if username exists
    for (let attempt = 0; attempt < 3; attempt++) {
        const username = attempt === 0 ? name : `${name}_${Math.floor(Math.random() * 9000 + 1000)}`;

        const res = await fetch(`${currentBaseUrl}/api/players`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ username })
        });

        const data = await safeJson(res);

        if (res.ok && data.player_id) {
            playerId = Number(data.player_id);
            localStorage.setItem(serverKey("currentPlayerId"), String(playerId));
            localStorage.setItem("persistentPlayerName", username);
            if (input) input.value = username;
            persistSession();
            setStatus(`Logged in as ${username} on this server.`);
            return playerId;
        }

        if (res.status !== 409) {
            throw new Error(data.message || "Could not create player on this server.");
        }
    }

    throw new Error("Username already exists on this server. Try a different name.");
}

// --- LOBBY ---
setInterval(() => {
    const lobby = document.getElementById("screen-lobby");
    if (lobby && !lobby.classList.contains("hidden")) {
        refreshLobby();
    }
}, 3000);

document.getElementById("btnCreateRoom").onclick = async () => {
    try {
        if (!playerId) await ensurePlayer();

        const grid = document.getElementById("gridSizeInput").value || DEFAULT_SIZE;
        const maxP = document.getElementById("maxPlayersInput").value || 2;

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
        if (!res.ok) throw new Error(data.message || "Could not create room.");

        currentGridSize = Number(grid) || DEFAULT_SIZE;
        persistSession();

        await joinGameById(data.game_id);
    } catch (err) {
        console.error("Creation failed:", err);
        alert(`Creation failed: ${err.message}`);
    }
};

async function refreshLobby() {
    if (!playerId) {
        const savedId = localStorage.getItem(serverKey("currentPlayerId"));
        if (savedId) playerId = Number(savedId);
    }

    const listEl = document.getElementById("gameList");
    if (!listEl) return;

    try {
        const res = await fetch(`${currentBaseUrl}/api/games?t=${Date.now()}`);
        const games = await safeJson(res);

        if (!res.ok || !Array.isArray(games)) {
            throw new Error("This server did not return a valid games list.");
        }

        listEl.innerHTML = games.map(g => `
            <div class="game-item">
                <span>Room #${g.game_id} (${g.status}) · ${g.grid_size}x${g.grid_size} · ${g.max_players} players</span>
                ${g.status !== "finished"
                    ? `<button class="success" onclick="joinGameById(${g.game_id})">Join</button>`
                    : `<span class="muted">Closed</span>`}
            </div>
        `).join("") || `<p class="hint" style="padding:10px;">No active signals found.</p>`;
    } catch (err) {
        console.error("Lobby refresh failed:", err);
        listEl.innerHTML = `<p class="hint" style="padding:10px;">Error retrieving signals from this server.</p>`;
    }
}

async function joinGameById(id) {
    try {
        if (!playerId) await ensurePlayer();

        const res = await fetch(`${currentBaseUrl}/api/games/${id}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId })
        });

        const data = await safeJson(res);

        if (!res.ok) {
            console.error("JOIN FAILED RESPONSE:", data);
            throw new Error(data.message || data.error || "Join failed.");
        }

        gameId = id;
        lastMoveCount = 0;
        persistentShotMarks = {};
        localStorage.removeItem(serverKey("persistentShotMarks"));
        if (logEl) logEl.innerHTML = "";

        await loadGameMeta(gameId);
        startPlacementMode();
    } catch (err) {
        console.error("Join Error:", err);
        alert(`Join Error: ${err.message}`);
    }
}

async function loadGameMeta(id) {
    const res = await fetch(`${currentBaseUrl}/api/games/${id}?t=${Date.now()}`);
    const data = await safeJson(res);

    if (!res.ok) throw new Error(data.message || "Unable to load game.");

    const idPlacement = document.getElementById("displayGameId");
    const idGame = document.getElementById("displayGameIdGame");

    if (idPlacement) idPlacement.textContent = `#${id}`;
    if (idGame) idGame.textContent = `#${id}`;

    currentGridSize = Number(data.grid_size) || DEFAULT_SIZE;

    if (Array.isArray(data.players)) {
        playerMap = {};
        data.players.forEach(p => {
            playerMap[p.player_id] =
                p.username || (Number(p.player_id) === Number(playerId) ? "You" : `Player ${p.player_id}`);
        });
    }

    gameStatus = data.status || gameStatus;
    persistSession();

    return data;
}

// --- PLACEMENT ---
function startPlacementMode() {
    isPlacementMode = true;
    selectedShips = [];
    placedShips = [];
    currentPlacementIndex = 0;
    currentPlacementDirection = "horizontal";
    lastMoveCount = 0;

    navigateTo("screen-placement");
    updatePlacementInstructions(`Place your Carrier (5 squares) on the ${getBoardSize()}x${getBoardSize()} grid`);

    const confirmBtn = document.getElementById("btnConfirmPlacement");
    if (confirmBtn) confirmBtn.disabled = true;

    renderPlacementBoard();
}

function getShipCells(startRow, startCol, length, direction) {
    const cells = [];

    for (let i = 0; i < length; i++) {
        cells.push({
            row: direction === "vertical" ? startRow + i : startRow,
            col: direction === "horizontal" ? startCol + i : startCol
        });
    }

    return cells;
}

function canPlaceShip(cells) {
    const size = getBoardSize();

    return cells.every(cell => (
        cell.row >= 0 &&
        cell.row < size &&
        cell.col >= 0 &&
        cell.col < size &&
        !selectedShips.some(s => s.row === cell.row && s.col === cell.col)
    ));
}

function syncPlacementControls() {
    const dirBtn = document.getElementById("btnRotatePlacement");
    const dirLabel = document.getElementById("placementDirectionLabel");
    const directionText = currentPlacementDirection === "horizontal" ? "Horizontal" : "Vertical";

    if (dirBtn) dirBtn.textContent = `Direction: ${directionText}`;
    if (dirLabel) dirLabel.textContent = `Current direction: ${directionText}`;
}

function renderPlacementBoard() {
    const board = document.getElementById("placementBoard");
    if (!board) return;

    const size = getBoardSize();

    board.innerHTML = "";
    board.style.gridTemplateColumns = `repeat(${size + 1}, 32px)`;

    syncPlacementControls();

    for (let r = -1; r < size; r++) {
        for (let c = -1; c < size; c++) {
            const cell = document.createElement("div");

            if (r === -1 && c === -1) {
                cell.className = "grid-corner";
            } else if (r === -1) {
                cell.textContent = c + 1;
                cell.className = "grid-label";
            } else if (c === -1) {
                cell.textContent = String.fromCharCode(65 + r);
                cell.className = "grid-label";
            } else {
                const btn = document.createElement("button");
                btn.className = "cell";

                if (selectedShips.some(s => s.row === r && s.col === c)) {
                    btn.classList.add("ship");
                }

                btn.onclick = () => handlePlacementClick(r, c);
                cell.appendChild(btn);
            }

            board.appendChild(cell);
        }
    }
}

function handlePlacementClick(row, col) {
    if (!isPlacementMode || currentPlacementIndex >= SHIP_SEQUENCE.length) return;

    const shipLength = SHIP_SEQUENCE[currentPlacementIndex];
    const shipCells = getShipCells(row, col, shipLength, currentPlacementDirection);

    if (!canPlaceShip(shipCells)) {
        updatePlacementInstructions(`That ${shipLength}-square ship does not fit there. Try another starting cell or rotate it.`);
        return;
    }

    placedShips.push({
        length: shipLength,
        direction: currentPlacementDirection,
        cells: shipCells
    });

    selectedShips = placedShips.flatMap(ship => ship.cells.map(cell => ({ ...cell })));
    currentPlacementIndex++;

    persistSession();

    if (currentPlacementIndex < SHIP_SEQUENCE.length) {
        updatePlacementInstructions(`Place your next ship (${SHIP_SEQUENCE[currentPlacementIndex]} squares)`);
    } else {
        updatePlacementInstructions("Fleet stationed. Ready for confirmation.");
        const confirmBtn = document.getElementById("btnConfirmPlacement");
        if (confirmBtn) confirmBtn.disabled = false;
    }

    renderPlacementBoard();
}

document.getElementById("btnConfirmPlacement").onclick = async () => {
    try {
        const res = await fetch(`${currentBaseUrl}/api/games/${gameId}/place`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId, ships: selectedShips })
        });

        const data = await safeJson(res);
        if (!res.ok) throw new Error(data.message || "Deployment rejected.");

        persistSession();
        navigateTo("screen-game");
        startPolling();
        await refreshGameState();
    } catch (err) {
        console.error("Placement failed:", err);
        alert(err.message);
    }
};

document.getElementById("btnResetPlacement").onclick = () => startPlacementMode();

document.getElementById("btnRotatePlacement").onclick = () => {
    currentPlacementDirection = currentPlacementDirection === "horizontal" ? "vertical" : "horizontal";
    persistSession();
    renderPlacementBoard();
};

document.getElementById("btnUndoPlacement").onclick = () => {
    if (!placedShips.length) return;

    placedShips.pop();
    selectedShips = placedShips.flatMap(ship => ship.cells.map(cell => ({ ...cell })));
    currentPlacementIndex = placedShips.length;

    const confirmBtn = document.getElementById("btnConfirmPlacement");
    if (confirmBtn) confirmBtn.disabled = currentPlacementIndex !== SHIP_SEQUENCE.length;

    if (currentPlacementIndex < SHIP_SEQUENCE.length) {
        updatePlacementInstructions(`Place your next ship (${SHIP_SEQUENCE[currentPlacementIndex]} squares)`);
    } else {
        updatePlacementInstructions("Fleet stationed. Ready for confirmation.");
    }

    persistSession();
    renderPlacementBoard();
};

// --- BATTLE ---
async function firePhasers(row, col) {
    const cell = document.getElementById(`enemy-cell-${row}-${col}`);
    if (!cell || cell.classList.contains("hit") || cell.classList.contains("miss") || cell.disabled) return;

    try {
        const res = await fetch(`${currentBaseUrl}/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId, row, col })
        });

        const data = await safeJson(res);

        if (!res.ok) {
            addToLog(data.message || "Shot rejected.", "miss");
            return;
        }

        const resultClass = data.result;

        setPersistentShotMark("enemy", row, col, resultClass);

        cell.classList.add(resultClass);
        cell.disabled = true;

        setTimeout(() => {
            refreshGameState();
        }, 300);
    } catch (err) {
        console.error("Tactical Error:", err);
    }
}

async function refreshGameState() {
    if (!gameId || !playerId) return;

    try {
        const gameData = await loadGameMeta(gameId);

        if (gameData.status === "finished") {
            showFinalSummary(gameData);
        } else {
            updateTurnIndicator(gameData.current_turn_player_id);
        }

        await renderActiveBoards(gameData);
    } catch (err) {
        console.error("Refresh failed:", err);
    }
}

async function renderActiveBoards(gameData) {
    const movesRes = await fetch(`${currentBaseUrl}/api/games/${gameId}/moves?t=${Date.now()}`);
    const movesData = await safeJson(movesRes);
    const moves = Array.isArray(movesData.moves) ? movesData.moves : [];

    if (logEl && logEl.innerHTML === "" && moves.length > 0) {
        lastMoveCount = 0;
    }

    if (moves.length > lastMoveCount) {
        moves.slice(lastMoveCount).forEach(m => {
            setPersistentShotMark(
                Number(m.player_id) === Number(playerId) ? "enemy" : "player",
                m.row,
                m.col,
                m.result
            );

            addToLog(
                `Fired at ${String.fromCharCode(65 + Number(m.row))}-${Number(m.col) + 1} (${String(m.result).toUpperCase()})`,
                m.result,
                m.player_id
            );
        });

        lastMoveCount = moves.length;
    }

    const myMoves = moves.filter(m => Number(m.player_id) === Number(playerId));
    const hits = myMoves.filter(m => m.result === "hit").length;
    const misses = myMoves.filter(m => m.result === "miss").length;
    const accuracy = myMoves.length > 0 ? ((hits / myMoves.length) * 100).toFixed(1) : "0.0";

    const hitsEl = document.getElementById("liveHits");
    const missesEl = document.getElementById("liveMisses");
    const accuracyEl = document.getElementById("liveAccuracy");

    if (hitsEl) hitsEl.textContent = hits;
    if (missesEl) missesEl.textContent = misses;
    if (accuracyEl) accuracyEl.textContent = `${accuracy}%`;

    renderGrid("activePlayerBoard", moves, true, "player-cell");
    renderGrid("activeEnemyBoard", moves, false, "enemy-cell");
}

function renderGrid(containerId, moves, isPlayer, idPrefix) {
    const board = document.getElementById(containerId);
    if (!board) return;

    const size = getBoardSize();
    const shotMap = new Map();

    moves.forEach(m => {
        const row = Number(m.row);
        const col = Number(m.col);
        const key = `${row},${col}`;
        const moverId = Number(m.player_id);

        if (isPlayer) {
            if (moverId !== Number(playerId)) {
                shotMap.set(key, m.result);
            }
        } else {
            if (moverId === Number(playerId)) {
                shotMap.set(key, m.result);
            }
        }
    });

    for (let r = 0; r < size; r++) {
        for (let c = 0; c < size; c++) {
            const localMark = getPersistentShotMark(isPlayer ? "player" : "enemy", r, c);
            if (localMark) {
                shotMap.set(`${r},${c}`, localMark);
            }
        }
    }

    board.innerHTML = "";
    board.style.gridTemplateColumns = `repeat(${size + 1}, 28px)`;

    for (let r = -1; r < size; r++) {
        for (let c = -1; c < size; c++) {
            const wrapper = document.createElement("div");

            if (r === -1 && c === -1) {
                wrapper.className = "grid-corner";
                board.appendChild(wrapper);
                continue;
            }

            if (r === -1) {
                wrapper.className = "grid-label";
                wrapper.textContent = c + 1;
                board.appendChild(wrapper);
                continue;
            }

            if (c === -1) {
                wrapper.className = "grid-label";
                wrapper.textContent = String.fromCharCode(65 + r);
                board.appendChild(wrapper);
                continue;
            }

            const cell = document.createElement("button");
            const key = `${r},${c}`;

            cell.className = "cell";
            cell.id = `${idPrefix}-${r}-${c}`;

            const myShipCells = placedShips.flatMap(ship => ship.cells);

            if (
                isPlayer &&
                myShipCells.some(s => Number(s.row) === r && Number(s.col) === c)
            ) {
                cell.classList.add("ship");
            }

            if (shotMap.has(key)) {
                const status = shotMap.get(key);
                cell.classList.add(status);
                cell.disabled = true;
            } else if (!isPlayer) {
                cell.onclick = () => firePhasers(r, c);
            } else {
                cell.disabled = true;
            }

            wrapper.appendChild(cell);
            board.appendChild(wrapper);
        }
    }
}

function updateTurnIndicator(turnId) {
    const ind = document.getElementById("turnIndicator");
    if (!ind) return;

    const myTurn = Number(turnId) === Number(playerId);

    ind.textContent = myTurn ? "YOUR TURN: FIRE WHEN READY" : "OPPONENT TURN: BRACING FOR IMPACT";
    ind.style.color = myTurn ? "#2ecc71" : "#ff5c5c";
}

async function showFinalSummary(gameData) {
    stopPolling();
    navigateTo("screen-summary");

    const isWin = Number(gameData.winner_id) === Number(playerId);
    const resEl = document.getElementById("missionResult");

    if (resEl) {
        resEl.textContent = isWin ? "MISSION ACCOMPLISHED" : "MISSION FAILURE";
        resEl.style.color = isWin ? "#2ecc71" : "#ff5c5c";
    }

    try {
        const statsRes = await fetch(`${currentBaseUrl}/api/players/${playerId}/stats`);
        const stats = await safeJson(statsRes);

        const statsBox = document.getElementById("playerLifetimeStats");
        if (statsBox) {
            const accuracyPercent = Number(stats.accuracy || 0) * 100;

            statsBox.innerHTML = `
                <h3>Captain's Record</h3>
                <p>Wins: ${stats.wins ?? 0} | Losses: ${stats.losses ?? 0}</p>
                <p>Career Accuracy: ${accuracyPercent.toFixed(1)}%</p>
            `;
        }
    } catch (err) {
        console.error("Stats fetch failed", err);
    }

    const summaryBoards = document.getElementById("summaryBoards");
    const battleArena = document.querySelector(".battle-arena");

    if (summaryBoards && battleArena && !summaryBoards.contains(battleArena)) {
        summaryBoards.appendChild(battleArena);
    }
}

// --- LOGGING / POLLING ---
function updatePlacementInstructions(msg) {
    const el = document.getElementById("placementInstructions");
    if (el) el.textContent = msg;
}

function addToLog(msg, type, actorId = null) {
    if (!logEl) return;

    const entry = document.createElement("div");
    const name = actorId ? (playerMap[actorId] || "Unknown") : "System";

    entry.className = type === "hit" ? "hitTxt" : "missTxt";
    entry.innerHTML = `<span style="color:var(--muted)">[${new Date().toLocaleTimeString()}]</span> <strong style="color:var(--accent)">${name}:</strong> ${msg}`;
    logEl.prepend(entry);
}

function startPolling() {
    stopPolling();
    pollHandle = setInterval(refreshGameState, 1500);
}

function stopPolling() {
    if (pollHandle) clearInterval(pollHandle);
    pollHandle = null;
}

// --- NAVIGATION BUTTONS ---
document.getElementById("nav-disconnect").onclick = () => {
    clearSessionState();
    localStorage.removeItem("currentServer");
    location.reload();
};

document.getElementById("nav-logout").onclick = () => {
    playerId = null;
    localStorage.removeItem(serverKey("currentPlayerId"));
    clearGameSessionOnly();
    navigateTo("screen-login");
};

document.getElementById("nav-lobby").onclick = async () => {
    clearGameSessionOnly();
    navigateTo("screen-lobby");
    refreshLobby();
};

document.getElementById("nav-logout-summary").onclick = () => {
    playerId = null;
    localStorage.removeItem(serverKey("currentPlayerId"));
    clearGameSessionOnly();
    navigateTo("screen-login");
};

document.getElementById("btnDisconnectSummary").onclick = () => {
    clearSessionState();
    localStorage.removeItem("currentServer");
    location.reload();
};

document.getElementById("btnReturnLobby").onclick = async () => {
    clearGameSessionOnly();
    navigateTo("screen-lobby");
    refreshLobby();
};

document.getElementById("btnDisconnect").onclick = () => {
    clearSessionState();
    localStorage.removeItem("currentServer");
    location.reload();
};

// --- LOAD RESTORE ---
window.addEventListener("load", async () => {
    const savedServer = localStorage.getItem("currentServer");

    if (savedServer) {
        currentBaseUrl = normalizeBaseUrl(savedServer);

        const activeServerUrl = document.getElementById("activeServerUrl");
        if (activeServerUrl) activeServerUrl.textContent = `Connected: ${currentBaseUrl}`;

        const serverSelect = document.getElementById("serverSelect");
        if (serverSelect) serverSelect.value = currentBaseUrl;
    }

    const savedView = localStorage.getItem(serverKey("currentView"));
    const savedGameId = localStorage.getItem(serverKey("currentGameId"));
    const savedShips = localStorage.getItem(serverKey("persistentShips"));
    const savedPlacedShips = localStorage.getItem(serverKey("persistentPlacedShips"));
    const savedPlayerId = localStorage.getItem(serverKey("currentPlayerId"));
    const savedGridSize = localStorage.getItem(serverKey("currentGridSize"));
    const savedDirection = localStorage.getItem(serverKey("currentPlacementDirection"));

    if (savedPlayerId) playerId = Number(savedPlayerId);
    if (savedGridSize) currentGridSize = Number(savedGridSize) || DEFAULT_SIZE;

    if (savedShips) {
        try { selectedShips = JSON.parse(savedShips); } catch { selectedShips = []; }
    }

    if (savedPlacedShips) {
        try { placedShips = JSON.parse(savedPlacedShips); } catch { placedShips = []; }
    }

    if (placedShips.length) currentPlacementIndex = placedShips.length;

    if (savedDirection === "horizontal" || savedDirection === "vertical") {
        currentPlacementDirection = savedDirection;
    }

    loadPersistentShotMarks();

    const savedName = localStorage.getItem("persistentPlayerName");
    if (savedName) {
        const pInput = document.getElementById("playerName");
        if (pInput) pInput.value = savedName;
    }

    if (savedGameId && playerId) {
        gameId = Number(savedGameId);

        try {
            const gameData = await loadGameMeta(gameId);

            if (savedView === "screen-placement") {
                navigateTo("screen-placement");
                renderPlacementBoard();

                const confirmBtn = document.getElementById("btnConfirmPlacement");
                if (confirmBtn) confirmBtn.disabled = currentPlacementIndex !== SHIP_SEQUENCE.length;
            } else if (savedView === "screen-game" || savedView === "screen-summary") {
                navigateTo(savedView);
                startPolling();
                await refreshGameState();
            }

            if (gameData.status === "finished") {
                navigateTo("screen-summary");
            }

            return;
        } catch (err) {
            console.warn("Could not restore saved game:", err.message);
            clearGameSessionOnly();
        }
    }

    if (savedServer) {
        navigateTo("screen-login");
        setStatus("Connected. Please log in for this server.");
    } else {
        navigateTo("screen-server");
        setStatus("Uplink required.");
    }
});
