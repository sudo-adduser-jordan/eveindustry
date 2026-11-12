package jobs

import (
	"context"
	"fmt"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type demoProduct struct {
	Prod  int
	BP    int
	Name  string
	Group int
	Price float64
}

var demoIndustryProducts = []demoProduct{
	{587, 99011, "Rifter", 25, 500000},
	{24702, 99012, "Hurricane", 419, 12000000},
	{24698, 99013, "Drake", 419, 10000000},
	{638, 99014, "Raven", 27, 180000000},
	{17480, 99015, "Procurer", 463, 8000000},
	{32880, 99016, "Venture", 25, 400000},
}

var demoIndustryMinerals = []struct {
	ID    int
	Name  string
	Price float64
}{
	{34, "Tritanium", 5},
	{35, "Pyerite", 12},
	{36, "Mexallon", 60},
	{37, "Isogen", 120},
	{38, "Nocxium", 800},
	{39, "Zydrine", 1500},
	{40, "Megacyte", 3000},
	{17887, "Oxygen Isotopes", 900},
	{17888, "Nitrogen Isotopes", 950},
	{17889, "Hydrogen Isotopes", 1000},
	{16274, "Helium Isotopes", 1100},
}

var demoIndustryFormulas = []struct {
	TypeID  int
	GroupID int
	Name    string
}{
	{99201, 1888, "Demo Composite Reaction Formula"},
	{99202, 1889, "Demo Biochemical Reaction Formula"},
	{99203, 4097, "Demo Hybrid Reaction Formula"},
}

var demoIndustryT2 = []struct {
	T2    int
	T2BP  int
	T1    int
	T1BP  int
	Name  string
	Group int
	Price float64
}{
	{99301, 99311, 587, 99011, "Demo Rifter II", 25, 2500000},
	{99302, 99312, 24702, 99012, "Demo Hurricane II", 419, 40000000},
}

var demoIndustryDatacores = []struct {
	ID    int
	Name  string
	Price float64
}{
	{20171, "Datacore - Electronic Engineering", 50000},
	{20172, "Datacore - Electromagnetic Physics", 55000},
}

var demoIndustrySkills = []struct {
	ID   int
	Name string
}{
	{23087, "Amarr Encryption Methods"},
	{11443, "Electronic Engineering"},
	{11433, "Electromagnetic Physics"},
}

func int64PtrDemo(v int64) *int64 { return &v }

func buildDemoIndustrySeed() store.DemoIndustrySeed {
	var seed store.DemoIndustrySeed
	mgStarships := int64(9901)
	mgFormulas := int64(9902)
	for _, m := range demoIndustryMinerals {
		g := int64(18)
		seed.Types = append(seed.Types, store.SdeType{
			TypeID: m.ID, Name: m.Name, GroupID: &g, Published: true,
		})
		seed.Prices = append(seed.Prices, store.DemoPrice{
			TypeID: m.ID, RegionID: 10000002,
			SellMin: m.Price, BuyMax: m.Price * 0.9, Average: m.Price, Adjusted: m.Price,
		})
	}
	for _, p := range demoIndustryProducts {
		g := int64(p.Group)
		portion := int64(1)
		seed.Types = append(seed.Types, store.SdeType{
			TypeID: p.Prod, Name: p.Name, GroupID: &g,
			MarketGroupID: &mgStarships, PortionSize: &portion, Published: true,
		})
		bpg := int64(p.Group)
		seed.Types = append(seed.Types, store.SdeType{
			TypeID: p.BP, Name: "Demo " + p.Name + " Blueprint", GroupID: &bpg,
			MarketGroupID: &mgStarships, Published: true,
		})
		seed.Blueprints = append(seed.Blueprints, store.SdeBlueprint{
			BlueprintTypeID: p.BP, ActivityID: 1, Time: 3600,
			MaterialsJSON: `[{"type_id":34,"quantity":10000},{"type_id":35,"quantity":5000},{"type_id":36,"quantity":2000}]`,
			ProductsJSON:  fmt.Sprintf(`[{"type_id":%d,"quantity":1}]`, p.Prod),
		})
		seed.Materials = append(seed.Materials,
			store.SdeMaterial{TypeID: p.Prod, MaterialTypeID: 34, Quantity: 1000000},
			store.SdeMaterial{TypeID: p.Prod, MaterialTypeID: 35, Quantity: 500000},
			store.SdeMaterial{TypeID: p.Prod, MaterialTypeID: 36, Quantity: 200000},
		)
		seed.Prices = append(seed.Prices, store.DemoPrice{
			TypeID: p.Prod, RegionID: 10000002,
			SellMin: p.Price, BuyMax: p.Price * 0.9, Average: p.Price, Adjusted: p.Price,
		})
	}
	for _, f := range demoIndustryFormulas {
		g := int64(f.GroupID)
		seed.Types = append(seed.Types, store.SdeType{
			TypeID: f.TypeID, Name: f.Name, GroupID: &g,
			MarketGroupID: &mgFormulas, Published: true,
		})
		seed.Blueprints = append(seed.Blueprints, store.SdeBlueprint{
			BlueprintTypeID: f.TypeID, ActivityID: 11, Time: 1800,
			MaterialsJSON: `[{"type_id":34,"quantity":100},{"type_id":17887,"quantity":50}]`,
			ProductsJSON:  fmt.Sprintf(`[{"type_id":%d,"quantity":1}]`, f.TypeID),
		})
		seed.Prices = append(seed.Prices, store.DemoPrice{
			TypeID: f.TypeID, RegionID: 10000002,
			SellMin: 10000000, BuyMax: 9000000, Average: 10000000, Adjusted: 10000000,
		})
	}
	for _, dc := range demoIndustryDatacores {
		g := int64(18)
		seed.Types = append(seed.Types, store.SdeType{
			TypeID: dc.ID, Name: dc.Name, GroupID: &g, Published: true,
		})
		seed.Prices = append(seed.Prices, store.DemoPrice{
			TypeID: dc.ID, RegionID: 10000002,
			SellMin: dc.Price, BuyMax: dc.Price * 0.9, Average: dc.Price, Adjusted: dc.Price,
		})
	}
	for _, sk := range demoIndustrySkills {
		g := int64(16)
		seed.Types = append(seed.Types, store.SdeType{
			TypeID: sk.ID, Name: sk.Name, GroupID: &g, Published: true,
		})
	}
	metaT2 := int64(2)
	for _, t := range demoIndustryT2 {
		g := int64(t.Group)
		portion := int64(1)
		seed.Types = append(seed.Types, store.SdeType{
			TypeID: t.T2, Name: t.Name, GroupID: &g,
			MarketGroupID: &mgStarships, PortionSize: &portion,
			MetaGroupID: &metaT2, Published: true,
		})
		seed.Types = append(seed.Types, store.SdeType{
			TypeID: t.T2BP, Name: t.Name + " Blueprint", GroupID: &g, Published: true,
		})
		seed.Blueprints = append(seed.Blueprints, store.SdeBlueprint{
			BlueprintTypeID: t.T2BP, ActivityID: 1, Time: 7200,
			MaterialsJSON: fmt.Sprintf(`[{"type_id":%d,"quantity":1},{"type_id":34,"quantity":20000},{"type_id":35,"quantity":10000}]`, t.T1),
			ProductsJSON:  fmt.Sprintf(`[{"type_id":%d,"quantity":1}]`, t.T2),
		})
		seed.Blueprints = append(seed.Blueprints, store.SdeBlueprint{
			BlueprintTypeID: t.T1BP, ActivityID: 8, Time: 3600,
			MaterialsJSON: `[{"type_id":20171,"quantity":4},{"type_id":20172,"quantity":4}]`,
			ProductsJSON:  fmt.Sprintf(`[{"type_id":%d,"quantity":10,"probability":0.3}]`, t.T2BP),
		})
		seed.Prices = append(seed.Prices, store.DemoPrice{
			TypeID: t.T2, RegionID: 10000002,
			SellMin: t.Price, BuyMax: t.Price * 0.9, Average: t.Price, Adjusted: t.Price,
		})
	}
	seed.MarketGroups = []store.SdeMarketGroup{
		{GroupID: 9901, Name: "Demo Starships", HasTypes: true},
		{GroupID: 9902, Name: "Demo Reaction Formulas", HasTypes: true},
	}
	cat := 17
	seed.Groups = []store.SdeGroup{
		{GroupID: 1888, Name: "Composite", CategoryID: &cat, Published: true},
		{GroupID: 1889, Name: "Biochemical", CategoryID: &cat, Published: true},
		{GroupID: 4097, Name: "Hybrid", CategoryID: &cat, Published: true},
	}
	seed.Categories = []store.SdeCategory{
		{CategoryID: 6, Name: "Ship", Published: true},
		{CategoryID: 7, Name: "Module", Published: true},
		{CategoryID: 17, Name: "Commodity", Published: true},
	}
	return seed
}

func seedDemoIndustryData(ctx context.Context, h HandlerDeps, userID int64) {
	if h.DB == nil {
		return
	}
	seed := buildDemoIndustrySeed()
	if err := h.DB.SeedDemoIndustry(ctx, seed); err != nil {
		h.logger().Warn("demo industry seed failed", "err", err)
	}
	if err := h.DB.RepairDemoTypes(ctx, seed.Types); err != nil {
		h.logger().Warn("demo types repair failed", "err", err)
	}
	for _, item := range []store.WatchedItemUpsert{
		{UserID: userID, TypeID: 34, TypeName: "Tritanium", DesiredQuantity: 10000000},
		{UserID: userID, TypeID: 35, TypeName: "Pyerite", DesiredQuantity: 5000000},
		{UserID: userID, TypeID: 587, TypeName: "Rifter", DesiredQuantity: 10},
	} {
		if _, err := h.DB.UpsertWatchedItem(ctx, item); err != nil {
			h.logger().Warn("demo stock seed failed", "type_id", item.TypeID, "err", err)
		}
	}
}
