package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

type stubSkills struct {
	levels map[int]int
	err    error
}

func (s *stubSkills) GetSkills(ctx context.Context, characterID int64) (map[int]int, error) {
	return s.levels, s.err
}

func skillsTestDeps(levels map[int]int) Deps {
	stub := testAuth()
	stub.chars = []auth.Character{{CharacterID: 11, Name: "Main", IsMain: true}}
	return Deps{Sessions: stub, Users: stub, Skills: SkillsDeps{Characters: &stubSkills{levels: levels}}}
}

func TestManufacturingSkills(t *testing.T) {
	d := skillsTestDeps(map[int]int{3380: 5, 3388: 4})
	code, body := get(t, d, "/api/v1/calculators/manufacturing/skills?character_id=11")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	skills := body["data"].(map[string]any)["skills"].(map[string]any)
	if skills["industry"].(float64) != 5 || skills["advanced_industry"].(float64) != 4 {
		t.Fatalf("skills = %v", skills)
	}
	if code, _ := get(t, d, "/api/v1/calculators/manufacturing/skills?character_id=99"); code != http.StatusNotFound {
		t.Fatalf("foreign char = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/calculators/manufacturing/skills"); code != http.StatusUnprocessableEntity {
		t.Fatalf("missing id = %d", code)
	}
}

func TestReactionAndInventionSkills(t *testing.T) {
	d := skillsTestDeps(map[int]int{45746: 3, 11443: 5, 23087: 2})
	code, body := get(t, d, "/api/v1/calculators/reactions/skills?character_id=11")
	if code != http.StatusOK || body["data"].(map[string]any)["skills"].(map[string]any)["reactions"].(float64) != 3 {
		t.Fatalf("reactions = %d %v", code, body)
	}
	code, body = get(t, d, "/api/v1/calculators/invention/skills?character_id=11")
	if code != http.StatusOK {
		t.Fatalf("invention = %d %v", code, body)
	}
	skills := body["data"].(map[string]any)["skills"].(map[string]any)
	if len(skills["encryption"].([]any)) != 4 || len(skills["science"].([]any)) != 13 {
		t.Fatalf("skill lists = %v", skills)
	}
}
