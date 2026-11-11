# Tasks

Reference: https://taskfile.dev/docs/reference/schema

Taskfile uses schema version 3 with `output: prefixed` for colored per task logging. Every `cmds` entry uses `[command]` object form (`cmd:` for shell, `task:` for subtask). No external scripts: all work is inline in `Taskfile.yml`, no `scripts/*.sh`.

Default command set only:

- `install`: download Go modules, `npm ci` in `frontend`, compile Go tree.
- `update`: `go get -u` plus `go mod tidy`, `npm update` in `frontend`.
- `remove`: delete `.run`, `frontend/node_modules`, JSONL outputs and `public/app`.
- `setup`: create `.env` from `.env.example` on first run, then install, migrate up, SDE import and demo reset.
- `migrate`: run `go run ./cmd/migrate` with `CLI_ARGS` passthrough, usage `task migrate -- [up|down|status] [n]`.
- `start`: build api worker scheduler binaries, migrate up, launch daemons with pidfiles under `.run`, wait on `/up` and Vite with `curl --retry`, no `sleep` binary.
- `stop`: graceful kill then `timeout tail --pid` wait, no `sleep` binary.
- `dev`: run api and Vite dev server concurrently for local development.

Never use `sleep`: readiness uses `curl --retry --retry-delay --retry-all-errors`, process exit uses `timeout tail --pid -f /dev/null`. No `time.Sleep` in Go: backoff uses `<-time.After`.
