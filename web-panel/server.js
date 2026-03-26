const express = require("express");
const cors = require("cors");
const path = require("path");
const fs = require("fs");
const initSqlJs = require("sql.js");

const app = express();
const PORT = process.env.PORT || 3000;
const API_KEY = process.env.API_KEY || "mcpe-analytics-secret-key-change-me";
const PANEL_PASSWORD = process.env.PANEL_PASSWORD || "admin";
const DB_PATH = path.join(__dirname, "data", "analytics.db");

let db;

// Helper: run query, return nothing
function run(sql, params = []) {
  db.run(sql, params);
  saveDb();
}

// Helper: get one row as object
function getOne(sql, params = []) {
  const stmt = db.prepare(sql);
  stmt.bind(params);
  let row = null;
  if (stmt.step()) {
    const cols = stmt.getColumnNames();
    const vals = stmt.get();
    row = {};
    cols.forEach((c, i) => row[c] = vals[i]);
  }
  stmt.free();
  return row;
}

// Helper: get all rows as array of objects
function getAll(sql, params = []) {
  const stmt = db.prepare(sql);
  stmt.bind(params);
  const rows = [];
  const cols = stmt.getColumnNames();
  while (stmt.step()) {
    const vals = stmt.get();
    const row = {};
    cols.forEach((c, i) => row[c] = vals[i]);
    rows.push(row);
  }
  stmt.free();
  return rows;
}

// Save database to disk (debounced)
let saveTimeout = null;
function saveDb() {
  if (saveTimeout) return;
  saveTimeout = setTimeout(() => {
    saveTimeout = null;
    const data = db.export();
    fs.writeFileSync(DB_PATH, Buffer.from(data));
  }, 500);
}

function saveDbNow() {
  if (saveTimeout) { clearTimeout(saveTimeout); saveTimeout = null; }
  const data = db.export();
  fs.writeFileSync(DB_PATH, Buffer.from(data));
}

async function main() {
  const SQL = await initSqlJs();

  // Load or create database
  if (fs.existsSync(DB_PATH)) {
    const fileBuffer = fs.readFileSync(DB_PATH);
    db = new SQL.Database(fileBuffer);
  } else {
    db = new SQL.Database();
  }

  // Create tables
  db.run(`CREATE TABLE IF NOT EXISTS players (
    uuid TEXT PRIMARY KEY,
    username TEXT NOT NULL,
    platform TEXT DEFAULT 'Unknown',
    first_seen INTEGER NOT NULL,
    last_seen INTEGER NOT NULL,
    total_playtime INTEGER DEFAULT 0,
    session_count INTEGER DEFAULT 0,
    ip_address TEXT
  )`);

  db.run(`CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_uuid TEXT NOT NULL,
    join_time INTEGER NOT NULL,
    leave_time INTEGER,
    duration INTEGER DEFAULT 0,
    platform TEXT,
    FOREIGN KEY (player_uuid) REFERENCES players(uuid)
  )`);

  db.run(`CREATE TABLE IF NOT EXISTS commands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_uuid TEXT NOT NULL,
    command TEXT NOT NULL,
    arguments TEXT,
    world TEXT,
    timestamp INTEGER NOT NULL,
    FOREIGN KEY (player_uuid) REFERENCES players(uuid)
  )`);

  db.run(`CREATE TABLE IF NOT EXISTS world_visits (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_uuid TEXT NOT NULL,
    world_name TEXT NOT NULL,
    enter_time INTEGER NOT NULL,
    leave_time INTEGER,
    duration INTEGER DEFAULT 0,
    FOREIGN KEY (player_uuid) REFERENCES players(uuid)
  )`);

  db.run(`CREATE TABLE IF NOT EXISTS chat_messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_uuid TEXT NOT NULL,
    message TEXT NOT NULL,
    world TEXT,
    timestamp INTEGER NOT NULL,
    FOREIGN KEY (player_uuid) REFERENCES players(uuid)
  )`);

  db.run(`CREATE TABLE IF NOT EXISTS deaths (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_uuid TEXT NOT NULL,
    cause TEXT,
    world TEXT,
    x REAL,
    y REAL,
    z REAL,
    timestamp INTEGER NOT NULL,
    FOREIGN KEY (player_uuid) REFERENCES players(uuid)
  )`);

  db.run(`CREATE TABLE IF NOT EXISTS block_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_uuid TEXT NOT NULL,
    action TEXT NOT NULL,
    block_id TEXT NOT NULL,
    world TEXT,
    x INTEGER,
    y INTEGER,
    z INTEGER,
    timestamp INTEGER NOT NULL,
    FOREIGN KEY (player_uuid) REFERENCES players(uuid)
  )`);

  db.run(`CREATE TABLE IF NOT EXISTS logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    player_uuid TEXT,
    player_name TEXT,
    category TEXT NOT NULL,
    action TEXT NOT NULL,
    detail TEXT,
    item_name TEXT,
    item_count INTEGER,
    target_player TEXT,
    world TEXT,
    x REAL,
    y REAL,
    z REAL,
    level TEXT DEFAULT 'info',
    timestamp INTEGER NOT NULL
  )`);

  db.run(`CREATE INDEX IF NOT EXISTS idx_logs_player ON logs(player_name)`);
  db.run(`CREATE INDEX IF NOT EXISTS idx_logs_category ON logs(category)`);
  db.run(`CREATE INDEX IF NOT EXISTS idx_logs_action ON logs(action)`);
  db.run(`CREATE INDEX IF NOT EXISTS idx_logs_item ON logs(item_name)`);
  db.run(`CREATE INDEX IF NOT EXISTS idx_logs_timestamp ON logs(timestamp)`);
  db.run(`CREATE INDEX IF NOT EXISTS idx_logs_target ON logs(target_player)`);

  saveDbNow();

  // Middleware
  app.use(cors());
  app.use(express.json({ limit: "10mb" }));
  app.use(express.urlencoded({ extended: true }));
  app.use(express.static(path.join(__dirname, "public")));
  app.set("view engine", "ejs");
  app.set("views", path.join(__dirname, "views"));

  // Simple cookie-based session tokens
  const activeSessions = new Set();
  function generateToken() {
    return require("crypto").randomBytes(32).toString("hex");
  }

  function parseCookies(req) {
    const cookies = {};
    (req.headers.cookie || "").split(";").forEach(c => {
      const [k, v] = c.trim().split("=");
      if (k && v) cookies[k] = v;
    });
    return cookies;
  }

  // Panel authentication middleware (for browser pages + dashboard API)
  function panelAuth(req, res, next) {
    const cookies = parseCookies(req);
    if (cookies.session && activeSessions.has(cookies.session)) return next();
    // Redirect to login page
    if (req.path === "/login") return next();
    res.redirect("/login");
  }

  // API key authentication (for plugin ingest endpoints)
  function apiAuth(req, res, next) {
    const key = req.headers["x-api-key"];
    if (key !== API_KEY) return res.status(401).json({ error: "Invalid API key" });
    next();
  }

  // Login page
  app.get("/login", (req, res) => {
    const cookies = parseCookies(req);
    if (cookies.session && activeSessions.has(cookies.session)) return res.redirect("/");
    res.render("login", { error: null });
  });

  app.post("/login", (req, res) => {
    if (req.body.password === PANEL_PASSWORD) {
      const token = generateToken();
      activeSessions.add(token);
      res.setHeader("Set-Cookie", `session=${token}; HttpOnly; Path=/; Max-Age=604800; SameSite=Strict`);
      return res.redirect("/");
    }
    res.render("login", { error: "Mot de passe incorrect" });
  });

  app.get("/logout", (req, res) => {
    const cookies = parseCookies(req);
    if (cookies.session) activeSessions.delete(cookies.session);
    res.setHeader("Set-Cookie", "session=; HttpOnly; Path=/; Max-Age=0");
    res.redirect("/login");
  });

  // Dashboard API auth: accept session cookie OR api key
  function dashAuth(req, res, next) {
    const cookies = parseCookies(req);
    if (cookies.session && activeSessions.has(cookies.session)) return next();
    const key = req.headers["x-api-key"];
    if (key === API_KEY) return next();
    return res.status(401).json({ error: "Unauthorized" });
  }

  // ===================== INGEST API (no panel auth, uses API key) =====================

  app.post("/api/ingest/join", apiAuth, (req, res) => {
    const { uuid, username, platform, timestamp, ip } = req.body;
    const now = timestamp || Date.now();
    run(`INSERT INTO players (uuid, username, platform, first_seen, last_seen, session_count, ip_address)
      VALUES (?, ?, ?, ?, ?, 1, ?)
      ON CONFLICT(uuid) DO UPDATE SET
        username = excluded.username,
        platform = excluded.platform,
        last_seen = excluded.last_seen,
        session_count = session_count + 1,
        ip_address = excluded.ip_address`,
      [uuid, username, platform || "Unknown", now, now, ip || null]);
    run(`INSERT INTO sessions (player_uuid, join_time, platform) VALUES (?, ?, ?)`,
      [uuid, now, platform || "Unknown"]);
    res.json({ success: true });
  });

  app.post("/api/ingest/leave", apiAuth, (req, res) => {
    const { uuid, timestamp, playtime } = req.body;
    const now = timestamp || Date.now();
    // Find the open session
    const session = getOne(
      `SELECT id FROM sessions WHERE player_uuid = ? AND leave_time IS NULL ORDER BY join_time DESC LIMIT 1`,
      [uuid]);
    if (session) {
      run(`UPDATE sessions SET leave_time = ?, duration = ? WHERE id = ?`, [now, playtime || 0, session.id]);
    }
    run(`UPDATE players SET last_seen = ?, total_playtime = total_playtime + ? WHERE uuid = ?`,
      [now, playtime || 0, uuid]);
    run(`UPDATE world_visits SET leave_time = ?, duration = ? - enter_time WHERE player_uuid = ? AND leave_time IS NULL`,
      [now, now, uuid]);
    res.json({ success: true });
  });

  app.post("/api/ingest/command", apiAuth, (req, res) => {
    const { uuid, command, arguments: args, world, timestamp } = req.body;
    run(`INSERT INTO commands (player_uuid, command, arguments, world, timestamp) VALUES (?, ?, ?, ?, ?)`,
      [uuid, command, args || "", world || "", timestamp || Date.now()]);
    res.json({ success: true });
  });

  app.post("/api/ingest/world", apiAuth, (req, res) => {
    const { uuid, world, timestamp } = req.body;
    const now = timestamp || Date.now();
    run(`UPDATE world_visits SET leave_time = ?, duration = ? - enter_time WHERE player_uuid = ? AND leave_time IS NULL`,
      [now, now, uuid]);
    run(`INSERT INTO world_visits (player_uuid, world_name, enter_time) VALUES (?, ?, ?)`,
      [uuid, world, now]);
    res.json({ success: true });
  });

  app.post("/api/ingest/chat", apiAuth, (req, res) => {
    const { uuid, message, world, timestamp } = req.body;
    run(`INSERT INTO chat_messages (player_uuid, message, world, timestamp) VALUES (?, ?, ?, ?)`,
      [uuid, message, world || "", timestamp || Date.now()]);
    res.json({ success: true });
  });

  app.post("/api/ingest/death", apiAuth, (req, res) => {
    const { uuid, cause, world, x, y, z, timestamp } = req.body;
    run(`INSERT INTO deaths (player_uuid, cause, world, x, y, z, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)`,
      [uuid, cause || "Unknown", world || "", x || 0, y || 0, z || 0, timestamp || Date.now()]);
    res.json({ success: true });
  });

  app.post("/api/ingest/block", apiAuth, (req, res) => {
    const { uuid, action, block_id, world, x, y, z, timestamp } = req.body;
    run(`INSERT INTO block_events (player_uuid, action, block_id, world, x, y, z, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
      [uuid, action, block_id, world || "", x || 0, y || 0, z || 0, timestamp || Date.now()]);
    res.json({ success: true });
  });

  app.post("/api/ingest/batch", apiAuth, (req, res) => {
    const { events } = req.body;
    if (!Array.isArray(events)) return res.status(400).json({ error: "events must be an array" });

    for (const e of events) {
      switch (e.type) {
        case "command":
          run(`INSERT INTO commands (player_uuid, command, arguments, world, timestamp) VALUES (?, ?, ?, ?, ?)`,
            [e.uuid, e.command, e.arguments || "", e.world || "", e.timestamp || Date.now()]);
          break;
        case "chat":
          run(`INSERT INTO chat_messages (player_uuid, message, world, timestamp) VALUES (?, ?, ?, ?)`,
            [e.uuid, e.message, e.world || "", e.timestamp || Date.now()]);
          break;
        case "death":
          run(`INSERT INTO deaths (player_uuid, cause, world, x, y, z, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?)`,
            [e.uuid, e.cause || "Unknown", e.world || "", e.x || 0, e.y || 0, e.z || 0, e.timestamp || Date.now()]);
          break;
        case "block":
          run(`INSERT INTO block_events (player_uuid, action, block_id, world, x, y, z, timestamp) VALUES (?, ?, ?, ?, ?, ?, ?, ?)`,
            [e.uuid, e.action, e.block_id, e.world || "", e.x || 0, e.y || 0, e.z || 0, e.timestamp || Date.now()]);
          break;
      }
    }
    res.json({ success: true, count: events.length });
  });

  // Log ingestion (single)
  app.post("/api/ingest/log", apiAuth, (req, res) => {
    const { uuid, player, category, action, detail, item_name, item_count, target_player, world, x, y, z, level, timestamp } = req.body;
    run(`INSERT INTO logs (player_uuid, player_name, category, action, detail, item_name, item_count, target_player, world, x, y, z, level, timestamp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
      [uuid || null, player || null, category, action, detail || null, item_name || null, item_count || null, target_player || null, world || null, x || null, y || null, z || null, level || "info", timestamp || Date.now()]);
    res.json({ success: true });
  });

  // Log batch ingestion
  app.post("/api/ingest/logs", apiAuth, (req, res) => {
    const { logs: entries } = req.body;
    if (!Array.isArray(entries)) return res.status(400).json({ error: "logs must be an array" });
    for (const e of entries) {
      run(`INSERT INTO logs (player_uuid, player_name, category, action, detail, item_name, item_count, target_player, world, x, y, z, level, timestamp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)`,
        [e.uuid || null, e.player || null, e.category, e.action, e.detail || null, e.item_name || null, e.item_count || null, e.target_player || null, e.world || null, e.x || null, e.y || null, e.z || null, e.level || "info", e.timestamp || Date.now()]);
    }
    res.json({ success: true, count: entries.length });
  });

  // ===================== LOGS API (protected) =====================

  // Search logs with filters
  app.get("/api/logs", dashAuth, (req, res) => {
    const { player, category, action, item, target, world, level, search, from, to, limit = 100, offset = 0 } = req.query;
    let where = [];
    let params = [];

    if (player) { where.push("(player_name LIKE ? OR player_uuid = ?)"); params.push(`%${player}%`, player); }
    if (category) { where.push("category = ?"); params.push(category); }
    if (action) { where.push("action LIKE ?"); params.push(`%${action}%`); }
    if (item) { where.push("item_name LIKE ?"); params.push(`%${item}%`); }
    if (target) { where.push("(target_player LIKE ?)"); params.push(`%${target}%`); }
    if (world) { where.push("world = ?"); params.push(world); }
    if (level) { where.push("level = ?"); params.push(level); }
    if (search) { where.push("(detail LIKE ? OR action LIKE ? OR item_name LIKE ? OR player_name LIKE ? OR target_player LIKE ?)"); params.push(`%${search}%`, `%${search}%`, `%${search}%`, `%${search}%`, `%${search}%`); }
    if (from) { where.push("timestamp >= ?"); params.push(Number(from)); }
    if (to) { where.push("timestamp <= ?"); params.push(Number(to)); }

    const whereClause = where.length > 0 ? "WHERE " + where.join(" AND ") : "";
    const total = getOne(`SELECT COUNT(*) as count FROM logs ${whereClause}`, params).count;
    const logs = getAll(`SELECT * FROM logs ${whereClause} ORDER BY timestamp DESC LIMIT ? OFFSET ?`, [...params, Number(limit), Number(offset)]);

    res.json({ logs, total });
  });

  // Get all categories
  app.get("/api/logs/categories", dashAuth, (req, res) => {
    res.json(getAll("SELECT category, COUNT(*) as count FROM logs GROUP BY category ORDER BY count DESC"));
  });

  // Get all actions for a category
  app.get("/api/logs/actions", dashAuth, (req, res) => {
    const { category } = req.query;
    let q = "SELECT action, COUNT(*) as count FROM logs";
    const p = [];
    if (category) { q += " WHERE category = ?"; p.push(category); }
    q += " GROUP BY action ORDER BY count DESC";
    res.json(getAll(q, p));
  });

  // Item trace: follow an item across all events
  app.get("/api/logs/trace/item", dashAuth, (req, res) => {
    const { item, from, to, limit = 200 } = req.query;
    if (!item) return res.status(400).json({ error: "item parameter required" });
    let where = "WHERE item_name LIKE ?";
    const params = [`%${item}%`];
    if (from) { where += " AND timestamp >= ?"; params.push(Number(from)); }
    if (to) { where += " AND timestamp <= ?"; params.push(Number(to)); }
    const logs = getAll(`SELECT * FROM logs ${where} ORDER BY timestamp ASC LIMIT ?`, [...params, Number(limit)]);
    res.json(logs);
  });

  // Player timeline: every single log for a player, chronological
  app.get("/api/logs/trace/player", dashAuth, (req, res) => {
    const { player, from, to, limit = 300 } = req.query;
    if (!player) return res.status(400).json({ error: "player parameter required" });
    let where = "WHERE (player_name LIKE ? OR target_player LIKE ?)";
    const params = [`%${player}%`, `%${player}%`];
    if (from) { where += " AND timestamp >= ?"; params.push(Number(from)); }
    if (to) { where += " AND timestamp <= ?"; params.push(Number(to)); }
    const logs = getAll(`SELECT * FROM logs ${where} ORDER BY timestamp DESC LIMIT ?`, [...params, Number(limit)]);
    res.json(logs);
  });

  // Logs stats overview
  app.get("/api/logs/stats", dashAuth, (req, res) => {
    const now = Date.now();
    const day = 86400000;
    res.json({
      totalLogs: getOne("SELECT COUNT(*) as count FROM logs").count,
      logsLast24h: getOne("SELECT COUNT(*) as count FROM logs WHERE timestamp > ?", [now - day]).count,
      warnings: getOne("SELECT COUNT(*) as count FROM logs WHERE level = 'warning'").count,
      warningsLast24h: getOne("SELECT COUNT(*) as count FROM logs WHERE level = 'warning' AND timestamp > ?", [now - day]).count,
      topPlayers: getAll("SELECT player_name, COUNT(*) as count FROM logs WHERE player_name IS NOT NULL GROUP BY player_name ORDER BY count DESC LIMIT 10"),
      topItems: getAll("SELECT item_name, COUNT(*) as count FROM logs WHERE item_name IS NOT NULL AND item_name != '' GROUP BY item_name ORDER BY count DESC LIMIT 10"),
      topActions: getAll("SELECT action, COUNT(*) as count FROM logs GROUP BY action ORDER BY count DESC LIMIT 10"),
      byLevel: getAll("SELECT level, COUNT(*) as count FROM logs GROUP BY level ORDER BY count DESC"),
    });
  });

  // ===================== DASHBOARD API =====================

  app.get("/api/stats/overview", dashAuth, (req, res) => {
    const now = Date.now();
    const day = 86400000;
    const week = 7 * day;

    res.json({
      totalPlayers: getOne("SELECT COUNT(*) as count FROM players").count,
      activeLast24h: getOne("SELECT COUNT(*) as count FROM players WHERE last_seen > ?", [now - day]).count,
      activeLast7d: getOne("SELECT COUNT(*) as count FROM players WHERE last_seen > ?", [now - week]).count,
      newLast24h: getOne("SELECT COUNT(*) as count FROM players WHERE first_seen > ?", [now - day]).count,
      newLast7d: getOne("SELECT COUNT(*) as count FROM players WHERE first_seen > ?", [now - week]).count,
      totalCommands: getOne("SELECT COUNT(*) as count FROM commands").count,
      totalDeaths: getOne("SELECT COUNT(*) as count FROM deaths").count,
      totalMessages: getOne("SELECT COUNT(*) as count FROM chat_messages").count,
      avgPlaytime: Math.round(getOne("SELECT AVG(total_playtime) as avg FROM players WHERE total_playtime > 0").avg || 0),
      avgSessionCount: Math.round((getOne("SELECT AVG(session_count) as avg FROM players").avg || 0) * 10) / 10,
    });
  });

  app.get("/api/players", dashAuth, (req, res) => {
    const { sort = "last_seen", order = "DESC", limit = 50, offset = 0, search } = req.query;
    const allowedSorts = ["last_seen", "first_seen", "total_playtime", "session_count", "username"];
    const sortCol = allowedSorts.includes(sort) ? sort : "last_seen";
    const sortOrder = order === "ASC" ? "ASC" : "DESC";

    let query = "SELECT * FROM players";
    const params = [];
    if (search) { query += " WHERE username LIKE ?"; params.push(`%${search}%`); }
    query += ` ORDER BY ${sortCol} ${sortOrder} LIMIT ? OFFSET ?`;
    params.push(Number(limit), Number(offset));

    const players = getAll(query, params);
    const countParams = search ? [`%${search}%`] : [];
    const total = getOne(`SELECT COUNT(*) as count FROM players ${search ? "WHERE username LIKE ?" : ""}`, countParams).count;

    res.json({ players, total });
  });

  app.get("/api/players/:uuid", dashAuth, (req, res) => {
    const uuid = req.params.uuid;
    const player = getOne("SELECT * FROM players WHERE uuid = ?", [uuid]);
    if (!player) return res.status(404).json({ error: "Player not found" });

    res.json({
      player,
      sessions: getAll("SELECT * FROM sessions WHERE player_uuid = ? ORDER BY join_time DESC LIMIT 50", [uuid]),
      commands: getAll("SELECT * FROM commands WHERE player_uuid = ? ORDER BY timestamp DESC LIMIT 100", [uuid]),
      worlds: getAll("SELECT * FROM world_visits WHERE player_uuid = ? ORDER BY enter_time DESC LIMIT 100", [uuid]),
      deaths: getAll("SELECT * FROM deaths WHERE player_uuid = ? ORDER BY timestamp DESC LIMIT 50", [uuid]),
      messages: getAll("SELECT * FROM chat_messages WHERE player_uuid = ? ORDER BY timestamp DESC LIMIT 100", [uuid]),
      commandStats: getAll(`SELECT command, COUNT(*) as count FROM commands WHERE player_uuid = ? GROUP BY command ORDER BY count DESC LIMIT 20`, [uuid]),
      worldStats: getAll(`SELECT world_name, SUM(duration) as total_time, COUNT(*) as visits FROM world_visits WHERE player_uuid = ? GROUP BY world_name ORDER BY total_time DESC`, [uuid]),
    });
  });

  app.get("/api/stats/retention", dashAuth, (req, res) => {
    const days = Number(req.query.days) || 30;
    const now = Date.now();
    const day = 86400000;

    const retention = [];
    for (let i = 0; i < days; i++) {
      const dayStart = now - (i + 1) * day;
      const dayEnd = now - i * day;

      const joined = getOne("SELECT COUNT(*) as count FROM players WHERE first_seen BETWEEN ? AND ?", [dayStart, dayEnd]).count;
      const returnedNextDay = getOne(`
        SELECT COUNT(DISTINCT p.uuid) as count FROM players p
        INNER JOIN sessions s ON s.player_uuid = p.uuid
        WHERE p.first_seen BETWEEN ? AND ? AND s.join_time > ? AND s.join_time < ?
      `, [dayStart, dayEnd, dayEnd, dayEnd + day]).count;
      const returnedWeek = getOne(`
        SELECT COUNT(DISTINCT p.uuid) as count FROM players p
        INNER JOIN sessions s ON s.player_uuid = p.uuid
        WHERE p.first_seen BETWEEN ? AND ? AND s.join_time > ? AND s.join_time < ?
      `, [dayStart, dayEnd, dayEnd, dayEnd + 7 * day]).count;

      retention.push({
        date: new Date(dayStart).toISOString().split("T")[0],
        newPlayers: joined,
        returnedDay1: returnedNextDay,
        returnedWeek: returnedWeek,
        retentionDay1: joined > 0 ? Math.round((returnedNextDay / joined) * 100) : 0,
        retentionWeek: joined > 0 ? Math.round((returnedWeek / joined) * 100) : 0,
      });
    }

    res.json(retention.reverse());
  });

  app.get("/api/stats/activity", dashAuth, (req, res) => {
    const days = Number(req.query.days) || 7;
    const since = Date.now() - days * 86400000;
    const sessions = getAll("SELECT join_time, leave_time, duration FROM sessions WHERE join_time > ? ORDER BY join_time", [since]);

    const hourly = {};
    for (const s of sessions) {
      const hour = new Date(s.join_time).toISOString().slice(0, 13) + ":00";
      if (!hourly[hour]) hourly[hour] = { joins: 0, totalDuration: 0 };
      hourly[hour].joins++;
      hourly[hour].totalDuration += s.duration || 0;
    }

    res.json(Object.entries(hourly).map(([hour, data]) => ({
      hour, joins: data.joins,
      avgDuration: data.joins > 0 ? Math.round(data.totalDuration / data.joins) : 0,
    })));
  });

  app.get("/api/stats/platforms", dashAuth, (req, res) => {
    res.json(getAll("SELECT platform, COUNT(*) as count FROM players GROUP BY platform ORDER BY count DESC"));
  });

  app.get("/api/stats/commands", dashAuth, (req, res) => {
    const limit = Number(req.query.limit) || 20;
    res.json(getAll("SELECT command, COUNT(*) as count FROM commands GROUP BY command ORDER BY count DESC LIMIT ?", [limit]));
  });

  app.get("/api/stats/worlds", dashAuth, (req, res) => {
    res.json(getAll(`
      SELECT world_name, COUNT(DISTINCT player_uuid) as unique_players,
      COUNT(*) as total_visits, SUM(duration) as total_time
      FROM world_visits GROUP BY world_name ORDER BY unique_players DESC
    `));
  });

  app.get("/api/stats/peak-hours", dashAuth, (req, res) => {
    res.json(getAll("SELECT (join_time / 3600000 % 24) as hour, COUNT(*) as count FROM sessions GROUP BY hour ORDER BY hour"));
  });

  app.get("/api/stats/churn", dashAuth, (req, res) => {
    const now = Date.now();
    const day = 86400000;
    const brackets = [
      { label: "Actif (< 1 jour)", min: 0, max: day },
      { label: "Recent (1-3 jours)", min: day, max: 3 * day },
      { label: "A risque (3-7 jours)", min: 3 * day, max: 7 * day },
      { label: "Inactif (7-30 jours)", min: 7 * day, max: 30 * day },
      { label: "Perdu (30+ jours)", min: 30 * day, max: Infinity },
    ];

    res.json(brackets.map(b => ({
      label: b.label,
      count: b.max === Infinity
        ? getOne("SELECT COUNT(*) as count FROM players WHERE ? - last_seen >= ?", [now, b.min]).count
        : getOne("SELECT COUNT(*) as count FROM players WHERE ? - last_seen >= ? AND ? - last_seen < ?", [now, b.min, now, b.max]).count,
    })));
  });

  app.get("/api/stats/daily-players", dashAuth, (req, res) => {
    const days = Number(req.query.days) || 30;
    const now = Date.now();
    const day = 86400000;

    const result = [];
    for (let i = days - 1; i >= 0; i--) {
      const dayStart = now - (i + 1) * day;
      const dayEnd = now - i * day;
      result.push({
        date: new Date(dayStart).toISOString().split("T")[0],
        activePlayers: getOne("SELECT COUNT(DISTINCT player_uuid) as count FROM sessions WHERE join_time BETWEEN ? AND ?", [dayStart, dayEnd]).count,
        newPlayers: getOne("SELECT COUNT(*) as count FROM players WHERE first_seen BETWEEN ? AND ?", [dayStart, dayEnd]).count,
      });
    }
    res.json(result);
  });

  // ===================== PAGES (protected) =====================

  app.get("/", panelAuth, (req, res) => res.render("dashboard"));
  app.get("/players", panelAuth, (req, res) => res.render("players"));
  app.get("/player/:uuid", panelAuth, (req, res) => res.render("player-detail", { uuid: req.params.uuid }));
  app.get("/retention", panelAuth, (req, res) => res.render("retention"));
  app.get("/worlds", panelAuth, (req, res) => res.render("worlds"));
  app.get("/logs", panelAuth, (req, res) => res.render("logs"));

  // Save DB on shutdown
  process.on("SIGINT", () => { saveDbNow(); process.exit(0); });
  process.on("SIGTERM", () => { saveDbNow(); process.exit(0); });

  app.listen(PORT, () => {
    console.log(`[Analytics] Dashboard running at http://localhost:${PORT}`);
    console.log(`[Analytics] API Key: ${API_KEY}`);
  });
}

main().catch(err => { console.error("Failed to start:", err); process.exit(1); });
