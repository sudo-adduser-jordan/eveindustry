# Logging

Reference: https://loggingsucks.com/

All Go processes use `internal/logx` which wraps `log/slog`.

Sinks:

- stdout: pretty printed single line per record with ANSI color. Timestamp dim, level bold color (DEBUG blue, INFO green, WARN yellow, ERROR red), message bold white, keys cyan, values colored by type (string green, int and float magenta, bool yellow, duration blue, error red, other white). Value types drive color choice.
- `./logs/test/app.jsonl`: JSONL, every record. Fields: timestamp RFC3339Nano UTC, level, service, msg plus all attrs. Wide event style: one structured record per operation with full context, high cardinality fields preserved.
- `./logs/errors/app.jsonl`: JSONL, only WARN and above. Same schema as test file.

Services pass their name to `logx.New`: `api`, `worker`, `scheduler`, `job`. `HandlerDeps.Log` receives the same logger so background jobs emit wide events with job name, attempts and payload context.

Directories are created on startup via `MkdirAll`. Log files are opened append only. JSONL keeps one JSON object per line for grep free querying with `jq`.

Query examples:

```sh
jq -c 'select(.level=="ERROR")' logs/errors/app.jsonl
jq -s 'group_by(.msg) | map({msg: .[0].msg, count: length})' logs/test/app.jsonl
```

No `log/slog` JSON handler is constructed directly in `cmd/*`. Echo access logs go to stdout through the same pretty printer.
