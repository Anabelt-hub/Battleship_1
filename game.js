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
    // 1. Create YOUR Player
    const pRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: "Captain Gabbie" })
    });
    const pData = await pRes.json();
    playerId = pData.player_id;

    // 2. Create the Game
    const gRes = await fetch('/api/games', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ grid_size: 10 })
    });
    const gData = await gRes.json();
    gameId = gData.game_id;

    // Save IDs immediately for index.html to find
    localStorage.setItem('currentPlayerId', playerId);
    localStorage.setItem('currentGameId', gameId);

    // 3. YOU Join the Game
    await fetch(`/api/games/${gameId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId })
    });

    // --- CPU AUTOMATION: Ensure 2 players are ready ---
    
    // A. Create CPU Player
    const cpuRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: "Borg Cube" })
    });
    const cpuData = await cpuRes.json();
    const cpuId = cpuData.player_id;
    localStorage.setItem('cpuPlayerId', cpuId); // Needed for Reveal/Scan

    // B. CPU Joins Game
    await fetch(`/api/games/${gameId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: cpuId })
    });

    // C. CPU Places Ships via TEST MODE
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

    // 4. Start YOUR Placement Phase
    isPlacementMode = true;
    selectedShips = [];
    gameStatus = "waiting";
    setStatus("Placement Mode: Select 3 sectors on your board to station your fleet.");
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
    if (selectedShips.length !== 3) {
        alert("Tactical Error: Select exactly 3 sectors.");
        return;
    }

    // 1. Submit your ships FIRST
    const res = await fetch(`/api/games/${gameId}/place`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId, ships: selectedShips })
    });

    if (res.ok) {
        setStatus("Fleet deployed. Authorizing CPU battle stations...");
        
        // 2. WAIT for the CPU to fully join and place
        await setupCPUOpponent(); 
        
        // 3. ONLY THEN start looking for the 'active' status
        isPlacementMode = false;
        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;
        pollForActivation(); 
    } else {
        const err = await res.json();
        alert("Placement Error: " + err.error);
    }
}

async function setupCPUOpponent() {
    // 1. Create a fresh CPU Player
    const cpuRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: "Borg Cube" })
    });
    const cpuData = await cpuRes.json();
    const cpuId = parseInt(cpuData.player_id); // Ensure it is an integer

    // 2. CPU MUST Join this specific game first
    await fetch(`/api/games/${gameId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: cpuId })
    });

    // 3. Place CPU ships via Test Mode
    const testRes = await fetch(`/api/test/games/${gameId}/ships`, {
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
    
    // Debug: Check if the server actually accepted the CPU ships
    const testData = await testRes.json();
    console.log("CPU Placement Status:", testData);
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
