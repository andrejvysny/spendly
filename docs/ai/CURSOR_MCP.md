# Cursor IDE – SQLite MCP

This project is configured to use the **SQLite MCP server** in Cursor so the AI agent can query and inspect the app database (read/write queries, list tables, describe schema, etc.).

## Configuration

- **Config file:** `.cursor/mcp.json` (project-specific MCP)
- **Database path:** `database/database.sqlite` (Laravel default when `DB_CONNECTION=sqlite` and `DB_DATABASE` is unset)
- **Server:** [mcp-server-sqlite-npx](https://github.com/johnnyoshika/mcp-server-sqlite-npx) via `npx` (no global install)

## Requirements

- Node.js (already required for the frontend)
- SQLite DB file present: `database/database.sqlite`  
  Create it and run migrations if needed:
  ```bash
  touch database/database.sqlite
  php artisan migrate
  ```

## How it works

- Cursor reads `.cursor/mcp.json` when you open the project.
- The SQLite MCP server is started with `npx -y mcp-server-sqlite-npx database/database.sqlite`.
- The path is relative to the **project root** (Cursor typically runs MCP from the workspace root).

## Custom database path

If you use a different DB file (e.g. set `DB_DATABASE` in `.env`), update the path in `.cursor/mcp.json`:

```json
"args": [
  "-y",
  "mcp-server-sqlite-npx",
  "/absolute/path/to/your/database.sqlite"
]
```

## Alternative: UV (Python)

If you use [uv](https://docs.astral.sh/uv/) and prefer the official Python SQLite MCP:

```json
"sqlite": {
  "command": "uvx",
  "args": [
    "mcp-server-sqlite",
    "--db-path",
    "database/database.sqlite"
  ]
}
```

Replace the `sqlite` entry in `.cursor/mcp.json` with the above if you switch to `uvx`.
