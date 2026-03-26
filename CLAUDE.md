# CLAUDE.md

## Project Overview

AnalyticsPM — analytics system for PocketMine-MP 5 Minecraft servers. Two components:
- **web-panel/**: Node.js + Express + EJS + sql.js (SQLite in WASM) dashboard
- **mcpe-analytics-plugin/**: PHP plugin for PocketMine-MP 5 API

The plugin collects player data (sessions, commands, worlds, chat, deaths, items) and sends it via HTTP to the web panel which stores it in SQLite and displays it.

## Architecture

```
web-panel/server.js          — Single file: Express server, all API routes, SQLite setup
web-panel/views/*.ejs        — EJS templates (dashboard, players, player-detail, retention, worlds, logs, login)
web-panel/public/css/style.css — Full CSS (violet/pink theme, no framework)
web-panel/public/js/app.js   — Minimal JS (active nav highlight)

mcpe-analytics-plugin/src/mcpe/analytics/
  Main.php            — Plugin entry, registers listeners, schedules buffer flushes
  EventListener.php   — Tracks joins/leaves/commands/chat/deaths/worlds/blocks for analytics
  LogsTracker.php     — Tracks detailed logs (items, transactions, connections) for /logs page
  AnalyticsAPI.php    — Static public API for external plugins to send custom logs
  ApiClient.php       — Async HTTP client (uses AsyncTask for non-blocking requests)
  EventBuffer.php     — Simple array buffer with drain()
  SessionTracker.php  — Tracks session start/end timestamps per player UUID
```

## Key Technical Decisions

- **sql.js** instead of better-sqlite3 (native compilation fails on newer Node versions). API is synchronous after init but requires manual `db.export()` to persist to disk — handled via debounced `saveDb()`.
- **No npm frameworks** for frontend — vanilla JS + Chart.js loaded from CDN.
- **Authentication**: Cookie-based sessions for the web panel, API key header (`X-Api-Key`) for plugin ingestion. Both are checked by `dashAuth` middleware on read endpoints.
- **PocketMine-MP 5 API**: `PlayerCommandPreprocessEvent` does not exist — use `CommandEvent` from `pocketmine\event\server`. `getDeathMessage()` returns `string|Translatable`, not an object with `getText()`.
- **Async HTTP in PHP**: All API calls use anonymous `AsyncTask` classes to avoid blocking the server thread.
- **Logs buffer**: Both internal LogsTracker and external AnalyticsAPI logs are buffered separately and flushed every `flush-interval` seconds (default 30s).

## Commands

### Web panel
```bash
cd web-panel
npm install                    # Install dependencies
PANEL_PASSWORD=xxx API_KEY=xxx PORT=3000 node server.js  # Run
```

### Testing
No test suite exists. Manual testing: start the server and use curl or the integration test in the git history.

```bash
# Quick smoke test
cd web-panel && timeout 4 node server.js
# Send test data
curl -X POST http://localhost:3000/api/ingest/join \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: mcpe-analytics-secret-key-change-me" \
  -d '{"uuid":"test-1","username":"TestPlayer","platform":"Windows","timestamp":1234567890000}'
```

## Environment Variables (web-panel)

| Variable | Required | Default | Description |
|---|---|---|---|
| `API_KEY` | Yes | `mcpe-analytics-secret-key-change-me` | Shared secret with plugin |
| `PANEL_PASSWORD` | Yes | `admin` | Password for web panel login |
| `PORT` | No | `3000` | HTTP port |

## Database

SQLite via sql.js, stored at `web-panel/data/analytics.db`. Auto-created on first start. Tables: `players`, `sessions`, `commands`, `world_visits`, `chat_messages`, `deaths`, `block_events`, `logs`. Deleting the .db file resets all data; tables are recreated automatically.

## Code Conventions

- **PHP**: PocketMine-MP 5 style — `declare(strict_types=1)`, namespace `mcpe\analytics`, typed properties, match expressions
- **JS/Node**: CommonJS (`require`), no TypeScript, single `server.js` with helper functions (`run()`, `getOne()`, `getAll()`)
- **CSS**: Custom properties (CSS vars), no preprocessor, BEM-ish class names
- **EJS**: Each page is self-contained with inline `<script>` for data fetching and Chart.js rendering
- **French UI**: All user-facing text in the dashboard is in French

## Plugin API for External Plugins

Other PocketMine plugins can send logs without dependency injection:

```php
use mcpe\analytics\AnalyticsAPI;

AnalyticsAPI::log("category", "Action", $player, ["detail" => "...", "item_name" => "..."]);
AnalyticsAPI::warning("category", "Action", $player, [...]);
AnalyticsAPI::server("category", "Action", ["player" => "CONSOLE", ...]);
AnalyticsAPI::batch([...]);
```

Logs are buffered and sent async. If called before MCPEAnalytics loads, they are kept in an early-buffer.

## Common Pitfalls

- The plugin targets **PocketMine-MP 5 only** — PM4 events/methods differ significantly
- `sql.js` keeps the entire DB in memory; `saveDb()` is debounced (500ms) to avoid excessive disk writes. `saveDbNow()` is called on SIGINT/SIGTERM
- Session tokens are stored in a `Set` in memory — server restart logs everyone out
- The `logs` table has indexes on player_name, category, action, item_name, timestamp, target_player for fast filtering
