const SIZE = 10;
const MAX_PLACEMENT_SHIPS = 3;
const TEST_PASSWORD = "clemson-test-2026";

let gameId = null;
let playerId = null;
let cpuPlayerId = null;
let isPlacementMode = false;
let selectedShips = [];
let gameStatus = "waiting_setup";

const statusEl = document.getElementById("status");
const playerBoardEl = document.getElementById("playerBoard");
const cpuBoardEl = document.getElementById("cpuBoard");
const logEl = document.getElementById("log");

const btnNewGame = document.getElementById("btnNewGame");
const btnConfirmPlacement = document.getElementById("btnConfirmPlacement");
const btnReveal = document.getElementById("btnReveal");
const btnResume = document.getElementById("btnResume");
const btnClearSave = document.getElementById("btnClearSave");
const btnUndo = document.getElementById("btnUndo");
const btnReset = document.getElementById("btnReset");

if (btnNewGame) btnNewGame.addEventListener("click", startNewMission);
if (btnConfirmPlacement) btnConfirmPlacement.addEventListener("click", submitPlacement);

if (btnReveal) {
    btnReveal.addEventListener("click", async () => {
        if (!gameId || !cpuPlayerId) {
            addToLog("Sensor sweep unavailable. No active mission.", "miss");
            return;
        }

        try {
            addToLog("Initiating long-range sensor sweep...");

            const res = await fetch(`/api/test/games/${gameId}/board/${cpuPlayerId}`, {
                method: "GET",
                headers: {
                    "X-Test-Password": TEST_PASSWORD
                }
            });

            const data = await safeJson(res);
            if (!res.ok) {
                throw new Error(data.message || "Sensor sweep failed.");
            }

            if (!Array.isArray(data.ships)) {
                throw new Error("Invalid scan response.");
            }

            addToLog("Sensors successful. Enemy positions highlighted.", "hit");

            data.ships.forEach(ship => {
                const cell = document.getElementById(`cpu-cell-${ship.row}-${ship.col}`);
                if (cell && !cell.classList.contains("hit") && !cell.classList.contains("miss")) {
                    cell.style.border = "2px solid #ffcc66";
                    cell.style.boxShadow = "0 0 12px rgba(255, 204, 102, 0.6)";
                }
            });
        } catch (err) {
            console.error(err);
            addToLog(`Sensor sweep failed: ${err.message}`, "miss");
            setStatus(`Sensor sweep failed: ${err.message}`);
        }
    });
}

if (btnResume) {
    btnResume.addEventListener("click", resumeMission);
}

if (btnClearSave) {
    btnClearSave.addEventListener("click", () => {
        localStorage.removeItem("currentGameId");
        localStorage.removeItem("currentPlayerId");
        localStorage.removeItem("cpuPlayerId");
        gameId = null;
        playerId = null;
        cpuPlayerId = null;
        gameStatus = "waiting_setup";
        isPlacementMode = false;
        selectedShips = [];
        playerBoardEl.innerHTML = "";
        cpuBoardEl.innerHTML = "";
        setStatus("Save cleared. Ready for a new mission.");
        addToLog("Mission save data cleared.");
    });
}

if (btnUndo) {
    btnUndo.addEventListener("click", () => {
        if (!isPlacementMode || selectedShips.length === 0) {
            addToLog("No placement to undo.", "miss");
            return;
        }

        selectedShips.pop();
        renderPlacementBoard();
        addToLog("Last ship placement selection removed.");
    });
}

if (btnReset) {
    btnReset.addEventListener("click", async () => {
        try {
            const res = await fetch("/api/reset", {
                method: "POST"
            });

            const data = await safeJson(res);
            if (!res.ok) {
                throw new Error(data.message || "Reset failed.");
            }

            localStorage.removeItem("currentGameId");
            localStorage.removeItem("currentPlayerId");
            localStorage.removeItem("cpuPlayerId");

            gameId = null;
            playerId = null;
            cpuPlayerId = null;
            gameStatus = "waiting_setup";
            isPlacementMode = false;
            selectedShips = [];
            playerBoardEl.innerHTML = "";
            cpuBoardEl.innerHTML = "";

            if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;

            setStatus("Scoreboard reset. Ready for a new mission.");
            addToLog("Simulation reset complete.");
        } catch (err) {
            console.error(err);
            setStatus(`Reset failed: ${err.message}`);
            addToLog(`Reset failed: ${err.message}`, "miss");
        }
    });
}

function setStatus(msg) {
    if (statusEl) {
        statusEl.textContent = msg;
    }
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

function generateRandomShips() {
    const vertical = Math.random() > 0.5;
    const startRow = Math.floor(Math.random() * (vertical ? SIZE - 2 : SIZE));
    const startCol = Math.floor(Math.random() * (vertical ? SIZE : SIZE - 2));

    if (vertical) {
        return [
            { row: startRow, col: startCol },
            { row: startRow + 1, col: startCol },
            { row: startRow + 2, col: startCol }
        ];
    }

    return [
        { row: startRow, col: startCol },
        { row: startRow, col: startCol + 1 },
        { row: startRow, col: startCol + 2 }
    ];
}

async function startNewMission() {
    try {
        if (logEl) logEl.innerHTML = "";
        addToLog("Initializing Starfleet Tactical computer...");
        setStatus("Opening mission channel...");

        const humanName = `Captain_Gabbie_${Date.now()}`;
        const cpuName = `Borg_Cube_${Date.now()}`;

        const pRes = await fetch("/api/players", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ username: humanName })
        });

        const pData = await safeJson(pRes);
        if (!pRes.ok) {
            throw new Error(pData.message || "Could not create player.");
        }
        playerId = Number(pData.player_id);

        const gRes = await fetch("/api/games", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ grid_size: SIZE })
        });

        const gData = await safeJson(gRes);
        if (!gRes.ok) {
            throw new Error(gData.message || "Could not create game.");
        }
        gameId = Number(gData.game_id);

        const joinHumanRes = await fetch(`/api/games/${gameId}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId })
        });

        const joinHumanData = await safeJson(joinHumanRes);
        if (!joinHumanRes.ok) {
            throw new Error(joinHumanData.message || "Could not join game.");
        }

        const cpuRes = await fetch("/api/players", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ username: cpuName })
        });

        const cpuData = await safeJson(cpuRes);
        if (!cpuRes.ok) {
            throw new Error(cpuData.message || "Could not create CPU player.");
        }
        cpuPlayerId = Number(cpuData.player_id);

        const joinCpuRes = await fetch(`/api/games/${gameId}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: cpuPlayerId })
        });

        const joinCpuData = await safeJson(joinCpuRes);
        if (!joinCpuRes.ok) {
            throw new Error(joinCpuData.message || "Could not add CPU to game.");
        }

        const cpuShipRes = await fetch(`/api/test/games/${gameId}/ships`, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-Test-Password": TEST_PASSWORD
            },
            body: JSON.stringify({
                player_id: cpuPlayerId,
                ships: generateRandomShips()
            })
        });

        const cpuShipData = await safeJson(cpuShipRes);
        if (!cpuShipRes.ok) {
            throw new Error(cpuShipData.message || "Could not place CPU ships.");
        }

        localStorage.setItem("currentGameId", String(gameId));
        localStorage.setItem("currentPlayerId", String(playerId));
        localStorage.setItem("cpuPlayerId", String(cpuPlayerId));

        selectedShips = [];
        isPlacementMode = true;
        gameStatus = "waiting_setup";

        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;

        setStatus("Placement Mode: Click 3 sectors to station your fleet.");
        addToLog("Mission assigned. Sector grid ready for ship deployment.");
        renderPlacementBoard();
    } catch (err) {
        console.error("startNewMission failed:", err);
        setStatus(`Mission setup failed: ${err.message}`);
        addToLog(`Mission setup failed: ${err.message}`, "miss");
    }
}

async function resumeMission() {
    try {
        const savedGameId = localStorage.getItem("currentGameId");
        const savedPlayerId = localStorage.getItem("currentPlayerId");
        const savedCpuId = localStorage.getItem("cpuPlayerId");

        if (!savedGameId || !savedPlayerId || !savedCpuId) {
            setStatus("No saved mission found.");
            addToLog("Resume failed. No saved mission.");
            return;
        }

        gameId = Number(savedGameId);
        playerId = Number(savedPlayerId);
        cpuPlayerId = Number(savedCpuId);

        const res = await fetch(`/api/games/${gameId}`);
        const data = await safeJson(res);

        if (!res.ok) {
            throw new Error(data.message || "Could not load mission.");
        }

        if (data.status === "playing") {
            isPlacementMode = false;
            gameStatus = "playing";
            if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;
            setStatus("Mission resumed. Enemy grid active.");
            addToLog("Saved mission resumed.");
            renderBattleBoards();
            return;
        }

        isPlacementMode = true;
        gameStatus = "waiting_setup";
        selectedShips = [];
        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;
        setStatus("Placement Mode: Click 3 sectors to station your fleet.");
        addToLog("Saved mission resumed in setup mode.");
        renderPlacementBoard();
    } catch (err) {
        console.error(err);
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
        addToLog("No active mission to deploy.", "miss");
        return;
    }

    if (selectedShips.length !== MAX_PLACEMENT_SHIPS) {
        setStatus("Select exactly 3 sectors first.");
        addToLog("Deployment blocked. Exactly 3 sectors required.", "miss");
        return;
    }

    try {
        const res = await fetch(`/api/games/${gameId}/place`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                player_id: playerId,
                ships: selectedShips
            })
        });

        const data = await safeJson(res);
        if (!res.ok) {
            throw new Error(data.message || "Could not place ships.");
        }

        isPlacementMode = false;
        gameStatus = "playing";

        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;

        setStatus("Sensors Active. Enemy fleet detected. Fire when ready!");
        addToLog("Federation fleet has exited warp and taken positions.");
        addToLog("Long-range sensors confirm enemy presence. Red Alert!");

        renderBattleBoards();
    } catch (err) {
        console.error(err);
        setStatus(`Deployment failed: ${err.message}`);
        addToLog(`Deployment failed: ${err.message}`, "miss");
    }
}

function renderBattleBoards() {
    if (!playerBoardEl || !cpuBoardEl) return;

    playerBoardEl.innerHTML = "";
    cpuBoardEl.innerHTML = "";

    for (let r = 0; r < SIZE; r++) {
        for (let c = 0; c < SIZE; c++) {
            const playerCell = document.createElement("div");
            playerCell.className = "cell";
            playerCell.id = `player-cell-${r}-${c}`;

            if (selectedShips.some(s => s.row === r && s.col === c)) {
                playerCell.classList.add("ship");
            }

            playerBoardEl.appendChild(playerCell);

            const cpuCell = document.createElement("button");
            cpuCell.type = "button";
            cpuCell.className = "cell";
            cpuCell.id = `cpu-cell-${r}-${c}`;
            cpuCell.addEventListener("click", () => firePhasers(r, c));
            cpuBoardEl.appendChild(cpuCell);
        }
    }
}

async function firePhasers(row, col) {
    if (gameStatus !== "playing") return;

    const cell = document.getElementById(`cpu-cell-${row}-${col}`);
    if (!cell) return;

    if (cell.classList.contains("hit") || cell.classList.contains("miss")) {
        return;
    }

    try {
        const res = await fetch(`/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                player_id: playerId,
                row,
                col
            })
        });

        const data = await safeJson(res);
        if (!res.ok) {
            throw new Error(data.message || "Could not fire.");
        }

        if (data.result === "hit") {
            cell.classList.add("hit");
            addToLog(`Tactical: Phasers fired at Sector ${row},${col} - HIT`, "hit");
        } else {
            cell.classList.add("miss");
            addToLog(`Tactical: Phasers fired at Sector ${row},${col} - MISS`, "miss");
        }

        if (data.game_status === "finished") {
            gameStatus = "finished";
            setStatus("Victory! Enemy fleet neutralized.");
            addToLog("VICTORY: Enemy fleet neutralized. Returning to Starbase.", "hit");
            return;
        }

        setTimeout(cpuTurn, 700);
    } catch (err) {
        console.error(err);
        setStatus(`Weapons error: ${err.message}`);
        addToLog(`Weapons error: ${err.message}`, "miss");
    }
}

async function cpuTurn() {
    if (gameStatus !== "playing" || !cpuPlayerId) return;

    let attempts = 0;
    let row = 0;
    let col = 0;
    let target = null;

    do {
        row = Math.floor(Math.random() * SIZE);
        col = Math.floor(Math.random() * SIZE);
        target = document.getElementById(`player-cell-${row}-${col}`);
        attempts++;
    } while (
        target &&
        (target.classList.contains("hit") || target.classList.contains("miss")) &&
        attempts < 200
    );

    try {
        const res = await fetch(`/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                player_id: cpuPlayerId,
                row,
                col
            })
        });

        const data = await safeJson(res);
        if (!res.ok) {
            throw new Error(data.message || "Enemy turn failed.");
        }

        if (target) {
            if (data.result === "hit") {
                target.classList.add("hit");
                addToLog(`Alert: Enemy fire detected at Sector ${row},${col} - HIT`, "hit");
            } else {
                target.classList.add("miss");
                addToLog(`Alert: Enemy fire detected at Sector ${row},${col} - MISS`, "miss");
            }
        }

        if (data.game_status === "finished") {
            gameStatus = "finished";
            setStatus("Game over. Your fleet has been destroyed.");
            addToLog("CRITICAL: Hull integrity failing. Abandon ship!", "miss");
        }
    } catch (err) {
        console.error(err);
        setStatus(`Enemy action failed: ${err.message}`);
        addToLog(`Enemy action failed: ${err.message}`, "miss");
    }
}

window.addEventListener("load", () => {
    setStatus("Awaiting mission start.");
});
