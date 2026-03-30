const SIZE = 10;
const MAX_PLACEMENT_SHIPS = 3;

let gameId = null;
let playerId = null;
let isPlacementMode = false;
let selectedShips = []; 
let gameStatus = "waiting";

const statusEl = document.getElementById("status");
const playerBoardEl = document.getElementById("playerBoard");
const cpuBoardEl = document.getElementById("cpuBoard");
const btnNewGame = document.getElementById("btnNewGame");
const btnConfirmPlacement = document.getElementById("btnConfirmPlacement");

btnNewGame.addEventListener("click", startNewMission);

if (btnConfirmPlacement) {
    btnConfirmPlacement.addEventListener("click", submitPlacement);
}

// Helper to generate 3 random, connected coordinates
function generateRandomShips() {
    const vertical = Math.random() > 0.5;
    const startRow = Math.floor(Math.random() * (vertical ? SIZE - 3 : SIZE));
    const startCol = Math.floor(Math.random() * (vertical ? SIZE : SIZE - 3));

    if (vertical) {
        return [
            {row: startRow, col: startCol},
            {row: startRow + 1, col: startCol},
            {row: startRow + 2, col: startCol}
        ];
    } else {
        return [
            {row: startRow, col: startCol},
            {row: startRow, col: startCol + 1},
            {row: startRow, col: startCol + 2}
        ];
    }
}

async function startNewMission() {
    const pRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: "Captain Gabbie" })
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
    gameStatus = "waiting";
    setStatus("Placement Mode: Select 3 sectors, then click Confirm.");
    renderPlacementBoard();
}

async function setupCPUOpponent(currentGId) {
    const cpuRes = await fetch('/api/players', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ username: "Borg Cube" })
    });
    const cpuData = await cpuRes.json();
    const cpuId = cpuData.player_id;

    localStorage.setItem('cpuPlayerId', cpuId); 

    await fetch(`/api/games/${currentGId}/join`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: cpuId })
    });

    // --- RANDOMIZED CPU PLACEMENT ---
    const randomShips = generateRandomShips();

    await fetch(`/api/test/games/${currentGId}/ships`, {
        method: 'POST',
        headers: { 
            'Content-Type': 'application/json',
            'X-Test-Password': 'clemson-test-2026' 
        },
        body: JSON.stringify({ 
            player_id: cpuId, 
            ships: randomShips 
        })
    });
}

async function submitPlacement() {
    const currentGId = localStorage.getItem('currentGameId');
    const currentPId = localStorage.getItem('currentPlayerId');

    if (!currentGId || !currentPId) return alert("Mission Data Error.");
    if (selectedShips.length !== 3) return alert("Select exactly 3 sectors.");

    const res = await fetch(`/api/games/${currentGId}/place`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            player_id: parseInt(currentPId), 
            ships: selectedShips 
        })
    });

    if (res.ok) {
        isPlacementMode = false;
        if (btnConfirmPlacement) btnConfirmPlacement.disabled = true;
        setStatus("Fleet deployed. Battle stations!");
        pollForActivation();
    } else {
        const err = await res.json();
        alert("Placement Error: " + err.error);
    }
}

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
    const index = selectedShips.findIndex(s => s.row === r && s.col === c);
    if (index > -1) {
        selectedShips.splice(index, 1);
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
    if (gameStatus !== "active") return;

    const res = await fetch(`/api/games/${gameId}/fire`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: playerId, row: r, col: c })
    });

    const data = await res.json();
    
    if (data.result === "hit") {
        cell.classList.add("hit");
        setStatus(`Direct hit at ${String.fromCharCode(65+c)}${r+1}!`);
    } else {
        cell.classList.add("miss");
        setStatus(`Phasers missed at ${String.fromCharCode(65+c)}${r+1}.`);
    }

    // --- VICTORY ALERT ---
    if (data.game_status === "finished") {
        gameStatus = "finished";
        setStatus("MISSION ACCOMPLISHED: Enemy fleet neutralized!");
        setTimeout(() => alert("🎉 VICTORY! You have defeated the Borg Cube."), 500);
    } else {
        setTimeout(cpuTurn, 1000); 
    }
}

async function cpuTurn() {
    if (gameStatus !== "active") return;

    const cpuId = parseInt(localStorage.getItem('cpuPlayerId'));
    const randomRow = Math.floor(Math.random() * SIZE);
    const randomCol = Math.floor(Math.random() * SIZE);

    const res = await fetch(`/api/games/${gameId}/fire`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ player_id: cpuId, row: randomRow, col: randomCol })
    });

    const data = await res.json();
    const playerCells = playerBoardEl.getElementsByClassName("cell");
    const targetCell = playerCells[randomRow * SIZE + randomCol];

    if (data.result === "hit") {
        targetCell.classList.add("hit");
        setStatus(`Borg Strike! We've been hit at ${String.fromCharCode(65+randomCol)}${randomRow+1}!`);
    } else {
        targetCell.classList.add("miss");
        setStatus(`Borg phasers missed wide at ${String.fromCharCode(65+randomCol)}${randomRow+1}.`);
    }

    // --- DEFEAT ALERT ---
    if (data.game_status === "finished") {
        gameStatus = "finished";
        setStatus("CRITICAL FAILURE: The Federation fleet has been destroyed.");
        setTimeout(() => alert("💀 GAME OVER: The Borg Cube has won."), 500);
    }
}

function setStatus(msg) {
    const logEl = document.getElementById("log");
    const entry = document.createElement("div");
    entry.className = "log-entry";
    const now = new Date();
    const timeStr = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    entry.innerHTML = `<span class="muted">[${timeStr}]</span> ${msg}`;
    if (logEl) {
        logEl.appendChild(entry);
        logEl.scrollTop = logEl.scrollHeight;
    }
    if (statusEl) statusEl.textContent = msg;
}

document.getElementById('btnReveal').addEventListener('click', async () => {
    const gId = localStorage.getItem('currentGameId');
    const cpuId = localStorage.getItem('cpuPlayerId');
    const response = await fetch(`/api/test/games/${gId}/board/${cpuId}`, {
        headers: { 'X-Test-Password': 'clemson-test-2026' }
    });

    if (response.ok) {
        const data = await response.json();
        data.ships.forEach(s => {
            const cell = document.getElementById(`cpu-cell-${s.row}-${s.col}`);
            if (cell) cell.classList.add('ship-selected');
        });
        setStatus("Long-range sensors bypass cloaking. Enemy positions revealed!");
    }
});
