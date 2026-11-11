package esi

import (
	"context"
	"fmt"
	"math"
	"net/http"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

const (
	DemoCharacterBase   int64 = 99_000_000_000
	DemoCorporationBase int64 = 99_100_000_000
	DemoAllianceBase    int64 = 99_200_000_000
	DemoPlanetBase      int64 = 99_250_000_000
)

var DemoHubs = []struct {
	StationID int64
	SystemID  int
	System    string
}{
	{60003760, 30000142, "Jita"},
	{60008494, 30002187, "Amarr"},
	{60011866, 30002659, "Dodixie"},
	{60004588, 30002510, "Rens"},
	{60005686, 30002053, "Hek"},
}

var DemoSkills = map[int]int{
	3380: 5, 3388: 4, 3387: 5, 24625: 4, 3406: 5, 24624: 4, 3402: 5, 3403: 5,
	3409: 4, 45746: 4, 45748: 4, 45749: 3, 2406: 5, 2403: 4, 2505: 5, 2495: 5,
}

var DemoMinerals = map[int]int{
	34: 4000000, 35: 1200000, 36: 400000, 37: 90000, 38: 30000, 39: 12000, 40: 4000,
}

var DemoIsotopes = []int{17887, 17888, 17889, 16274}

var DemoShips = []int{587, 24702, 24698, 638, 17480, 32880}

var DemoShipGroups = []struct {
	GroupID int
	Name    string
}{
	{25, "Frigate"},
	{27, "Battleship"},
	{419, "Combat Battlecruiser"},
	{463, "Mining Barge"},
	{60, "Damage Control"},
	{38, "Shield Extender"},
	{77, "Shield Hardener"},
	{74, "Hybrid Weapon"},
	{1964, "Mutaplasmids"},
}

var DemoShipCategories = []struct {
	CategoryID int
	Name       string
}{
	{6, "Ship"},
	{7, "Module"},
	{17, "Commodity"},
}

var DemoAbyssal = []struct {
	Mutated int
	Source  int
	Mutator int
}{
	{99101, 2048, 47831},
	{99102, 3839, 47775},
	{99103, 2281, 47831},
	{99104, 3178, 47775},
}

var DemoAbyssalNames = map[int]string{
	99101: "Abyssal Damage Control II",
	99102: "Abyssal Large Shield Extender I",
	99103: "Abyssal Multispectrum Shield Hardener II",
	99104: "Abyssal Light Neutron Blaster II",
}

var DemoTypeFallbacks = map[int]struct {
	Name  string
	Group int
}{
	587:   {"Rifter", 25},
	24702: {"Hurricane", 419},
	24698: {"Drake", 419},
	638:   {"Raven", 27},
	17480: {"Procurer", 463},
	32880: {"Venture", 25},
	2048:  {"Damage Control II", 60},
	3839:  {"Large Shield Extender I", 38},
	2281:  {"Multispectrum Shield Hardener II", 77},
	3178:  {"Light Neutron Blaster II", 74},
	47831: {"Gravid Heavy Energy Neutralizer Mutaplasmid", 1964},
	47775: {"Unstable Large Armor Repairer Mutaplasmid", 1964},
	99101: {"Abyssal Damage Control II", 60},
	99102: {"Abyssal Large Shield Extender I", 38},
	99103: {"Abyssal Multispectrum Shield Hardener II", 77},
	99104: {"Abyssal Light Neutron Blaster II", 74},
}

var DemoCorpRoles = []string{"Director", "Factory_Manager", "Accountant", "Junior_Accountant", "Project_Manager", "Station_Manager"}

var DemoScopes = []string{
	"publicData", "esi-skills.read_skills.v1", "esi-skills.read_skillqueue.v1", "esi-location.read_location.v1",
	"esi-industry.read_character_jobs.v1", "esi-characters.read_blueprints.v1", "esi-assets.read_assets.v1",
	"esi-universe.read_structures.v1", "esi-planets.manage_planets.v1", "esi-search.search_structures.v1",
	"esi-industry.read_corporation_jobs.v1", "esi-characters.read_corporation_roles.v1", "esi-corporations.read_blueprints.v1",
	"esi-assets.read_corporation_assets.v1", "esi-wallet.read_corporation_wallets.v1", "esi-corporations.read_projects.v1",
}

type demoPlanetType struct {
	ECU       int
	Basic     int
	Resources []int
}

var DemoPlanetTypes = map[string]demoPlanetType{
	"temperate": {ECU: 3068, Basic: 2481, Resources: []int{2305, 2288, 2073}},
	"barren":    {ECU: 2848, Basic: 2473, Resources: []int{2267, 2288, 2268}},
	"oceanic":   {ECU: 3063, Basic: 2490, Resources: []int{2268, 2286, 2073}},
	"lava":      {ECU: 3062, Basic: 2469, Resources: []int{2267, 2272, 2307}},
	"ice":       {ECU: 3061, Basic: 2493, Resources: []int{2268, 2310, 2309}},
	"gas":       {ECU: 3060, Basic: 2492, Resources: []int{2310, 2311, 2309}},
	"storm":     {ECU: 3067, Basic: 2483, Resources: []int{2310, 2311, 2272}},
	"plasma":    {ECU: 3064, Basic: 2471, Resources: []int{2267, 2272, 2308}},
}

type DemoCharacter struct {
	ID            int64
	Name          string
	CorporationID int64
	CorpName      string
	AllianceID    int64
	AllianceName  string
	Roles         []string
	CreatedAt     time.Time
}

type DemoResponder struct {
	Chars func(ctx context.Context) ([]DemoCharacter, error)
	Pool  func(ctx context.Context) ([][2]int, error)
	Now   func() time.Time
}

func (r *DemoResponder) now() time.Time {
	if r.Now != nil {
		return r.Now()
	}
	return time.Now()
}

func DemoHandles(endpoint string) bool {
	m := demoIDRe.FindStringSubmatch(endpoint)
	if m == nil {
		return false
	}
	id, _ := strconv.ParseInt(m[2], 10, 64)
	return auth.IsDemoID(id)
}

var demoIDRe = regexp.MustCompile(`/(characters|corporations|alliances|universe/planets)/(\d+)`)

func (r *DemoResponder) Respond(ctx context.Context, method, endpoint string, ch *DemoCharacter, params map[string]string, body any) (int, any, map[string]string) {
	path := endpoint
	if u, rest, ok := stringsCut(path, "?"); ok {
		_ = rest
		path = u
	}
	path = stripDemoVersion(path)
	m := strings.ToLower(method)
	_ = body
	if m == "post" && strings.TrimSuffix(path, "/") == "/characters/affiliation" {
		ids := bodyIDs(body)
		chars, _ := r.Chars(ctx)
		byID := map[int64]DemoCharacter{}
		for _, c := range chars {
			byID[c.ID] = c
		}
		var out []any
		for _, id := range ids {
			if c, ok := byID[id]; ok {
				m := map[string]any{"character_id": c.ID, "corporation_id": c.CorporationID}
				if c.AllianceID != 0 {
					m["alliance_id"] = c.AllianceID
				}
				out = append(out, m)
			}
		}
		return 200, out, nil
	}
	if mm := regexp.MustCompile(`^/universe/planets/(\d+)/?$`).FindStringSubmatch(path); mm != nil {
		id, _ := strconv.ParseInt(mm[1], 10, 64)
		return 200, r.planetInfo(ctx, id), nil
	}
	if mm := regexp.MustCompile(`^/dogma/dynamic/items/(\d+)/(\d+)/?$`).FindStringSubmatch(path); mm != nil {
		typeID, _ := strconv.Atoi(mm[1])
		itemID, _ := strconv.ParseInt(mm[2], 10, 64)
		if dyn, ok := r.demoDynamicItem(typeID, itemID); ok {
			return 200, dyn, nil
		}
		return 404, map[string]any{"error": "Not found"}, nil
	}
	if mm := regexp.MustCompile(`^/characters/(\d+)(/.*)?$`).FindStringSubmatch(path); mm != nil {
		id, _ := strconv.ParseInt(mm[1], 10, 64)
		rest := strings.TrimSuffix(mm[2], "/")
		c := ch
		if c == nil || c.ID != id {
			chars, _ := r.Chars(ctx)
			for _, cc := range chars {
				if cc.ID == id {
					cc := cc
					c = &cc
					break
				}
			}
		}
		if c == nil {
			return 404, map[string]any{"error": "Character not found"}, nil
		}
		return r.characterEndpoint(*c, rest, m, params)
	}
	if mm := regexp.MustCompile(`^/alliances/(\d+)/?$`).FindStringSubmatch(path); mm != nil {
		id, _ := strconv.ParseInt(mm[1], 10, 64)
		chars, _ := r.Chars(ctx)
		for _, c := range chars {
			if c.AllianceID == id {
				return 200, map[string]any{"name": c.AllianceName, "ticker": demoTicker(c.AllianceName),
					"creator_corporation_id": c.CorporationID, "date_founded": "2018-06-02T12:00:00Z"}, nil
			}
		}
		return 404, map[string]any{"error": "Alliance not found"}, nil
	}
	if mm := regexp.MustCompile(`^/corporations/(\d+)(/.*)?$`).FindStringSubmatch(path); mm != nil {
		id, _ := strconv.ParseInt(mm[1], 10, 64)
		rest := strings.TrimSuffix(mm[2], "/")
		return r.corporationEndpoint(ctx, id, rest, ch, params)
	}
	return 0, nil, nil
}

func bodyIDs(body any) []int64 {
	var out []int64
	if list, ok := body.([]any); ok {
		for _, item := range list {
			switch n := item.(type) {
			case float64:
				out = append(out, int64(n))
			case int64:
				out = append(out, n)
			case int:
				out = append(out, int64(n))
			}
		}
	}
	return out
}

func demoTicker(name string) string {
	var b strings.Builder
	for _, ch := range strings.ToUpper(name) {
		if ch >= 'A' && ch <= 'Z' {
			b.WriteRune(ch)
		}
	}
	s := b.String()
	if len(s) > 4 {
		s = s[:4]
	}
	if s == "" {
		return "DEMO"
	}
	return s
}

func stringsCut(s, sep string) (before, after string, found bool) {
	if i := strings.Index(s, sep); i >= 0 {
		return s[:i], s[i+len(sep):], true
	}
	return s, "", false
}

func DemoHome(id int64) (stationID int64, systemID int, system string) {
	h := DemoHubs[int(id)%len(DemoHubs)]
	if int(id) < 0 {
		h = DemoHubs[0]
	}
	return h.StationID, h.SystemID, h.System
}

func (r *DemoResponder) characterEndpoint(c DemoCharacter, rest, method string, query map[string]string) (int, any, map[string]string) {
	switch {
	case rest == "":
		m := map[string]any{"name": c.Name, "corporation_id": c.CorporationID,
			"birthday": "2019-03-14T09:21:00Z", "gender": "female", "race_id": 1, "bloodline_id": 1, "security_status": 2.4}
		if c.AllianceID != 0 {
			m["alliance_id"] = c.AllianceID
		}
		return 200, m, nil
	case rest == "/skills":
		var skills []any
		total := 0
		for id, level := range DemoSkills {
			sp := int(250 * math.Pow(2, 2.5*float64(level-1)) * 4)
			total += sp
			skills = append(skills, map[string]any{"skill_id": id, "active_skill_level": level,
				"trained_skill_level": level, "skillpoints_in_skill": sp})
		}
		return 200, map[string]any{"skills": skills, "total_sp": total + 5000000, "unallocated_sp": 0}, nil
	case rest == "/roles":
		return 200, map[string]any{"roles": c.Roles, "roles_at_base": []any{}, "roles_at_hq": []any{}, "roles_at_other": []any{}}, nil
	case rest == "/blueprints":
		return 200, demoPaged(r.demoBlueprints(c, "char", 30), query), demoPages(len(r.demoBlueprints(c, "char", 30)))
	case rest == "/assets":
		return 200, demoPaged(r.demoAssets(c), query), demoPages(len(r.demoAssets(c)))
	case rest == "/assets/names":
		return 200, []any{}, nil
	case rest == "/location":
		st, sys, _ := DemoHome(c.ID)
		return 200, map[string]any{"solar_system_id": sys, "station_id": st}, nil
	case rest == "/skillqueue":
		start := r.now().Add(-3 * time.Hour)
		return 200, []any{map[string]any{
			"queue_position": 0, "skill_id": 45749, "finished_level": 4,
			"start_date": start.UTC().Format(time.RFC3339), "finish_date": start.Add(21 * time.Hour).UTC().Format(time.RFC3339),
			"training_start_sp": 181000, "level_start_sp": 181000, "level_end_sp": 1024000,
		}}, nil
	case rest == "/industry/jobs":
		inc := query["include_completed"] == "true"
		return 200, r.demoJobs(c, inc), nil
	case rest == "/planets":
		return 200, r.demoColonies(c), nil
	}
	if m := regexp.MustCompile(`^/planets/(\d+)$`).FindStringSubmatch(rest); m != nil {
		id, _ := strconv.ParseInt(m[1], 10, 64)
		return 200, r.demoColonyDetails(c, id), nil
	}
	return 404, map[string]any{"error": "Not available in the demo"}, nil
}

func (r *DemoResponder) corporationEndpoint(ctx context.Context, corpID int64, rest string, ch *DemoCharacter, query map[string]string) (int, any, map[string]string) {
	chars, _ := r.Chars(ctx)
	var members []DemoCharacter
	for _, c := range chars {
		if c.CorporationID == corpID {
			members = append(members, c)
		}
	}
	sortDemoChars(members)
	if len(members) == 0 {
		return 404, map[string]any{"error": "Corporation not found"}, nil
	}
	first := members[0]
	switch {
	case rest == "":
		m := map[string]any{"name": first.CorpName, "ticker": demoTicker(first.CorpName),
			"member_count": 40 + corpID%90, "ceo_id": first.ID, "tax_rate": 0.1,
			"date_founded": "2018-06-02T12:00:00Z"}
		if first.AllianceID != 0 {
			m["alliance_id"] = first.AllianceID
		}
		return 200, m, nil
	case rest == "/blueprints":
		var all []any
		for _, m := range members {
			for _, b := range r.demoBlueprints(m, "corp", 12) {
				all = append(all, b)
			}
		}
		return 200, demoPaged(all, query), demoPages(len(all))
	case rest == "/assets":
		corp := r.demoCorpAssets(ctx, corpID)
		return 200, demoPaged(corp, query), demoPages(len(corp))
	case rest == "/assets/names":
		return 200, demoPaged([]any{}, query), demoPages(0)
	case rest == "/industry/jobs":
		inc := query["include_completed"] == "true"
		var all []any
		for _, m := range members {
			for _, j := range r.demoJobs(m, inc) {
				all = append(all, j)
			}
		}
		return 200, demoPaged(all, query), demoPages(len(all))
	case rest == "/wallets":
		var out []any
		for d := 1; d <= 7; d++ {
			out = append(out, map[string]any{"division": d, "balance": math.Round(demoRand(corpID*7+int64(d)) * 3950000000 / 100 * 100 / 100)})
		}
		return 200, out, nil
	}
	if regexp.MustCompile(`^/wallets/\d+/journal$`).MatchString(rest) {
		return 200, demoPaged([]any{}, query), demoPages(0)
	}
	if rest == "/projects" {
		return 200, map[string]any{"projects": []any{}, "cursor": map[string]any{}}, nil
	}
	return 404, map[string]any{"error": "Not available in the demo"}, nil
}

func demoRand(seed int64) float64 {
	s := uint64(seed*1103515245 + 12345)
	return float64(s&0x7fffffff) / float64(0x7fffffff)
}

func demoPaged(rows []any, query map[string]string) []any {
	page := 1
	if p, err := strconv.Atoi(query["page"]); err == nil && p > 0 {
		page = p
	}
	start := (page - 1) * 1000
	if start >= len(rows) {
		return []any{}
	}
	end := start + 1000
	if end > len(rows) {
		end = len(rows)
	}
	return rows[start:end]
}

func demoPages(n int) map[string]string {
	pages := (n + 999) / 1000
	if pages < 1 {
		pages = 1
	}
	return map[string]string{"X-Pages": strconv.Itoa(pages)}
}

func sortDemoChars(members []DemoCharacter) {
	for i := 1; i < len(members); i++ {
		for j := i; j > 0 && members[j].ID < members[j-1].ID; j-- {
			members[j], members[j-1] = members[j-1], members[j]
		}
	}
}

func (r *DemoResponder) demoPool(ctx context.Context) [][2]int {
	if r.Pool == nil {
		return nil
	}
	pool, _ := r.Pool(ctx)
	if len(pool) > 400 {
		pool = pool[:400]
	}
	return pool
}

func (r *DemoResponder) demoPick(c DemoCharacter, count int, salt string) [][2]int {
	pool := r.demoPool(context.Background())
	if len(pool) == 0 {
		return nil
	}
	h := crc32demo(fmt.Sprintf("%d%s", c.ID, salt))
	var picked [][2]int
	seen := map[int]bool{}
	for i := 0; len(picked) < count && len(picked) < len(pool) && i < len(pool)*3; i++ {
		h = (h*1103515245 + 12345) & 0x7FFFFFFF
		idx := int(h) % len(pool)
		if !seen[idx] {
			seen[idx] = true
			picked = append(picked, pool[idx])
		}
	}
	return picked
}

func crc32demo(s string) uint64 {
	var h uint64 = 0
	for i := 0; i < len(s); i++ {
		h = h*31 + uint64(s[i])
	}
	return h & 0x7FFFFFFF
}

func demoItemBase(c DemoCharacter) int64 {
	return 2000000000000 + ((c.ID-DemoCharacterBase)%100000)*1000
}

func (r *DemoResponder) demoBlueprints(c DemoCharacter, salt string, count int) []any {
	home, _, _ := DemoHome(c.ID)
	base := demoItemBase(c)
	var out []any
	for i, pair := range r.demoPick(c, count, salt) {
		original := i%3 != 2
		runs := -1
		me := []int{10, 8, 6, 4, 10}[i%5]
		te := []int{20, 16, 12, 8, 20}[i%5]
		if !original {
			runs = 1 + (i*7)%40
			me, te = 0, 0
		}
		qty := -1
		if !original {
			qty = -2
		}
		out = append(out, map[string]any{
			"item_id": base + 500 + int64(i), "type_id": pair[0],
			"location_id": home, "location_flag": "Hangar",
			"quantity": qty, "runs": runs,
			"material_efficiency": me, "time_efficiency": te,
		})
	}
	return out
}

func (r *DemoResponder) demoAssets(c DemoCharacter) []any {
	home, _, _ := DemoHome(c.ID)
	base := demoItemBase(c)
	var items []any
	n := int64(0)
	add := func(typeID int, qty int, single bool) {
		items = append(items, map[string]any{
			"item_id": base + n, "type_id": typeID, "quantity": qty, "is_singleton": single,
			"location_id": home, "location_type": "station", "location_flag": "Hangar",
		})
		n++
	}
	for typeID, qty := range DemoMinerals {
		add(typeID, int(float64(qty)*(0.4+float64((c.ID+int64(typeID))%100)/100)), false)
	}
	for _, typeID := range DemoIsotopes {
		add(typeID, 20000+int((c.ID+int64(typeID))%60000), false)
	}
	for _, typeID := range DemoShips {
		add(typeID, 1, true)
	}
	for _, ab := range DemoAbyssal {
		add(ab.Mutated, 1, true)
	}
	for _, pair := range r.demoPick(c, 12, "asset") {
		add(pair[1], 1+int((c.ID+int64(pair[1]))%40), false)
	}
	return items
}

func (r *DemoResponder) demoDynamicItem(typeID int, itemID int64) (map[string]any, bool) {
	for _, ab := range DemoAbyssal {
		if ab.Mutated != typeID {
			continue
		}
		var attrs []any
		mk := func(id int, v float64) map[string]any {
			return map[string]any{"attribute_id": float64(id), "value": v}
		}
		switch ab.Mutated {
		case 99101:
			attrs = []any{mk(267, 0.80), mk(271, 0.83), mk(272, 0.83), mk(50, 33)}
		case 99102:
			attrs = []any{mk(72, 2100), mk(50, 44), mk(30, 140)}
		case 99103:
			attrs = []any{mk(987, -36), mk(984, -36), mk(985, -36), mk(986, -36), mk(50, 48), mk(6, 30)}
		case 99104:
			attrs = []any{mk(64, 4.9), mk(51, 3300), mk(50, 20), mk(6, 1.35)}
		default:
			return nil, false
		}
		_ = itemID
		return map[string]any{
			"source_type_id": float64(ab.Source), "mutator_type_id": float64(ab.Mutator),
			"dogma_attributes": attrs,
		}, true
	}
	return nil, false
}

func (r *DemoResponder) demoCorpAssets(ctx context.Context, corpID int64) []any {
	chars, _ := r.Chars(ctx)
	var members []DemoCharacter
	for _, c := range chars {
		if c.CorporationID == corpID {
			members = append(members, c)
		}
	}
	sortDemoChars(members)
	if len(members) == 0 {
		return []any{}
	}
	home, _, _ := DemoHome(members[0].ID)
	base := 2000000000000 + 500000000 + ((corpID-DemoCorporationBase)%100000)*1000
	flags := []string{"CorpSAG1", "CorpSAG2", "CorpSAG3", "CorpSAG4", "CorpSAG5", "CorpSAG6", "CorpSAG7", "CorpDeliveries"}
	var items []any
	n := int64(0)
	add := func(typeID int, qty int) {
		items = append(items, map[string]any{
			"item_id": base + n, "type_id": typeID, "quantity": qty, "is_singleton": false,
			"location_id": home, "location_type": "station", "location_flag": flags[int(n)%len(flags)],
		})
		n++
	}
	addSingle := func(typeID int) {
		items = append(items, map[string]any{
			"item_id": base + n, "type_id": typeID, "quantity": 1, "is_singleton": true,
			"location_id": home, "location_type": "station", "location_flag": flags[int(n)%len(flags)],
		})
		n++
	}
	for _, typeID := range sortedDemoMineralIDs() {
		add(typeID, int(float64(DemoMinerals[typeID])*(0.6+float64(corpID%50)/100)))
	}
	for _, typeID := range DemoIsotopes {
		add(typeID, 40000+int(corpID%80000))
	}
	for _, typeID := range DemoShips {
		addSingle(typeID)
	}
	for _, ab := range DemoAbyssal {
		addSingle(ab.Mutated)
	}
	for _, pair := range r.demoPick(members[0], 12, "corpasset") {
		add(pair[1], 5+int((corpID+int64(pair[1]))%120))
	}
	return items
}

func sortedDemoMineralIDs() []int {
	ids := make([]int, 0, len(DemoMinerals))
	for id := range DemoMinerals {
		ids = append(ids, id)
	}
	for i := 1; i < len(ids); i++ {
		for j := i; j > 0 && ids[j] < ids[j-1]; j-- {
			ids[j], ids[j-1] = ids[j-1], ids[j]
		}
	}
	return ids
}

func (r *DemoResponder) demoJobs(c DemoCharacter, includeCompleted bool) []any {
	home, _, _ := DemoHome(c.ID)
	var jobs []any
	now := r.now()
	anchor := now.Add(-2 * time.Hour)
	acts := []int{1, 1, 1, 1, 1, 3, 4, 5, 1, 1}
	durs := []int{4, 9, 14, 22, 30, 48, 60, 26, 12, 40}
	base := demoItemBase(c)
	for i, pair := range r.demoPick(c, 10, "jobs") {
		activity := acts[i%len(acts)]
		duration := durs[i%len(durs)] * 3600
		start := anchor.Add(-time.Duration(1+(i*5)%26) * time.Hour)
		end := start.Add(time.Duration(duration) * time.Second)
		status := "active"
		if !end.After(now) {
			status = "delivered"
		}
		if status == "delivered" && !includeCompleted {
			continue
		}
		job := map[string]any{
			"job_id":       800000000 + (c.ID%1000000)*10 + int64(i),
			"installer_id": c.ID, "facility_id": home, "station_id": home,
			"activity_id": activity, "blueprint_id": base + 500 + int64(i),
			"blueprint_type_id": pair[0], "blueprint_location_id": home, "output_location_id": home,
			"runs": 5 + i*3, "licensed_runs": 10, "cost": 12000 + i*4000,
			"status": status, "duration": duration,
			"start_date": start.UTC().Format(time.RFC3339), "end_date": end.UTC().Format(time.RFC3339),
		}
		if activity == 1 {
			job["product_type_id"] = pair[1]
		}
		jobs = append(jobs, job)
	}
	return jobs
}

func (r *DemoResponder) demoColonies(c DemoCharacter) []any {
	var out []any
	for _, p := range r.demoPlanetsFor(c) {
		out = append(out, map[string]any{
			"planet_id": p.PlanetID, "solar_system_id": p.SystemID, "planet_type": p.Type,
			"upgrade_level": 4, "num_pins": 7,
			"last_update": r.now().Add(-5 * time.Hour).UTC().Format(time.RFC3339),
		})
	}
	return out
}

type demoPlanet struct {
	PlanetID int64
	SystemID int
	Type     string
}

func demoPlanetTypeNames() []string {
	names := make([]string, 0, len(DemoPlanetTypes))
	for k := range DemoPlanetTypes {
		names = append(names, k)
	}
	sortDemoStrings(names)
	return names
}

func sortDemoStrings(s []string) {
	for i := 1; i < len(s); i++ {
		for j := i; j > 0 && s[j] < s[j-1]; j-- {
			s[j], s[j-1] = s[j-1], s[j]
		}
	}
}

func (r *DemoResponder) demoPlanetsFor(c DemoCharacter) []demoPlanet {
	names := demoPlanetTypeNames()
	var out []demoPlanet
	for i := 0; i < 3; i++ {
		hub := DemoHubs[int((c.ID+int64(i))%int64(len(DemoHubs)))]
		out = append(out, demoPlanet{
			PlanetID: DemoPlanetBase + ((c.ID-DemoCharacterBase)%10000)*10 + int64(i),
			SystemID: hub.SystemID,
			Type:     names[int((c.ID+int64(i)*3)%int64(len(names)))],
		})
	}
	return out
}

func (r *DemoResponder) demoColonyDetails(c DemoCharacter, planetID int64) map[string]any {
	var planet *demoPlanet
	for _, p := range r.demoPlanetsFor(c) {
		if p.PlanetID == planetID {
			p := p
			planet = &p
			break
		}
	}
	if planet == nil {
		return map[string]any{"links": []any{}, "pins": []any{}, "routes": []any{}}
	}
	cfg := DemoPlanetTypes[planet.Type]
	now := r.now()
	pinBase := planetID * 10
	pins := []any{map[string]any{"pin_id": pinBase + 1, "type_id": 2254, "latitude": 0.3, "longitude": 1.2}}
	var routes []any
	for k := 0; k < 2; k++ {
		product := cfg.Resources[k%len(cfg.Resources)]
		pins = append(pins, map[string]any{
			"pin_id": pinBase + 2 + int64(k), "type_id": cfg.ECU,
			"latitude": 0.4 + float64(k)/10, "longitude": 1.0 + float64(k)/10,
			"install_time":     now.Add(-48 * time.Hour).UTC().Format(time.RFC3339),
			"expiry_time":      now.Add(time.Duration(20+planetID%40+int64(k)*30) * time.Hour).UTC().Format(time.RFC3339),
			"last_cycle_start": now.Add(-30 * time.Minute).UTC().Format(time.RFC3339),
			"extractor_details": map[string]any{"product_type_id": product, "cycle_time": 1800,
				"qty_per_cycle": 4200 + k*800, "head_radius": 0.05, "heads": []any{}},
		})
		pins = append(pins, map[string]any{
			"pin_id": pinBase + 4 + int64(k), "type_id": cfg.Basic,
			"latitude": 0.5, "longitude": 1.1 + float64(k)/10,
			"last_cycle_start": now.Add(-10 * time.Minute).UTC().Format(time.RFC3339),
			"schematic_id":     65 + k,
		})
		routes = append(routes, map[string]any{
			"route_id": planetID*10 + int64(k), "source_pin_id": pinBase + 2 + int64(k),
			"destination_pin_id": pinBase + 4 + int64(k), "content_type_id": product, "quantity": 3000,
		})
	}
	links := []any{
		map[string]any{"source_pin_id": pinBase + 1, "destination_pin_id": pinBase + 2, "link_level": 5},
		map[string]any{"source_pin_id": pinBase + 1, "destination_pin_id": pinBase + 3, "link_level": 5},
	}
	return map[string]any{"links": links, "pins": pins, "routes": routes}
}

func (r *DemoResponder) planetInfo(ctx context.Context, planetID int64) map[string]any {
	chars, _ := r.Chars(ctx)
	numerals := []string{"I", "II", "III", "IV", "V", "VI"}
	system, sysID := "Demo", 0
	for _, c := range chars {
		for _, p := range r.demoPlanetsFor(c) {
			if p.PlanetID == planetID {
				for _, h := range DemoHubs {
					if h.SystemID == p.SystemID {
						system, sysID = h.System, h.SystemID
					}
				}
			}
		}
	}
	return map[string]any{"planet_id": planetID, "name": fmt.Sprintf("%s %s", system, numerals[int(planetID)%6]),
		"system_id": sysID, "type_id": 11, "position": map[string]any{"x": 0, "y": 0, "z": 0}}
}

var _ = http.MethodGet

var demoVersionRe = regexp.MustCompile(`^/(latest|legacy|dev|v[0-9]+)/`)

func stripDemoVersion(path string) string {
	return demoVersionRe.ReplaceAllString(path, "/")
}
