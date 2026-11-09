package jobs

import (
	"os"
	"testing"
)

func TestImportRegistration(t *testing.T) {
	r := NewRunner(Deps{})
	RegisterImportJobs(r, HandlerDeps{})
	for _, name := range []string{"market-update-prices", "import-daily-market-history", "import-public-contracts"} {
		if _, ok := r.Jobs[name]; !ok {
			t.Fatalf("missing %s", name)
		}
	}
	entries := ImportSchedule(HandlerDeps{}, 10000002)
	if len(entries) != 3 {
		t.Fatalf("entries = %d", len(entries))
	}
}

func writeTestFile(path, content string) error {
	return os.WriteFile(path, []byte(content), 0o644)
}

func TestReadCSVMap(t *testing.T) {
	dir := t.TempDir()
	path := dir + "/t.csv"
	content := "a,b\n1,2\n3,4\n"
	if err := writeTestFile(path, content); err != nil {
		t.Fatal(err)
	}
	rows, err := readCSVMap(path, []string{"a", "b"})
	if err != nil || len(rows) != 2 || rows[0]["a"] != "1" {
		t.Fatalf("rows = %v %v", rows, err)
	}
	if _, err := readCSVMap(path, []string{"a", "zzz"}); err == nil {
		t.Fatal("expected missing column error")
	}
	bad := dir + "/bad.csv"
	if err := writeTestFile(bad, "a,b\n1\n"); err != nil {
		t.Fatal(err)
	}
	if _, err := readCSVMap(bad, []string{"a", "b"}); err == nil {
		t.Fatal("expected malformed row error")
	}
}
