const SIZE = 10;
// Note: Checkpoint B requires exactly 3 single-cell ships
const MAX_PLACEMENT_SHIPS = 3;

// --- State Variables ---
let gameId = null;
let playerId = null;
let isPlacementMode = false;
let selectedShips = []; // Track manual clicks
let gameStatus = "waiting";

// --- Elements ---
const statusEl = document.getElementById("status");
const playerBoardEl = document.getElementById("playerBoard");
const cpuBoardEl = document.getElementById("cpuBoard");
const btnNewGame = document.getElementById("btnNewGame");
const btnConfirmPlacement = document.getElementById("btnConfirmPlacement");

// --- Initialization ---

btnNewGame.addEventListener("click", startNewMission);

if (btnConfirmPlacement) {
    btnConfirmPlacement.addEventListener("click", submitPlacement);
}

async function startNewMission() {
    // 1. Create Your Player
    const pRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: "Captain Gabbie" })
    });
    const pData = await pRes.json();
    playerId = pData.player_id;

    // 2. Create Game
    const gRes = await fetch('/api/games', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ grid_size: SIZE })
    });
    const gData = await gRes.json();
    gameId = gData.game_id;

    localStorage.setItem('currentPlayerId', playerId);
    localStorage.setItem('currentGameId', gameId);

    // 3. Join Game
    await fetch(`/api/games/${gameId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId })
    });

    // --- NEW: Add a CPU Player so the game can start! ---
    // A. Create CPU Player
    const cpuRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: "Borg Cube" })
    });
    const cpuData = await cpuRes.json();
    const cpuId = cpuData.player_id;
    localStorage.setItem('cpuPlayerId', cpuId); // Store for the Scan/Reveal button

    // B. CPU Joins Game
    await fetch(`/api/games/${gameId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: cpuId })
    });

    // C. CPU Places Ships (Test Mode makes this easy)
    await fetch(`/api/test/games/${gameId}/ships`, {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-Test-Password': 'clemson-test-2026' 
        },
        body: JSON.stringify({ 
            player_id: cpuId, 
            ships: [{row:0, col:0}, {row:0, col:1}, {row:0, col:2}] 
        })
    });

    // 4. Enter Placement Mode for YOU
    isPlacementMode = true;
    selectedShips = [];
    gameStatus = "waiting";
    setStatus("Placement Mode: Select 3 sectors on your board.");
    renderPlacementBoard();
}
// --- Phase 1: Manual Placement Logic ---

function renderPlacementBoard() {
    playerBoardEl.innerHTML = "";
    cpuBoardEl.innerHTML = ""; // Clear enemy board during placement
    
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

    const index = selectedShips.findIndex(s => s.row === r && s.col === c);
    if (index > -1) {
        selectedShips.splice(index, 1);
        cell.classList.remove("ship-selected");
    } else if (selectedShips.length < MAX_PLACEMENT_SHIPS) {
        selectedShips.push({ row: r, col: c });
        cell.classList.add("ship-selected");
    }

    // Enable confirm button only if exactly 3 are picked
    if (btnConfirmPlacement) {
        btnConfirmPlacement.disabled = (selectedShips.length !== MAX_PLACEMENT_SHIPS);
    }
}

async function submitPlacement() {
    const res = await fetch(`/api/games/${gameId}/place`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId, ships: selectedShips })
    });

    if (res.ok) {
        isPlacementMode = false;
        btnConfirmPlacement.disabled = true;
        setStatus("Fleet deployed. Waiting for server to authorize battle...");
        pollForActivation(); // Wait for the game to become "active"
    } else {
        const err = await res.json();
        alert("Error: " + err.error);
    }
}

// --- Phase 2: Battle Logic ---

async function pollForActivation() {
    const res = await fetch(`/api/games/${gameId}`);
    const data = await res.json();

    if (data.status === "active") {
        gameStatus = "active";
        setStatus("Sensors Active. Enemy fleet detected. Fire when ready!");
        renderBattleBoards();
    } else {
        setTimeout(pollForActivation, 2000);
    }
}

function renderBattleBoards() {
    cpuBoardEl.innerHTML = "";
    for (let r = 0; r < SIZE; r++) {
        for (let c = 0; c < SIZE; c++) {
            const cell = document.createElement("button");
            cell.className = "cell";
            // Important: Add an ID so the Reveal/Scan button can find it
            cell.id = `cpu-cell-${r}-${c}`; 
            cell.onclick = () => firePhasers(r, c, cell);
            cpuBoardEl.appendChild(cell);
        }
    }
}

async function firePhasers(r, c, cell) {
    if (gameStatus !== "active") return;

    const res = await fetch(`/api/games/${gameId}/fire`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId, row: r, col: c })
    });

    const data = await res.json();
    
    if (res.status === 403 && data.error === "Out of turn") {
        setStatus("Wait your turn, Captain! Recharging phasers...");
        return;
    }

    if (data.result === "hit") {
        cell.classList.add("hit");
        setStatus(`Direct hit at ${String.fromCharCode(65+c)}${r+1}!`);
    } else {
        cell.classList.add("miss");
        setStatus(`Phasers missed at ${String.fromCharCode(65+c)}${r+1}.`);
    }

    if (data.game_status === "finished") {
        setStatus("Mission accomplished. Enemy fleet neutralized!");
        gameStatus = "finished";
    }
}

function setStatus(msg) {
    statusEl.textContent = msg;
}
