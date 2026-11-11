package calc

import "math"

var MaterialGroups = map[int]string{
	18:   "Minerals",
	423:  "Ice Products",
	427:  "Moon Materials",
	428:  "Intermediate Materials",
	429:  "Composite Materials",
	712:  "Molecular-Forged Materials",
	974:  "Hybrid Polymers",
	1034: "Salvage Materials",
	1033: "Ancient Salvage",
	1136: "Fuel Blocks",
	1857: "Biochemical Material",
	4051: "Abyssal Materials",
	4168: "Triglavian Materials",
	4144: "Mutaplasmids",
}

var FallbackMaterialGroups = map[int]int{
	34: 18, 35: 18, 36: 18, 37: 18, 38: 18, 39: 18, 40: 18,
	16274: 423, 17887: 423, 17888: 423, 17889: 423,
}

type ProgressMeta struct {
	Groups         map[int]int
	BlueprintNames map[string]string
	Volumes        map[int]float64
}

type ProgressSummary struct {
	OverallProgress    float64
	Steps              []any
	RequiredBlueprints []any
	BaseMaterials      []any
	FuelBlocks         []any
	ReactionsSummary   []any
	Sections           []any
	HasSections        bool
}

func SummariseProgress(steps []any, settings map[string]any, inputQuantities, outputQuantities map[int]float64, meta ProgressMeta) ProgressSummary {
	if settings == nil {
		settings = map[string]any{}
	}
	res := calcNestedProgress(steps, inputQuantities, outputQuantities, 1.0)

	var sections []any
	hasSections := false
	if secs, ok := settings["sections"].([]any); ok && len(secs) > 0 {
		hasSections = true
		idx := 0
		for _, sv := range secs {
			sm, ok := sv.(map[string]any)
			if !ok {
				continue
			}
			count, _ := asInt(sm["step_count"])
			var slice []any
			if idx < len(steps) && count > 0 {
				end := idx + count
				if end > len(steps) {
					end = len(steps)
				}
				slice = steps[idx:end]
				idx = end
			}
			sr := calcNestedProgress(slice, inputQuantities, outputQuantities, 1.0)
			name, _ := sm["name"].(string)
			sections = append(sections, map[string]any{
				"name": name, "step_count": count,
				"overall_progress": sr.overall,
			})
		}
	}

	bps := map[int]map[string]any{}
	base := map[int]map[string]any{}
	fuel := map[int]map[string]any{}
	collectSummaryData(steps, inputQuantities, outputQuantities, bps, base, fuel, meta.Groups, 1.0)

	bpList := make([]any, 0, len(bps))
	for id, b := range bps {
		if n, ok := meta.BlueprintNames[itoaCalc(id)]; ok {
			b["name"] = n
		} else if _, ok := b["name"]; !ok {
			b["name"] = "Unknown Blueprint"
		}
		bpList = append(bpList, b)
	}
	baseList := make([]any, 0, len(base))
	for id, m := range base {
		gid := meta.Groups[id]
		if n, ok := MaterialGroups[gid]; ok {
			m["group_name"] = n
		} else {
			m["group_name"] = "Other"
		}
		m["volume"] = meta.Volumes[id]
		baseList = append(baseList, m)
	}
	fuelList := make([]any, 0, len(fuel))
	for id, m := range fuel {
		m["volume"] = meta.Volumes[id]
		fuelList = append(fuelList, m)
	}

	reactions := collectReactionData(steps, outputQuantities, 1.0)
	reactList := make([]any, 0, len(reactions))
	for _, r := range reactions {
		inputVol := 0.0
		inputs := []any{}
		for matID, qty := range r.materialQtys {
			inputVol += qty * meta.Volumes[matID]
			avail := inputQuantities[matID]
			inputs = append(inputs, map[string]any{
				"type_id": matID, "name": r.materialNames[matID],
				"required_qty": qty, "available": avail,
			})
		}
		unitVol := meta.Volumes[r.typeID]
		reactList = append(reactList, map[string]any{
			"type_id": r.typeID, "name": r.name, "blueprint_type_id": r.bpID,
			"total_runs": r.totalRuns, "output_qty": r.outputQty, "available": r.available,
			"net_output_qty": r.netOutput, "net_runs": r.netRuns,
			"input_volume": math.Round(inputVol*100) / 100, "output_volume": math.Round(r.netOutput*unitVol*100) / 100,
			"inputs": inputs,
		})
	}

	return ProgressSummary{
		OverallProgress: res.overall, Steps: res.steps,
		RequiredBlueprints: bpList, BaseMaterials: baseList,
		FuelBlocks: fuelList, ReactionsSummary: reactList,
		Sections: sections, HasSections: hasSections,
	}
}

type nestedResult struct {
	steps          []any
	overall        float64
	totalRequired  float64
	totalAvailable float64
}

func round1(v float64) float64 { return math.Round(v*10) / 10 }

func calcNestedProgress(steps []any, inQ, outQ map[int]float64, parentScale float64) nestedResult {
	out := []any{}
	var totalReq, totalAvail float64
	for _, sv := range steps {
		sm, ok := sv.(map[string]any)
		if !ok {
			continue
		}
		typeID, _ := asInt(sm["type_id"])
		storedReq, _ := asNum(sm["quantity_required"])
		required := storedReq
		if parentScale < 1.0 {
			required = math.Ceil(storedReq * parentScale)
		}
		available := outQ[typeID]
		progress := 100.0
		if required > 0 {
			progress = math.Min(100, round1(available/required*100))
		}
		hasEnough := available >= required
		remaining := math.Max(0, required-available)
		childScale := 0.0
		if storedReq > 0 {
			childScale = remaining / storedReq
		}
		mats := []any{}
		if ml, ok := sm["materials"].([]any); ok {
			for _, mv := range ml {
				mm, ok := mv.(map[string]any)
				if !ok {
					continue
				}
				matID, _ := asInt(mm["type_id"])
				stored := matStoredQty(mm)
				matReq := stored
				if childScale < 1.0 {
					matReq = math.Ceil(stored * childScale)
				}
				matAvail := inQ[matID]
				matProg := 100.0
				if matReq > 0 {
					matProg = math.Min(100, round1(matAvail/matReq*100))
				}
				name, _ := mm["name"].(string)
				if name == "" {
					name = "Unknown"
				}
				mats = append(mats, map[string]any{
					"type_id": matID, "name": name, "required": matReq,
					"available": matAvail, "progress": matProg,
					"has_enough": matAvail >= matReq,
				})
			}
		}
		var kids []any
		if kl, ok := sm["children"].([]any); ok {
			kids = kl
		}
		childRes := calcNestedProgress(kids, inQ, outQ, childScale)
		name, _ := sm["name"].(string)
		if name == "" {
			name = "Unknown"
		}
		out = append(out, map[string]any{
			"type_id": typeID, "name": name, "quantity_required": required,
			"available": available, "progress": progress, "has_enough": hasEnough,
			"materials": mats, "children": childRes.steps,
		})
		totalReq += required + childRes.totalRequired
		capped := available
		if capped > required {
			capped = required
		}
		totalAvail += capped + childRes.totalAvailable
	}
	overall := 100.0
	if totalReq > 0 {
		overall = round1(totalAvail / totalReq * 100)
	}
	if out == nil {
		out = []any{}
	}
	return nestedResult{steps: out, overall: overall, totalRequired: totalReq, totalAvailable: totalAvail}
}

func matStoredQty(mm map[string]any) float64 {
	if v, ok := mm["adjusted_quantity"]; ok && v != nil {
		if n, ok := asNum(v); ok {
			return n
		}
	}
	n, _ := asNum(mm["quantity"])
	return n
}

func asNum(v any) (float64, bool) {
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

func collectSummaryData(steps []any, inQ, outQ map[int]float64, bps, base, fuel map[int]map[string]any, groups map[int]int, parentScale float64) {
	for _, sv := range steps {
		sm, ok := sv.(map[string]any)
		if !ok {
			continue
		}
		bpID, _ := asInt(sm["blueprint_type_id"])
		act, _ := sm["activity_type"].(string)
		typeID, _ := asInt(sm["type_id"])
		storedReq, _ := asNum(sm["quantity_required"])
		effReq := storedReq
		if parentScale < 1.0 {
			effReq = math.Ceil(storedReq * parentScale)
		}
		available := outQ[typeID]
		remaining := math.Max(0, effReq-available)
		childScale := 0.0
		if storedReq > 0 {
			childScale = remaining / storedReq
		}
		if bpID != 0 && act != "" {
			if _, ok := bps[bpID]; !ok {
				bps[bpID] = map[string]any{
					"type_id": bpID, "name": "Unknown Blueprint",
					"activity_type": act, "owned": inQ[bpID] > 0,
				}
			}
		} else if typeID != 0 && effReq > 0 {
			accumulateSupply(base, fuel, groups, typeID, stepName(sm), effReq, inQ[typeID])
		}
		childIDs := map[int]bool{}
		var kids []any
		if kl, ok := sm["children"].([]any); ok {
			kids = kl
			for _, kv := range kl {
				if km, ok := kv.(map[string]any); ok {
					id, _ := asInt(km["type_id"])
					childIDs[id] = true
				}
			}
		}
		if ml, ok := sm["materials"].([]any); ok {
			for _, mv := range ml {
				mm, ok := mv.(map[string]any)
				if !ok {
					continue
				}
				matID, _ := asInt(mm["type_id"])
				if matID == 0 {
					continue
				}
				matQty := matStoredQty(mm)
				if childScale < 1.0 {
					matQty = math.Ceil(matStoredQty(mm) * childScale)
				}
				if groups[matID] == FuelBlockGroupID {
					accumulateSupply(nil, fuel, groups, matID, matName(mm), matQty, inQ[matID])
					continue
				}
				if childIDs[matID] {
					continue
				}
				accumulateSupply(base, nil, groups, matID, matName(mm), matQty, inQ[matID])
			}
		}
		for _, kv := range kids {
			km, ok := kv.(map[string]any)
			if !ok {
				continue
			}
			kidID, _ := asInt(km["type_id"])
			if groups[kidID] == FuelBlockGroupID {
				continue
			}
			collectSummaryData([]any{km}, inQ, outQ, bps, base, fuel, groups, childScale)
		}
	}
}

func accumulateSupply(base, fuel map[int]map[string]any, groups map[int]int, typeID int, name string, qty, avail float64) {
	if groups[typeID] == FuelBlockGroupID {
		if fuel == nil {
			return
		}
		if e, ok := fuel[typeID]; ok {
			e["quantity"] = e["quantity"].(float64) + qty
		} else {
			fuel[typeID] = map[string]any{
				"type_id": typeID, "name": name, "quantity": qty,
				"available": avail, "group_name": "Fuel Blocks",
			}
		}
		return
	}
	if base == nil {
		return
	}
	if e, ok := base[typeID]; ok {
		e["quantity"] = e["quantity"].(float64) + qty
	} else {
		base[typeID] = map[string]any{
			"type_id": typeID, "name": name, "quantity": qty, "available": avail,
		}
	}
}

func stepName(sm map[string]any) string {
	if n, _ := sm["name"].(string); n != "" {
		return n
	}
	return "Unknown"
}

func matName(mm map[string]any) string { return stepName(mm) }

type reactionDatum struct {
	typeID        int
	name          string
	bpID          int
	totalRuns     int
	outputQty     float64
	available     float64
	netOutput     float64
	netRuns       int
	materialQtys  map[int]float64
	materialNames map[int]string
}

func collectReactionData(steps []any, outQ map[int]float64, parentScale float64) map[int]*reactionDatum {
	out := map[int]*reactionDatum{}
	var walk func(list []any, scale float64)
	walk = func(list []any, scale float64) {
		for _, sv := range list {
			sm, ok := sv.(map[string]any)
			if !ok {
				continue
			}
			act, _ := sm["activity_type"].(string)
			typeID, _ := asInt(sm["type_id"])
			storedReq, _ := asNum(sm["quantity_required"])
			runsNeeded, _ := asInt(sm["runs_needed"])
			bpID, _ := asInt(sm["blueprint_type_id"])
			effReq := storedReq
			if scale < 1.0 {
				effReq = math.Ceil(storedReq * scale)
			}
			if act == "reaction" && bpID != 0 {
				available := outQ[typeID]
				remaining := math.Max(0, effReq-available)
				effRuns := runsNeeded
				if storedReq > 0 && runsNeeded > 0 {
					effRuns = int(math.Ceil(float64(runsNeeded) * (effReq / storedReq)))
				}
				portion := 1
				if runsNeeded > 0 && storedReq > 0 {
					portion = int(math.Round(storedReq / float64(runsNeeded)))
					if portion < 1 {
						portion = 1
					}
				}
				netRuns := 0
				if portion > 0 {
					netRuns = int(math.Ceil(remaining / float64(portion)))
				}
				qtys := map[int]float64{}
				names := map[int]string{}
				if ml, ok := sm["materials"].([]any); ok {
					for _, mv := range ml {
						mm, ok := mv.(map[string]any)
						if !ok {
							continue
						}
						matID, _ := asInt(mm["type_id"])
						if matID == 0 {
							continue
						}
						stored := matStoredQty(mm)
						q := stored
						if storedReq > 0 {
							q = math.Ceil(stored * (effReq / storedReq))
						}
						qtys[matID] += q
						names[matID] = matName(mm)
					}
				}
				if e, ok := out[bpID]; ok {
					e.totalRuns += effRuns
					e.outputQty += effReq
					e.netOutput += remaining
					e.netRuns += netRuns
					for id, q := range qtys {
						e.materialQtys[id] += q
						e.materialNames[id] = names[id]
					}
				} else {
					out[bpID] = &reactionDatum{
						typeID: typeID, name: stepName(sm), bpID: bpID,
						totalRuns: effRuns, outputQty: effReq, available: available,
						netOutput: remaining, netRuns: netRuns,
						materialQtys: qtys, materialNames: names,
					}
				}
			}
			if kl, ok := sm["children"].([]any); ok && len(kl) > 0 {
				available := outQ[typeID]
				remaining := math.Max(0, effReq-available)
				cs := 0.0
				if storedReq > 0 {
					cs = remaining / storedReq
				}
				walk(kl, cs)
			}
		}
	}
	walk(steps, parentScale)
	return out
}

func itoaCalc(i int) string {
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
