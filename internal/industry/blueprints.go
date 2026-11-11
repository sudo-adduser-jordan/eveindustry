package industry

import (
	"database/sql"
	"encoding/json"
	"strings"
)

func nullString(ns sql.NullString) *string {
	if !ns.Valid {
		return nil
	}
	return &ns.String
}

func StrVal(ns sql.NullString) *string { return nullString(ns) }

const (
	ActivityManufacturing = 1
	ActivityCopying       = 5
	ActivityInvention     = 8
	ActivityReaction      = 11
)

const (
	MetaGroupTechI   = 1
	MetaGroupTechII  = 2
	MetaGroupTechIII = 14
)

const (
	BlueprintSearchLimit   = 20
	ReactionSearchLimit    = 20
	InventionSearchLimit   = 20
	CorporationSearchLimit = 10
)

var EncryptionSkills = map[int]string{
	23087: "Amarr Encryption Methods",
	21790: "Caldari Encryption Methods",
	23121: "Gallente Encryption Methods",
	21791: "Minmatar Encryption Methods",
}

var DatacoreSkills = map[int]string{
	11433: "Electromagnetic Physics",
	11443: "Electronic Engineering",
	11441: "Graviton Physics",
	11449: "High Energy Physics",
	11451: "Hydromagnetic Physics",
	11448: "Laser Physics",
	11452: "Mechanical Engineering",
	11442: "Molecular Engineering",
	11445: "Nuclear Physics",
	11444: "Plasma Physics",
	11447: "Quantum Physics",
	11446: "Rocket Science",
	11455: "Nanite Engineering",
}

var DatacoreToSkill = map[int]int{
	20172: 11433, 20171: 11443, 20173: 11441, 20174: 11449,
	20175: 11451, 20176: 11448, 20424: 11452, 20418: 11442,
	20177: 11445, 20178: 11444, 20179: 11447, 20180: 11446,
	25887: 11455,
}

func EscapeLike(q string) string {
	return strings.NewReplacer(`\`, `\\`, `%`, `\%`, `_`, `\_`).Replace(q)
}

type Item struct {
	TypeID   int     `json:"type_id"`
	Name     *string `json:"name"`
	Quantity int     `json:"quantity"`
}

func NormalizeItems(raw []map[string]any) []Item {
	out := make([]Item, 0, len(raw))
	for _, m := range raw {
		id := intVal(m["type_id"], intVal(m["typeid"], intVal(m["typeID"], 0)))
		var name *string
		if n, ok := m["name"].(string); ok {
			name = &n
		}
		out = append(out, Item{TypeID: id, Name: name, Quantity: intVal(m["quantity"], 0)})
	}
	return out
}

func intVal(v any, fallback int) int {
	switch n := v.(type) {
	case int:
		return n
	case int64:
		return int(n)
	case float64:
		return int(n)
	case json.Number:
		if i, err := n.Int64(); err == nil {
			return int(i)
		}
	}
	return fallback
}

func DedupeMaterials(raw []map[string]any) []Item {
	seen := map[int]bool{}
	out := []Item{}
	for _, m := range raw {
		id := intVal(m["type_id"], intVal(m["typeid"], intVal(m["typeID"], 0)))
		if id == 0 || seen[id] {
			continue
		}
		seen[id] = true
		var name *string
		if n, ok := m["name"].(string); ok {
			name = &n
		} else {
			unknown := "Unknown"
			name = &unknown
		}
		out = append(out, Item{TypeID: id, Name: name, Quantity: intVal(m["quantity"], 0)})
	}
	return out
}

func DecodeJSONList(raw *string) []map[string]any {
	if raw == nil || *raw == "" {
		return nil
	}
	var out []map[string]any
	if err := json.Unmarshal([]byte(*raw), &out); err != nil {
		return nil
	}
	return out
}
