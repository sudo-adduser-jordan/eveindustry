package calc

import "testing"

func progressSteps() []any {
	return []any{
		map[string]any{
			"type_id": 587, "name": "Rifter", "quantity_required": 10.0,
			"blueprint_type_id": 786, "activity_type": "manufacturing",
			"materials": []any{
				map[string]any{"type_id": 34, "name": "Tritanium", "quantity": 100.0},
				map[string]any{"type_id": 35, "name": "Pyerite", "quantity": 50.0},
			},
			"children": []any{
				map[string]any{
					"type_id": 600, "name": "Damage Control", "quantity_required": 10.0,
					"blueprint_type_id": 0, "activity_type": "",
					"materials": []any{
						map[string]any{"type_id": 34, "name": "Tritanium", "quantity": 20.0},
					},
				},
			},
		},
	}
}

func progressMeta() ProgressMeta {
	return ProgressMeta{
		Groups:         map[int]int{34: 18, 35: 18, 587: 25, 600: 25, 786: 1034},
		BlueprintNames: map[string]string{"786": "Rifter Blueprint"},
		Volumes:        map[int]float64{34: 0.01, 35: 0.01, 587: 16500, 600: 5},
	}
}

func TestSummariseProgressFull(t *testing.T) {
	q := map[int]float64{34: 100000, 35: 100000, 587: 0, 600: 0, 786: 1}
	s := SummariseProgress(progressSteps(), map[string]any{}, q, q, progressMeta())
	if s.OverallProgress != 0 {
		t.Fatalf("overall = %v", s.OverallProgress)
	}
	if len(s.RequiredBlueprints) != 1 {
		t.Fatalf("blueprints = %v", s.RequiredBlueprints)
	}
	bp := s.RequiredBlueprints[0].(map[string]any)
	if bp["name"] != "Rifter Blueprint" || bp["owned"] != true {
		t.Fatalf("bp = %v", bp)
	}
	byID := map[int]map[string]any{}
	for _, m := range s.BaseMaterials {
		mm := m.(map[string]any)
		byID[mm["type_id"].(int)] = mm
	}
	if byID[34]["quantity"] != 120.0 || byID[34]["group_name"] != "Minerals" || byID[34]["volume"] != 0.01 {
		t.Fatalf("trit = %v", byID[34])
	}
	if byID[35]["quantity"] != 50.0 {
		t.Fatalf("pyer = %v", byID[35])
	}
	if len(s.Steps) != 1 {
		t.Fatalf("steps = %d", len(s.Steps))
	}
	step := s.Steps[0].(map[string]any)
	if step["progress"] != 0.0 || step["has_enough"] != false {
		t.Fatalf("step = %v", step)
	}
	kids := step["children"].([]any)
	if len(kids) != 1 {
		t.Fatalf("children = %v", step["children"])
	}
}

func TestSummariseProgressPartial(t *testing.T) {
	q := map[int]float64{34: 100000, 35: 100000, 587: 4, 600: 0}
	s := SummariseProgress(progressSteps(), map[string]any{}, q, q, progressMeta())
	step := s.Steps[0].(map[string]any)
	if step["progress"] != 40.0 {
		t.Fatalf("step progress = %v", step["progress"])
	}
	mats := step["materials"].([]any)
	if mats[0].(map[string]any)["required"] != 60.0 || mats[1].(map[string]any)["required"] != 30.0 {
		t.Fatalf("mats = %v", mats)
	}
	kid := step["children"].([]any)[0].(map[string]any)
	if kid["quantity_required"] != 6.0 {
		t.Fatalf("kid qty = %v", kid["quantity_required"])
	}
	if s.OverallProgress != 25.0 {
		t.Fatalf("overall = %v", s.OverallProgress)
	}
}

func TestSummariseProgressSections(t *testing.T) {
	settings := map[string]any{"sections": []any{
		map[string]any{"name": "Hull", "step_count": 1},
	}}
	q := map[int]float64{34: 100000, 35: 100000, 587: 10, 600: 10, 786: 1}
	s := SummariseProgress(progressSteps(), settings, q, q, progressMeta())
	if !s.HasSections || len(s.Sections) != 1 {
		t.Fatalf("sections = %v has=%v", s.Sections, s.HasSections)
	}
	sec := s.Sections[0].(map[string]any)
	if sec["name"] != "Hull" || sec["overall_progress"] != 100.0 {
		t.Fatalf("sec = %v", sec)
	}
}

func TestSummariseProgressFuelAndReactions(t *testing.T) {
	steps := []any{
		map[string]any{
			"type_id": 200, "name": "Fuel Block", "quantity_required": 5.0,
			"materials": []any{
				map[string]any{"type_id": 200, "name": "Fuel Block", "quantity": 5.0},
			},
		},
		map[string]any{
			"type_id": 300, "name": "Composite", "quantity_required": 100.0,
			"blueprint_type_id": 999, "activity_type": "reaction", "runs_needed": 10,
			"materials": []any{
				map[string]any{"type_id": 34, "name": "Tritanium", "quantity": 1000.0},
			},
		},
	}
	meta := ProgressMeta{
		Groups:         map[int]int{200: FuelBlockGroupID, 300: 429, 34: 18},
		BlueprintNames: map[string]string{"999": "Composite Formula"},
		Volumes:        map[int]float64{200: 5, 300: 1.5, 34: 0.01},
	}
	q := map[int]float64{200: 2, 300: 0, 34: 500}
	s := SummariseProgress(steps, map[string]any{}, q, q, meta)
	if len(s.FuelBlocks) != 1 {
		t.Fatalf("fuel = %v", s.FuelBlocks)
	}
	fb := s.FuelBlocks[0].(map[string]any)
	if fb["quantity"] != 8.0 || fb["group_name"] != "Fuel Blocks" || fb["volume"] != 5.0 {
		t.Fatalf("fb = %v", fb)
	}
	if len(s.ReactionsSummary) != 1 {
		t.Fatalf("reactions = %v", s.ReactionsSummary)
	}
	r := s.ReactionsSummary[0].(map[string]any)
	if r["total_runs"] != 10 || r["net_runs"] != 10 || r["net_output_qty"] != 100.0 {
		t.Fatalf("reaction = %v", r)
	}
	if r["input_volume"] != 10.0 || r["output_volume"] != 150.0 {
		t.Fatalf("volumes = %v", r)
	}
	ins := r["inputs"].([]any)
	if len(ins) != 1 || ins[0].(map[string]any)["available"] != 500.0 {
		t.Fatalf("inputs = %v", ins)
	}
}
