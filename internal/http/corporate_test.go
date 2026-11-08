package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubCorp struct {
	configs map[int64]*store.CorpConfig
	members map[int64][]store.CorpCharacter
}

func (s *stubCorp) ActiveCorpConfig(ctx context.Context, corpID int64) (*store.CorpConfig, error) {
	return s.configs[corpID], nil
}
func (s *stubCorp) UserCorpCharacters(ctx context.Context, userID, corpID int64) ([]store.CorpCharacter, error) {
	return s.members[corpID], nil
}

func corpTestDeps(cfgs map[int64]*store.CorpConfig, members map[int64][]store.CorpCharacter, corpID *int64) Deps {
	stub := testAuth()
	stub.chars = []auth.Character{{CharacterID: 1, Name: "Main", IsMain: true, CorporationID: corpID}}
	return Deps{Sessions: stub, Users: stub, Corp: &stubCorp{configs: cfgs, members: members}}
}

func TestCorporateExportAvailable(t *testing.T) {
	corp := int64(987)
	d := corpTestDeps(
		map[int64]*store.CorpConfig{987: {CorporationID: 987, CorporationName: "ACME", IsActive: true}},
		map[int64][]store.CorpCharacter{987: {{IsDirectorCEO: true}}},
		&corp,
	)
	code, body := get(t, d, "/api/v1/calculators/corporate-export")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["available"] != true || data["corporation_name"] != "ACME" {
		t.Fatalf("data = %v", data)
	}
}

func TestCorporateExportUnavailable(t *testing.T) {
	// no corp on character
	d := corpTestDeps(nil, nil, nil)
	code, body := get(t, d, "/api/v1/calculators/corporate-export")
	if code != http.StatusOK || body["data"].(map[string]any)["available"] != false {
		t.Fatalf("code = %d %v", code, body)
	}
	// config exists but no director role
	corp := int64(987)
	d = corpTestDeps(
		map[int64]*store.CorpConfig{987: {CorporationID: 987, CorporationName: "ACME", IsActive: true}},
		map[int64][]store.CorpCharacter{987: {{Roles: []string{"Member"}}}},
		&corp,
	)
	code, body = get(t, d, "/api/v1/calculators/corporate-export")
	if code != http.StatusOK || body["data"].(map[string]any)["available"] != false {
		t.Fatalf("member = %d %v", code, body)
	}
	// factory manager counts
	d = corpTestDeps(
		map[int64]*store.CorpConfig{987: {CorporationID: 987, CorporationName: "ACME", IsActive: true}},
		map[int64][]store.CorpCharacter{987: {{Roles: []string{"Factory_Manager"}}}},
		&corp,
	)
	if code, _ := get(t, d, "/api/v1/calculators/corporate-export"); code != http.StatusOK {
		t.Fatalf("fm = %d", code)
	}
}
