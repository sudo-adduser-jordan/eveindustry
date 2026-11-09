package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubCorp struct {
	configs map[int64]*store.CorpConfig
	members map[int64][]store.CorpCharacter
	full    map[int64]*store.CorpConfigFull
}

func (s *stubCorp) CorpConfigForCorps(ctx context.Context, corpIDs []int64) (*store.CorpConfigFull, error) {
	for _, id := range corpIDs {
		if c, ok := s.full[id]; ok && c.IsActive {
			return c, nil
		}
	}
	return nil, nil
}
func (s *stubCorp) UpsertCorpConfig(ctx context.Context, corpID int64, corpName string, characterID, userID int64) (*store.CorpConfigFull, error) {
	if s.full == nil {
		s.full = map[int64]*store.CorpConfigFull{}
	}
	c := &store.CorpConfigFull{CorporationID: corpID, CharacterID: characterID, EnabledByUserID: userID, IsActive: true}
	c.CorporationName = sqlNullStrCorp(corpName)
	s.full[corpID] = c
	return c, nil
}
func (s *stubCorp) DeactivateCorpConfig(ctx context.Context, corpID int64) error {
	if c, ok := s.full[corpID]; ok {
		c.IsActive = false
	}
	return nil
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

func sqlNullStrCorp(s string) sql.NullString {
	if s == "" {
		return sql.NullString{}
	}
	return sql.NullString{String: s, Valid: true}
}
