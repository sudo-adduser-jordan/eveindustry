package http

import (
	"context"
	"database/sql"
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubAdminUsers struct {
	rows map[int64]store.AdminUser
}

func (s *stubAdminUsers) AdminUsers(ctx context.Context, search, sort, dir string, lim, off int) ([]store.AdminUser, int, error) {
	var out []store.AdminUser
	for _, u := range s.rows {
		out = append(out, u)
	}
	return out, len(out), nil
}
func (s *stubAdminUsers) AdminUserByID(ctx context.Context, id int64) (*store.AdminUser, error) {
	if u, ok := s.rows[id]; ok {
		u := u
		return &u, nil
	}
	return nil, nil
}
func (s *stubAdminUsers) UpdateAdminUserFlags(ctx context.Context, id int64, active, admin, stock *bool) (*store.AdminUser, error) {
	u := s.rows[id]
	if active != nil {
		u.IsActive = *active
	}
	if admin != nil {
		u.IsAdmin = *admin
	}
	if stock != nil {
		u.IsStockManager = *stock
	}
	s.rows[id] = u
	return &u, nil
}
func (s *stubAdminUsers) DeleteAdminUser(ctx context.Context, id int64) error {
	delete(s.rows, id)
	return nil
}
func (s *stubAdminUsers) AdminUserEmailTaken(ctx context.Context, email string, except int64) (bool, error) {
	for id, u := range s.rows {
		if id != except && u.Email.Valid && u.Email.String == email {
			return true, nil
		}
	}
	return false, nil
}
func (s *stubAdminUsers) UpdateAdminAccount(ctx context.Context, id int64, email string, hash *string) (*store.AdminUser, error) {
	u := s.rows[id]
	u.Email = sql.NullString{String: email, Valid: true}
	if hash != nil {
		u.PasswordHash = sql.NullString{String: *hash, Valid: true}
	}
	s.rows[id] = u
	return &u, nil
}

func adminTestDeps() Deps {
	stub := testAuth()
	stub.user.IsAdmin = true
	created := time.Date(2026, 11, 1, 0, 0, 0, 0, time.UTC)
	hash, _ := hashPassword("oldpassword")
	d := Deps{Sessions: stub, Users: stub,
		AdminUsers: &stubAdminUsers{rows: map[int64]store.AdminUser{
			1: {ID: 1, Name: "Admin", Email: sql.NullString{String: "admin@x.io", Valid: true},
				IsAdmin: true, IsActive: true, CreatedAt: created,
				PasswordHash:      sql.NullString{String: hash, Valid: true},
				MainCharacterName: sql.NullString{String: "Main", Valid: true}, CharacterCount: 2},
			2: {ID: 2, Name: "User", Email: sql.NullString{String: "user@x.io", Valid: true},
				IsActive: true, CreatedAt: created, CharacterCount: 1},
			3: {ID: 3, Name: "Root", IsSuperAdmin: true, IsActive: true, CreatedAt: created},
		}},
	}
	d.IndustryDeps.Characters = &stubIndustryJobs{}
	d.IndustryDeps.Universe = stubUniverse{}
	d.Market = &stubMarket{}
	d.Industry = &stubIndustry{}
	d.Corp = &stubCorp{}
	return d
}

func TestAdminGate(t *testing.T) {
	d := adminTestDeps()
	d.Sessions.(*stubAuth).user.IsAdmin = false
	if code, _ := get(t, d, "/api/v1/admin/users"); code != http.StatusForbidden {
		t.Fatalf("gate = %d", code)
	}
}

func TestAdminUsers(t *testing.T) {
	d := adminTestDeps()
	code, body := get(t, d, "/api/v1/admin/users")
	if code != http.StatusOK {
		t.Fatalf("index = %d %v", code, body)
	}
	if body["meta"].(map[string]any)["pagination"].(map[string]any)["total"] != 3.0 {
		t.Fatalf("total = %v", body["meta"])
	}
	var adminItem map[string]any
	for _, it := range body["data"].([]any) {
		if it.(map[string]any)["display_name"] == "Main" {
			adminItem = it.(map[string]any)
		}
	}
	if adminItem == nil || adminItem["role"] != "admin" || adminItem["character_count"] != 2.0 {
		t.Fatalf("item = %v", adminItem)
	}
	if code, _ := get(t, d, "/api/v1/admin/users?sort=nope"); code != http.StatusUnprocessableEntity {
		t.Fatalf("bad sort = %d", code)
	}
	if code, body := post(t, d, "/api/v1/admin/users/2/toggle-status", `{}`); code != http.StatusOK {
		t.Fatalf("toggle = %d %v", code, body)
	} else if body["data"].(map[string]any)["is_active"] != false {
		t.Fatalf("toggled = %v", body["data"])
	}
	if code, _ := post(t, d, "/api/v1/admin/users/3/toggle-admin", `{}`); code != http.StatusForbidden {
		t.Fatalf("super toggle = %d", code)
	}
	if code, body := post(t, d, "/api/v1/admin/users/2/toggle-stock-manager", `{}`); code != http.StatusOK {
		t.Fatalf("stock = %d %v", code, body)
	} else if body["data"].(map[string]any)["is_stock_manager_enabled"] != true {
		t.Fatalf("stocked = %v", body["data"])
	}
	if code, _ := deleteReq(t, d, "/api/v1/admin/users/3"); code != http.StatusForbidden {
		t.Fatalf("super delete = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/admin/users/2"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/admin/users/99"); code != http.StatusNotFound {
		t.Fatalf("missing = %d", code)
	}
}

func TestAdminAccount(t *testing.T) {
	d := adminTestDeps()
	code, body := get(t, d, "/api/v1/admin/account")
	if code != http.StatusOK || body["data"].(map[string]any)["email"] != "admin@x.io" {
		t.Fatalf("show = %d %v", code, body)
	}
	if code, body := putJSON(t, d, "/api/v1/admin/account", `{"email":"new@x.io"}`); code != http.StatusOK {
		t.Fatalf("email = %d %v", code, body)
	} else if body["data"].(map[string]any)["email"] != "new@x.io" {
		t.Fatalf("updated = %v", body["data"])
	}
	if code, _ := putJSON(t, d, "/api/v1/admin/account", `{"email":"user@x.io"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("taken = %d", code)
	}
	if code, _ := putJSON(t, d, "/api/v1/admin/account", `{"email":"new@x.io","password":"newpassword","password_confirmation":"newpassword","current_password":"wrong"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("wrong current = %d", code)
	}
	if code, _ := putJSON(t, d, "/api/v1/admin/account", `{"email":"new@x.io","password":"newpassword","password_confirmation":"newpassword","current_password":"oldpassword"}`); code != http.StatusOK {
		t.Fatalf("password = %d", code)
	}
	if code, _ := putJSON(t, d, "/api/v1/admin/account", `{"email":"new@x.io","password":"short","password_confirmation":"short","current_password":"newpassword"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("short = %d", code)
	}
}

func TestAdminUserJobs(t *testing.T) {
	d := adminTestDeps()
	code, body := get(t, d, "/api/v1/admin/users/2/industry-jobs")
	if code != http.StatusOK {
		t.Fatalf("jobs = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	if _, ok := data["groups"]; !ok {
		t.Fatalf("groups missing: %v", data)
	}
	if code, _ := get(t, d, "/api/v1/admin/users/99/industry-jobs"); code != http.StatusNotFound {
		t.Fatalf("missing = %d", code)
	}
	var _ = auth.PortraitURL
}
