package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

type stubProjectESI struct {
	projects     map[int64][]map[string]any
	contributors map[string][]map[string]any
}

func (s *stubProjectESI) GetCorporationProjects(ctx context.Context, charID, corpID int64) ([]map[string]any, error) {
	return s.projects[corpID], nil
}
func (s *stubProjectESI) GetCorporationProjectContributors(ctx context.Context, charID, corpID int64, pid string) ([]map[string]any, error) {
	return s.contributors[pid], nil
}
func (s *stubProjectESI) ClearCorporationProjectsCache(corpID int64) {}
func (s *stubProjectESI) ClearCorporationProjectContributorsCache(corpID int64, pid string) {
}

func projectsTestDeps() Deps {
	stub := testAuth()
	corpID := int64(2001)
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		CorporationID: &corpID, CorporationName: strPtr("ACME"),
		Scopes: []string{"esi-corporations.read_projects.v1"},
	}}
	return Deps{Sessions: stub, Users: stub,
		Projects: &stubProjectESI{
			projects: map[int64][]map[string]any{2001: {
				{"id": "p1", "name": "Build Rifters", "state": "Active", "last_modified": "2026-11-07T10:00:00Z",
					"progress": map[string]any{"current": 5.0, "desired": 10.0},
					"reward":   map[string]any{"initial": 1000.0, "remaining": 400.0}},
				{"id": "p2", "name": "Old Job", "state": "Completed", "last_modified": "2026-11-06T10:00:00Z",
					"progress": map[string]any{"current": 10.0, "desired": 10.0},
					"reward":   map[string]any{"initial": 2000.0, "remaining": 0.0}},
				{"id": "p3", "name": "No Reward", "state": "Active", "last_modified": "2026-11-08T10:00:00Z",
					"progress": map[string]any{"current": 0.0, "desired": 10.0}},
			}},
			contributors: map[string][]map[string]any{
				"p1": {{"character_id": 100, "name": "Builder", "contributed": 5.0}},
				"p2": {
					{"character_id": 100, "name": "Builder", "contributed": 6.0},
					{"character_id": 101, "name": "Helper", "contributed": 4.0},
				},
			},
		},
	}
}

func TestProjectsIndex(t *testing.T) {
	d := projectsTestDeps()
	code, body := get(t, d, "/api/v1/corporation/projects")
	if code != http.StatusOK {
		t.Fatalf("index = %d %v", code, body)
	}
	items := body["data"].([]any)
	if len(items) != 3 {
		t.Fatalf("items = %v", items)
	}
	// sorted by last_modified desc: p3 first
	if items[0].(map[string]any)["id"] != "p3" {
		t.Fatalf("order = %v", items[0])
	}
	p1 := items[1].(map[string]any)
	if p1["completion_pct"] != 50.0 || p1["is_complete"] != false || p1["reward_earned"] != 600.0 {
		t.Fatalf("p1 = %v", p1)
	}
	if len(p1["contributors"].([]any)) != 1 {
		t.Fatalf("contributors = %v", p1["contributors"])
	}
	sum := body["meta"].(map[string]any)["summary"].(map[string]any)
	if sum["total_projects"] != 3.0 || sum["completed_projects"] != 1.0 || sum["in_progress_projects"] != 1.0 {
		t.Fatalf("summary = %v", sum)
	}
	if sum["total_reward_earned"] != 2600.0 || sum["overall_completion_pct"] != 50.0 {
		t.Fatalf("totals = %v", sum)
	}
	// no scope -> 403
	d2 := projectsTestDeps()
	d2.Sessions.(*stubAuth).chars[0].Scopes = nil
	d2.Users.(*stubAuth).chars[0].Scopes = nil
	if code, _ := get(t, d2, "/api/v1/corporation/projects"); code != http.StatusForbidden {
		t.Fatalf("gate = %d", code)
	}
	if code := postRaw(t, d, "/api/v1/corporation/projects/refresh", `{}`); code != http.StatusNoContent {
		t.Fatalf("refresh = %d", code)
	}
}

func TestProjectContributors(t *testing.T) {
	d := projectsTestDeps()
	code, body := get(t, d, "/api/v1/corporation/projects/contributors")
	if code != http.StatusOK {
		t.Fatalf("leaders = %d %v", code, body)
	}
	items := body["data"].([]any)
	// only p2 is Closed/Completed + rewarded: Builder 6*200=1200, Helper 4*200=800
	if len(items) != 2 {
		t.Fatalf("items = %v", items)
	}
	top := items[0].(map[string]any)
	if top["character_name"] != "Builder" || top["attributed_isk"] != 1200.0 || top["project_count"] != 1.0 {
		t.Fatalf("top = %v", top)
	}
	if top["attributed_pct"] != 60.0 {
		t.Fatalf("pct = %v", top)
	}
	sum := body["meta"].(map[string]any)["summary"].(map[string]any)
	if sum["total_attributed_isk"] != 2000.0 || sum["total_rewarded_projects"] != 1.0 {
		t.Fatalf("summary = %v", sum)
	}
	// project name filter excluding all
	code, body = get(t, d, "/api/v1/corporation/projects/contributors?project_name=zzz")
	if code != http.StatusOK || len(body["data"].([]any)) != 0 {
		t.Fatalf("filter = %d %v", code, body)
	}
	// date filter excluding all
	code, body = get(t, d, "/api/v1/corporation/projects/contributors?date_from=2020-01-01&date_to=2020-12-31")
	if code != http.StatusOK || len(body["data"].([]any)) != 0 {
		t.Fatalf("dates = %d %v", code, body)
	}
	if code, _ := get(t, d, "/api/v1/corporation/projects/contributors?date_from=nope"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad date = %d", code)
	}
}
