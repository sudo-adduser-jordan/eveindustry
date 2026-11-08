package industry

import (
	"context"
	"sort"
)

// Datacore is one invention material with its science skill, mirroring the
// datacores list in resolveInventionChain().
type Datacore struct {
	TypeID    int     `json:"type_id"`
	Name      string  `json:"name"`
	Quantity  int     `json:"quantity"`
	SkillID   *int    `json:"skill_id"`
	SkillName *string `json:"skill_name"`
}

// Activity is one {time, materials, products} block.
type Activity struct {
	Time      int    `json:"time"`
	Materials []Item `json:"materials"`
	Products  []Item `json:"products"`
}

// Chain mirrors the InventionChainResource payload built by
// InventionPlannerService::resolveInventionChain(). Nil means "no invention
// chain for this item" (the PHP 404 case).
type Chain struct {
	T2ItemTypeID      int       `json:"t2_item_type_id"`
	T2BlueprintTypeID int       `json:"t2_blueprint_type_id"`
	T2Manufacturing   Activity  `json:"t2_manufacturing"`
	T1ItemTypeID      int       `json:"t1_item_type_id"`
	T1ItemName        *string   `json:"t1_item_name"`
	T1BlueprintTypeID int       `json:"t1_blueprint_type_id"`
	T1Manufacturing   *Activity `json:"t1_manufacturing"`
	Datacores         []Datacore `json:"datacores"`
	InventionTime     int       `json:"invention_time"`
	BaseProbability   float64   `json:"base_probability"`
	BaseRuns          int       `json:"base_runs"`
	ScienceSkillIDs   []int     `json:"science_skill_ids"`
	EncryptionSkillID   *int    `json:"encryption_skill_id"`
	EncryptionSkillName *string `json:"encryption_skill_name"`
	Science1SkillID     *int    `json:"science1_skill_id"`
	Science1SkillName   *string `json:"science1_skill_name"`
	Science2SkillID     *int    `json:"science2_skill_id"`
	Science2SkillName   *string `json:"science2_skill_name"`
}

func strPtr(s string) *string { v := s; return &v }
func intPtr(i int) *int       { v := i; return &v }

func nullStr(s *string) *string {
	if s == nil || *s == "" {
		return nil
	}
	return s
}

// ResolveChain ports InventionPlannerService::resolveInventionChain() over
// the IndustryStore. The Fuzzwork SDE fallback (live HTTP for base
// probability/encryption skill when local rows are thin) is skipped: the Go
// API serves local blueprint_activities rows only.
func ResolveChain(ctx context.Context, st IndustryStore, t2ItemTypeID int) (*Chain, error) {
	// 1. T2 manufacturing blueprint.
	t2mfg, err := st.ActivityProducingType(ctx, t2ItemTypeID, ActivityManufacturing)
	if err != nil || t2mfg == nil {
		return nil, err
	}
	t2BlueprintID := t2mfg.BlueprintTypeID
	t2Materials := DecodeJSONList(nullString(t2mfg.Materials))
	t2Products := DecodeJSONList(nullString(t2mfg.Products))

	// 2. T1 item: first T2 material whose own blueprint has invention,
	// else the invention activity producing the T2 blueprint (T2 rigs).
	t1ItemID, t1ItemName, t1BlueprintID := 0, "Unknown", 0
	for _, m := range t2Materials {
		matID := intVal(m["type_id"], 0)
		if matID == 0 {
			continue
		}
		matBP, err := st.ActivityProducingType(ctx, matID, ActivityManufacturing)
		if err != nil {
			return nil, err
		}
		if matBP == nil {
			continue
		}
		hasInv, err := st.HasActivity(ctx, matBP.BlueprintTypeID, ActivityInvention)
		if err != nil {
			return nil, err
		}
		if hasInv {
			t1ItemID = matID
			if n, ok := m["name"].(string); ok {
				t1ItemName = n
			}
			t1BlueprintID = matBP.BlueprintTypeID
			break
		}
	}
	if t1ItemID == 0 {
		invForT2, err := st.ActivityProducingType(ctx, t2BlueprintID, ActivityInvention)
		if err != nil {
			return nil, err
		}
		if invForT2 != nil {
			t1BlueprintID = invForT2.BlueprintTypeID
			t1mfg, err := st.ActivityByBlueprint(ctx, t1BlueprintID, ActivityManufacturing)
			if err != nil {
				return nil, err
			}
			if t1mfg != nil {
				for _, p := range DecodeJSONList(nullString(t1mfg.Products)) {
					if id := intVal(p["type_id"], 0); id != 0 {
						t1ItemID = id
						if n, ok := p["name"].(string); ok {
							t1ItemName = n
						} else {
							t1ItemName = "Unknown"
						}
						break
					}
				}
			}
		}
	}
	if t1ItemID == 0 {
		return nil, nil
	}

	// 3. T1 manufacturing.
	var t1mfgOut *Activity
	if t1mfg, err := st.ActivityByBlueprint(ctx, t1BlueprintID, ActivityManufacturing); err != nil {
		return nil, err
	} else if t1mfg != nil {
		t1mfgOut = &Activity{
			Time:      t1mfg.Time,
			Materials: NormalizeItems(DecodeJSONList(nullString(t1mfg.Materials))),
			Products:  NormalizeItems(DecodeJSONList(nullString(t1mfg.Products))),
		}
	}

	// 4. Invention inputs: datacores + skills.
	inv, err := st.ActivityByBlueprint(ctx, t1BlueprintID, ActivityInvention)
	if err != nil {
		return nil, err
	}
	chain := &Chain{
		T2ItemTypeID:      t2ItemTypeID,
		T2BlueprintTypeID: t2BlueprintID,
		T2Manufacturing: Activity{
			Time:      t2mfg.Time,
			Materials: NormalizeItems(t2Materials),
			Products:  NormalizeItems(t2Products),
		},
		T1ItemTypeID:      t1ItemID,
		T1ItemName:        strPtr(t1ItemName),
		T1BlueprintTypeID: t1BlueprintID,
		T1Manufacturing:   t1mfgOut,
		Datacores:         []Datacore{},
		BaseProbability:   0.25,
		BaseRuns:          10,
		ScienceSkillIDs:   []int{},
	}
	if inv == nil {
		return chain, nil
	}
	chain.InventionTime = inv.Time

	var scienceIDs []int
	for _, m := range DecodeJSONList(nullString(inv.Materials)) {
		matID := intVal(m["type_id"], 0)
		name := "Unknown"
		if n, ok := m["name"].(string); ok {
			name = n
		}
		skillID, skillName := datacoreSkill(matID)
		if skillID != nil {
			scienceIDs = append(scienceIDs, *skillID)
		}
		chain.Datacores = append(chain.Datacores, Datacore{
			TypeID: matID, Name: name,
			Quantity: intVal(m["quantity"], 1),
			SkillID:  skillID, SkillName: skillName,
		})
	}
	chain.ScienceSkillIDs = scienceIDs

	// Local invention products set probability and base runs.
	for _, p := range DecodeJSONList(nullString(inv.Products)) {
		if prob, ok := numVal(p["probability"]); ok && prob > 0 {
			chain.BaseProbability = prob
		}
		if runs := intVal(p["quantity"], 0); runs > 0 {
			chain.BaseRuns = runs
		}
	}

	// Local invention skills: encryption + up to two science skills.
	skills := DecodeJSONList(nullString(inv.Skills))
	unknownIDs := []int{}
	for _, s := range skills {
		id := intVal(s["type_id"], 0)
		if id == 0 {
			continue
		}
		if _, ok := EncryptionSkills[id]; !ok {
			if _, ok := DatacoreSkills[id]; !ok {
				unknownIDs = append(unknownIDs, id)
			}
		}
	}
	unknownNames := map[int]string{}
	if len(unknownIDs) > 0 {
		if names, err := st.TypeNames(ctx, unknownIDs); err != nil {
			return nil, err
		} else {
			unknownNames = names
		}
	}
	scienceIdx := 0
	for _, s := range skills {
		id := intVal(s["type_id"], 0)
		if id == 0 {
			continue
		}
		if name, ok := EncryptionSkills[id]; ok {
			chain.EncryptionSkillID = intPtr(id)
			chain.EncryptionSkillName = strPtr(name)
			continue
		}
		if scienceIdx < 2 {
			name := DatacoreSkills[id]
			if name == "" {
				name = unknownNames[id]
				if name == "" {
					name = "Unknown Skill"
				}
			}
			if scienceIdx == 0 {
				chain.Science1SkillID = intPtr(id)
				chain.Science1SkillName = strPtr(name)
			} else {
				chain.Science2SkillID = intPtr(id)
				chain.Science2SkillName = strPtr(name)
			}
			scienceIdx++
		}
	}
	if chain.Science1SkillID == nil && len(scienceIDs) >= 1 {
		chain.Science1SkillID = intPtr(scienceIDs[0])
		name := DatacoreSkills[scienceIDs[0]]
		chain.Science1SkillName = nullStr(&name)
	}
	if chain.Science2SkillID == nil && len(scienceIDs) >= 2 {
		chain.Science2SkillID = intPtr(scienceIDs[1])
		name := DatacoreSkills[scienceIDs[1]]
		chain.Science2SkillName = nullStr(&name)
	}
	return chain, nil
}

func datacoreSkill(matTypeID int) (*int, *string) {
	skill, ok := DatacoreToSkill[matTypeID]
	if !ok {
		return nil, nil
	}
	name := DatacoreSkills[skill]
	if name == "" {
		name = "Unknown"
	}
	return intPtr(skill), strPtr(name)
}

func numVal(v any) (float64, bool) {
	switch n := v.(type) {
	case float64:
		return n, true
	case int:
		return float64(n), true
	case int64:
		return float64(n), true
	}
	return 0, false
}

// HasTechChain ports InventionPlannerService::hasTechChain(): some T2
// material has a blueprint with invention, else some invention activity
// produces the T2 blueprint.
func HasTechChain(ctx context.Context, st IndustryStore, t2mfg *BlueprintActivityRow) (bool, error) {
	for _, m := range DecodeJSONList(nullString(t2mfg.Materials)) {
		matID := intVal(m["type_id"], 0)
		if matID == 0 {
			continue
		}
		matBP, err := st.ActivityProducingType(ctx, matID, ActivityManufacturing)
		if err != nil {
			return false, err
		}
		if matBP == nil {
			continue
		}
		hasInv, err := st.HasActivity(ctx, matBP.BlueprintTypeID, ActivityInvention)
		if err != nil {
			return false, err
		}
		if hasInv {
			return true, nil
		}
	}
	inv, err := st.ActivityProducingType(ctx, t2mfg.BlueprintTypeID, ActivityInvention)
	if err != nil {
		return false, err
	}
	return inv != nil, nil
}

// ProductCategories ports ManufacturingBrowserService::getAvailableProductCategories().
func ProductCategories(ctx context.Context, st IndustryStore) ([]CategoryCount, error) {
	productIDs, err := st.ProductTypeIDsExcludingInvented(ctx)
	if err != nil {
		return nil, err
	}
	if len(productIDs) == 0 {
		return []CategoryCount{}, nil
	}
	groupCounts, err := st.ProductGroupCounts(ctx, productIDs)
	if err != nil {
		return nil, err
	}
	groupIDs := make([]int, 0, len(groupCounts))
	for g := range groupCounts {
		groupIDs = append(groupIDs, g)
	}
	groupCats, err := st.GroupCategories(ctx, groupIDs)
	if err != nil {
		return nil, err
	}
	catCounts := map[int]int{}
	for g, n := range groupCounts {
		if c, ok := groupCats[g]; ok {
			catCounts[c] += n
		}
	}
	catIDs := make([]int, 0, len(catCounts))
	for c := range catCounts {
		catIDs = append(catIDs, c)
	}
	names, err := st.CategoryNames(ctx, catIDs)
	if err != nil {
		return nil, err
	}
	out := make([]CategoryCount, 0, len(catCounts))
	for c, n := range catCounts {
		name := names[c]
		if name == "" {
			name = "Category " + itoa(c)
		}
		out = append(out, CategoryCount{CategoryID: c, Name: name, ItemCount: n})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].Name < out[j].Name })
	return out, nil
}

// CategoryCount mirrors one ProductCategoryResource row.
type CategoryCount struct {
	CategoryID int    `json:"category_id"`
	Name       string `json:"name"`
	ItemCount  int    `json:"item_count"`
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	var buf [20]byte
	i := len(buf)
	for n > 0 {
		i--
		buf[i] = byte('0' + n%10)
		n /= 10
	}
	return string(buf[i:])
}
