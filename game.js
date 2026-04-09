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
const logEl = document.getElementById("log"); // Target the log panel
const btnNewGame = document.getElementById("btnNewGame");
const btnConfirmPlacement = document.getElementById("btnConfirmPlacement");

btnNewGame.addEventListener("click", startNewMission);

if (btnConfirmPlacement) {
    btnConfirmPlacement.addEventListener("click", submitPlacement);
}

// --- LOGGING UTILITY ---
function addToLog(message, type = "") {
    if (!logEl) return;
    const entry = document.createElement("div");
    const time = new Date().toLocaleTimeString([], { hour12: false, hour: '2-digit', minute: '2-digit', second: '2-digit' });
    
    let spanClass = "";
    if (type === "hit") spanClass = "hitTxt"; // Matches styles.css
    if (type === "miss") spanClass = "missTxt";

    entry.innerHTML = `<span class="muted">[${time}]</span> <span class="${spanClass}">${message}</span>`;
    logEl.prepend(entry); // Newest battle entries at the top
}

function generateRandomShips() {
    const vertical = Math.random() > 0.5;
    const startRow = Math.floor(Math.random() * (vertical ? SIZE - 3 : SIZE));
    const startCol = Math.floor(Math.random() * (vertical ? SIZE : SIZE - 3));
    return vertical ? 
        [{row: startRow, col: startCol}, {row: startRow + 1, col: startCol}, {row: startRow + 2, col: startCol}] :
        [{row: startRow, col: startCol}, {row: startRow, col: startCol + 1}, {row: startRow, col: startCol + 2}];
}

async function startNewMission() {
    if (logEl) logEl.innerHTML = ""; // Clear log for new mission
    addToLog("Initializing Starfleet Tactical computer...");

    const pRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: "Captain_Gabbie" })
    });
    const pData = await pRes.json();
    playerId = pData.player_id;

    const gRes = await fetch('/api/games', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ grid_size: 10 })
    });
    const gData = await gRes.json();
    gameId = gData.game_id;

    localStorage.setItem('currentPlayerId', playerId);
    localStorage.setItem('currentGameId', gameId);

    await fetch(`/api/games/${gameId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId })
    });

    await setupCPUOpponent(gameId); 

    isPlacementMode = true;
    selectedShips = [];
    gameStatus = "waiting_setup";
    setStatus("Placement Mode: Select 3 sectors, then click Confirm.");
    addToLog("Mission assigned. Sector grid ready for ship deployment.");
    renderPlacementBoard();
}

async function setupCPUOpponent(currentGId) {
    const cpuRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: "Borg Cube" })
    });
    const cpuId = (await cpuRes.json()).player_id;
    localStorage.setItem('cpuPlayerId', cpuId); 

    await fetch(`/api/games/${currentGId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: cpuId })
    });

    await fetch(`/api/test/games/${currentGId}/ships`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Test-Password': 'clemson-test-2026' },
        body: JSON.stringify({ player_id: cpuId, ships: generateRandomShips() })
    });
}

async function submitPlacement() {
    if (selectedShips.length !== 3) return alert("Select 3 sectors.");
    
    const currentGId = localStorage.getItem('currentGameId');
    const currentPId = localStorage.getItem('currentPlayerId');

    const res = await fetch(`/api/games/${currentGId}/place`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: parseInt(currentPId), ships: selectedShips })
    });

    if (res.ok) {
        isPlacementMode = false;
        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;
        setStatus("Fleet deployed. Battle stations!");
        addToLog("Federation fleet has exited warp and taken positions.");
        pollForActivation();
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
        cell.classList.remove("ship-selected"); //
    } else if (selectedShips.length < MAX_PLACEMENT_SHIPS) {
        selectedShips.push({ row: r, col: c });
        cell.classList.add("ship-selected");
    }
    if (btnConfirmPlacement) btnConfirmPlacement.disabled = (selectedShips.length !== MAX_PLACEMENT_SHIPS);
}

// REVISED: Shows your ships during battle
function renderBattleBoards() {
    // Render Player Board with your ship markers
    playerBoardEl.innerHTML = "";
    for (let r = 0; r < SIZE; r++) {
        for (let c = 0; c < SIZE; c++) {
            const cell = document.createElement("div");
            cell.className = "cell";
            if (selectedShips.some(s => s.row === r && s.col === c)) {
                cell.classList.add("ship"); // CSS provides yellow glow
            }
            playerBoardEl.appendChild(cell);
        }
    }

    // Render Enemy Board
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
    if (gameStatus !== "playing" || cell.classList.contains("hit") || cell.classList.contains("miss")) return;
    
    const res = await fetch(`/api/games/${gameId}/fire`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId, row: r, col: c })
    });
    const data = await res.json();
    
    cell.classList.add(data.result === "hit" ? "hit" : "miss");
    addToLog(`Tactical: Phasers fired at Sector ${r},${c} - ${data.result.toUpperCase()}`, data.result);
    
    if (data.game_status === "finished") {
        gameStatus = "finished";
        addToLog("VICTORY: Enemy fleet neutralized. Returning to Starbase.", "hit");
        alert("🎉 VICTORY! Enemy fleet neutralized.");
    } else {
        setTimeout(cpuTurn, 800); 
    }
}

async function cpuTurn() {
    if (gameStatus !== "playing") return;
    const cpuId = parseInt(localStorage.getItem('cpuPlayerId'));
    const r = Math.floor(Math.random() * SIZE), c = Math.floor(Math.random() * SIZE);
    
    const res = await fetch(`/api/games/${gameId}/fire`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: cpuId, row: r, col: c })
    });
    const data = await res.json();
    
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
}

function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
}
