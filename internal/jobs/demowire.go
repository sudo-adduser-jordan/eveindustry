package jobs

import (
	"context"
	"encoding/json"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// DemoCharStore lists demo characters for the responder.
type DemoCharStore interface {
	DemoCharacters(ctx context.Context) ([]store.DemoCharacterRow, error)
}

// DemoPoolStore returns priced blueprint pairs for deterministic demo picks.
type DemoPoolStore interface {
	DemoBlueprintPool(ctx context.Context) ([][2]int, error)
}

// DemoResponder builds the ESI stand-in for demo characters from live stores.
// A nil char store disables it; a nil pool just leaves picks empty. One
// factory wires cmd/api, cmd/worker, cmd/scheduler and cmd/job so demo data
// flows identically in every process.
func DemoResponder(chars DemoCharStore, pool DemoPoolStore) *esi.DemoResponder {
	if chars == nil {
		return nil
	}
	return &esi.DemoResponder{
		Chars: func(ctx context.Context) ([]esi.DemoCharacter, error) {
			rows, err := chars.DemoCharacters(ctx)
			if err != nil {
				return nil, err
			}
			out := make([]esi.DemoCharacter, 0, len(rows))
			for _, r := range rows {
				c := esi.DemoCharacter{ID: r.CharacterID, Name: r.Name, CreatedAt: r.CreatedAt}
				if r.CorporationID.Valid {
					c.CorporationID = r.CorporationID.Int64
				}
				if r.CorporationName.Valid {
					c.CorpName = r.CorporationName.String
				}
				if r.AllianceID.Valid {
					c.AllianceID = r.AllianceID.Int64
				}
				if r.AllianceName.Valid {
					c.AllianceName = r.AllianceName.String
				}
				if r.Roles.Valid && r.Roles.String != "" {
					var roles []string
					if err := json.Unmarshal([]byte(r.Roles.String), &roles); err == nil {
						c.Roles = roles
					}
				}
				out = append(out, c)
			}
			return out, nil
		},
		Pool: func(ctx context.Context) ([][2]int, error) {
			if pool == nil {
				return nil, nil
			}
			return pool.DemoBlueprintPool(ctx)
		},
	}
}

// WireDemo attaches the responder to already-built ESI clients. No-op when
// the handler has no database or no character client.
func WireDemo(h *HandlerDeps) {
	if h == nil || h.DB == nil || h.ESIChars == nil {
		return
	}
	var pool DemoPoolStore
	if h.Industry != nil {
		pool = h.Industry
	}
	demo := DemoResponder(h.DB, pool)
	h.ESIChars.Demo = demo
	if h.ESIUniverse != nil {
		h.ESIUniverse.Demo = demo
	}
}

// compile-time surface checks: the concrete stores satisfy the factory.
var (
	_ DemoCharStore = (*store.SQL)(nil)
	_ DemoPoolStore = (*industry.SQL)(nil)
)
