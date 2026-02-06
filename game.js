// Starfleet Tactical Simulator (Battleship reskin) - Vanilla JS
// Includes: hit/miss markers, Captain's Log, end-of-game summary
// Includes: Smarter enemy AI (hunt/target + direction lock after 2 hits)
// Includes: Persistent JSON storage via localStorage (save/resume/clear)
// Rules fixes:
//  - Victory/defeat only after a HIT
//  - Scan shows enemy ships even with misses (render priority HIT > SHIP > MISS)

const SIZE = 10;

const SHIPS = [
  { name: "USS Enterprise (Heavy Cruiser)", length: 5 },
  { name: "USS Defiant (Escort)", length: 4 },
  { name: "USS Voyager (Intrepid)", length: 3 },
  { name: "USS Discovery (Science)", length: 3 },
  { name: "Runabout (Support Craft)", length: 2 },
];

// ---- Persistent storage keys ----
const SAVE_KEY = "st_battleship_save_v1";
const STATS_KEY = "st_battleship_stats_v1";
// ---------------------------------

const statusEl = document.getElementById("status");
const statsEl = document.getElementById("stats");
const playerBoardEl = document.getElementById("playerBoard");
const cpuBoardEl = document.getElementById("cpuBoard");
const logEl = document.getElementById("log");

const btnNewGame = document.getElementById("btnNewGame");
const btnResume = document.getElementById("btnResume");
const btnClearSave = document.getElementById("btnClearSave");
const btnReveal = document.getElementById("btnReveal");

let game = null;
let stats = loadStats();

// ---------- Buttons ----------
btnNewGame.addEventListener("click", () => {
  newMission();
  saveGame(); // persist immediately
});

btnResume.addEventListener("click", () => {
  const ok = loadGame();
  if (!ok) {
    setStatus("No saved mission found. Start a new mission.");
  }
});

btnClearSave.addEventListener("click", () => {
  clearSavedMission();
  setStatus("Saved mission cleared.");
});

btnReveal.addEventListener("click", () => {
  if (!game) return;
  game.revealCPU = !game.revealCPU;
  renderAll();

  setStatus(
    game.revealCPU
      ? "Long-range scan engaged: enemy signatures revealed."
      : "Scan offline: enemy vessels cloaked."
  );

  addLogLine(
    game.revealCPU
      ? "Scan engaged — enemy ship signatures temporarily visible."
      : "Scan offline — enemy vessels cloaked again.",
    "muted"
  );

  saveGame();
});
// ----------------------------

renderLog();
renderStatsLine();

// Auto-resume if save exists
if (!loadGame()) {
  setStatus("Press “New Mission” to begin.");
}

function setStatus(msg) {
  statusEl.textContent = msg;
}

function renderStatsLine() {
  if (!statsEl) return;
  statsEl.textContent = `Career record — Federation wins: ${stats.fedWins} | Enemy wins: ${stats.enemyWins}`;
}

function coordLabel(r, c) {
  const letter = String.fromCharCode(65 + c);
  return `${letter}${r + 1}`;
}

function addLogLine(text, kind = "muted") {
  if (!game) return;
  game.log.push({ text, kind });
  renderLog();
}

function renderLog() {
  if (!logEl) return;

  if (!game) {
    logEl.innerHTML = `<div class="muted">No mission log yet.</div>`;
    return;
  }

  if (game.log.length === 0) {
    logEl.innerHTML = `<div class="muted">Log will appear here once the mission starts.</div>`;
    return;
  }

  logEl.innerHTML = game.log
    .map((entry) => `<div class="${entry.kind}">${entry.text}</div>`)
    .join("");

  logEl.scrollTop = logEl.scrollHeight;
}

function finalizeLogSummary(winner) {
  const totalFederationShots = game.cpu.shots.size;
  const totalEnemyShots = game.player.shots.size;

  const federationHits = countHits(game.cpu);
  const enemyHits = countHits(game.player);

  const fedAcc = totalFederationShots
    ? Math.round((federationHits / totalFederationShots) * 100)
    : 0;

  const enemyAcc = totalEnemyShots
    ? Math.round((enemyHits / totalEnemyShots) * 100)
    : 0;

  addLogLine("— — — Mission Summary — — —", "muted");
  addLogLine(`Winner: <span class="sinkTxt">${winner}</span>`, "muted");
  addLogLine(
    `Federation shots: ${totalFederationShots} | hits: ${federationHits} | accuracy: ${fedAcc}%`,
    "muted"
  );
  addLogLine(
    `Enemy shots: ${totalEnemyShots} | hits: ${enemyHits} | accuracy: ${enemyAcc}%`,
    "muted"
  );
}

function countHits(defenderSide) {
  return defenderSide.ships.reduce((sum, ship) => sum + ship.hits.size, 0);
}

// ---------- Smarter AI helpers ----------
function getAdjacentCells(r, c) {
  return [
    [r - 1, c],
    [r + 1, c],
    [r, c - 1],
    [r, c + 1],
  ].filter(([rr, cc]) => inBounds(rr, cc));
}

function getRandomUntargetedCell(side) {
  const options = [];
  for (let r = 0; r < SIZE; r++) {
    for (let c = 0; c < SIZE; c++) {
      if (!side.shots.has(key(r, c))) options.push([r, c]);
    }
  }
  return options[Math.floor(Math.random() * options.length)];
}

function resetAIToHunt() {
  game.ai.mode = "hunt";
  game.ai.targets = [];
  game.ai.currentHits = [];
  game.ai.dir = null;
  game.ai.blockedPos = false;
  game.ai.blockedNeg = false;
  game.ai.lastEnd = null;
}

function pushUniqueTargets(list, coords, side) {
  const seen = new Set(list.map(([r, c]) => key(r, c)));
  for (const [r, c] of coords) {
    const k = key(r, c);
    if (!side.shots.has(k) && !seen.has(k)) {
      list.push([r, c]);
      seen.add(k);
    }
  }
}

function determineDirectionFromHits(hits) {
  if (hits.length < 2) return null;

  for (let i = 0; i < hits.length; i++) {
    for (let j = i + 1; j < hits.length; j++) {
      const [r1, c1] = hits[i];
      const [r2, c2] = hits[j];
      if (r1 === r2) return { dr: 0, dc: 1 }; // horizontal
      if (c1 === c2) return { dr: 1, dc: 0 }; // vertical
    }
  }
  return null;
}

function getLineEnds(hits, dir) {
  const { dr } = dir;

  let minT = Infinity, maxT = -Infinity;
  let negEnd = null, posEnd = null;

  for (const [r, c] of hits) {
    const t = dr === 0 ? c : r;
    if (t < minT) { minT = t; negEnd = [r, c]; }
    if (t > maxT) { maxT = t; posEnd = [r, c]; }
  }
  return { posEnd, negEnd };
}

function nextCellFromEnd(end, dir, sign) {
  const [r, c] = end;
  return [r + dir.dr * sign, c + dir.dc * sign];
}

function pickTargetForAI() {
  // 1) Direction-locked extension (pos/neg)
  if (game.ai.mode === "target" && game.ai.dir && game.ai.currentHits.length > 0) {
    const { posEnd, negEnd } = getLineEnds(game.ai.currentHits, game.ai.dir);

    const tryPos = !game.ai.blockedPos ? nextCellFromEnd(posEnd, game.ai.dir, +1) : null;
    const tryNeg = !game.ai.blockedNeg ? nextCellFromEnd(negEnd, game.ai.dir, -1) : null;

    const candidates = [];
    if (tryPos) candidates.push({ cell: tryPos, end: "pos" });
    if (tryNeg) candidates.push({ cell: tryNeg, end: "neg" });

    for (const cand of candidates) {
      const [r, c] = cand.cell;
      const k = key(r, c);

      if (inBounds(r, c) && !game.player.shots.has(k)) {
        return { r, c, end: cand.end };
      } else {
        if (cand.end === "pos") game.ai.blockedPos = true;
        if (cand.end === "neg") game.ai.blockedNeg = true;
      }
    }
  }

  // 2) Queue-based targeting before direction is known
  while (game.ai.mode === "target" && game.ai.targets.length > 0) {
    const [r, c] = game.ai.targets.shift();
    if (!game.player.shots.has(key(r, c))) return { r, c, end: null };
  }

  // 3) Hunt randomly
  const [r, c] = getRandomUntargetedCell(game.player);
  return { r, c, end: null };
}
// ---------------------------------------

function newMission() {
  game = {
    phase: "battle",
    turn: "player",
    revealCPU: false,
    log: [],
    ai: {
      mode: "hunt",
      targets: [],
      currentHits: [],
      dir: null,
      blockedPos: false,
      blockedNeg: false,
      lastEnd: null,
    },
    player: createSide(),
    cpu: createSide(),
  };

  placeAllShipsRandom(game.player);
  placeAllShipsRandom(game.cpu);

  renderAll();
  renderLog();
  addLogLine("Stardate 2402.5 — Mission initialized. Enemy vessels detected.", "muted");
  setStatus("Mission started. Choose an enemy sector to fire phasers.");
}

function createSide() {
  return {
    grid: Array.from({ length: SIZE }, () => Array(SIZE).fill(null)),
    shots: new Set(),
    ships: [],
  };
}

function placeAllShipsRandom(side) {
  side.ships = [];
  for (let r = 0; r < SIZE; r++) {
    for (let c = 0; c < SIZE; c++) side.grid[r][c] = null;
  }

  SHIPS.forEach((shipDef, idx) => {
    const ship = {
      id: idx,
      name: shipDef.name,
      length: shipDef.length,
      cells: new Set(),
      hits: new Set(),
    };

    let placed = false;
    while (!placed) {
      const horizontal = Math.random() < 0.5;
      const r = randInt(0, SIZE - 1);
      const c = randInt(0, SIZE - 1);

      const coords = [];
      for (let i = 0; i < ship.length; i++) {
        const rr = horizontal ? r : r + i;
        const cc = horizontal ? c + i : c;
        coords.push([rr, cc]);
      }

      const inGrid = coords.every(([rr, cc]) => inBounds(rr, cc));
      const noOverlap = coords.every(([rr, cc]) => side.grid[rr][cc] === null);

      if (inGrid && noOverlap) {
        coords.forEach(([rr, cc]) => {
          side.grid[rr][cc] = ship.id;
          ship.cells.add(key(rr, cc));
        });
        side.ships.push(ship);
        placed = true;
      }
    }
  });
}

function inBounds(r, c) {
  return r >= 0 && r < SIZE && c >= 0 && c < SIZE;
}

function key(r, c) {
  return `${r},${c}`;
}

function randInt(min, max) {
  return Math.floor(Math.random() * (max - min + 1)) + min;
}

function renderAll() {
  if (!game) return;
  renderBoard(playerBoardEl, game.player, { showShips: true, clickable: false });
  renderBoard(cpuBoardEl, game.cpu, { showShips: game.revealCPU, clickable: true });
}

function renderBoard(container, side, opts) {
  if (!container) return;
  container.innerHTML = "";

  for (let r = 0; r < SIZE; r++) {
    for (let c = 0; c < SIZE; c++) {
      const cell = document.createElement("button");
      cell.className = "cell";
      cell.type = "button";

      const shipId = side.grid[r][c];
      const wasShot = side.shots.has(key(r, c));

      // Render priority HIT > SHIP (if reveal) > MISS
      if (opts.showShips && shipId !== null) cell.classList.add("ship");

      if (wasShot) {
        if (shipId !== null) {
          cell.classList.remove("ship");
          cell.classList.add("hit");
        } else {
          cell.classList.add("miss");
        }
      }

      if (opts.clickable) {
        cell.addEventListener("click", () => onFireAtEnemy(r, c));

        const notPlayersTurn = !game || game.turn !== "player" || game.phase !== "battle";
        const alreadyShot = side.shots.has(key(r, c));
        if (notPlayersTurn || alreadyShot) cell.classList.add("disabled");
      } else {
        cell.classList.add("disabled");
      }

      cell.setAttribute("aria-label", `Sector ${coordLabel(r, c)}`);
      container.appendChild(cell);
    }
  }
}

function onFireAtEnemy(r, c) {
  if (!game || game.phase !== "battle" || game.turn !== "player") return;

  const targetKey = key(r, c);
  if (game.cpu.shots.has(targetKey)) return;

  const result = applyShot(game.cpu, r, c);
  const where = coordLabel(r, c);

  if (result.hit) {
    addLogLine(`You fired on ${where}: <span class="hitTxt">HIT</span>.`, "muted");
    if (result.sunk) addLogLine(`Enemy vessel disabled: <span class="sinkTxt">${result.sunk}</span>.`, "muted");
    setStatus(`Direct hit in sector ${where}!${result.sunk ? " Enemy vessel disabled: " + result.sunk + "." : ""}`);
  } else {
    addLogLine(`You fired on ${where}: <span class="missTxt">MISS</span>.`, "muted");
    setStatus(`Phasers missed in sector ${where}. Enemy returning fire...`);
  }

  renderAll();
  saveGame();

  // Victory only after a HIT
  if (result.hit && isAllSunk(game.cpu)) {
    game.phase = "gameover";
    setStatus("🏆 Victory! Enemy fleet neutralized. Mission accomplished.");
    addLogLine("Enemy fleet neutralized. Mission accomplished.", "sinkTxt");
    finalizeLogSummary("Federation");

    stats.fedWins += 1;
    saveStats();

    clearSavedMission(); // mission complete, don’t resume mid-end
    renderStatsLine();
    renderAll();
    return;
  }

  game.turn = "cpu";
  renderAll();
  saveGame();

  setTimeout(cpuMove, 450);
}

// Enemy AI move (hunt/target + direction lock)
function cpuMove() {
  if (!game || game.phase !== "battle") return;

  const choice = pickTargetForAI();
  const r = choice.r;
  const c = choice.c;
  game.ai.lastEnd = choice.end;

  const result = applyShot(game.player, r, c);
  const where = coordLabel(r, c);

  if (result.hit) {
    addLogLine(`Enemy fired on ${where}: <span class="hitTxt">HIT</span>.`, "muted");

    game.ai.mode = "target";
    game.ai.currentHits.push([r, c]);

    if (result.sunk) {
      addLogLine(`We lost: <span class="sinkTxt">${result.sunk}</span>.`, "muted");
      setStatus(`Red alert! Enemy disabled ${result.sunk}!`);
      resetAIToHunt();
    } else {
      setStatus(`Red alert! Enemy hit sector ${where}!`);

      if (!game.ai.dir) {
        const dir = determineDirectionFromHits(game.ai.currentHits);

        if (dir) {
          game.ai.dir = dir;
          game.ai.targets = [];
          game.ai.blockedPos = false;
          game.ai.blockedNeg = false;
        } else {
          pushUniqueTargets(game.ai.targets, getAdjacentCells(r, c), game.player);
        }
      }
    }
  } else {
    addLogLine(`Enemy fired on ${where}: <span class="missTxt">MISS</span>.`, "muted");
    setStatus(`Enemy missed sector ${where}. Your turn, Captain.`);

    if (game.ai.mode === "target" && game.ai.dir && game.ai.lastEnd) {
      if (game.ai.lastEnd === "pos") game.ai.blockedPos = true;
      if (game.ai.lastEnd === "neg") game.ai.blockedNeg = true;
    }

    if (game.ai.dir && game.ai.blockedPos && game.ai.blockedNeg) {
      resetAIToHunt();
    }
  }

  renderAll();
  saveGame();

  // Defeat only after a HIT
  if (result.hit && isAllSunk(game.player)) {
    game.phase = "gameover";
    setStatus("💥 Defeat. Starfleet task force disabled.");
    addLogLine("Starfleet task force disabled. Mission failed.", "sinkTxt");
    finalizeLogSummary("Enemy");

    stats.enemyWins += 1;
    saveStats();

    clearSavedMission();
    renderStatsLine();
    renderAll();
    return;
  }

  game.turn = "player";
  renderAll();
  saveGame();
}

function applyShot(defender, r, c) {
  const shotKey = key(r, c);
  defender.shots.add(shotKey);

  const shipId = defender.grid[r][c];
  if (shipId === null) return { hit: false, sunk: null };

  const ship = defender.ships.find((s) => s.id === shipId);
  ship.hits.add(shotKey);

  const sunkNow = ship.hits.size === ship.cells.size;
  return { hit: true, sunk: sunkNow ? ship.name : null };
}

function isAllSunk(side) {
  return side.ships.every((ship) => ship.hits.size === ship.cells.size);
}

// -------------------- Persistence (JSON/localStorage) --------------------
function saveGame() {
  if (!game) return;

  const payload = serializeGame(game);
  localStorage.setItem(SAVE_KEY, JSON.stringify(payload));
}

function loadGame() {
  const raw = localStorage.getItem(SAVE_KEY);
  if (!raw) return false;

  try {
    const payload = JSON.parse(raw);
    game = deserializeGame(payload);

    renderAll();
    renderLog();
    renderStatsLine();

    setStatus(game.phase === "gameover"
      ? "Loaded previous mission (completed). Start a new mission."
      : "Mission resumed from saved state.");

    return true;
  } catch (e) {
    // corrupted save
    localStorage.removeItem(SAVE_KEY);
    return false;
  }
}

function clearSavedMission() {
  localStorage.removeItem(SAVE_KEY);
}

function serializeGame(g) {
  return {
    phase: g.phase,
    turn: g.turn,
    revealCPU: g.revealCPU,
    log: g.log,
    ai: g.ai,
    player: serializeSide(g.player),
    cpu: serializeSide(g.cpu),
  };
}

function serializeSide(side) {
  return {
    grid: side.grid,
    shots: Array.from(side.shots),
    ships: side.ships.map(s => ({
      id: s.id,
      name: s.name,
      length: s.length,
      cells: Array.from(s.cells),
      hits: Array.from(s.hits),
    })),
  };
}

function deserializeGame(p) {
  return {
    phase: p.phase,
    turn: p.turn,
    revealCPU: p.revealCPU,
    log: p.log || [],
    ai: p.ai || {
      mode: "hunt", targets: [], currentHits: [], dir: null,
      blockedPos: false, blockedNeg: false, lastEnd: null,
    },
    player: deserializeSide(p.player),
    cpu: deserializeSide(p.cpu),
  };
}

function deserializeSide(pSide) {
  return {
    grid: pSide.grid,
    shots: new Set(pSide.shots || []),
    ships: (pSide.ships || []).map(s => ({
      id: s.id,
      name: s.name,
      length: s.length,
      cells: new Set(s.cells || []),
      hits: new Set(s.hits || []),
    })),
  };
}

// -------------------- Persistent stats --------------------
function loadStats() {
  const raw = localStorage.getItem(STATS_KEY);
  if (!raw) return { fedWins: 0, enemyWins: 0 };
  try {
    const p = JSON.parse(raw);
    return {
      fedWins: Number(p.fedWins || 0),
      enemyWins: Number(p.enemyWins || 0),
    };
  } catch {
    return { fedWins: 0, enemyWins: 0 };
  }
}

function saveStats() {
  localStorage.setItem(STATS_KEY, JSON.stringify(stats));
}
// ------------------------------------------------------------------------
