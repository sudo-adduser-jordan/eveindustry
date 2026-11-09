package http

import (
	"bytes"
	"context"
	"mime/multipart"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type stubSponsors struct {
	rows map[int64]store.Sponsor
	next int64
}

func (s *stubSponsors) Sponsors(ctx context.Context) ([]store.Sponsor, error) {
	var out []store.Sponsor
	for _, r := range s.rows {
		out = append(out, r)
	}
	return out, nil
}
func (s *stubSponsors) SponsorByID(ctx context.Context, id int64) (*store.Sponsor, error) {
	if r, ok := s.rows[id]; ok {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubSponsors) CreateSponsor(ctx context.Context, sp store.Sponsor) (*store.Sponsor, error) {
	s.next++
	sp.ID = s.next
	s.rows[sp.ID] = sp
	out := sp
	return &out, nil
}
func (s *stubSponsors) UpdateSponsor(ctx context.Context, sp store.Sponsor) (*store.Sponsor, error) {
	s.rows[sp.ID] = sp
	out := sp
	return &out, nil
}
func (s *stubSponsors) DeleteSponsor(ctx context.Context, id int64) error {
	delete(s.rows, id)
	return nil
}

type stubAdminStats struct{}

func (s *stubAdminStats) AdminStats(ctx context.Context) (*store.AdminStats, error) {
	return &store.AdminStats{Users: 10, Characters: 20, ActiveUsers: 9, RecentLogins: 3, NewWeek: 2, NewMonth: 5, Logged24h: 1}, nil
}
func (s *stubAdminStats) AdminRegistrationSeries(ctx context.Context) ([]store.AdminSeriesPoint, error) {
	return []store.AdminSeriesPoint{{Date: "2026-11-01", Count: 2}}, nil
}
func (s *stubAdminStats) AdminLoginSeries(ctx context.Context) ([]store.AdminSeriesPoint, error) {
	return []store.AdminSeriesPoint{{Date: "2026-11-01", Count: 3}}, nil
}

type memSponsorImages struct {
	files map[string][]byte
}

func (m *memSponsorImages) Save(name string, data []byte) (string, error) {
	if m.files == nil {
		m.files = map[string][]byte{}
	}
	m.files[name] = data
	return "sponsors/" + name, nil
}
func (m *memSponsorImages) Delete(path string) error {
	delete(m.files, path[len("sponsors/"):])
	return nil
}

func sponsorTestDeps() Deps {
	d := adminTestDeps()
	d.Sponsors = &stubSponsors{rows: map[int64]store.Sponsor{
		1: {ID: 1, Name: "ACME", URL: "https://acme.io", ImagePath: "sponsors/a.png", IsActive: true},
	}, next: 1}
	d.AdminStats = &stubAdminStats{}
	d.SponsorImages = &memSponsorImages{}
	return d
}

func multipartSponsor(t *testing.T, fields map[string]string, filename string, data []byte) (string, string) {
	t.Helper()
	var buf bytes.Buffer
	w := multipart.NewWriter(&buf)
	for k, v := range fields {
		_ = w.WriteField(k, v)
	}
	if filename != "" {
		fw, err := w.CreateFormFile("image", filename)
		if err != nil {
			t.Fatal(err)
		}
		if _, err := fw.Write(data); err != nil {
			t.Fatal(err)
		}
	}
	if err := w.Close(); err != nil {
		t.Fatal(err)
	}
	return w.FormDataContentType(), buf.String()
}

func postMultipart(t *testing.T, d Deps, path, ctype, body string) (int, map[string]any) {
	t.Helper()
	e := New(d)
	req := httptest.NewRequest(http.MethodPost, path, strings.NewReader(body))
	req.Header.Set("Content-Type", ctype)
	rec := httptest.NewRecorder()
	e.ServeHTTP(rec, req)
	var out map[string]any
	if err := jsonUnmarshal(rec.Body.Bytes(), &out); err != nil {
		t.Fatalf("POST %s: body is not JSON: %v (%s)", path, err, rec.Body.String())
	}
	return rec.Code, out
}

func TestAdminDashboard(t *testing.T) {
	d := sponsorTestDeps()
	code, body := get(t, d, "/api/v1/admin/dashboard")
	if code != http.StatusOK {
		t.Fatalf("dashboard = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	stats := data["stats"].(map[string]any)
	if stats["users"] != 10.0 || stats["logged_in_24h"] != 1.0 {
		t.Fatalf("stats = %v", stats)
	}
	if len(data["registration_series"].([]any)) != 1 || len(data["login_series"].([]any)) != 1 {
		t.Fatalf("series = %v", data)
	}
}

var tinyPNG = []byte{0x89, 'P', 'N', 'G', 0x0d, 0x0a, 0x1a, 0x0a, 0x00}

func TestSponsors(t *testing.T) {
	d := sponsorTestDeps()
	code, body := get(t, d, "/api/v1/admin/sponsors")
	if code != http.StatusOK || len(body["data"].([]any)) != 1 {
		t.Fatalf("index = %d %v", code, body)
	}
	code, body = get(t, d, "/api/v1/admin/sponsors/1")
	if code != http.StatusOK || body["data"].(map[string]any)["image_url"] != "/storage/sponsors/a.png" {
		t.Fatalf("show = %d %v", code, body)
	}
	if code, _ := get(t, d, "/api/v1/admin/sponsors/99"); code != http.StatusNotFound {
		t.Fatalf("missing = %d", code)
	}
	ct, b := multipartSponsor(t, map[string]string{"name": "New", "url": "https://new.io"}, "b.png", tinyPNG)
	code, body = postMultipart(t, d, "/api/v1/admin/sponsors", ct, b)
	if code != http.StatusCreated {
		t.Fatalf("store = %d %v", code, body)
	}
	if body["data"].(map[string]any)["name"] != "New" {
		t.Fatalf("stored = %v", body["data"])
	}
	// missing image -> 422
	ct, b = multipartSponsor(t, map[string]string{"name": "X", "url": "https://x.io"}, "", nil)
	if code, _ := postMultipart(t, d, "/api/v1/admin/sponsors", ct, b); code != http.StatusUnprocessableEntity {
		t.Fatalf("no image = %d", code)
	}
	// non-image bytes -> 422
	ct, b = multipartSponsor(t, map[string]string{"name": "X", "url": "https://x.io"}, "x.txt", []byte("hello world, this is text"))
	if code, _ := postMultipart(t, d, "/api/v1/admin/sponsors", ct, b); code != http.StatusUnprocessableEntity {
		t.Fatalf("text image = %d", code)
	}
	// update without new banner keeps old path
	ct, b = multipartSponsor(t, map[string]string{"name": "ACME2", "url": "https://acme.io"}, "", nil)
	code, body = postMultipart(t, d, "/api/v1/admin/sponsors/1", ct, b)
	if code != http.StatusOK || body["data"].(map[string]any)["image_url"] != "/storage/sponsors/a.png" {
		t.Fatalf("update = %d %v", code, body)
	}
	if code, _ := deleteReq(t, d, "/api/v1/admin/sponsors/1"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
	if code, _ := get(t, d, "/api/v1/admin/sponsors/1"); code != http.StatusNotFound {
		t.Fatalf("deleted still there = %d", code)
	}
}
