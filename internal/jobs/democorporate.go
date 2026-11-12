package jobs

import (
	"context"
	"database/sql"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func seedDemoCorporateData(ctx context.Context, h HandlerDeps, userID, mainCharID, mainCorpID int64, mainCorpName string) {
	if h.DB == nil || mainCorpID == 0 || mainCharID == 0 {
		return
	}
	if _, err := h.DB.UpsertDonationConfig(ctx, mainCorpID, mainCorpName, mainCharID, userID); err != nil {
		h.logger().Warn("demo donation config seed failed", "err", err)
	}
	if _, err := h.DB.UpsertCorpConfig(ctx, mainCorpID, mainCorpName, mainCharID, userID); err != nil {
		h.logger().Warn("demo corp config seed failed", "err", err)
	}
	seedDemoCorpPrograms(ctx, h, userID, mainCorpID)
	seedDemoStructureWindows(ctx, h, userID, mainCorpID)
}

func seedDemoCorpPrograms(ctx context.Context, h HandlerDeps, userID, corpID int64) {
	existing, err := h.DB.CorpPrograms(ctx, corpID)
	if err == nil && len(existing) > 0 {
		return
	}
	t2Rifter := sql.NullInt64{Int64: 587, Valid: true}
	t2RifterName := sql.NullString{String: "Rifter", Valid: true}
	t2Hurricane := sql.NullInt64{Int64: 24702, Valid: true}
	t2HurricaneName := sql.NullString{String: "Hurricane", Valid: true}
	programs := []store.CorpProgram{
		{CorporationID: corpID, CreatedByUserID: userID, Name: "Demo Corp Rifter Line — 20 runs", ProgramType: "manufacturing",
			T2TypeID: t2Rifter, T2TypeName: t2RifterName,
			StepsJSON:    `[{"type_id":587,"name":"Rifter","quantity_required":20,"runs_needed":20,"materials":[{"type_id":34,"name":"Tritanium","quantity":200000},{"type_id":35,"name":"Pyerite","quantity":100000},{"type_id":36,"name":"Mexallon","quantity":40000}]}]`,
			SettingsJSON: sql.NullString{String: `{"me_level":10,"te_level":20,"structure_type":"azbel","system_security":"lowsec","sections":[]}`, Valid: true}},
		{CorporationID: corpID, CreatedByUserID: userID, Name: "Demo Corp Hurricane Wing — 10 runs", ProgramType: "manufacturing",
			T2TypeID: t2Hurricane, T2TypeName: t2HurricaneName,
			StepsJSON:    `[{"type_id":24702,"name":"Hurricane","quantity_required":10,"runs_needed":10,"materials":[{"type_id":34,"name":"Tritanium","quantity":100000},{"type_id":35,"name":"Pyerite","quantity":50000},{"type_id":36,"name":"Mexallon","quantity":20000}]}]`,
			SettingsJSON: sql.NullString{String: `{"me_level":10,"te_level":20,"structure_type":"azbel","system_security":"lowsec","sections":[]}`, Valid: true}},
		{CorporationID: corpID, CreatedByUserID: userID, Name: "Demo Corp Composite Reactions", ProgramType: "reaction",
			StepsJSON:    `[{"type_id":99201,"name":"Demo Composite Reaction Formula","quantity_required":960,"runs_needed":960,"activity_type":"reaction","blueprint_type_id":99201,"materials":[{"type_id":34,"name":"Tritanium","quantity":96000},{"type_id":17887,"name":"Oxygen Isotopes","quantity":48000}]}]`,
			SettingsJSON: sql.NullString{String: `{"reaction_structure_type":"athanor","reaction_rig_me":"t1","reaction_system_security":"lowsec","sections":[]}`, Valid: true}},
	}
	for _, p := range programs {
		if _, err := h.DB.CreateCorpProgram(ctx, p); err != nil {
			h.logger().Warn("demo corp program seed failed", "name", p.Name, "err", err)
		}
	}
}

func seedDemoStructureWindows(ctx context.Context, h HandlerDeps, userID, corpID int64) {
	existing, _, err := h.DB.StructureWindows(ctx, corpID, 1, 0)
	if err == nil && len(existing) > 0 {
		return
	}
	byUID := sql.NullInt64{Int64: userID, Valid: true}
	reinforce := sql.NullTime{Time: time.Now().UTC().AddDate(0, 0, 7), Valid: true}
	windows := []store.StructureWindow{
		{CorporationID: corpID, SolarSystemID: 30000142, SolarSystemName: "Jita",
			StructureType: "upwell", StructureName: "Demo Azbel — Forge HQ",
			DayOfWeek:    sql.NullInt64{Int64: 3, Valid: true},
			StartTimeUTC: "18:00", DurationMinutes: 60,
			ReinforceExitAt: reinforce,
			Notes:           sql.NullString{String: "Demo prime vulnerability window", Valid: true},
			CreatedByUserID: byUID},
		{CorporationID: corpID, SolarSystemID: 30002187, SolarSystemName: "Amarr",
			StructureType: "upwell", StructureName: "Demo Raitaru — Assembly",
			DayOfWeek:    sql.NullInt64{Int64: 6, Valid: true},
			StartTimeUTC: "20:00", DurationMinutes: 90,
			Notes:           sql.NullString{String: "Weekend industry window", Valid: true},
			CreatedByUserID: byUID},
		{CorporationID: corpID, SolarSystemID: 30002510, SolarSystemName: "Rens",
			StructureType: "skyhook", StructureName: "Demo Skyhook — Belt 1",
			StartTimeUTC: "12:00", DurationMinutes: 30,
			Notes:           sql.NullString{String: "Skyhooks reinforce daily, no weekday", Valid: true},
			CreatedByUserID: byUID},
	}
	for _, w := range windows {
		if _, err := h.DB.CreateStructureWindow(ctx, w); err != nil {
			h.logger().Warn("demo structure window seed failed", "name", w.StructureName, "err", err)
		}
	}
}
