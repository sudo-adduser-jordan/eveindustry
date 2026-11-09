package main

import (
	"regexp"
	"strings"
	"testing"
)

var (
	bt               = "\x60"
	colDefRe         = regexp.MustCompile("(?m)^\\s*" + "\x60" + "([^`]+)" + "\x60" + "\\s+[A-Z]")
	namedKeyRe       = regexp.MustCompile("(?i)(?:UNIQUE\\s+)?KEY\\s+" + "\x60" + "([^`]+)" + "\x60")
	alterAddColRe    = regexp.MustCompile("(?i)ADD\\s+COLUMN\\s+" + "\x60" + "([^`]+)" + "\x60")
	alterDropColRe   = regexp.MustCompile("(?i)DROP\\s+COLUMN\\s+" + "\x60" + "([^`]+)" + "\x60")
	alterModifyColRe = regexp.MustCompile("(?i)MODIFY\\s+(?:COLUMN\\s+)?" + "\x60" + "([^`]+)" + "\x60")
	alterAddKeyRe    = regexp.MustCompile("(?i)ADD\\s+(?:UNIQUE\\s+)?KEY\\s+" + "\x60" + "([^`]+)" + "\x60")
	alterDropIndexRe = regexp.MustCompile("(?i)DROP\\s+INDEX\\s+" + "\x60" + "([^`]+)" + "\x60")
	dropIndexOnRe    = regexp.MustCompile("(?i)DROP\\s+INDEX\\s+" + "\x60" + "([^`]+)" + "\x60" + "\\s+ON\\s+" + "\x60" + "([^`]+)" + "\x60")
)

func quotedName(s, prefix string) string {
	i := strings.Index(strings.ToUpper(s), prefix) + len(prefix)
	rest := strings.TrimSpace(s[i:])
	if strings.HasPrefix(rest, "IF EXISTS") {
		rest = strings.TrimSpace(rest[len("IF EXISTS"):])
	}
	rest = strings.Trim(rest, "`")
	if j := strings.Index(rest, "`"); j >= 0 {
		return rest[:j]
	}
	if j := strings.IndexAny(rest, " ("); j >= 0 {
		return rest[:j]
	}
	return rest
}

func lastQuoted(s string) string {
	i := strings.LastIndex(s, "`")
	j := strings.LastIndex(s[:i], "`")
	if i < 0 || j < 0 {
		return ""
	}
	return s[j+1 : i]
}

func TestSplitStatements(t *testing.T) {
	src := "CREATE TABLE `a` (\n  `c` VARCHAR(255) NOT NULL DEFAULT 'a;b',\n  `d` ENUM('x','y') NOT NULL\n); -- trailing;\n# hash;\nINSERT INTO `a` VALUES ('it''s; fine');\n"
	stmts := splitStatements(src)
	if len(stmts) != 2 {
		t.Fatalf("got %d statements: %q", len(stmts), stmts)
	}
	if !strings.Contains(stmts[0], "CREATE TABLE") || !strings.Contains(stmts[1], "INSERT INTO") {
		t.Fatalf("bad split: %q", stmts)
	}
}

func TestMigrationsPairAndSplit(t *testing.T) {
	versions, err := availableVersions()
	if err != nil {
		t.Fatal(err)
	}
	if len(versions) != 1 {
		t.Fatalf("got %d versions, want 1 squashed baseline", len(versions))
	}
	for _, v := range versions {
		for _, name := range []string{v + ".up.sql", v + ".down.sql"} {
			stmts := splitStatements(readTestFile(t, name))
			empty := true
			for _, s := range stmts {
				if strings.TrimSpace(s) != "" {
					empty = false
				}
			}
			if empty {
				t.Errorf("%s has no statements", name)
			}
		}
	}
}

func readTestFile(t *testing.T, name string) string {
	t.Helper()
	raw, err := migrationFS.ReadFile(name)
	if err != nil {
		t.Fatal(err)
	}
	return string(raw)
}

func TestMigrationChainConsistent(t *testing.T) {
	versions, err := availableVersions()
	if err != nil {
		t.Fatal(err)
	}
	cols := map[string]map[string]bool{}
	indexes := map[string]map[string]bool{}
	for _, v := range versions {
		raw := readTestFile(t, v+".up.sql")
		for _, stmt := range splitStatements(raw) {
			s := strings.TrimSpace(stmt)
			if s == "" {
				continue
			}
			u := strings.ToUpper(s)
			switch {
			case strings.HasPrefix(u, "CREATE TABLE"):
				name := quotedName(s, "CREATE TABLE")
				cols[name] = map[string]bool{}
				indexes[name] = map[string]bool{}
				body := s[strings.Index(s, "("):]
				for _, m := range colDefRe.FindAllStringSubmatch(body, -1) {
					cols[name][m[1]] = true
				}
				for _, m := range namedKeyRe.FindAllStringSubmatch(body, -1) {
					indexes[name][m[1]] = true
				}
			case strings.HasPrefix(u, "ALTER TABLE"):
				name := quotedName(s, "ALTER TABLE")
				if cols[name] == nil {
					t.Errorf("%s: ALTER unknown table %s", v, name)
					continue
				}
				for _, m := range alterAddColRe.FindAllStringSubmatch(s, -1) {
					if cols[name][m[1]] {
						t.Errorf("%s: ADD existing column %s.%s", v, name, m[1])
					}
					cols[name][m[1]] = true
				}
				for _, m := range alterDropColRe.FindAllStringSubmatch(s, -1) {
					if !cols[name][m[1]] {
						t.Errorf("%s: DROP missing column %s.%s", v, name, m[1])
					}
					delete(cols[name], m[1])
				}
				for _, m := range alterModifyColRe.FindAllStringSubmatch(s, -1) {
					if !cols[name][m[1]] {
						t.Errorf("%s: MODIFY missing column %s.%s", v, name, m[1])
					}
				}
				for _, m := range alterAddKeyRe.FindAllStringSubmatch(s, -1) {
					indexes[name][m[1]] = true
				}
				for _, m := range alterDropIndexRe.FindAllStringSubmatch(s, -1) {
					if !indexes[name][m[1]] {
						t.Errorf("%s: DROP missing index %s.%s", v, name, m[1])
					}
					delete(indexes[name], m[1])
				}
			case strings.HasPrefix(u, "DROP TABLE"):
				delete(cols, lastQuoted(s))
				delete(indexes, lastQuoted(s))
			case strings.HasPrefix(u, "DROP INDEX"):
				m := dropIndexOnRe.FindStringSubmatch(s)
				if len(m) == 3 {
					if indexes[m[2]] == nil {
						t.Errorf("%s: DROP INDEX on unknown table %s", v, m[2])
					} else if !indexes[m[2]][m[1]] {
						t.Errorf("%s: DROP missing index %s.%s", v, m[2], m[1])
					} else {
						delete(indexes[m[2]], m[1])
					}
				}
			}
		}
	}
}
