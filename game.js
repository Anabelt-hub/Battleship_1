const SIZE = 10;
const MAX_PLACEMENT_SHIPS = 3;

let gameId = null;
let playerId = null;
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

btnNewGame.addEventListener("click", startNewMission);

if (btnConfirmPlacement) {
    btnConfirmPlacement.addEventListener("click", submitPlacement);
}

function addToLog(message, type = "") {
    if (!logEl) return;

    const entry = document.createElement("div");
    const time = new Date().toLocaleTimeString([], {
        hour12: false,
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit"
    });

    let spanClass = "";
    if (type === "hit") spanClass = "hitTxt";
    if (type === "miss") spanClass = "missTxt";

    entry.innerHTML = `<span class="muted">[${time}]</span> <span class="${spanClass}">${message}</span>`;
    logEl.prepend(entry);
}

function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
}

function generateRandomShips() {
    const vertical = Math.random() > 0.5;
    const startRow = Math.floor(Math.random() * (vertical ? SIZE - 3 : SIZE));
    const startCol = Math.floor(Math.random() * (vertical ? SIZE : SIZE - 3));

    return vertical
        ? [
            { row: startRow, col: startCol },
            { row: startRow + 1, col: startCol },
            { row: startRow + 2, col: startCol }
        ]
        : [
            { row: startRow, col: startCol },
            { row: startRow, col: startCol + 1 },
            { row: startRow, col: startCol + 2 }
        ];
}

async function startNewMission() {
    try {
        if (logEl) logEl.innerHTML = "";
        addToLog("Initializing Starfleet Tactical computer...");

        const pRes = await fetch("/api/players", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ username: "Captain_Gabbie" })
        });

        const pData = await pRes.json();
        if (!pRes.ok) throw new Error(pData.message || "Could not create player.");
        playerId = pData.player_id;

        const gRes = await fetch("/api/games", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ grid_size: 10 })
        });

        const gData = await gRes.json();
        if (!gRes.ok) throw new Error(gData.message || "Could not create game.");
        gameId = gData.game_id;

        localStorage.setItem("currentPlayerId", playerId);
        localStorage.setItem("currentGameId", gameId);

        await fetch(`/api/games/${gameId}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId })
        });

        await setupCPUOpponent(gameId);

        isPlacementMode = true;
        selectedShips = [];
        gameStatus = "waiting_setup";

        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;

        setStatus("Placement Mode: Click 3 sectors to station your fleet.");
        addToLog("Mission assigned. Sector grid ready for ship deployment.");
        renderPlacementBoard();
    } catch (err) {
        console.error(err);
        setStatus("Mission setup failed.");
        addToLog(`System error: ${err.message}`, "miss");
    }
}

async function setupCPUOpponent(currentGId) {
    const cpuRes = await fetch("/api/players", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ username: "Borg Cube" })
    });

    const cpuData = await cpuRes.json();
    if (!cpuRes.ok) throw new Error(cpuData.message || "Could not create CPU player.");

    const cpuId = cpuData.player_id;
    localStorage.setItem("cpuPlayerId", cpuId);

    await fetch(`/api/games/${currentGId}/join`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ player_id: cpuId })
    });

    await fetch(`/api/test/games/${currentGId}/ships`, {
        method: "POST",
        headers: {
            "Content-Type": "application/json",
            "X-Test-Password": "clemson-test-2026"
        },
        body: JSON.stringify({
            player_id: cpuId,
            ships: generateRandomShips()
        })
    });
}

async function submitPlacement() {
    if (selectedShips.length !== 3) {
        alert("Select 3 sectors.");
        return;
    }

    const currentGId = localStorage.getItem("currentGameId");
    const currentPId = localStorage.getItem("currentPlayerId");

    try {
        const res = await fetch(`/api/games/${currentGId}/place`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                player_id: parseInt(currentPId, 10),
                ships: selectedShips
            })
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.message || "Could not place ships.");

        isPlacementMode = false;
        gameStatus = "playing";

        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;

        setStatus("Sensors Active. Enemy fleet detected. Fire when ready!");
        addToLog("Federation fleet has exited warp and taken positions.");
        addToLog("Long-range sensors confirm enemy presence. Red Alert!");

        renderBattleBoards();
    } catch (err) {
        console.error(err);
        addToLog(`Deployment failed: ${err.message}`, "miss");
        alert("Could not confirm ship positions.");
    }
}

async function pollForActivation() {
    const res = await fetch(`/api/games/${gameId}`);
    const data = await res.json();

    if (data.status === "playing") {
        gameStatus = "playing";
        setStatus("Sensors Active. Enemy fleet detected. Fire when ready!");
        addToLog("Long-range sensors confirm enemy presence. Red Alert!");
        renderBattleBoards();
    } else {
        setTimeout(pollForActivation, 2000);
    }
}

function renderPlacementBoard() {
    playerBoardEl.innerHTML = "";
    cpuBoardEl.innerHTML = "";

    for (let r = 0; r < SIZE; r++) {
        for (let c = 0; c < SIZE; c++) {
            const cell = document.createElement("button");
            cell.className = "cell";
            cell.onclick = () => handlePlacementClick(r, c, cell);
            playerBoardEl.appendChild(cell);
        }
    }
}

function handlePlacementClick(r, c, cell) {
    if (!isPlacementMode) return;

    const idx = selectedShips.findIndex(s => s.row === r && s.col === c);

    if (idx > -1) {
        selectedShips.splice(idx, 1);
        cell.classList.remove("ship-selected");
    } else if (selectedShips.length < MAX_PLACEMENT_SHIPS) {
        selectedShips.push({ row: r, col: c });
        cell.classList.add("ship-selected");
    }

    if (btnConfirmPlacement) {
        btnConfirmPlacement.disabled = (selectedShips.length !== MAX_PLACEMENT_SHIPS);
    }
}

function renderBattleBoards() {
    playerBoardEl.innerHTML = "";
    for (let r = 0; r < SIZE; r++) {
        for (let c = 0; c < SIZE; c++) {
            const cell = document.createElement("div");
            cell.className = "cell";

            if (selectedShips.some(s => s.row === r && s.col === c)) {
                cell.classList.add("ship");
            }

            playerBoardEl.appendChild(cell);
        }
    }

    cpuBoardEl.innerHTML = "";
    for (let r = 0; r < SIZE; r++) {
        for (let c = 0; c < SIZE; c++) {
            const cell = document.createElement("button");
            cell.className = "cell";
            cell.id = `cpu-cell-${r}-${c}`;
            cell.onclick = () => firePhasers(r, c, cell);
            cpuBoardEl.appendChild(cell);
        }
    }
}

async function firePhasers(r, c, cell) {
    if (gameStatus !== "playing") return;
    if (cell.classList.contains("hit") || cell.classList.contains("miss")) return;

    try {
        const res = await fetch(`/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId, row: r, col: c })
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.message || "Could not fire.");

        cell.classList.add(data.result === "hit" ? "hit" : "miss");
        addToLog(`Tactical: Phasers fired at Sector ${r},${c} - ${data.result.toUpperCase()}`, data.result);

        if (data.game_status === "finished") {
            gameStatus = "finished";
            addToLog("VICTORY: Enemy fleet neutralized. Returning to Starbase.", "hit");
            alert("🎉 VICTORY! Enemy fleet neutralized.");
        } else {
            setTimeout(cpuTurn, 800);
        }
    } catch (err) {
        console.error(err);
        addToLog(`Weapons error: ${err.message}`, "miss");
    }
}

async function cpuTurn() {
    if (gameStatus !== "playing") return;

    const cpuId = parseInt(localStorage.getItem("cpuPlayerId"), 10);
    const r = Math.floor(Math.random() * SIZE);
    const c = Math.floor(Math.random() * SIZE);

    try {
        const res = await fetch(`/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: cpuId, row: r, col: c })
        });

        const data = await res.json();
        if (!res.ok) throw new Error(data.message || "CPU turn failed.");

        const target = playerBoardEl.getElementsByClassName("cell")[r * SIZE + c];
        if (target) {
            target.classList.add(data.result === "hit" ? "hit" : "miss");
        }

        addToLog(`Alert: Enemy fire detected at Sector ${r},${c} - ${data.result.toUpperCase()}`, data.result);

        if (data.game_status === "finished") {
            gameStatus = "finished";
            addToLog("CRITICAL: Hull integrity failing. Abandon ship!", "hit");
            alert("💀 GAME OVER: You have been destroyed.");
        }
    } catch (err) {
        console.error(err);
        addToLog(`Enemy weapons system error: ${err.message}`, "miss");
    }
}

if (btnReveal) {
    btnReveal.addEventListener("click", async () => {
        if (!gameId) return;

        const cpuId = localStorage.getItem("cpuPlayerId");
        addToLog("Initiating long-range sensor sweep...");

        try {
            const res = await fetch(`/api/test/games/${gameId}/board/${cpuId}`, {
                method: "GET",
                headers: { "X-Test-Password": "clemson-test-2026" }
            });

            const data = await res.json();
            if (!res.ok) throw new Error(data.message || "Sensor sweep failed.");

            if (data.ships) {
                addToLog("Sensors successful. Enemy positions highlighted.", "hit");

                data.ships.forEach(ship => {
                    const cell = document.getElementById(`cpu-cell-${ship.row}-${ship.col}`);
                    if (cell && !cell.classList.contains("hit") && !cell.classList.contains("miss")) {
                        cell.style.border = "2px solid #ffcc66";
                        cell.style.boxShadow = "0 0 12px rgba(255, 204, 102, 0.6)";
                    }
                });
            }
        } catch (err) {
            console.error(err);
            addToLog(`Sensor sweep failed: ${err.message}`, "miss");
        }
    });
}
