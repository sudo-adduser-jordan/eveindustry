package jobs

import (
	"context"
	"encoding/json"
	"math/rand"
	"strings"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func RegisterMaintenanceJobs(r *Runner, h HandlerDeps) {
	r.Register("demo-reset", JobDef{
		Queue: QueueDefault, MaxTries: 1, Timeout: 600 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			return ResetDemoAccount(ctx, h)
		},
	})
	r.Register("market-snapshots-prune", JobDef{
		Queue: QueueUpdates, MaxTries: 2, Timeout: 600 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			return PruneSnapshots(ctx, h)
		},
	})
}

var demoFirst = []string{"Kael", "Ryn", "Tavo", "Mira", "Dax", "Selen", "Orrin", "Vexa", "Joren", "Alis", "Brask", "Nyla", "Corvin", "Ilsa", "Thane", "Zora", "Marek", "Eryn", "Valo", "Sunn", "Pike", "Lorn", "Wren", "Davos", "Isko", "Talia", "Hex", "Quill", "Rowan", "Soren"}
var demoLast = []string{"Vandrel", "Okoro", "Halvane", "Strand", "Mirosh", "Tallen", "Brightwater", "Kessing", "Duvall", "Orlane", "Pryce", "Ashgrove", "Nakamura-Ray", "Velkor", "Harrow", "Ibsen", "Cade", "Lindqvist", "Sarn", "Moreau", "Falk", "Treven", "Yoru", "Grayle", "Dunmere", "Castellan", "Rook", "Thessaly", "Voss", "Amari"}
var demoCorpA = []string{"Nova", "Ironclad", "Silent", "Gilded", "Northern", "Ember", "Quantum", "Obsidian", "Lunar", "Cobalt", "Veridian", "Apex", "Harbor", "Stellar", "Tidal"}
var demoCorpB = []string{"Forge", "Freight", "Works", "Extraction", "Logistics", "Syndicate", "Fabrication", "Holdings", "Mining Co", "Industries", "Reclamation", "Dynamics"}
var demoAlliance = []string{"Aurora", "Meridian", "Ashen Coast", "Fallow Reach", "Kestrel", "Open Horizon"}

func ResetDemoAccount(ctx context.Context, h HandlerDeps) error {
	uid, err := h.DB.EnsureDemoUser(ctx, "demo@eve-industrialist.invalid")
	if err != nil {
		return err
	}
	if err := h.DB.TouchDemoUser(ctx, uid); err != nil {
		return err
	}
	if err := h.DB.WipeUserContent(ctx, uid); err != nil {
		return err
	}
	rng := rand.New(rand.NewSource(time.Now().UnixNano()))
	pick := func(list []string) string { return list[rng.Intn(len(list))] }
	nextID := func(floor, ceiling int64, col string) int64 {
		max, err := h.DB.MaxCharacterColumn(ctx, col, floor, ceiling)
		if err != nil {
			max = floor
		}
		if max < floor {
			max = floor
		}
		v := max + int64(1000+rng.Intn(39000000))
		if v >= ceiling {
			v = floor + int64(rng.Intn(100000))
		}
		return v
	}
	charID := nextID(99000000000, 99100000000, "character_id")
	corpID := nextID(99100000000, 99200000000, "corporation_id")
	allianceID := nextID(99200000000, 99250000000, "alliance_id")
	mainCorp := struct {
		id   int64
		name string
	}{corpID, pick(demoCorpA) + " " + pick(demoCorpB)}
	otherCorp := struct {
		id   int64
		name string
	}{corpID + 1, pick(demoCorpA) + " " + pick(demoCorpB)}
	alliance := struct {
		id   int64
		name string
	}{allianceID, pick(demoAlliance) + " Compact"}
	used := map[string]bool{}
	type charSpec struct {
		charID   int64
		name     string
		corpID   int64
		corpName string
		allyID   *int64
		allyName *string
		main     bool
		director bool
	}
	var specs []charSpec
	for i := 0; i < 3; i++ {
		var name string
		for {
			name = pick(demoFirst) + " " + pick(demoLast)
			if !used[name] {
				used[name] = true
				break
			}
		}
		corp := mainCorp
		if i == 2 {
			corp = otherCorp
		}
		var allyID *int64
		var allyName *string
		if i != 2 {
			allyID = &alliance.id
			allyName = &alliance.name
		}
		charID += int64(1 + rng.Intn(40))
		specs = append(specs, charSpec{charID, name, corp.id, corp.name, allyID, allyName, i == 0, i == 0})
	}
	for _, sp := range specs {
		var roles []string
		if sp.director {
			roles = []string{"Director", "Factory_Manager", "Accountant", "Junior_Accountant", "Project_Manager", "Station_Manager"}
		}
		if err := h.DB.CreateDemoCharacter(ctx, store.DemoCharacter{
			CharacterID: sp.charID, UserID: uid, Name: sp.name,
			CorporationID: sp.corpID, CorpName: sp.corpName,
			AllianceID: sp.allyID, AllianceName: sp.allyName,
			IsMain: sp.main, IsDirectorCEO: sp.director, Roles: roles,
			CachedLoc: "Jita", CachedTrain: "Advanced Mass Reactions 4",
		}); err != nil {
			return err
		}
	}
	seedDemoReferenceData(ctx, h)
	seedDemoIndustryData(ctx, h, uid)
	seedDemoPlanningData(ctx, h, uid)
	seedDemoCorporateData(ctx, h, uid, specs[0].charID, specs[0].corpID, specs[0].corpName)
	_ = RefreshAssetData(ctx, h, uid, true)
	_ = RefreshIndustryData(ctx, h, uid)
	_ = RefreshDashboardStats(ctx, h, uid)
	h.logger().Info("demo account reset", "user", uid)
	return nil
}

func PruneSnapshots(ctx context.Context, h HandlerDeps) error {
	now := time.Now()
	graceCutoff := now.Add(-3 * time.Hour)
	failedCutoff := now.Add(-24 * time.Hour)
	active, err := h.DB.ActiveContractGeneration(ctx)
	if err != nil {
		return err
	}
	cands, err := h.DB.PrunableContractGenerations(ctx, graceCutoff, failedCutoff, 2, active)
	if err != nil {
		return err
	}
	for _, g := range cands {
		if _, err := h.DB.DeleteContractGenerationItems(ctx, g.ID, 500); err != nil {
			h.logger().Warn("prune contract items failed", "gen", g.ID, "err", err)
			continue
		}
		if _, err := h.DB.DeleteContractGenerationContracts(ctx, g.ID, 500); err != nil {
			h.logger().Warn("prune contracts failed", "gen", g.ID, "err", err)
			continue
		}
		if ok, err := h.DB.DeleteContractGeneration(ctx, g.ID); err != nil {
			return err
		} else if ok {
			h.logger().Info("pruned contract generation", "id", g.ID)
		}
	}
	regions, err := h.DB.SweptRegionIDs(ctx)
	if err != nil {
		return err
	}
	marketCutoff := now.Add(-3 * time.Hour)
	for _, region := range regions {
		protected, err := h.DB.ActiveSweepIDs(ctx, region, 2)
		if err != nil {
			return err
		}
		if _, _, activeSweep, err := h.DB.CurrentGeneration(ctx, region); err == nil && activeSweep != "" {
			protected = append(protected, activeSweep)
		}
		cands, err := h.DB.PrunableSweeps(ctx, region, marketCutoff, protected)
		if err != nil {
			return err
		}
		for _, sweepID := range cands {
			if _, err := h.DB.DeleteSweepAggregates(ctx, region, sweepID, 500); err != nil {
				return err
			}
			h.logger().Info("pruned market aggregates", "region", region, "sweep", sweepID)
		}
	}
	return nil
}

var _ = strings.TrimSpace

func MaintenanceSchedule(h HandlerDeps, demoEnabled bool, demoResetAt string) []ScheduleEntry {
	if demoResetAt == "" {
		demoResetAt = "03:30"
	}
	return []ScheduleEntry{
		{
			Name: "demo:reset", Every: 24 * time.Hour, At: demoResetAt, Overlap: 24 * time.Hour,
			When: func(ctx context.Context) bool { return demoEnabled },
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return r.Dispatch(ctx, q, "demo-reset", nil)
			},
		},
		{
			Name: "market:snapshots-prune", Every: 24 * time.Hour, At: "04:30", Overlap: 24 * time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return r.Dispatch(ctx, q, "market-snapshots-prune", nil)
			},
		},
	}
}
