package main

import (
	"database/sql"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"

	_ "modernc.org/sqlite"

	"github.com/sudo-adduser-jordan/eveindustry/internal/config"
	"github.com/sudo-adduser-jordan/eveindustry/migrations"
)

func dirOf(p string) string { return filepath.Dir(p) }

var migrationFS = migrations.FS

// Minimal migrate runner: `migrate up|down [n]|status`.
//
// Each migrations/<version>_<name>.up.sql/.down.sql pair is one version,
// applied in filename order like Laravel. Applied versions live in
// schema_migrations. Statements run one at a time and a failure stops the
// run (same as `php artisan migrate`).
func main() {
	if err := run(os.Args[1:]); err != nil {
		fmt.Fprintln(os.Stderr, "migrate: "+err.Error())
		os.Exit(1)
	}
}

func run(args []string) error {
	cfg := config.Load()
	if cfg.DBPath == "" {
		return fmt.Errorf("no database configured (set DB_PATH)")
	}
	if dir := dirOf(cfg.DBPath); dir != "" && dir != "." {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			return err
		}
	}
	dsn := cfg.DBPath + "?_pragma=journal_mode(WAL)&_pragma=busy_timeout(5000)&_pragma=foreign_keys(1)&_time_format=sqlite&_timezone=UTC&_texttotime=1"
	db, err := sql.Open("sqlite", dsn)
	if err != nil {
		return err
	}
	defer func() { _ = db.Close() }()

	if _, err := db.Exec(`CREATE TABLE IF NOT EXISTS schema_migrations (
		version VARCHAR(255) PRIMARY KEY,
		applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
	)`); err != nil {
		return err
	}

	versions, err := availableVersions()
	if err != nil {
		return err
	}
	applied, err := appliedVersions(db)
	if err != nil {
		return err
	}

	cmd := "up"
	if len(args) > 0 {
		cmd = args[0]
	}
	switch cmd {
	case "status":
		for _, v := range versions {
			state := "pending"
			if applied[v] {
				state = "applied"
			}
			fmt.Printf("%s %s\n", state, v)
		}
		return nil
	case "up":
		n := len(versions)
		if len(args) > 1 {
			n, err = strconv.Atoi(args[1])
			if err != nil {
				return fmt.Errorf("bad count %q", args[1])
			}
		}
		done := 0
		for _, v := range versions {
			if done >= n {
				break
			}
			if applied[v] {
				continue
			}
			if err := applyFile(db, v+".up.sql"); err != nil {
				return fmt.Errorf("%s: %w", v, err)
			}
			if _, err := db.Exec(`INSERT INTO schema_migrations (version) VALUES (?)`, v); err != nil {
				return err
			}
			fmt.Println("applied " + v)
			done++
		}
		return nil
	case "down":
		n := 1
		if len(args) > 1 {
			n, err = strconv.Atoi(args[1])
			if err != nil {
				return fmt.Errorf("bad count %q", args[1])
			}
		}
		var have []string
		for _, v := range versions {
			if applied[v] {
				have = append(have, v)
			}
		}
		for i := len(have) - 1; i >= 0 && n > 0; i-- {
			v := have[i]
			if err := applyFile(db, v+".down.sql"); err != nil {
				return fmt.Errorf("%s: %w", v, err)
			}
			if _, err := db.Exec(`DELETE FROM schema_migrations WHERE version = ?`, v); err != nil {
				return err
			}
			fmt.Println("rolled back " + v)
			n--
		}
		return nil
	default:
		return fmt.Errorf("usage: migrate [up [n]|down [n]|status]")
	}
}

func availableVersions() ([]string, error) {
	entries, err := migrationFS.ReadDir(".")
	if err != nil {
		return nil, err
	}
	var out []string
	for _, e := range entries {
		name := e.Name()
		if strings.HasSuffix(name, ".up.sql") {
			v := strings.TrimSuffix(name, ".up.sql")
			if _, err := migrationFS.ReadFile(v + ".down.sql"); err != nil {
				return nil, fmt.Errorf("migration %s missing .down.sql", v)
			}
			out = append(out, v)
		}
	}
	sort.Strings(out)
	return out, nil
}

func appliedVersions(db *sql.DB) (map[string]bool, error) {
	rows, err := db.Query(`SELECT version FROM schema_migrations`)
	if err != nil {
		return nil, err
	}
	defer func() { _ = rows.Close() }()
	out := map[string]bool{}
	for rows.Next() {
		var v string
		if err := rows.Scan(&v); err != nil {
			return nil, err
		}
		out[v] = true
	}
	return out, rows.Err()
}

func applyFile(db *sql.DB, name string) error {
	raw, err := migrationFS.ReadFile(name)
	if err != nil {
		return err
	}
	for _, stmt := range splitStatements(string(raw)) {
		if strings.TrimSpace(stmt) == "" {
			continue
		}
		if _, err := db.Exec(stmt); err != nil {
			return fmt.Errorf("statement failed: %v\n%s", err, stmt)
		}
	}
	return nil
}

// splitStatements splits on semicolons outside strings, quoted identifiers,
// line comments and block comments.
func splitStatements(src string) []string {
	var out []string
	var cur strings.Builder
	i := 0
	q := byte(0)
	for i < len(src) {
		c := src[i]
		if q != 0 {
			cur.WriteByte(c)
			if c == '\\' && (q == '\'' || q == '"') && i+1 < len(src) {
				cur.WriteByte(src[i+1])
				i++
			} else if c == q {
				if q == '`' || (i+1 < len(src) && src[i+1] != q) {
					q = 0
				} else {
					cur.WriteByte(src[i+1])
					i++
				}
			}
		} else if c == '\'' || c == '"' || c == '`' {
			q = c
			cur.WriteByte(c)
		} else if c == '-' && i+2 < len(src) && src[i+1] == '-' && (src[i+2] == ' ' || src[i+2] == '\t' || src[i+2] == '\n') {
			for i < len(src) && src[i] != '\n' {
				cur.WriteByte(src[i])
				i++
			}
			continue
		} else if c == '#' {
			for i < len(src) && src[i] != '\n' {
				cur.WriteByte(src[i])
				i++
			}
			continue
		} else if c == '/' && i+1 < len(src) && src[i+1] == '*' {
			for i+1 < len(src) && !(src[i] == '*' && src[i+1] == '/') {
				cur.WriteByte(src[i])
				i++
			}
			cur.WriteString("*/")
			i += 2
			continue
		} else if c == ';' {
			out = append(out, cur.String())
			cur.Reset()
		} else {
			cur.WriteByte(c)
		}
		i++
	}
	if tail := strings.TrimSpace(cur.String()); tail != "" {
		out = append(out, cur.String())
	}
	return out
}
