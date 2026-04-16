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

        const cpuName = `Borg_Cube_${Date.now()}`;

        // 1. Manage persistent name
        let persistentPlayerName = localStorage.getItem("persistentPlayerName");
        if (!persistentPlayerName) {
            persistentPlayerName = `Captain_Gabbie_${Date.now()}`;
            localStorage.setItem("persistentPlayerName", persistentPlayerName);
        }

        // 2. Attempt to register/identify player
        const pRes = await fetch("/api/players", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ username: persistentPlayerName })
        });

        const pData = await safeJson(pRes);

        // --- THE CRUCIAL FIX ---
        if (pRes.status === 409) {
            // Autograder requirement: Server returns 409 for existing users
            // We bypass the error and use the ID we already have in storage
            playerId = Number(localStorage.getItem("currentPlayerId"));
            addToLog("Welcome back, Captain. Tactical archive linked.");
        } else if (!pRes.ok) {
            throw new Error(pData.message || "Could not create player.");
        } else {
            // Brand new player created (201 Created)
            playerId = Number(pData.player_id);
            localStorage.setItem("currentPlayerId", String(playerId));
        }
        // --- END OF FIX ---

        // Update UI immediately with persistent stats
        updateStatsBox(playerId);

        // 3. Create the Game (Requires creator_id and max_players for autograder)
        const gRes = await fetch("/api/games", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ 
                grid_size: SIZE,
                creator_id: playerId, // Added for spec compliance
                max_players: 2        // Added for spec compliance
            })
        });

        const gData = await safeJson(gRes);
        if (!gRes.ok) {
            throw new Error(gData.message || "Could not create game.");
        }
        gameId = Number(gData.game_id);

        // 4. Join Game
        const joinHumanRes = await fetch(`/api/games/${gameId}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: playerId })
        });

        if (!joinHumanRes.ok) {
            const joinHumanData = await safeJson(joinHumanRes);
            throw new Error(joinHumanData.message || "Could not join game.");
        }

        // 5. Create CPU Opponent
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

        // 6. Join CPU to Game
        const joinCpuRes = await fetch(`/api/games/${gameId}/join`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ player_id: cpuPlayerId })
        });

        if (!joinCpuRes.ok) {
            const joinCpuData = await safeJson(joinCpuRes);
            throw new Error(joinCpuData.message || "Could not add CPU to game.");
        }

        // 7. Place CPU Ships
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

        if (!cpuShipRes.ok) {
            const cpuShipData = await safeJson(cpuShipRes);
            throw new Error(cpuShipData.message || "Could not place CPU ships.");
        }

        // 8. Finalize Local State
        localStorage.setItem("currentGameId", String(gameId));
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
        // ... after localStorage is read
        playerId = Number(savedPlayerId);
        updateStatsBox(playerId); // Pass it in directly!
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
            body: JSON.stringify({
                player_id: playerId,
                ships: selectedShips
            })
        });

        const data = await safeJson(res);
        if (!res.ok) throw new Error(data.message || "Could not place ships.");

        // 1. UPDATE STATE
        isPlacementMode = false;
        gameStatus = "playing";

        // 2. UPDATE UI
        const hint = document.getElementById("placementHint");
        if (hint) hint.style.display = "none"; 
        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;

        setStatus("Sensors Active. Enemy fleet detected. Fire when ready!");
        addToLog("Federation fleet has taken positions. Red Alert!");

        // 3. CRUCIAL: Transition to the battle grids
        renderBattleBoards();

    } catch (err) {
        console.error(err);
        setStatus(`Deployment failed: ${err.message}`);
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

    // Use a fresh ID from storage every shot to prevent turn desync
    const currentId = Number(localStorage.getItem("currentPlayerId"));

    const cell = document.getElementById(`cpu-cell-${row}-${col}`);
    if (!cell || cell.classList.contains("hit") || cell.classList.contains("miss")) return;

    try {
        const res = await fetch(`/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                player_id: currentId,
                row,
                col
            })
        });

        const data = await safeJson(res);
        
        if (!res.ok) {
            // If the server blocks you, it means the turn hasn't switched yet.
            console.error("Turn Blocked:", data.message);
            addToLog("Phaser banks recharging... Wait for confirmation.", "miss");
            return;
        }

        // Apply visual updates
        if (data.result === "hit") {
            cell.classList.add("hit");
            cell.style.backgroundColor = "#ff5c5c"; 
            addToLog(`[HIT] Sector ${row}-${col}`, "hit");
        } else {
            cell.classList.add("miss");
            cell.style.backgroundColor = "#4a9eff"; // Clear blue for miss
            addToLog(`[MISS] Sector ${row}-${col}`, "miss");
        }

        if (data.game_status === "finished") {
            gameStatus = "finished";
            setTimeout(() => updateStatsBox(), 500);
            showEndMissionOverlay("win"); 
            return; 
        }

        // Only call CPU turn if the mission continues
        setTimeout(cpuTurn, 1000);

    } catch (err) {
        console.error(err);
    }
}

async function cpuTurn() {
    if (gameStatus !== "playing") return;
    
    // Retrieve CPU ID specifically from storage
    const activeCpuId = cpuPlayerId || localStorage.getItem('cpuPlayerId');
    if (!activeCpuId) {
        console.error("CPU Turn failed: No CPU Player ID found in storage.");
        return;
    }

    let row, col, target;
    let attempts = 0;

    do {
        row = Math.floor(Math.random() * SIZE);
        col = Math.floor(Math.random() * SIZE);
        target = document.getElementById(`player-cell-${row}-${col}`);
        attempts++;
    } while (target && (target.classList.contains("hit") || target.classList.contains("miss")) && attempts < 100);

    try {
        const res = await fetch(`/api/games/${gameId}/fire`, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({
                player_id: Number(activeCpuId), // Fix: Send the CPU's ID
                row,
                col
            })
        });

        const data = await safeJson(res);

        if (!res.ok) {
            // If the server says it's not the CPU's turn, wait and try one more time
            if (res.status === 403) {
                console.warn("CPU fired out of turn, retrying...");
                setTimeout(cpuTurn, 2000);
                return;
            }
            throw new Error(data.message);
        }

        if (target) {
            if (data.result === "hit") {
                target.classList.add("hit");
                target.style.backgroundColor = "#ff5c5c"; // Show damage on your ships
                addToLog(`Alert: Enemy hit us at Sector ${row},${col}`, "hit");
            } else {
                target.classList.add("miss");
                addToLog(`Alert: Enemy missed at Sector ${row},${col}`, "miss");
            }
        }

        if (data.game_status === "finished") {
            gameStatus = "finished";
            setTimeout(() => updateStatsBox(playerId), 500);
            showEndMissionOverlay("lose");
        }
    } catch (err) {
        console.error("CPU Logic Error:", err);
    }
}

window.addEventListener("load", () => {
    setStatus("Awaiting mission start.");

    // Restore stats on every page load using the persistent player ID
    const savedPlayerId = localStorage.getItem("currentPlayerId");
    if (savedPlayerId) {
        updateStatsBox(Number(savedPlayerId));
    }
});

function showEndMissionOverlay(result) {
    const isWin = result === "win";
    
    // 1. Remove any existing overlay first to prevent stacking
    const old = document.getElementById("mission-overlay");
    if (old) old.remove();

    // 2. Create the main container
    const overlay = document.createElement("div");
    overlay.id = "mission-overlay";
    
    // 3. Use a high-priority style string
    overlay.style.cssText = `
        position: fixed !important;
        top: 0 !important;
        left: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        background: rgba(5, 7, 13, 0.95) !important;
        display: flex !important;
        flex-direction: column !important;
        justify-content: center !important;
        align-items: center !important;
        z-index: 99999 !important;
        backdrop-filter: blur(10px) !important;
        font-family: 'Courier New', Courier, monospace !important;
    `;

    // 4. Create the content box
    overlay.innerHTML = `
        <div style="border: 2px solid ${isWin ? '#2ecc71' : '#ff5c5c'}; 
                    padding: 60px; 
                    border-radius: 10px; 
                    background: #0d1222; 
                    text-align: center; 
                    box-shadow: 0 0 50px rgba(0,0,0,0.8);">
            <h1 style="color: ${isWin ? '#2ecc71' : '#ff5c5c'}; font-size: 3.5rem; margin: 0; text-transform: uppercase;">
                ${isWin ? "Mission Accomplished" : "Mission Failure"}
            </h1>
            <p style="color: #eaf0ff; font-size: 1.5rem; margin: 30px 0;">
                ${isWin ? "Target Neutralized. Sector 7-G is secure." : "Tactical systems offline. Fleet is retreating."}
            </p>
            <button onclick="returnToStarbase()" 
                    style="background: ${isWin ? '#2ecc71' : '#ff5c5c'}; 
                           color: white; 
                           border: none; 
                           padding: 20px 40px; 
                           font-size: 1.2rem; 
                           border-radius: 5px; 
                           cursor: pointer;
                           font-weight: bold;
                           text-transform: uppercase;">
                Return to Starbase
            </button>
        </div>
    `;

    document.body.appendChild(overlay);
    console.log("Overlay successfully appended to body.");
}

// Clears the current game session but keeps the persistent player ID
// so Mission Intel (wins/losses) survives across games and page refreshes.
function returnToStarbase() {
    const persistentPlayerId = localStorage.getItem("currentPlayerId");
    const persistentPlayerName = localStorage.getItem("persistentPlayerName");

    // Wipe game-specific data only
    localStorage.removeItem("currentGameId");
    localStorage.removeItem("cpuPlayerId");

    // Re-save the player identity so stats load on next page load
    if (persistentPlayerId) {
        localStorage.setItem("currentPlayerId", persistentPlayerId);
    }
    if (persistentPlayerName) {
        localStorage.setItem("persistentPlayerName", persistentPlayerName);
    }

    window.location.reload();
}

async function updateStatsBox(id = null) {
    const targetId = id || playerId || localStorage.getItem("currentPlayerId");
    if (!targetId) return; // Keep defaults if no ID is known

    try {
        const res = await fetch(`/api/players/${targetId}/stats`);
        if (!res.ok) return;
        
        const stats = await res.json();
        const statsBox = document.getElementById("stats-container");
        if (!statsBox) return;

        // Force render the updated stats over the defaults
        statsBox.innerHTML = `
            <div style="border: 1px solid #4a9eff; padding: 15px; background: rgba(13, 18, 34, 0.8); color: white; border-radius: 5px; font-family: monospace;">
                <h3 style="color: #4a9eff; margin-top: 0; border-bottom: 1px solid #4a9eff; font-size: 1rem;">TACTICAL ARCHIVE</h3>
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <span>MISSIONS WON:</span> <span style="color: #2ecc71; font-weight: bold;">${stats.wins || 0}</span>
                </div>
                <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                    <span>MISSIONS LOST:</span> <span style="color: #ff5c5c; font-weight: bold;">${stats.losses || 0}</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>FIRE ACCURACY:</span> <span style="color: #ffcc66; font-weight: bold;">${((stats.accuracy || 0) * 100).toFixed(1)}%</span>
                </div>
            </div>
        `;
    } catch (err) {
        console.error("Stats update failed:", err);
    }
}
