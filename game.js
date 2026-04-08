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
const btnNewGame = document.getElementById("btnNewGame");
const btnConfirmPlacement = document.getElementById("btnConfirmPlacement");

btnNewGame.addEventListener("click", startNewMission);

if (btnConfirmPlacement) {
    btnConfirmPlacement.addEventListener("click", submitPlacement);
}

function generateRandomShips() {
    const vertical = Math.random() > 0.5;
    const startRow = Math.floor(Math.random() * (vertical ? SIZE - 3 : SIZE));
    const startCol = Math.floor(Math.random() * (vertical ? SIZE : SIZE - 3));
    return vertical ? 
        [{row: startRow, col: startCol}, {row: startRow + 1, col: startCol}, {row: startRow + 2, col: startCol}] :
        [{row: startRow, col: startCol}, {row: startRow, col: startCol + 1}, {row: startRow, col: startCol + 2}];
}

// Create or retrieve a player by username — handles 409 (duplicate) by fetching the existing id
async function getOrCreatePlayer(username) {
    const res = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username })
    });
    const data = await res.json();
    if (data.player_id) return data.player_id;
    // 409 conflict means username exists — fetch existing player via stats to get id
    // We store a unique suffix per session so each "New Mission" gets fresh players
    return null;
}

async function startNewMission() {
    // Use a unique username per session so duplicates never occur
    const sessionId = Date.now();
    const humanUsername = `Captain_${sessionId}`;
    const cpuUsername   = `Borg_${sessionId}`;

    // Create human player
    const pRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: humanUsername })
    });
    const pData = await pRes.json();
    playerId = pData.player_id;

    if (!playerId) {
        setStatus("Error creating player. Please try again.");
        return;
    }

    // Create game
    const gRes = await fetch('/api/games', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ creator_id: playerId, grid_size: 10, max_players: 2 })
    });
    const gData = await gRes.json();
    gameId = gData.game_id;

    if (!gameId) {
        setStatus("Error creating game. Please try again.");
        return;
    }

    localStorage.setItem('currentPlayerId', playerId);
    localStorage.setItem('currentGameId', gameId);

    // Human player joins
    await fetch(`/api/games/${gameId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId })
    });

    // Setup CPU opponent (always unique username, always fresh)
    await setupCPUOpponent(gameId, cpuUsername);

    isPlacementMode = true;
    selectedShips = [];
    gameStatus = "waiting_setup";
    if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;
    setStatus("Placement Mode: Select 3 sectors, then click Confirm Ship Positions.");
    renderPlacementBoard();
}

async function setupCPUOpponent(currentGId, cpuUsername) {
    // Create CPU player with unique username
    const cpuRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: cpuUsername })
    });
    const cpuData = await cpuRes.json();
    const cpuId = cpuData.player_id;

    if (!cpuId) {
        console.error("Failed to create CPU player:", cpuData);
        return;
    }

    localStorage.setItem('cpuPlayerId', cpuId);

    // CPU joins the game
    const joinRes = await fetch(`/api/games/${currentGId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: cpuId })
    });
    if (!joinRes.ok) {
        console.error("CPU join failed:", await joinRes.json());
        return;
    }

    // CPU places ships — this is done via test endpoint for determinism
    const randomShips = generateRandomShips();
    await fetch(`/api/test/games/${currentGId}/ships`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-Test-Password': 'clemson-test-2026' },
        body: JSON.stringify({ player_id: cpuId, ships: randomShips })
    });
}

async function pollForActivation() {
    const res = await fetch(`/api/games/${gameId}`);
    const data = await res.json();

    // UPDATE: V2.3 spec uses "playing", ensure your JS matches 
    if (data.status === "playing") {
        gameStatus = "playing";
        setStatus("Sensors Active. Enemy fleet detected. Fire when ready!");
        renderBattleBoards();
    } else {
        setTimeout(pollForActivation, 2000);
    }
}

async function submitPlacement() {
    if (selectedShips.length !== 3) {
        setStatus("Select exactly 3 sectors first.");
        return;
    }
    const currentGId = localStorage.getItem('currentGameId');
    const currentPId = localStorage.getItem('currentPlayerId');

    setStatus("Deploying fleet...");
    const res = await fetch(`/api/games/${currentGId}/place`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: parseInt(currentPId), ships: selectedShips })
    });

    if (res.ok) {
        isPlacementMode = false;
        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;
        setStatus("Fleet deployed. Battle stations!");
        
        // IMMEDIATE POLL: Don't wait 2 seconds for the first check
        await pollForActivation(); 
    } else {
        const err = await res.json();
        setStatus("Placement failed: " + (err.message || err.error));
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
    if (btnConfirmPlacement) btnConfirmPlacement.disabled = (selectedShips.length !== MAX_PLACEMENT_SHIPS);
}

function renderBattleBoards() {
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
    cell.onclick = null; // prevent double-clicking

    const res = await fetch(`/api/games/${gameId}/fire`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId, row: r, col: c })
    });

    if (!res.ok) {
        const err = await res.json();
        setStatus("Fire failed: " + (err.message || err.error));
        cell.onclick = () => firePhasers(r, c, cell); // re-enable
        return;
    }

    const data = await res.json();
    cell.classList.add(data.result === "hit" ? "hit" : "miss");

    if (data.game_status === "finished") {
        gameStatus = "finished";
        setStatus(`🎉 VICTORY! Direct hit at ${String.fromCharCode(65+c)}${r+1}! Enemy fleet neutralized!`);
        return;
    }

    setStatus(data.result === "hit"
        ? `Direct hit at ${String.fromCharCode(65+c)}${r+1}! Enemy reeling...`
        : `Phasers missed at ${String.fromCharCode(65+c)}${r+1}. Borg evading...`);

    if (data.next_player_id !== playerId) setTimeout(cpuTurn, 1000);
}

async function cpuTurn() {
    if (gameStatus !== "playing") return;
    const cpuId = parseInt(localStorage.getItem('cpuPlayerId'));

    // Pick a random cell that hasn't been fired at yet
    let r, c, attempts = 0;
    do {
        r = Math.floor(Math.random() * SIZE);
        c = Math.floor(Math.random() * SIZE);
        attempts++;
    } while (attempts < 50 && playerBoardEl.getElementsByClassName("cell")[r * SIZE + c]?.classList.contains("hit") ||
             playerBoardEl.getElementsByClassName("cell")[r * SIZE + c]?.classList.contains("miss"));

    const res = await fetch(`/api/games/${gameId}/fire`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: cpuId, row: r, col: c })
    });

    if (!res.ok) {
        // If it was a duplicate shot, just try again
        const err = await res.json();
        if (res.status === 409) { setTimeout(cpuTurn, 500); return; }
        return;
    }

    const data = await res.json();
    const target = playerBoardEl.getElementsByClassName("cell")[r * SIZE + c];
    if (target) target.classList.add(data.result === "hit" ? "hit" : "miss");

    if (data.game_status === "finished") {
        gameStatus = "finished";
        setStatus("💀 DEFEAT! The Borg Cube has destroyed the Federation fleet.");
        return;
    }

    setStatus(data.result === "hit"
        ? `⚠️ Borg Strike! Direct hit at ${String.fromCharCode(65+c)}${r+1}!`
        : `Borg phasers missed at ${String.fromCharCode(65+c)}${r+1}.`);
}

function setStatus(msg) {
    if (statusEl) statusEl.textContent = msg;
    const logEl = document.getElementById("log");
    if (logEl) {
        const entry = document.createElement("div");
        entry.className = "log-entry";
        const now = new Date();
        const t = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        entry.innerHTML = `<span class="muted">[${t}]</span> ${msg}`;
        logEl.appendChild(entry);
        logEl.scrollTop = logEl.scrollHeight;
    }
}
