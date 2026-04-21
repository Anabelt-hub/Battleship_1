// ═══════════════════════════════════════════
// STARFLEET TACTICAL — GAME ENGINE v3.0
// ═══════════════════════════════════════════

// ── CONFIG ──
const GRID_SIZE  = 10;
const SHIPS = [
  { name: "Carrier",    size: 5, icon: "🛸" },
  { name: "Battleship", size: 4, icon: "⚔" },
  { name: "Destroyer",  size: 3, icon: "🚀" }
];
const TOTAL_SHIP_CELLS = SHIPS.reduce((a,s) => a + s.size, 0); // 12

// ── STATE ──
let BASE_URL = "https://battleship-1-qpm6.onrender.com";
let playerId  = null;
let gameId    = null;
let gameGridSize = GRID_SIZE;
let isMyTurn  = false;
let pollHandle = null;
let gameOver  = false;

// Placement state
let placementShipIdx = 0;    // which ship we're placing (0=Carrier, 1=Battleship, 2=Destroyer)
let placedCells = [];         // [{row,col,shipIdx}]
let currentShipCells = [];    // cells selected for the current ship in progress

// Game tracking
let myMoves = [];            // {row,col,result}
let opponentMoves = [];      // {row,col,result}
let myShips = [];            // [{row,col}] — where I placed ships
let captainsLog = [];

// ── DOM shortcuts ──
const $ = id => document.getElementById(id);
const setStatus = msg => { $('status').textContent = msg; };

// ── SCREEN NAVIGATION ──
function navigateTo(id) {
  document.querySelectorAll('.screen').forEach(s => {
    s.classList.remove('active');
    s.classList.add('hidden');
  });
  const el = $(id);
  el.classList.remove('hidden');
  el.classList.add('active');
}

// ── THEME TOGGLE ──
$('themeToggle').onclick = () => {
  const isLight = document.body.classList.toggle('light');
  $('themeToggle').textContent = isLight ? '🌙 Dark Mode' : '☀ Light Mode';
};

// ═══════════════════════════════════════════
// SCREEN 1 — SERVER SELECTION
// ═══════════════════════════════════════════
$('btnConnectServer').onclick = async () => {
  const custom = $('customServerUrl').value.trim();
  const selected = $('serverSelect').value;
  BASE_URL = (custom || selected).replace(/\/$/, '');

  showStatusBadge('serverStatus', 'info', `⏳ Connecting to ${BASE_URL}…`);
  setStatus(`Connecting to ${BASE_URL}…`);

  try {
    // Try /api/health; if that fails try /api — if still fails, warn but allow through
    let ok = false;
    try {
      const r = await fetch(`${BASE_URL}/api/health`, { signal: AbortSignal.timeout(5000) });
      ok = r.ok;
    } catch {
      try {
        const r = await fetch(`${BASE_URL}/api`, { signal: AbortSignal.timeout(5000) });
        ok = r.ok;
      } catch { ok = false; }
    }

    if (ok) {
      showStatusBadge('serverStatus', 'ok', `✅ Connected to ${BASE_URL}`);
    } else {
      showStatusBadge('serverStatus', 'err', `⚠ Server responded but health check failed — proceeding anyway`);
    }

    $('activeServerUrl').textContent = `⚡ ${BASE_URL}`;
    setStatus(`Connected to ${BASE_URL}`);

    // Small delay so the user sees the status, then navigate
    setTimeout(() => navigateTo('screen-login'), 800);

  } catch (err) {
    showStatusBadge('serverStatus', 'err', `❌ Cannot reach server — check URL and try again`);
    setStatus('Connection failed.');
  }
};

// ═══════════════════════════════════════════
// SCREEN 2 — LOGIN
// ═══════════════════════════════════════════
$('btnLogin').onclick = async () => {
  const username = $('playerName').value.trim();
  if (!username) { showStatusBadge('loginStatus','err','⚠ Please enter a username.'); return; }
  if (!/^[A-Za-z0-9_]+$/.test(username)) {
    showStatusBadge('loginStatus','err','⚠ Username can only contain letters, numbers, and underscores.');
    return;
  }

  showStatusBadge('loginStatus','info','⏳ Logging in…');
  setStatus('Logging in…');

  try {
    const res = await apiFetch(`/api/players`, 'POST', { username });

    if (res.status === 201) {
      // New player created
      const data = await res.json();
      playerId = data.player_id;
      localStorage.setItem('playerId', playerId);
      localStorage.setItem('playerName', username);
      showStatusBadge('loginStatus','ok',`✅ New profile created! Welcome, ${username}!`);
      setStatus(`Welcome, ${username}! Player ID: ${playerId}`);
    } else if (res.status === 409) {
      // Username taken — we need to look up this player
      // Try to find them: the API returns 409 for duplicates
      // We'll store the username and look up their stats to confirm
      showStatusBadge('loginStatus','err',`❌ Username "${username}" is already taken. Please choose another.`);
      return;
    } else {
      const data = await res.json();
      showStatusBadge('loginStatus','err', `❌ ${data.message || 'Login failed.'}`);
      return;
    }

    setTimeout(async () => {
      navigateTo('screen-lobby');
      await refreshLobby();
    }, 600);

  } catch (err) {
    showStatusBadge('loginStatus','err','❌ Could not reach server.');
    setStatus('Login failed.');
  }
};

// ═══════════════════════════════════════════
// SCREEN 3 — LOBBY
// ═══════════════════════════════════════════
$('btnCreateRoom').onclick = async () => {
  const grid = parseInt($('gridSizeInput').value) || 10;
  const maxP = parseInt($('maxPlayersInput').value) || 2;

  if (grid < 5 || grid > 15) { alert('Grid size must be between 5 and 15.'); return; }

  try {
    const res = await apiFetch('/api/games', 'POST', { creator_id: playerId, grid_size: grid, max_players: maxP });
    const data = await res.json();
    if (!res.ok) { alert(data.message || 'Failed to create room.'); return; }

    gameId = data.game_id;
    gameGridSize = grid;
    localStorage.setItem('gameId', gameId);
    setStatus(`Room #${gameId} created! Join confirmed.`);

    // Join the game as creator
    await apiFetch(`/api/games/${gameId}/join`, 'POST', { player_id: playerId });

    navigateTo('screen-placement');
    startPlacementMode();

  } catch (err) { alert('Error creating room: ' + err.message); }
};

$('btnJoinById').onclick = () => {
  const id = parseInt($('searchGameId').value);
  if (!id) { alert('Please enter a Room ID.'); return; }
  joinGameById(id);
};

$('btnRefreshLobby').onclick = refreshLobby;

async function refreshLobby() {
  const listEl = $('gameList');
  listEl.innerHTML = '<p class="list-empty">Loading rooms…</p>';

  try {
    const res = await apiFetch('/api/games');
    const games = await res.json();

    if (!Array.isArray(games) || games.length === 0) {
      listEl.innerHTML = '<p class="list-empty">No active rooms found. Create one!</p>';
      return;
    }

    listEl.innerHTML = games.map(g => {
      const statusLabel = g.status === 'waiting_setup' ? 'waiting'
                        : g.status === 'playing'       ? 'playing'
                        : 'finished';
      const canJoin = g.status === 'waiting_setup';
      return `
        <div class="game-item">
          <div class="game-item-info">
            <div class="game-item-id">Room #${g.game_id}</div>
            <div class="game-item-meta">Grid: ${g.grid_size}×${g.grid_size} · Max: ${g.max_players} players</div>
          </div>
          <div style="display:flex;gap:8px;align-items:center;">
            <span class="badge-status ${statusLabel}">${statusLabel}</span>
            ${canJoin
              ? `<button class="btn-secondary" onclick="joinGameById(${g.game_id})" style="padding:8px 16px;font-size:12px;">Join</button>`
              : ''}
          </div>
        </div>`;
    }).join('');
  } catch (err) {
    listEl.innerHTML = '<p class="list-empty">Error loading rooms.</p>';
  }
}

async function joinGameById(id) {
  try {
    // Fetch game details first
    const gRes = await apiFetch(`/api/games/${id}`);
    if (!gRes.ok) { alert(`Room #${id} not found.`); return; }
    const gData = await gRes.json();

    if (gData.status === 'finished') { alert(`Room #${id} is finished and cannot be joined.`); return; }

    gameId = id;
    gameGridSize = gData.grid_size || GRID_SIZE;
    localStorage.setItem('gameId', gameId);

    const res = await apiFetch(`/api/games/${gameId}/join`, 'POST', { player_id: playerId });
    if (!res.ok) {
      const d = await res.json();
      // If already in game, that's fine — just proceed
      if (!d.message?.includes('already')) {
        alert(d.message || 'Could not join room.');
        return;
      }
    }

    setStatus(`Joined Room #${gameId}`);
    navigateTo('screen-placement');
    startPlacementMode();

  } catch (err) { alert('Error joining room: ' + err.message); }
}

// ═══════════════════════════════════════════
// SCREEN 4 — SHIP PLACEMENT
// ═══════════════════════════════════════════
function startPlacementMode() {
  placementShipIdx = 0;
  placedCells = [];
  currentShipCells = [];
  myShips = [];
  renderShipChecklist();
  renderPlacementBoard();
  $('btnConfirmPlacement').disabled = true;
  $('placementStatus').textContent = `Place your ${SHIPS[0].name} — ${SHIPS[0].size} cells`;
  $('placementStatus').style.color = 'var(--accent)';
}

$('btnResetPlacement').onclick = startPlacementMode;

function renderShipChecklist() {
  const list = $('shipChecklist');
  list.innerHTML = SHIPS.map((s, i) => {
    const done   = i < placementShipIdx;
    const active = i === placementShipIdx;
    const filled = done ? s.size : (active ? currentShipCells.length : 0);
    const squares = Array.from({length: s.size}, (_, j) =>
      `<div class="sq${j < filled ? ' filled' : ''}"></div>`
    ).join('');
    return `
      <div class="ship-row${done ? ' done' : active ? ' active' : ''}">
        <div class="ship-icon">${done ? '✅' : active ? '▶' : '○'}</div>
        <div class="ship-name">${s.icon} ${s.name} (${s.size})</div>
        <div class="ship-squares">${squares}</div>
      </div>`;
  }).join('');
}

function renderPlacementBoard() {
  buildBoard('placementBoard', gameGridSize, (r, c, cell) => {
    const placed = placedCells.find(p => p.row === r && p.col === c);
    const current = currentShipCells.find(p => p.row === r && p.col === c);
    if (placed)   cell.classList.add('ship-placed', 'no-click');
    if (current)  cell.classList.add('ship-placed');
    if (!placed)  cell.onclick = () => handlePlacementClick(r, c);
  });
}

function handlePlacementClick(row, col) {
  if (placementShipIdx >= SHIPS.length) return;

  // Toggle: clicking an already-selected current cell removes it
  const idx = currentShipCells.findIndex(c => c.row === row && c.col === col);
  if (idx > -1) {
    currentShipCells.splice(idx, 1);
    renderShipChecklist();
    renderPlacementBoard();
    $('placementStatus').textContent = `Select ${SHIPS[placementShipIdx].size - currentShipCells.length} more cells for ${SHIPS[placementShipIdx].name}`;
    return;
  }

  const target = SHIPS[placementShipIdx].size;
  if (currentShipCells.length >= target) return;

  // Validate adjacency if more than 0 cells selected (must be straight line)
  if (currentShipCells.length > 0) {
    const valid = isValidPlacement([...currentShipCells, {row, col}]);
    if (!valid) {
      $('placementStatus').textContent = '⚠ Ships must be placed in a straight line!';
      $('placementStatus').style.color = 'var(--danger)';
      setTimeout(() => {
        $('placementStatus').textContent = `Select ${target - currentShipCells.length} more cells for ${SHIPS[placementShipIdx].name}`;
        $('placementStatus').style.color = 'var(--accent)';
      }, 1500);
      return;
    }
  }

  currentShipCells.push({ row, col });

  if (currentShipCells.length === target) {
    // Ship complete — save it
    const shipIdx = placementShipIdx;
    currentShipCells.forEach(c => placedCells.push({ ...c, shipIdx }));
    myShips.push(...currentShipCells.map(c => ({row: c.row, col: c.col})));
    currentShipCells = [];
    placementShipIdx++;

    if (placementShipIdx < SHIPS.length) {
      $('placementStatus').textContent = `✅ ${SHIPS[placementShipIdx-1].name} placed! Now place your ${SHIPS[placementShipIdx].name} — ${SHIPS[placementShipIdx].size} cells`;
      $('placementStatus').style.color = 'var(--success)';
    } else {
      $('placementStatus').textContent = '✅ All ships placed! Ready to confirm.';
      $('placementStatus').style.color = 'var(--success)';
      $('btnConfirmPlacement').disabled = false;
    }
  } else {
    $('placementStatus').textContent = `Select ${target - currentShipCells.length} more cells for ${SHIPS[placementShipIdx].name}`;
    $('placementStatus').style.color = 'var(--accent)';
  }

  renderShipChecklist();
  renderPlacementBoard();
}

function isValidPlacement(cells) {
  if (cells.length < 2) return true;
  const rows = cells.map(c => c.row);
  const cols = cells.map(c => c.col);
  const sameRow = rows.every(r => r === rows[0]);
  const sameCol = cols.every(c => c === cols[0]);
  return sameRow || sameCol;
}

$('btnConfirmPlacement').onclick = async () => {
  if (placedCells.length !== TOTAL_SHIP_CELLS) {
    alert('Please place all ships before confirming.');
    return;
  }

  try {
    $('btnConfirmPlacement').disabled = true;
    $('placementStatus').textContent = '⏳ Deploying fleet to server…';

    const res = await apiFetch(`/api/games/${gameId}/place`, 'POST', {
      player_id: playerId,
      ships: myShips  // array of {row,col}
    });

    if (!res.ok) {
      const d = await res.json();
      $('placementStatus').textContent = `❌ Error: ${d.message}`;
      $('placementStatus').style.color = 'var(--danger)';
      $('btnConfirmPlacement').disabled = false;
      return;
    }

    $('placementStatus').textContent = '✅ Fleet deployed! Waiting for opponent…';
    setStatus('Fleet deployed. Waiting for battle to begin…');
    startWaitingForGame();

  } catch (err) {
    $('placementStatus').textContent = '❌ Network error — try again.';
    $('btnConfirmPlacement').disabled = false;
  }
};

function startWaitingForGame() {
  stopPolling();
  pollHandle = setInterval(async () => {
    try {
      const res = await apiFetch(`/api/games/${gameId}`);
      const data = await res.json();
      if (data.status === 'playing') {
        stopPolling();
        navigateTo('screen-game');
        initGameScreen(data);
        startGamePolling();
      }
    } catch (e) { /* keep waiting */ }
  }, 2000);
}

// ═══════════════════════════════════════════
// SCREEN 5 — BATTLE
// ═══════════════════════════════════════════
function initGameScreen(gameData) {
  myMoves = [];
  opponentMoves = [];
  captainsLog = [];
  gameOver = false;
  updateTurnBanner(gameData.current_turn_player_id);
  renderGameBoards();
  updateLiveStats();
}

function updateTurnBanner(currentTurnId) {
  const banner = $('turnBanner');
  isMyTurn = Number(currentTurnId) === Number(playerId);
  if (isMyTurn) {
    banner.className = 'turn-banner your-turn';
    banner.textContent = '⚡ YOUR TURN — Select a target on the Enemy Sector grid';
  } else if (currentTurnId === null) {
    banner.className = 'turn-banner waiting';
    banner.textContent = '⏳ Game over — calculating results…';
  } else {
    banner.className = 'turn-banner their-turn';
    banner.textContent = '🛡 OPPONENT\'S TURN — Brace for impact!';
  }
}

function renderGameBoards() {
  // My board — shows my ships + opponent's hits/misses on me
  buildBoard('activePlayerBoard', gameGridSize, (r, c, cell) => {
    const isShip = myShips.some(s => s.row === r && s.col === c);
    if (isShip) cell.classList.add('ship-placed');
    const shot = opponentMoves.find(m => m.row === r && m.col === c);
    if (shot) { cell.classList.add(shot.result); cell.classList.add('no-click'); }
  });

  // Enemy board — clickable when it's my turn
  buildBoard('activeEnemyBoard', gameGridSize, (r, c, cell) => {
    const shot = myMoves.find(m => m.row === r && m.col === c);
    if (shot) {
      cell.classList.add(shot.result, 'no-click');
    } else if (isMyTurn && !gameOver) {
      cell.onclick = () => fireAt(r, c);
    } else {
      cell.classList.add('no-click');
    }
  });
}

async function fireAt(row, col) {
  if (!isMyTurn || gameOver) return;

  // Optimistically disable the cell
  const boards = document.querySelectorAll('#activeEnemyBoard .cell');
  // re-render after response

  try {
    const res = await apiFetch(`/api/games/${gameId}/fire`, 'POST', {
      player_id: playerId, row, col
    });
    const data = await res.json();

    if (!res.ok) {
      addLog(data.message || 'Fire rejected', 'info');
      return;
    }

    myMoves.push({ row, col, result: data.result });

    const coord = `${String.fromCharCode(65+row)}-${col+1}`;
    addLog(`[${data.result.toUpperCase()}] You fired at ${coord}`, data.result);

    updateLiveStats();

    if (data.game_status === 'finished') {
      gameOver = true;
      updateTurnBanner(null);
      renderGameBoards();
      setTimeout(() => loadAndShowSummary(), 1500);
      return;
    }

    isMyTurn = false;
    updateTurnBanner(data.next_player_id);
    renderGameBoards();

  } catch (err) { addLog('Network error', 'info'); }
}

function startGamePolling() {
  stopPolling();
  pollHandle = setInterval(async () => {
    if (gameOver) { stopPolling(); return; }
    await pollGameState();
  }, 2000);
}

async function pollGameState() {
  try {
    const res = await apiFetch(`/api/games/${gameId}`);
    const gameData = await res.json();

    // Fetch full move list
    const movesRes = await apiFetch(`/api/games/${gameId}/moves`);
    const movesData = await movesRes.json();
    const allMoves = Array.isArray(movesData) ? movesData : (movesData.moves || []);

    // Separate my moves and opponent moves
    myMoves = allMoves.filter(m => Number(m.player_id) === Number(playerId))
                      .map(m => ({row: m.row, col: m.col, result: m.result}));
    opponentMoves = allMoves.filter(m => Number(m.player_id) !== Number(playerId))
                            .map(m => ({row: m.row, col: m.col, result: m.result}));

    updateTurnBanner(gameData.current_turn_player_id);
    renderGameBoards();
    updateLiveStats();

    if (gameData.status === 'finished') {
      gameOver = true;
      stopPolling();
      setTimeout(() => loadAndShowSummary(), 1500);
    }

  } catch (e) { /* silent */ }
}

function updateLiveStats() {
  const hits   = myMoves.filter(m => m.result === 'hit').length;
  const misses = myMoves.filter(m => m.result === 'miss').length;
  const total  = hits + misses;
  const acc    = total > 0 ? Math.round((hits/total)*100) : 0;

  $('statHits').textContent    = hits;
  $('statMisses').textContent  = misses;
  $('statAccuracy').textContent = `${acc}%`;
}

function addLog(msg, type = 'info') {
  const entry = { msg, type, time: new Date().toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'}) };
  captainsLog.push(entry);

  const logEl = $('log');
  const div = document.createElement('div');
  div.className = `log-entry ${type}`;
  div.textContent = `[${entry.time}] ${msg}`;
  logEl.appendChild(div);
  logEl.scrollTop = logEl.scrollHeight;
}

// ═══════════════════════════════════════════
// SCREEN 6 — SUMMARY
// ═══════════════════════════════════════════
async function loadAndShowSummary() {
  try {
    const gRes = await apiFetch(`/api/games/${gameId}`);
    const gameData = await gRes.json();
    showSummary(gameData);
  } catch (e) {
    showSummary({ winner_id: null });
  }
}

async function showSummary(gameData) {
  navigateTo('screen-summary');

  const isWin = Number(gameData.winner_id) === Number(playerId);
  const resultEl = $('missionResult');
  resultEl.textContent = isWin ? '🏆 MISSION ACCOMPLISHED' : '💀 MISSION FAILURE';
  resultEl.className = `mission-result ${isWin ? 'victory' : 'defeat'}`;
  $('missionSubtitle').textContent = isWin
    ? 'The enemy fleet has been neutralized. The Federation prevails!'
    : 'Your fleet has been destroyed. Regroup and try again.';

  // Battle stats
  const hits   = myMoves.filter(m => m.result === 'hit').length;
  const misses = myMoves.filter(m => m.result === 'miss').length;
  const total  = hits + misses;
  const acc    = total > 0 ? ((hits/total)*100).toFixed(1) : '0.0';

  $('summaryBattleStats').innerHTML = `
    <div class="stat-item"><div class="stat-val">${hits}</div><div class="stat-name">HITS</div></div>
    <div class="stat-item"><div class="stat-val">${misses}</div><div class="stat-name">MISSES</div></div>
    <div class="stat-item"><div class="stat-val">${total}</div><div class="stat-name">TOTAL SHOTS</div></div>
    <div class="stat-item"><div class="stat-val">${acc}%</div><div class="stat-name">ACCURACY</div></div>
  `;

  // Career stats
  try {
    const sRes = await apiFetch(`/api/players/${playerId}/stats`);
    const stats = await sRes.json();
    const careerAcc = stats.accuracy ? (stats.accuracy * 100).toFixed(1) : '0.0';
    $('summaryCareerStats').innerHTML = `
      <div class="stat-item"><div class="stat-val">${stats.wins||0}</div><div class="stat-name">CAREER WINS</div></div>
      <div class="stat-item"><div class="stat-val">${stats.losses||0}</div><div class="stat-name">CAREER LOSSES</div></div>
      <div class="stat-item"><div class="stat-val">${stats.total_shots||0}</div><div class="stat-name">TOTAL SHOTS</div></div>
      <div class="stat-item"><div class="stat-val">${careerAcc}%</div><div class="stat-name">CAREER ACCURACY</div></div>
    `;
  } catch (e) {
    $('summaryCareerStats').innerHTML = '<p style="color:var(--muted);font-size:13px;">Stats unavailable</p>';
  }

  // Render final boards
  buildBoard('summaryPlayerBoard', gameGridSize, (r, c, cell) => {
    cell.classList.add('no-click');
    if (myShips.some(s => s.row === r && s.col === c)) cell.classList.add('ship-placed');
    const shot = opponentMoves.find(m => m.row === r && m.col === c);
    if (shot) cell.classList.add(shot.result);
  });

  buildBoard('summaryEnemyBoard', gameGridSize, (r, c, cell) => {
    cell.classList.add('no-click');
    const shot = myMoves.find(m => m.row === r && m.col === c);
    if (shot) cell.classList.add(shot.result);
  });

  // Copy captain's log
  const summaryLogEl = $('summaryLog');
  captainsLog.forEach(entry => {
    const div = document.createElement('div');
    div.className = `log-entry ${entry.type}`;
    div.textContent = `[${entry.time}] ${entry.msg}`;
    summaryLogEl.appendChild(div);
  });
}

$('btnReturnLobby').onclick = () => {
  stopPolling();
  gameId = null;
  gameOver = false;
  navigateTo('screen-lobby');
  refreshLobby();
};

$('btnDisconnect').onclick = () => {
  stopPolling();
  location.reload();
};

// ═══════════════════════════════════════════
// HELPERS
// ═══════════════════════════════════════════

// Generic board builder with row/col labels
function buildBoard(containerId, size, cellCallback) {
  const container = $(containerId);
  container.innerHTML = '';
  // Grid: (size+1) cols × (size+1) rows — first col is row labels, first row is col labels
  container.style.display = 'grid';
  container.style.gridTemplateColumns = `22px repeat(${size}, 34px)`;
  container.style.gap = '3px';

  for (let r = -1; r < size; r++) {
    for (let c = -1; c < size; c++) {
      const el = document.createElement('div');
      if (r === -1 && c === -1) {
        el.style.width = '22px';
        el.style.height = '22px';
      } else if (r === -1) {
        el.className = 'col-label';
        el.textContent = c + 1;
      } else if (c === -1) {
        el.className = 'row-label';
        el.textContent = String.fromCharCode(65 + r);
      } else {
        el.className = 'cell';
        if (cellCallback) cellCallback(r, c, el);
      }
      container.appendChild(el);
    }
  }
}

// Utility fetch wrapper
async function apiFetch(endpoint, method = 'GET', body = null) {
  const opts = {
    method,
    headers: { 'Content-Type': 'application/json' }
  };
  if (body) opts.body = JSON.stringify(body);
  return fetch(`${BASE_URL}${endpoint}`, opts);
}

function showStatusBadge(id, type, msg) {
  const el = $(id);
  el.className = `status-badge ${type}`;
  el.textContent = msg;
  el.classList.remove('hidden');
}

function stopPolling() {
  if (pollHandle) { clearInterval(pollHandle); pollHandle = null; }
}

// Restore session on page load
window.addEventListener('load', () => {
  const savedName = localStorage.getItem('playerName');
  if (savedName) $('playerName').value = savedName;
});
