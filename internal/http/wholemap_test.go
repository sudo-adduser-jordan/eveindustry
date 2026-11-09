package http

import (
	"context"
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// stubWholemap implements store.WholemapStore in memory.
type stubWholemap struct {
	sigs   map[int64]store.WholemapSignature
	conns  map[int64]store.WormholeConnection
	notes  map[int64]store.WholemapNote
	jumps  map[int64]store.WormholeJumpLog
	nextID int64
	now    time.Time
}

func (s *stubWholemap) nid() int64 { s.nextID++; return s.nextID }

func (s *stubWholemap) SignatureByID(ctx context.Context, id int64) (*store.WholemapSignature, error) {
	if r, ok := s.sigs[id]; ok {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubWholemap) SignatureByKey(ctx context.Context, uid int64, sys int, sig string) (*store.WholemapSignature, error) {
	for _, r := range s.sigs {
		if r.UserID == uid && r.SystemID == sys && r.SignatureID != nil && *r.SignatureID == sig {
			r := r
			return &r, nil
		}
	}
	return nil, nil
}
func (s *stubWholemap) CreateSignature(ctx context.Context, g store.WholemapSignature) (*store.WholemapSignature, error) {
	g.ID = s.nid()
	g.CreatedAt, g.UpdatedAt = s.now, s.now
	s.sigs[g.ID] = g
	r := g
	return &r, nil
}
func (s *stubWholemap) UpdateSignature(ctx context.Context, g store.WholemapSignature) (*store.WholemapSignature, error) {
	g.UpdatedAt = s.now
	s.sigs[g.ID] = g
	r := g
	return &r, nil
}
func (s *stubWholemap) DeleteSignature(ctx context.Context, id int64) error {
	delete(s.sigs, id)
	return nil
}
func (s *stubWholemap) ConnectionByID(ctx context.Context, id int64) (*store.WormholeConnection, error) {
	if r, ok := s.conns[id]; ok {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubWholemap) CreateConnection(ctx context.Context, c store.WormholeConnection) (*store.WormholeConnection, error) {
	c.ID = s.nid()
	c.CreatedAt, c.UpdatedAt = s.now, s.now
	s.conns[c.ID] = c
	r := c
	return &r, nil
}
func (s *stubWholemap) UpdateConnection(ctx context.Context, c store.WormholeConnection) (*store.WormholeConnection, error) {
	c.UpdatedAt = s.now
	s.conns[c.ID] = c
	r := c
	return &r, nil
}
func (s *stubWholemap) DeleteConnection(ctx context.Context, id int64) error {
	delete(s.conns, id)
	return nil
}
func (s *stubWholemap) ConnectionForSignature(ctx context.Context, sigID int64) (*store.WormholeConnection, error) {
	for _, r := range s.conns {
		if r.SignatureID == sigID {
			r := r
			return &r, nil
		}
	}
	return nil, nil
}
func (s *stubWholemap) CreateJumpLog(ctx context.Context, j store.WormholeJumpLog) (*store.WormholeJumpLog, error) {
	j.ID = s.nid()
	j.JumpedAt = s.now
	s.jumps[j.ID] = j
	r := j
	return &r, nil
}
func (s *stubWholemap) NoteByID(ctx context.Context, id int64) (*store.WholemapNote, error) {
	if r, ok := s.notes[id]; ok {
		r := r
		return &r, nil
	}
	return nil, nil
}
func (s *stubWholemap) UpsertNote(ctx context.Context, n store.WholemapNote) (*store.WholemapNote, bool, error) {
	for id, r := range s.notes {
		if r.UserID == n.UserID && r.SystemID == n.SystemID {
			r.SystemName, r.Status, r.Comment, r.UpdatedAt = n.SystemName, n.Status, n.Comment, s.now
			s.notes[id] = r
			out := r
			return &out, false, nil
		}
	}
	n.ID = s.nid()
	n.CreatedAt, n.UpdatedAt = s.now, s.now
	s.notes[n.ID] = n
	out := n
	return &out, true, nil
}
func (s *stubWholemap) DeleteNote(ctx context.Context, id int64) error {
	delete(s.notes, id)
	return nil
}

func wholemapTestDeps() Deps {
	stub := testAuth()
	sid := "ABC-123"
	d := Deps{Sessions: stub, Users: stub, Wholemap: &stubWholemap{
		sigs: map[int64]store.WholemapSignature{
			1: {ID: 1, UserID: 1, SignatureID: &sid, SystemID: 30000142, SystemName: strPtr("Jita"), Type: "wormhole"},
			9: {ID: 9, UserID: 9, SystemID: 30000142, Type: "unknown"},
		},
		conns: map[int64]store.WormholeConnection{
			2: {ID: 2, UserID: 1, SignatureID: 1, FromSystemID: 30000142, Size: "large", Life: "stable", Mass: "stable"},
			8: {ID: 8, UserID: 9, SignatureID: 9, FromSystemID: 1, Size: "large", Life: "stable", Mass: "stable"},
		},
		notes:  map[int64]store.WholemapNote{},
		jumps:  map[int64]store.WormholeJumpLog{},
		nextID: 100,
		now:    time.Date(2026, 11, 8, 12, 0, 0, 0, time.UTC),
	}}
	return d
}

func TestSignatureCRUD(t *testing.T) {
	d := wholemapTestDeps()
	// create -> 201 with nested connection
	code, body := post(t, d, "/api/v1/wholemap/signatures", `{"system_id":30000142,"system_name":"Jita","type":"wormhole","name":"A"}`)
	if code != http.StatusCreated {
		t.Fatalf("create = %d %v", code, body)
	}
	id := body["data"].(map[string]any)["id"].(float64)
	_ = id
	// foreign signature -> 403
	if code, _ := putJSON(t, d, "/api/v1/wholemap/signatures/9", `{"name":"X"}`); code != http.StatusForbidden {
		t.Fatalf("foreign update = %d", code)
	}
	// missing -> 404
	if code, _ := get(t, d, "/api/v1/wholemap/signatures/999/lock-toggle"); code != http.StatusNotFound {
		t.Fatalf("missing = %d", code)
	}
	// lock then update -> 422
	if code, _ := post(t, d, "/api/v1/wholemap/signatures/1/lock-toggle", `{}`); code != http.StatusOK {
		t.Fatalf("lock = %d", code)
	}
	if code, _ := putJSON(t, d, "/api/v1/wholemap/signatures/1", `{"name":"Y"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("locked update = %d", code)
	}
	// unlock, update ok
	if code, _ := post(t, d, "/api/v1/wholemap/signatures/1/lock-toggle", `{}`); code != http.StatusOK {
		t.Fatalf("unlock = %d", code)
	}
	code, body = putJSON(t, d, "/api/v1/wholemap/signatures/1", `{"name":"Y","type":"relic"}`)
	if code != http.StatusOK || body["data"].(map[string]any)["name"] != "Y" {
		t.Fatalf("update = %d %v", code, body)
	}
	// delete -> 204
	if code, _ := deleteReq(t, d, "/api/v1/wholemap/signatures/1"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
}

func TestSignaturePaste(t *testing.T) {
	d := wholemapTestDeps()
	paste := "ABC-123\tCosmic Signature\tWormhole\tUnstable Wormhole\nXYZ-999\tCombat Site\tData Site\tRuined Station\nbadline\nTOOLONGID123\tX\tY\n"
	code, body := post(t, d, "/api/v1/wholemap/signatures/paste", `{"system_id":30000142,"system_name":"Jita","paste":`+quoteJSON(paste)+`}`)
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	// ABC-123 exists -> updated; XYZ-999 new; badline + long id skipped
	if body["data"].(map[string]any)["created"].(float64) != 1 || body["data"].(map[string]any)["updated"].(float64) != 1 {
		t.Fatalf("paste = %v", body)
	}
}

func quoteJSON(s string) string {
	out := "\""
	for _, r := range s {
		switch r {
		case '\n':
			out += "\\n"
		case '\t':
			out += "\\t"
		case '"':
			out += "\\\""
		default:
			out += string(r)
		}
	}
	return out + "\""
}

func TestConnectionsAndJumps(t *testing.T) {
	d := wholemapTestDeps()
	// create on foreign signature -> 403
	if code, _ := post(t, d, "/api/v1/wholemap/connections", `{"signature_id":9,"from_system_id":1,"from_system_name":"A"}`); code != http.StatusForbidden {
		t.Fatalf("foreign sig = %d", code)
	}
	// missing signature -> 422
	if code, _ := post(t, d, "/api/v1/wholemap/connections", `{"signature_id":4242,"from_system_id":1,"from_system_name":"A"}`); code != http.StatusUnprocessableEntity {
		t.Fatalf("missing sig = %d", code)
	}
	// ok create
	code, body := post(t, d, "/api/v1/wholemap/connections", `{"signature_id":1,"from_system_id":30000142,"from_system_name":"Jita","size":"small"}`)
	if code != http.StatusCreated || body["data"].(map[string]any)["size"] != "small" {
		t.Fatalf("create = %d %v", code, body)
	}
	cid := int(body["data"].(map[string]any)["id"].(float64))
	// jump log on own connection
	code, body = post(t, d, "/api/v1/wholemap/jump-logs", `{"connection_id":`+itoaJSON(cid)+`,"character_name":"Pilot"}`)
	if code != http.StatusCreated {
		t.Fatalf("jump = %d %v", code, body)
	}
	// jump log on foreign connection -> 403
	if code, _ := post(t, d, "/api/v1/wholemap/jump-logs", `{"connection_id":8}`); code != http.StatusForbidden {
		t.Fatalf("foreign jump = %d", code)
	}
	// update + delete
	if code, _ := putJSON(t, d, "/api/v1/wholemap/connections/2", `{"life":"critical"}`); code != http.StatusOK {
		t.Fatalf("update = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/wholemap/connections/2"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
}

func itoaJSON(n int) string {
	if n == 0 {
		return "0"
	}
	out := ""
	for n > 0 {
		out = string(rune('0'+n%10)) + out
		n /= 10
	}
	return out
}

func TestNotes(t *testing.T) {
	d := wholemapTestDeps()
	// create -> 201
	code, body := putJSON(t, d, "/api/v1/wholemap/notes", `{"system_id":30000142,"system_name":"Jita","status":"friendly","comment":"Hi"}`)
	if code != http.StatusCreated {
		t.Fatalf("create = %d %v", code, body)
	}
	// replace -> 200
	code, body = putJSON(t, d, "/api/v1/wholemap/notes", `{"system_id":30000142,"system_name":"Jita","status":"hostile"}`)
	if code != http.StatusOK || body["data"].(map[string]any)["status"] != "hostile" {
		t.Fatalf("upsert = %d %v", code, body)
	}
	id := int(body["data"].(map[string]any)["id"].(float64))
	_ = id
	if code, _ := deleteReq(t, d, "/api/v1/wholemap/notes/4242"); code != http.StatusNotFound {
		t.Fatalf("missing = %d", code)
	}
}

func (s *stubWholemap) RecentSignatures(ctx context.Context, uid int64, since time.Time, sys *int) ([]store.WholemapSignature, error) {
	var out []store.WholemapSignature
	for _, g := range s.sigs {
		if g.UserID != uid {
			continue
		}
		if sys != nil && g.SystemID != *sys {
			continue
		}
		out = append(out, g)
	}
	return out, nil
}
func (s *stubWholemap) RecentConnections(ctx context.Context, uid int64, since time.Time) ([]store.WormholeConnection, error) {
	var out []store.WormholeConnection
	for _, c := range s.conns {
		if c.UserID == uid {
			out = append(out, c)
		}
	}
	return out, nil
}
func (s *stubWholemap) RecentNotes(ctx context.Context, uid int64, since time.Time, sys *int) ([]store.WholemapNote, error) {
	var out []store.WholemapNote
	for _, n := range s.notes {
		if n.UserID != uid {
			continue
		}
		if sys != nil && n.SystemID != *sys {
			continue
		}
		out = append(out, n)
	}
	return out, nil
}
func (s *stubWholemap) JumpLogs(ctx context.Context, connID int64) ([]store.WormholeJumpLog, error) {
	var out []store.WormholeJumpLog
	for _, j := range s.jumps {
		if j.ConnectionID == connID {
			out = append(out, j)
		}
	}
	return out, nil
}
