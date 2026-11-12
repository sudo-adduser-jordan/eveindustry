package jobs

import (
	"context"
	"database/sql"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func strPtrDemo(s string) *string { return &s }

func intPtrDemo(v int) *int { return &v }

func floatPtrDemo(v float64) *float64 { return &v }

func boolPtrDemo(v bool) *bool { return &v }

func seedDemoPlanningData(ctx context.Context, h HandlerDeps, userID int64) {
	if h.DB == nil {
		return
	}
	seedDemoStationConfigs(ctx, h, userID)
	seedDemoInventionPlans(ctx, h, userID)
	seedDemoReactionPlans(ctx, h, userID)
	seedDemoBuildPrograms(ctx, h, userID)
	seedDemoManufacturingCosts(ctx, h)
}

func seedDemoStationConfigs(ctx context.Context, h HandlerDeps, userID int64) {
	existing, err := h.DB.AllStationConfigs(ctx, userID, nil)
	if err == nil && len(existing) > 0 {
		return
	}
	configs := []struct {
		activity string
		input    store.StationConfigInput
	}{
		{"manufacturing", store.StationConfigInput{
			Name: strPtrDemo("Demo Azbel — Lowsec"), StructureType: strPtrDemo("azbel"),
			RigME: strPtrDemo("t1"), RigTE: strPtrDemo("t1"), SystemSecurity: strPtrDemo("lowsec"),
			TaxRate: floatPtrDemo(2.5), IsDefault: boolPtrDemo(true),
		}},
		{"invention", store.StationConfigInput{
			Name: strPtrDemo("Demo Raitaru — Highsec"), StructureType: strPtrDemo("raitaru"),
			RigME: strPtrDemo("t1"), RigTE: strPtrDemo("t1"), SystemSecurity: strPtrDemo("highsec"),
			TaxRate: floatPtrDemo(1.0), IsDefault: boolPtrDemo(true),
		}},
		{"reaction", store.StationConfigInput{
			Name: strPtrDemo("Demo Athanor — Lowsec"), StructureType: strPtrDemo("athanor"),
			RigME: strPtrDemo("t1"), RigTE: strPtrDemo("none"), SystemSecurity: strPtrDemo("lowsec"),
			TaxRate: floatPtrDemo(2.0), IsDefault: boolPtrDemo(true),
		}},
	}
	for _, c := range configs {
		if _, err := h.DB.CreateStationConfig(ctx, userID, c.activity, c.input); err != nil {
			h.logger().Warn("demo station config seed failed", "activity", c.activity, "err", err)
		}
	}
}

func seedDemoInventionPlans(ctx context.Context, h HandlerDeps, userID int64) {
	rows, _, err := h.DB.InventionPlans(ctx, userID, 1, 0)
	if err == nil && len(rows) > 0 {
		return
	}
	region := 10000002
	plans := []store.InventionPlan{
		{UserID: userID, Name: "Demo Rifter II — 10 runs", T2TypeID: 99301, T2TypeName: "Demo Rifter II",
			DecryptorKey: "none", EncryptionSkill: 3, Science1Skill: 3, Science2Skill: 3, InventionRuns: 10,
			StructureType: strPtrDemo("raitaru"), RigME: strPtrDemo("t1"), RigTE: strPtrDemo("t1"),
			SystemSecurity: strPtrDemo("highsec"), PriceRegionID: &region},
		{UserID: userID, Name: "Demo Hurricane II — 5 runs", T2TypeID: 99302, T2TypeName: "Demo Hurricane II",
			DecryptorKey: "none", EncryptionSkill: 4, Science1Skill: 4, Science2Skill: 3, InventionRuns: 5,
			StructureType: strPtrDemo("raitaru"), RigME: strPtrDemo("t1"), RigTE: strPtrDemo("t1"),
			SystemSecurity: strPtrDemo("highsec"), PriceRegionID: &region},
	}
	for _, p := range plans {
		if _, err := h.DB.CreateInventionPlan(ctx, p); err != nil {
			h.logger().Warn("demo invention plan seed failed", "name", p.Name, "err", err)
		}
	}
}

func seedDemoReactionPlans(ctx context.Context, h HandlerDeps, userID int64) {
	rows, _, err := h.DB.ReactionPlans(ctx, userID, 1, 0)
	if err == nil && len(rows) > 0 {
		return
	}
	region := 10000002
	plans := []store.ReactionPlan{
		{UserID: userID, Name: "Demo Composite Cycle", DefaultRunsPerSlot: 240,
			EntriesJSON:   `[{"formula_type_id":99201,"formula_name":"Demo Composite Reaction Formula","slots":2,"runs_per_slot":240}]`,
			StructureType: "athanor", RigTier: "t1", SecuritySpace: "lowsec", PriceRegionID: &region},
		{UserID: userID, Name: "Demo Biochemical + Hybrid Mix", DefaultRunsPerSlot: 240,
			EntriesJSON:   `[{"formula_type_id":99202,"formula_name":"Demo Biochemical Reaction Formula","slots":1,"runs_per_slot":240},{"formula_type_id":99203,"formula_name":"Demo Hybrid Reaction Formula","slots":1,"runs_per_slot":240}]`,
			StructureType: "athanor", RigTier: "t1", SecuritySpace: "lowsec", PriceRegionID: &region},
	}
	for _, p := range plans {
		if _, err := h.DB.CreateReactionPlan(ctx, p); err != nil {
			h.logger().Warn("demo reaction plan seed failed", "name", p.Name, "err", err)
		}
	}
}

func seedDemoBuildPrograms(ctx context.Context, h HandlerDeps, userID int64) {
	rows, err := h.DB.BuildPrograms(ctx, userID)
	if err == nil && len(rows) > 0 {
		return
	}
	t2Rifter := sql.NullInt64{Int64: 587, Valid: true}
	t2RifterName := sql.NullString{String: "Rifter", Valid: true}
	t2Hurricane := sql.NullInt64{Int64: 24702, Valid: true}
	t2HurricaneName := sql.NullString{String: "Hurricane", Valid: true}
	programs := []store.BuildProgram{
		{UserID: userID, Name: "Demo Rifter Build — 10 runs", ProgramType: "manufacturing",
			T2TypeID: t2Rifter, T2TypeName: t2RifterName,
			StepsJSON:    `[{"type_id":587,"name":"Rifter","quantity_required":10,"runs_needed":10,"materials":[{"type_id":34,"name":"Tritanium","quantity":100000},{"type_id":35,"name":"Pyerite","quantity":50000},{"type_id":36,"name":"Mexallon","quantity":20000}]}]`,
			SettingsJSON: `{"me_level":10,"te_level":20,"structure_type":"azbel","system_security":"lowsec"}`},
		{UserID: userID, Name: "Demo Hurricane Fleet — 5 runs", ProgramType: "manufacturing",
			T2TypeID: t2Hurricane, T2TypeName: t2HurricaneName,
			StepsJSON:    `[{"type_id":24702,"name":"Hurricane","quantity_required":5,"runs_needed":5,"materials":[{"type_id":34,"name":"Tritanium","quantity":50000},{"type_id":35,"name":"Pyerite","quantity":25000},{"type_id":36,"name":"Mexallon","quantity":10000}]}]`,
			SettingsJSON: `{"me_level":10,"te_level":20,"structure_type":"azbel","system_security":"lowsec"}`},
		{UserID: userID, Name: "Demo Composite Reactions", ProgramType: "reaction",
			StepsJSON:    `[{"type_id":99201,"name":"Demo Composite Reaction Formula","quantity_required":480,"runs_needed":480,"activity_type":"reaction","blueprint_type_id":99201,"materials":[{"type_id":34,"name":"Tritanium","quantity":48000},{"type_id":17887,"name":"Oxygen Isotopes","quantity":24000}]}]`,
			SettingsJSON: `{"reaction_structure_type":"athanor","reaction_rig_me":"t1","reaction_system_security":"lowsec"}`},
	}
	for _, p := range programs {
		if _, err := h.DB.CreateBuildProgram(ctx, p); err != nil {
			h.logger().Warn("demo build program seed failed", "name", p.Name, "err", err)
		}
	}
}

func seedDemoManufacturingCosts(ctx context.Context, h HandlerDeps) {
	if h.DB == nil {
		return
	}
	region := 10000002
	materials := []struct {
		qty   int
		price float64
	}{
		{10000, 5},
		{5000, 12},
		{2000, 60},
	}
	baseInput := 0.0
	for _, m := range materials {
		baseInput += float64(m.qty) * m.price
	}
	products := []struct {
		productID   int
		blueprintID int
		name        string
		bpName      string
		output      float64
	}{
		{587, 99011, "Rifter", "Demo Rifter Blueprint", 500000},
		{24702, 99012, "Hurricane", "Demo Hurricane Blueprint", 12000000},
		{24698, 99013, "Drake", "Demo Drake Blueprint", 10000000},
		{638, 99014, "Raven", "Demo Raven Blueprint", 180000000},
		{17480, 99015, "Procurer", "Demo Procurer Blueprint", 8000000},
		{32880, 99016, "Venture", "Demo Venture Blueprint", 400000},
	}
	t2products := []struct {
		productID   int
		blueprintID int
		name        string
		bpName      string
		output      float64
		baseInput   float64
	}{
		{99301, 99311, "Demo Rifter II", "Demo Rifter II Blueprint", 2500000, 500000 + 20000*5 + 10000*12},
		{99302, 99312, "Demo Hurricane II", "Demo Hurricane II Blueprint", 40000000, 12000000 + 20000*5 + 10000*12},
	}
	var rows []store.PrecomputedCost
	appendCost := func(productID, blueprintID int, name, bpName string, output, base float64) {
		for me := 0; me <= 10; me++ {
			input := base * (1 - float64(me)*0.01)
			profit := output - input
			margin := 0.0
			if output > 0 {
				margin = profit / output * 100
			}
			rows = append(rows, store.PrecomputedCost{
				ProductID: productID, BlueprintID: blueprintID, RegionID: region, MEL: me,
				ProductName: name, BlueprintName: bpName, Portion: 1,
				InputCost: input, OutputValue: output, BaseProfit: profit, ProfitMargin: margin,
				SellScore: 80, ScoreAvgVolume: 100, ScoreAvgOrders: 20, ScoreActive: 28, ScoreTotal: 30,
			})
		}
	}
	for _, p := range products {
		appendCost(p.productID, p.blueprintID, p.name, p.bpName, p.output, baseInput)
	}
	for _, p := range t2products {
		appendCost(p.productID, p.blueprintID, p.name, p.bpName, p.output, p.baseInput)
	}
	if err := h.DB.UpsertManufacturingCosts(ctx, rows); err != nil {
		h.logger().Warn("demo manufacturing costs seed failed", "err", err)
	}
}
