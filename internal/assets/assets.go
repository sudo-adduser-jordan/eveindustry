package assets

import (
	"context"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// Characters is the ESI surface asset fetching needs.
type Characters interface {
	GetAssets(ctx context.Context, characterID int64) ([]map[string]any, error)
	GetCorporationAssets(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error)
	GetAssetNames(ctx context.Context, characterID int64, itemIDs []int64) (map[int64]string, error)
	GetCorporationAssetNames(ctx context.Context, characterID, corporationID int64, itemIDs []int64) (map[int64]string, error)
}

// Universe resolves type, station, structure and system names.
type Universe interface {
	GetTypeInfo(ctx context.Context, typeID int) (map[string]any, error)
	GetStation(ctx context.Context, stationID uint64) (map[string]any, error)
	GetSystem(ctx context.Context, systemID int) (map[string]any, error)
	GetStructure(ctx context.Context, structureID uint64, characterID int64) (map[string]any, error)
}

// Reference is the SDE surface for stations and systems.
type Reference interface {
	FindStation(ctx context.Context, stationID uint64) (name string, sysID *int, ok bool)
	FindSystem(ctx context.Context, systemID int) (name string, ok bool)
}

// Industry resolves bulk type names.
type Industry interface {
	TypeNames(ctx context.Context, ids []int) (map[int]string, error)
}

// Deps carries the asset fetch collaborators.
type Deps struct {
	Characters Characters
	Universe   Universe
	Reference  Reference
	Industry   Industry
}

// Asset is one flat ESI asset with resolved names.
type Asset struct {
	ItemID     int64
	TypeID     int
	TypeName   string
	Name       *string
	Quantity   int64
	LocationID int64
	Location   string
	Flag       string
	CharID     int64
	CharName   string
	Owner      string
	CorpName   *string
	Singleton  bool
	ParentID   *int64
}

var corpHangarFlags = map[string]string{
	"CorpSAG1": "Corp Hangar 1", "CorpSAG2": "Corp Hangar 2",
	"CorpSAG3": "Corp Hangar 3", "CorpSAG4": "Corp Hangar 4",
	"CorpSAG5": "Corp Hangar 5", "CorpSAG6": "Corp Hangar 6",
	"CorpSAG7": "Corp Hangar 7", "CorpDeliveries": "Corp Deliveries",
}

// FetchUserAssets ports AssetService::fetchAndCacheAssets without the cache:
// live ESI per character plus corporate via the main (director/CEO + scope).
func FetchUserAssets(ctx context.Context, d Deps, chars []auth.Character, corpOK bool) []Asset {
	var out []Asset
	var scoped []auth.Character
	for _, ch := range chars {
		if hasScope(ch, "esi-universe.read_structures.v1") {
			scoped = append(scoped, ch)
		}
	}
	var main *auth.Character
	for i := range chars {
		if chars[i].IsMain {
			main = &chars[i]
			break
		}
	}
	for _, ch := range chars {
		if !ch.IsEsiActive || !hasScope(ch, "esi-assets.read_assets.v1") {
			continue
		}
		raw, err := d.Characters.GetAssets(ctx, ch.CharacterID)
		if err != nil {
			continue
		}
		names := batchTypeNames(ctx, d, keysOf(collectTypeIDs(raw)))
		custom := map[int64]string{}
		if singletons := singletonIDs(raw); len(singletons) > 0 {
			if n, err := d.Characters.GetAssetNames(ctx, ch.CharacterID, singletons); err == nil {
				custom = n
			}
		}
		for _, a := range raw {
			out = append(out, enrichOne(a, &ch, "personal", nil, names, custom, scoped, ctx, d))
		}
	}
	if corpOK && main != nil && main.CorporationID != nil {
		raw, err := d.Characters.GetCorporationAssets(ctx, main.CharacterID, *main.CorporationID)
		if err == nil {
			names := batchTypeNames(ctx, d, keysOf(collectTypeIDs(raw)))
			custom := map[int64]string{}
			if singletons := singletonIDs(raw); len(singletons) > 0 {
				if n, err := d.Characters.GetCorporationAssetNames(ctx, main.CharacterID, *main.CorporationID, singletons); err == nil {
					custom = n
				}
			}
			var corpName *string
			if main.CorporationName != nil {
				corpName = main.CorporationName
			}
			for _, a := range raw {
				out = append(out, enrichOne(a, main, "corporate", corpName, names, custom, scoped, ctx, d))
			}
		}
	}
	return out
}

func collectTypeIDs(raw []map[string]any) map[int]bool {
	out := map[int]bool{}
	for _, a := range raw {
		if id, ok := asInt64(a["type_id"]); ok && id > 0 {
			out[int(id)] = true
		}
	}
	return out
}

func keysOf(m map[int]bool) []int {
	out := make([]int, 0, len(m))
	for k := range m {
		out = append(out, k)
	}
	return out
}

func singletonIDs(raw []map[string]any) []int64 {
	var out []int64
	seen := map[int64]bool{}
	for _, a := range raw {
		if b, _ := a["is_singleton"].(bool); !b {
			continue
		}
		if id, ok := asInt64(a["item_id"]); ok && !seen[id] {
			seen[id] = true
			out = append(out, id)
		}
	}
	return out
}

func batchTypeNames(ctx context.Context, d Deps, ids []int) map[int]string {
	if len(ids) == 0 || d.Industry == nil {
		return map[int]string{}
	}
	names, err := d.Industry.TypeNames(ctx, ids)
	if err != nil {
		return map[int]string{}
	}
	return names
}

func enrichOne(a map[string]any, ch *auth.Character, owner string, corpName *string, names map[int]string, custom map[int64]string, scoped []auth.Character, ctx context.Context, d Deps) Asset {
	itemID, _ := asInt64(a["item_id"])
	typeID, _ := asInt64(a["type_id"])
	tname := names[int(typeID)]
	if tname == "" {
		tname = ResolveTypeName(ctx, d, int(typeID))
	}
	var customName *string
	if n, ok := custom[itemID]; ok {
		customName = &n
	}
	locID, _ := asInt64(a["location_id"])
	locType, _ := a["location_type"].(string)
	flag, _ := a["location_flag"].(string)
	if f, ok := corpHangarFlags[flag]; ok {
		flag = f
	}
	var parent *int64
	if locType == "item" {
		parent = &locID
	}
	qty, _ := asInt64(a["quantity"])
	if qty == 0 {
		if f, ok := asFloat(a["quantity"]); ok {
			qty = int64(f)
		}
	}
	return Asset{
		ItemID: itemID, TypeID: int(typeID), TypeName: tname, Name: customName,
		Quantity: qty, LocationID: locID, Location: locationName(ctx, d, a, scoped),
		Flag: flag, CharID: ch.CharacterID, CharName: ch.Name, Owner: owner,
		CorpName: corpName, Singleton: boolOf(a["is_singleton"]), ParentID: parent,
	}
}

func ResolveTypeName(ctx context.Context, d Deps, typeID int) string {
	if typeID <= 0 {
		return "Unknown"
	}
	if d.Universe == nil {
		return "Type " + itoa(typeID)
	}
	if info, err := d.Universe.GetTypeInfo(ctx, typeID); err == nil && info != nil {
		if n, ok := info["name"].(string); ok && n != "" {
			return n
		}
	}
	return "Type " + itoa(typeID)
}

// locationName resolves stations, structures (scoped chars) and systems.
func locationName(ctx context.Context, d Deps, a map[string]any, scoped []auth.Character) string {
	locID, _ := asInt64(a["location_id"])
	if locID <= 0 {
		return "Unknown Location"
	}
	if d.Reference != nil {
		if locID >= 60000000 && locID < 64000000 {
			if name, _, ok := d.Reference.FindStation(ctx, uint64(locID)); ok {
				return name
			}
		} else if locID >= 30000000 && locID < 33000000 {
			if name, ok := d.Reference.FindSystem(ctx, int(locID)); ok {
				return name
			}
		}
	}
	if d.Universe == nil {
		return "Location #" + itoa(int(locID))
	}
	uv := d.Universe
	if locID >= 60000000 && locID < 64000000 {
		if st, err := uv.GetStation(ctx, uint64(locID)); err == nil && st != nil {
			if n, ok := st["name"].(string); ok && n != "" {
				return n
			}
		}
	} else if locID > 2000000000000 {
		for _, ch := range scoped {
			if st, err := uv.GetStructure(ctx, uint64(locID), ch.CharacterID); err == nil && st != nil {
				if n, ok := st["name"].(string); ok && n != "" {
					return n
				}
			}
		}
	} else if locID >= 30000000 && locID < 33000000 {
		if s, err := uv.GetSystem(ctx, int(locID)); err == nil && s != nil {
			if n, ok := s["name"].(string); ok && n != "" {
				return n
			}
		}
	}
	return "Location #" + itoa(int(locID))
}

// CorpDenial ports corporationAssetsDenial's static checks (no re-verify
// roundtrip: the Go port trusts the stored Director/CEO flag + scope).
func CorpDenial(chars []auth.Character) *string {
	msg := func(s string) *string { return &s }
	var main *auth.Character
	for i := range chars {
		if chars[i].IsMain {
			main = &chars[i]
			break
		}
	}
	if main == nil {
		return msg("No main character is set. Corporation assets can only be retrieved by your main character.")
	}
	if main.CorporationID == nil || *main.CorporationID == 0 {
		return msg("Your main character (" + main.Name + ") is not in a corporation.")
	}
	if !main.IsDirectorCEO {
		return msg("Your main character (" + main.Name + ") does not hold the Director or CEO role in their corporation.")
	}
	if !hasScope(*main, "esi-assets.read_corporation_assets.v1") {
		return msg("Your main character (" + main.Name + ") does not have the Corporation Assets ESI scope. Re-authenticate to grant it.")
	}
	return nil
}

func hasScope(ch auth.Character, scope string) bool {
	for _, s := range ch.Scopes {
		if s == scope {
			return true
		}
	}
	return false
}

func asInt64(v any) (int64, bool) {
	switch n := v.(type) {
	case int:
		return int64(n), true
	case int64:
		return n, true
	case float64:
		return int64(n), true
	}
	return 0, false
}

func asFloat(v any) (float64, bool) {
	switch n := v.(type) {
	case int:
		return float64(n), true
	case int64:
		return float64(n), true
	case float64:
		return n, true
	}
	return 0, false
}

func boolOf(v any) bool {
	b, _ := v.(bool)
	return b
}

func itoa(i int) string {
	if i == 0 {
		return "0"
	}
	neg := i < 0
	if neg {
		i = -i
	}
	var b [20]byte
	p := len(b)
	for i > 0 {
		p--
		b[p] = byte('0' + i%10)
		i /= 10
	}
	if neg {
		p--
		b[p] = '-'
	}
	return string(b[p:])
}
