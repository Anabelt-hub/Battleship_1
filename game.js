const SIZE = 10;
const MAX_PLACEMENT_SHIPS = 3;

let gameId = null;
let playerId = null;
let gameStatus = "waiting_setup";
let isPlacementMode = false;
let selectedShips = [];
let pollHandle = null;
let currentGameState = null;
let opponentId = null;

const statusEl = document.getElementById("status");
const playerBoardEl = document.getElementById("playerBoard");
const cpuBoardEl = document.getElementById("cpuBoard");
const logEl = document.getElementById("log");
const serverScoreEl = document.getElementById("serverScore");

const btnNewGame = document.getElementById("btnNewGame");
const btnJoinGame = document.getElementById("btnJoinGame");
const btnConfirmPlacement = document.getElementById("btnConfirmPlacement");
const btnResume = document.getElementById("btnResume");
const btnClearSave = document.getElementById("btnClearSave");
const btnUndo = document.getElementById("btnUndo");
const btnReset = document.getElementById("btnReset");
const btnReveal = document.getElementById("btnReveal");

const playerNameInput = document.getElementById("playerName");
const joinGameIdInput = document.getElementById("joinGameId");
const roomCodeEl = document.getElementById("roomCode");

if (btnNewGame) btnNewGame.addEventListener("click", createMultiplayerGame);
if (btnJoinGame) btnJoinGame.addEventListener("click", joinExistingGame);
if (btnConfirmPlacement) btnConfirmPlacement.addEventListener("click", submitPlacement);
if (btnResume) btnResume.addEventListener("click", resumeMission);
if (btnClearSave) btnClearSave.addEventListener("click", clearSave);
if (btnUndo) btnUndo.addEventListener("click", undoPlacement);
if (btnReset) btnReset.addEventListener("click", resetSimulation);
if (btnReveal) btnReveal.addEventListener("click", () => {
    addToLog("Scan is disabled in 2-player mode.", "miss");
    setStatus("Scan is disabled in 2-player mode.");
});

window.addEventListener("load", () => {
    setStatus("Enter a captain name, then create or join a mission.");
    const savedName = localStorage.getItem("persistentPlayerName");
    if (savedName && playerNameInput) playerNameInput.value = savedName;

    const savedPlayerId = Number(localStorage.getItem("currentPlayerId") || 0);
    if (savedPlayerId) updateStatsBox(savedPlayerId);
});

function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
}

function setRoomCode(msg) {
    if (roomCodeEl) roomCodeEl.textContent = msg;
}

function addToLog(message, type = "") {
    if (!logEl) return;

    const entry = document.createElement("div");
    const now = new Date();
    const time = now.toLocaleTimeString([], {
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hour12: false
    });

    let className = "";
    if (type === "hit") className = "hitTxt";
    if (type === "miss") className = "missTxt";

    entry.innerHTML = `<span class="muted">[${time}]</span> <span class="${className}">${message}</span>`;
    logEl.prepend(entry);
}

async function safeJson(res) {
    const text = await res.text();
    try {
        return text ? JSON.parse(text) : {};
    } catch {
        return { message: text || "Invalid server response." };
    }
}

function stopPolling() {
    if (pollHandle) {
        clearInterval(pollHandle);
        pollHandle = null;
    }
}

function startPolling() {
    stopPolling();
    pollHandle = setInterval(async () => {
        if (gameId && playerId) {
            await refreshGameState(false);
        }
    }, 1500);
}

function getPlayerName() {
    let name = (playerNameInput?.value || localStorage.getItem("persistentPlayerName") || "").trim();
    if (!name) {
        name = `Captain_${Date.now()}`;
        if (playerNameInput) playerNameInput.value = name;
    }
    localStorage.setItem("persistentPlayerName", name);
    return name;
}

async function ensurePlayer() {
    const username = getPlayerName();
    const existingName = localStorage.getItem("persistentPlayerName");
    const existingId = Number(localStorage.getItem("currentPlayerId") || 0);

    if (existingId && existingName === username) {
        playerId = existingId;
        return playerId;
    }

    const res = await fetch("/api/players", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username })
    });
    const data = await safeJson(res);

    if (res.status === 409 && existingId && existingName === username) {
        playerId = existingId;
        return playerId;
    }
    if (!res.ok) {
        throw new Error(data.message || "Could not create player.");
    }

    playerId = Number(data.player_id);
    localStorage.setItem("currentPlayerId", String(playerId));
    return playerId;
}

function clearBoards() {
    if (playerBoardEl) playerBoardEl.innerHTML = "";
    if (cpuBoardEl) cpuBoardEl.innerHTML = "";
}

function clearSave() {
    stopPolling();
    localStorage.removeItem("currentGameId");
    localStorage.removeItem("currentGamePlayerShips");
    gameId = null;
    gameStatus = "waiting_setup";
    isPlacementMode = false;
    selectedShips = [];
    currentGameState = null;
    opponentId = null;
    clearBoards();
    setRoomCode("No active room.");
    setStatus("Saved mission cleared.");
    addToLog("Mission save data cleared.");
}

async function resetSimulation() {
    try {
        const res = await fetch("/api/reset", { method: "POST" });
        const data = await safeJson(res);
        if (!res.ok) throw new Error(data.message || "Reset failed.");
        clearSave();
        localStorage.removeItem("currentPlayerId");
        setStatus("Simulation reset complete.");
        addToLog("Simulation reset complete.");
        updateStatsBox();
    } catch (err) {
        setStatus(`Reset failed: ${err.message}`);
        addToLog(`Reset failed: ${err.message}`, "miss");
    }
}

function undoPlacement() {
    if (!isPlacementMode || selectedShips.length === 0) {
        addToLog("No placement to undo.", "miss");
        return;
    }
    selectedShips.pop();
    renderPlacementBoard();
    addToLog("Last ship placement removed.");
}

async function createMultiplayerGame() {
    try {
        if (logEl) logEl.innerHTML = "";
        addToLog("Opening multiplayer mission channel...");
        setStatus("Creating room...");
        await ensurePlayer();
        updateStatsBox(playerId);

        const gRes = await fetch("/api/games", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                grid_size: SIZE,
                creator_id: playerId,
                max_players: 2
            })
        });
        const gData = await safeJson(gRes);
        if (!gRes.ok) throw new Error(gData.message || "Could not create game.");
        gameId = Number(gData.game_id);

        const joinRes = await fetch(`/api/games/${gameId}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId })
        });
        const joinData = await safeJson(joinRes);
        if (!joinRes.ok) throw new Error(joinData.message || "Could not join game.");

        selectedShips = [];
        gameStatus = "waiting_setup";
        isPlacementMode = true;
        currentGameState = null;
        opponentId = null;

        localStorage.setItem("currentGameId", String(gameId));
        localStorage.removeItem("currentGamePlayerShips");
        setRoomCode(`Room Code: ${gameId}`);
        setStatus(`Room ${gameId} created. Share this code with Player 2.`);
        addToLog(`Room ${gameId} created. Waiting for second player.`);
        renderPlacementBoard();
        startPolling();
    } catch (err) {
        setStatus(`Mission setup failed: ${err.message}`);
        addToLog(`Mission setup failed: ${err.message}`, "miss");
    }
}

async function joinExistingGame() {
    try {
        const entered = Number((joinGameIdInput?.value || "").trim());
        if (!entered) {
            setStatus("Enter a valid room code first.");
            return;
        }

        addToLog(`Joining room ${entered}...`);
        setStatus(`Joining room ${entered}...`);
        await ensurePlayer();
        updateStatsBox(playerId);

        const joinRes = await fetch(`/api/games/${entered}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId })
        });
        const joinData = await safeJson(joinRes);
        if (!joinRes.ok) throw new Error(joinData.message || "Could not join game.");

        gameId = entered;
        selectedShips = [];
        isPlacementMode = true;
        gameStatus = "waiting_setup";
        localStorage.setItem("currentGameId", String(gameId));
        localStorage.removeItem("currentGamePlayerShips");

        setRoomCode(`Room Code: ${gameId}`);
        setStatus(`Joined room ${gameId}. Place your 3 ships.`);
        addToLog(`Joined room ${gameId}. Awaiting fleet deployment.`);
        renderPlacementBoard();
        startPolling();
    } catch (err) {
        setStatus(`Join failed: ${err.message}`);
        addToLog(`Join failed: ${err.message}`, "miss");
    }
}

async function resumeMission() {
    try {
        const savedGameId = Number(localStorage.getItem("currentGameId") || 0);
        const savedPlayerId = Number(localStorage.getItem("currentPlayerId") || 0);
        if (!savedGameId || !savedPlayerId) {
            setStatus("No saved mission found.");
            addToLog("Resume failed. No saved mission.", "miss");
            return;
        }

        gameId = savedGameId;
        playerId = savedPlayerId;
        selectedShips = JSON.parse(localStorage.getItem("currentGamePlayerShips") || "[]");
        updateStatsBox(playerId);
        setRoomCode(`Room Code: ${gameId}`);
        addToLog(`Resuming room ${gameId}...`);
        await refreshGameState(true);
        startPolling();
    } catch (err) {
        setStatus(`Resume failed: ${err.message}`);
        addToLog(`Resume failed: ${err.message}`, "miss");
    }
}

function renderPlacementBoard() {
    if (!playerBoardEl || !cpuBoardEl) return;
    playerBoardEl.innerHTML = "";
    cpuBoardEl.innerHTML = "";

    for (let r = 0; r < SIZE; r++) {
        for (let c = 0; c < SIZE; c++) {
            const cell = document.createElement("button");
            cell.type = "button";
            cell.className = "cell";
            if (selectedShips.some(s => s.row === r && s.col === c)) {
                cell.classList.add("ship");
            }
            cell.addEventListener("click", () => handlePlacementClick(r, c));
            playerBoardEl.appendChild(cell);
        }
    }

    for (let r = 0; r < SIZE; r++) {
        for (let c = 0; c < SIZE; c++) {
            const cell = document.createElement("div");
            cell.className = "cell";
            cpuBoardEl.appendChild(cell);
        }
    }
}

function handlePlacementClick(row, col) {
    if (!isPlacementMode) return;

    const idx = selectedShips.findIndex(s => s.row === row && s.col === col);
    if (idx >= 0) {
        selectedShips.splice(idx, 1);
    } else {
        if (selectedShips.length >= MAX_PLACEMENT_SHIPS) return;
        selectedShips.push({ row, col });
    }

    if (btnConfirmPlacement) {
        btnConfirmPlacement.disabled = selectedShips.length !== MAX_PLACEMENT_SHIPS;
    }

    renderPlacementBoard();
}

async function submitPlacement() {
    if (!gameId || !playerId) {
        setStatus("No active mission.");
        return;
    }
    if (selectedShips.length !== MAX_PLACEMENT_SHIPS) {
        setStatus("Select exactly 3 sectors first.");
        return;
    }

    try {
        const res = await fetch(`/api/games/${gameId}/place`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId, ships: selectedShips })
        });
        const data = await safeJson(res);
        if (!res.ok) throw new Error(data.message || "Could not place ships.");

        localStorage.setItem("currentGamePlayerShips", JSON.stringify(selectedShips));
        isPlacementMode = false;
        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;
        addToLog("Fleet positions submitted.");
        await refreshGameState(true);
        startPolling();
    } catch (err) {
        setStatus(`Deployment failed: ${err.message}`);
        addToLog(`Deployment failed: ${err.message}`, "miss");
    }
}

async function refreshGameState(showLogs = false) {
    if (!gameId || !playerId) return;

    const [gameRes, movesRes] = await Promise.all([
        fetch(`/api/games/${gameId}`),
        fetch(`/api/games/${gameId}/moves`)
    ]);

    const gameData = await safeJson(gameRes);
    const movesData = await safeJson(movesRes);

    if (!gameRes.ok) throw new Error(gameData.message || "Could not load game.");
    if (!movesRes.ok) throw new Error(movesData.message || "Could not load moves.");

    currentGameState = gameData;
    gameStatus = gameData.status;
    opponentId = (gameData.players || []).find(p => Number(p.player_id) !== Number(playerId))?.player_id || null;

    const myShipsRemaining = (gameData.players || []).find(p => Number(p.player_id) === Number(playerId))?.ships_remaining ?? MAX_PLACEMENT_SHIPS;
    const enemyShipsRemaining = (gameData.players || []).find(p => Number(p.player_id) !== Number(playerId))?.ships_remaining ?? MAX_PLACEMENT_SHIPS;

    serverScoreEl.textContent = `My ships left: ${myShipsRemaining} | Enemy ships left: ${enemyShipsRemaining}`;
    setRoomCode(`Room Code: ${gameId}`);

    if (gameData.status === "waiting_setup") {
        isPlacementMode = selectedShips.length !== MAX_PLACEMENT_SHIPS || !hasPlacedShips();
        renderPlacementBoard();
        const joinedCount = (gameData.players || []).length;

        if (!hasPlacedShips()) {
            setStatus(joinedCount < 2
                ? `Room ${gameId} waiting for Player 2. You can place ships now.`
                : `Both players are here. Place your 3 ships.`);
        } else {
            setStatus(joinedCount < 2
                ? `Ships locked in. Waiting for Player 2 to join room ${gameId}.`
                : `Ships locked in. Waiting for the other player to finish placement.`);
        }

        if (showLogs) {
            addToLog(`Room ${gameId} status: waiting_setup.`);
        }
        return;
    }

    isPlacementMode = false;
    if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;
    renderBattleBoards(movesData.moves || []);

    if (gameData.status === "playing") {
        if (Number(gameData.current_turn_player_id) === Number(playerId)) {
            setStatus(`Your turn in room ${gameId}. Fire when ready.`);
        } else {
            setStatus(`Opponent's turn in room ${gameId}. Waiting for enemy fire...`);
        }
        if (showLogs) addToLog(`Room ${gameId} is live.`);
        return;
    }

    if (gameData.status === "finished") {
        stopPolling();
        const iWon = enemyShipsRemaining === 0 && myShipsRemaining > 0;
        updateStatsBox(playerId);
        showEndMissionOverlay(iWon ? "win" : "lose");
    }
}

function hasPlacedShips() {
    return Array.isArray(selectedShips) && selectedShips.length === MAX_PLACEMENT_SHIPS;
}

function renderBattleBoards(moves) {
    if (!playerBoardEl || !cpuBoardEl) return;

    playerBoardEl.innerHTML = "";
    cpuBoardEl.innerHTML = "";

    const myShipSet = new Set((selectedShips || []).map(s => `${s.row},${s.col}`));
    const enemyShots = new Map();
    const myShots = new Map();

    for (const move of moves) {
        const key = `${move.row},${move.col}`;
        if (Number(move.player_id) === Number(playerId)) {
            myShots.set(key, move.result);
        } else {
            enemyShots.set(key, move.result);
        }
    }

    for (let r = 0; r < SIZE; r++) {
        for (let c = 0; c < SIZE; c++) {
            const key = `${r},${c}`;
            const playerCell = document.createElement("div");
            playerCell.className = "cell";
            playerCell.id = `player-cell-${r}-${c}`;

            if (myShipSet.has(key)) playerCell.classList.add("ship");
            if (enemyShots.get(key) === "hit") playerCell.classList.add("hit");
            if (enemyShots.get(key) === "miss") playerCell.classList.add("miss");
            playerBoardEl.appendChild(playerCell);

            const enemyCell = document.createElement("button");
            enemyCell.type = "button";
            enemyCell.className = "cell";
            enemyCell.id = `enemy-cell-${r}-${c}`;

            if (myShots.get(key) === "hit") enemyCell.classList.add("hit");
            if (myShots.get(key) === "miss") enemyCell.classList.add("miss");
            if (myShots.has(key) || gameStatus !== "playing" || Number(currentGameState?.current_turn_player_id) !== Number(playerId)) {
                enemyCell.disabled = true;
            }
            enemyCell.addEventListener("click", () => firePhasers(r, c));
            cpuBoardEl.appendChild(enemyCell);
        }
    }
}

async function firePhasers(row, col) {
    if (gameStatus !== "playing") return;
    if (Number(currentGameState?.current_turn_player_id) !== Number(playerId)) {
        setStatus("Not your turn yet.");
        return;
    }

    const cell = document.getElementById(`enemy-cell-${row}-${col}`);
    if (!cell || cell.classList.contains("hit") || cell.classList.contains("miss")) return;

    try {
        const res = await fetch(`/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId, row, col })
        });
        const data = await safeJson(res);
        if (!res.ok) throw new Error(data.message || "Fire command failed.");

        addToLog(`[${data.result.toUpperCase()}] Fired at sector ${row},${col}.`, data.result === "hit" ? "hit" : "miss");
        await refreshGameState(true);
    } catch (err) {
        setStatus(`Fire command failed: ${err.message}`);
        addToLog(`Fire command failed: ${err.message}`, "miss");
    }
}

function showEndMissionOverlay(result) {
    const isWin = result === "win";
    const old = document.getElementById("mission-overlay");
    if (old) old.remove();

    const overlay = document.createElement("div");
    overlay.id = "mission-overlay";
    overlay.style.cssText = `
        position: fixed; inset: 0; background: rgba(5,7,13,0.95);
        display: flex; align-items: center; justify-content: center;
        z-index: 99999; backdrop-filter: blur(8px);
    `;

    overlay.innerHTML = `
        <div style="border: 2px solid ${isWin ? '#2ecc71' : '#ff5c5c'}; padding: 48px; border-radius: 10px; background: #0d1222; text-align: center; font-family: monospace; max-width: 560px;">
            <h1 style="color: ${isWin ? '#2ecc71' : '#ff5c5c'}; margin-top: 0; font-size: 2.5rem; text-transform: uppercase;">
                ${isWin ? 'Mission Accomplished' : 'Mission Failure'}
            </h1>
            <p style="color: #eaf0ff; font-size: 1.15rem; line-height: 1.5; margin: 20px 0 30px;">
                ${isWin ? 'Enemy fleet eliminated. Your room is secure.' : 'Your fleet has been destroyed. The other player won this round.'}
            </p>
            <button onclick="returnToStarbase()" style="background:${isWin ? '#2ecc71' : '#ff5c5c'}; color:white; border:none; padding:16px 28px; border-radius:6px; font-size:1rem; font-weight:bold; cursor:pointer;">
                Return to Starbase
            </button>
        </div>
    `;

    document.body.appendChild(overlay);
}

function returnToStarbase() {
    stopPolling();
    localStorage.removeItem("currentGameId");
    localStorage.removeItem("currentGamePlayerShips");
    window.location.reload();
}
window.returnToStarbase = returnToStarbase;

async function updateStatsBox(id = null) {
    const targetId = Number(id || playerId || localStorage.getItem("currentPlayerId") || 0);
    const statsBox = document.getElementById("stats-container");
    if (!statsBox) return;

    if (!targetId) {
        statsBox.innerHTML = defaultStatsHtml(0, 0, 0);
        return;
    }

    try {
        const res = await fetch(`/api/players/${targetId}/stats`);
        if (!res.ok) return;
        const stats = await res.json();
        statsBox.innerHTML = defaultStatsHtml(stats.wins || 0, stats.losses || 0, ((stats.accuracy || 0) * 100).toFixed(1));
    } catch (err) {
        console.error("Stats update failed:", err);
    }
}

function defaultStatsHtml(wins, losses, accuracyPercent) {
    return `
        <div style="border: 1px solid #4a9eff; padding: 15px; background: rgba(13, 18, 34, 0.8); color: white; border-radius: 5px; font-family: monospace;">
            <h3 style="color: #4a9eff; margin-top: 0; border-bottom: 1px solid #4a9eff; font-size: 1rem;">TACTICAL ARCHIVE</h3>
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span>MISSIONS WON:</span> <span style="color: #2ecc71; font-weight: bold;">${wins}</span>
            </div>
            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                <span>MISSIONS LOST:</span> <span style="color: #ff5c5c; font-weight: bold;">${losses}</span>
            </div>
            <div style="display: flex; justify-content: space-between;">
                <span>FIRE ACCURACY:</span> <span style="color: #ffcc66; font-weight: bold;">${accuracyPercent}%</span>
            </div>
        </div>
    `;
}
