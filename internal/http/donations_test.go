package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubDonationChars struct {
	wallets map[int64][]map[string]any
	journal map[int64]map[int][]map[string]any
	chars   map[int64]map[string]any
}

func (s *stubDonationChars) GetCorporationWallets(ctx context.Context, charID, corpID int64) ([]map[string]any, error) {
	return s.wallets[corpID], nil
}
func (s *stubDonationChars) GetCorporationWalletJournal(ctx context.Context, charID, corpID int64, div int) ([]map[string]any, error) {
	return s.journal[corpID][div], nil
}
func (s *stubDonationChars) ClearCorporationWalletCache(corpID int64) {}
func (s *stubDonationChars) GetCharacter(ctx context.Context, id int64) (map[string]any, error) {
	if c, ok := s.chars[id]; ok {
		return c, nil
	}
	return map[string]any{"name": "Character"}, nil
}

type stubDonations struct {
	cfg *store.DonationConfig
}

func (s *stubDonations) DonationConfigForCorps(ctx context.Context, ids []int64) (*store.DonationConfig, error) {
	for _, id := range ids {
		if s.cfg != nil && s.cfg.IsActive && s.cfg.CorporationID == id {
			return s.cfg, nil
		}
	}
	return nil, nil
}
func (s *stubDonations) UpsertDonationConfig(ctx context.Context, corpID int64, name string, charID, uid int64) (*store.DonationConfig, error) {
	s.cfg = &store.DonationConfig{CorporationID: corpID, CharacterID: charID, EnabledByUserID: uid, IsActive: true}
	if name != "" {
		s.cfg.CorporationName = sql.NullString{String: name, Valid: true}
	}
	return s.cfg, nil
}
func (s *stubDonations) DeactivateDonationConfig(ctx context.Context, corpID int64) error {
	if s.cfg != nil {
		s.cfg.IsActive = false
	}
	return nil
}

func donationTestDeps() Deps {
	stub := testAuth()
	corpID := int64(2001)
	stub.chars = []auth.Character{{
		CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
		CorporationID: &corpID, CorporationName: strPtr("ACME"),
		Scopes: []string{"esi-wallet.read_corporation_wallets.v1"},
	}}
	return Deps{Sessions: stub, Users: stub,
		Donations: &stubDonations{cfg: &store.DonationConfig{
			CorporationID: 2001, CorporationName: sqlNullStrCorp("ACME"),
			CharacterID: 11, EnabledByUserID: 1, IsActive: true,
		}},
		DonationCharacters: &stubDonationChars{
			wallets: map[int64][]map[string]any{},
			journal: map[int64]map[int][]map[string]any{},
			chars:   map[int64]map[string]any{100: {"name": "Donor"}},
		},
		Corp: &stubCorp{},
	}
}

func donationJournal() []map[string]any {
	return []map[string]any{
		{"id": 1, "date": "2026-11-01T12:00:00Z", "amount": 1000000.0, "ref_type": "player_donation",
			"first_party_id": 100, "reason": "for the corp", "balance": 5000000.0},
		{"id": 2, "date": "2026-11-02T12:00:00Z", "amount": 500000.0, "ref_type": "player_donation",
			"first_party_id": 100, "reason": "", "balance": 5500000.0},
		{"id": 3, "date": "2026-11-01T12:00:00Z", "amount": -100.0, "ref_type": "player_donation",
			"first_party_id": 101, "reason": "", "balance": 0.0},
		{"id": 4, "date": "2026-11-01T12:00:00Z", "amount": 10.0, "ref_type": "market_tax",
			"first_party_id": 102, "reason": "", "balance": 0.0},
	}
}

func TestDonationStatus(t *testing.T) {
	d := donationTestDeps()
	code, body := get(t, d, "/api/v1/corporation/donations/status")
	if code != http.StatusOK {
		t.Fatalf("status = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if data["enabled"] != true || data["enabled_by_me"] != true || data["can_enable"] != true {
		t.Fatalf("status = %v", data)
	}
	if data["provider_character_name"] != "Main" || data["corporation_name"] != "ACME" {
		t.Fatalf("names = %v", data)
	}
	if code := postRaw(t, d, "/api/v1/corporation/donations/disable", `{}`); code != http.StatusOK {
		t.Fatalf("disable = %d", code)
	}
	code, body = get(t, d, "/api/v1/corporation/donations/status")
	if body["data"].(map[string]any)["enabled"] != false {
		t.Fatalf("still enabled: %v", body)
	}
	if code := postRaw(t, d, "/api/v1/corporation/donations/enable", `{"character_id":11}`); code != http.StatusOK {
		t.Fatalf("enable = %d", code)
	}
	if code := postRaw(t, d, "/api/v1/corporation/donations/enable", `{"character_id":99}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad char = %d", code)
	}
	d2 := donationTestDeps()
	d2.Sessions.(*stubAuth).uid = 2
	if code := postRaw(t, d2, "/api/v1/corporation/donations/disable", `{}`); code != http.StatusForbidden {
		t.Fatalf("foreign disable = %d", code)
	}
	if code := postRaw(t, d, "/api/v1/corporation/donations/refresh", `{}`); code != http.StatusNoContent {
		t.Fatalf("refresh = %d", code)
	}
}

func TestDonationIndex(t *testing.T) {
	d := donationTestDeps()
	d.DonationCharacters.(*stubDonationChars).wallets = map[int64][]map[string]any{
		2001: {{"division": 1.0, "balance": 10000000.0}},
	}
	d.DonationCharacters.(*stubDonationChars).journal = map[int64]map[int][]map[string]any{
		2001: {1: donationJournal()},
	}
	code, body := get(t, d, "/api/v1/corporation/donations")
	if code != http.StatusOK {
		t.Fatalf("index = %d %v", code, body)
	}
	items := body["data"].([]any)
	if len(items) != 1 {
		t.Fatalf("items = %v", items)
	}
	it := items[0].(map[string]any)
	if it["total_amount"] != 1500000.0 || it["donation_count"] != 2.0 || it["donator_name"] != "Donor" {
		t.Fatalf("item = %v", it)
	}
	sum := body["meta"].(map[string]any)["summary"].(map[string]any)
	if sum["total"] != 1500000.0 || sum["corporation_id"] != 2001.0 {
		t.Fatalf("summary = %v", sum)
	}
	divs := sum["wallet_divisions"].([]any)
	if len(divs) != 1 || divs[0].(map[string]any)["name"] != "Master Wallet" {
		t.Fatalf("divisions = %v", divs)
	}
	code, body = get(t, d, "/api/v1/corporation/donations?division=2")
	if code != http.StatusOK || len(body["data"].([]any)) != 0 {
		t.Fatalf("division filter = %d %v", code, body)
	}
	code, body = get(t, d, "/api/v1/corporation/donations?date_from=2020-01-01&date_to=2020-01-31")
	if code != http.StatusOK || len(body["data"].([]any)) != 0 {
		t.Fatalf("date filter = %d %v", code, body)
	}
	if code, _ := get(t, d, "/api/v1/corporation/donations?division=9"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad division = %d", code)
	}
	d3 := donationTestDeps()
	d3.Donations.(*stubDonations).cfg = nil
	if code, _ := get(t, d3, "/api/v1/corporation/donations"); code != http.StatusForbidden {
		t.Fatalf("disabled = %d", code)
	}
}
