package http

import (
	"net/http"
	"testing"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func charsTestDeps() Deps {
	stub := testAuth()
	corpID := int64(2001)
	stub.chars = []auth.Character{
		{CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true, CorporationID: &corpID},
		{CharacterID: 12, Name: "Alt", IsEsiActive: true, CorporationID: &corpID},
	}
	return Deps{Sessions: stub, Users: stub,
		Plans: &stubPlans{inv: map[int64]store.InventionPlan{}, reac: map[int64]store.ReactionPlan{}, now: time.Now()},
		Corp:  &stubCorp{},
	}
}

func TestCharacters(t *testing.T) {
	d := charsTestDeps()
	if code := postRaw(t, d, "/api/v1/characters/12/main", `{}`); code != http.StatusNoContent {
		t.Fatalf("setmain = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/characters/12"); code != http.StatusNoContent {
		t.Fatalf("delete = %d", code)
	}
	if code, _ := deleteReq(t, d, "/api/v1/characters/11"); code != http.StatusUnprocessableEntity {
		t.Fatalf("last = %d", code)
	}
	d2 := charsTestDeps()
	d2.Sessions.(*stubAuth).uid = 2
	if code := postRaw(t, d2, "/api/v1/characters/11/main", `{}`); code != http.StatusNotFound {
		t.Fatalf("foreign = %d", code)
	}
}
