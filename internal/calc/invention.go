package calc

import (
	"math"
)

const (
	BaseBPCME = 2
	BaseBPCTE = 4
	MaxME     = 10
	MinME     = 0
	MaxTE     = 20
	MinTE     = 0
)

const (
	RaitaruMEBonus = 1.0
	RaitaruTEBonus = 3.0
	RigMET1        = 2.0
	RigMET2        = 2.4
	RigTET1        = 20.0
	RigTET2        = 24.0
	SecMultHigh    = 1.0
	SecMultLow     = 1.9
	SecMultNull    = 2.1
	ReactSecHigh   = 1.0
	ReactSecLow    = 1.0
	ReactSecNull   = 1.1
)

func CombineMeBonuses(bonuses ...float64) float64 {
	factor := 1.0
	for _, b := range bonuses {
		factor *= 1 - b/100
	}
	return (1 - factor) * 100
}

func secMult(security string) float64 {
	switch security {
	case "lowsec":
		return SecMultLow
	case "nullsec":
		return SecMultNull
	default:
		return SecMultHigh
	}
}

func ManufacturingME(structureType, rigMe, systemSecurity string) float64 {
	structBonus := 0.0
	switch structureType {
	case "raitaru", "azbel", "sotiyo":
		structBonus = RaitaruMEBonus
	}
	rigBase := 0.0
	switch rigMe {
	case "t1":
		rigBase = RigMET1
	case "t2":
		rigBase = RigMET2
	}
	return CombineMeBonuses(structBonus, rigBase*secMult(systemSecurity))
}

func ManufacturingTE(structureType, rigTe, systemSecurity string) float64 {
	structBonus := 0.0
	switch structureType {
	case "raitaru", "azbel", "sotiyo":
		structBonus = RaitaruTEBonus
	}
	rigBase := 0.0
	switch rigTe {
	case "t1":
		rigBase = RigTET1
	case "t2":
		rigBase = RigTET2
	}
	return structBonus + rigBase*secMult(systemSecurity)
}

func ReactionME(rigMe, systemSecurity string) float64 {
	rigBase := 0.0
	switch rigMe {
	case "t1":
		rigBase = RigMET1
	case "t2":
		rigBase = RigMET2
	}
	mult := ReactSecHigh
	switch systemSecurity {
	case "lowsec":
		mult = ReactSecLow
	case "nullsec":
		mult = ReactSecNull
	}
	return rigBase * mult
}

type Decryptor struct {
	Key                   string  `json:"key"`
	Name                  string  `json:"name"`
	TypeID                *int    `json:"type_id"`
	ProbabilityMultiplier float64 `json:"probability_multiplier"`
	MEModifier            int     `json:"me_modifier"`
	TEModifier            int     `json:"te_modifier"`
	RunModifier           int     `json:"run_modifier"`
}

func Decryptors() []Decryptor {
	intPtr := func(i int) *int { return &i }
	return []Decryptor{
		{Key: "none", Name: "None", ProbabilityMultiplier: 1.0},
		{Key: "accelerant", Name: "Accelerant Decryptor", TypeID: intPtr(34201), ProbabilityMultiplier: 1.2, MEModifier: 2, TEModifier: 10, RunModifier: 1},
		{Key: "attainment", Name: "Attainment Decryptor", TypeID: intPtr(34202), ProbabilityMultiplier: 1.8, MEModifier: -1, TEModifier: 4, RunModifier: 4},
		{Key: "augmentation", Name: "Augmentation Decryptor", TypeID: intPtr(34203), ProbabilityMultiplier: 0.6, MEModifier: -2, TEModifier: -2, RunModifier: 9},
		{Key: "parity", Name: "Parity Decryptor", TypeID: intPtr(34204), ProbabilityMultiplier: 1.5, MEModifier: 1, TEModifier: -2, RunModifier: 3},
		{Key: "process", Name: "Process Decryptor", TypeID: intPtr(34205), ProbabilityMultiplier: 1.1, MEModifier: 3, TEModifier: 6, RunModifier: 0},
		{Key: "symmetry", Name: "Symmetry Decryptor", TypeID: intPtr(34206), ProbabilityMultiplier: 1.0, MEModifier: 1, TEModifier: 8, RunModifier: 2},
	}
}

func DecryptorByKey(key string) Decryptor {
	for _, d := range Decryptors() {
		if d.Key == key {
			return d
		}
	}
	return Decryptors()[0]
}

func InventionProbability(base float64, encryption, science1, science2 int, decryptorMult float64) float64 {
	p := base * (1 + float64(encryption)/40 + float64(science1)/30 + float64(science2)/30) * decryptorMult
	p = math.Round(p*1e6) / 1e6
	return math.Min(1, math.Max(0, p))
}

type T2BPC struct {
	ME        int       `json:"me"`
	TE        int       `json:"te"`
	Runs      int       `json:"runs"`
	Decryptor Decryptor `json:"decryptor"`
}

func CalculateT2BPC(baseRuns int, d Decryptor) T2BPC {
	me := BaseBPCME + d.MEModifier
	me = max(MinME, min(MaxME, me))
	te := BaseBPCTE + d.TEModifier
	te = max(MinTE, min(MaxTE, te))
	return T2BPC{ME: me, TE: te, Runs: max(1, baseRuns+d.RunModifier), Decryptor: d}
}

type InventionDatacore struct {
	TypeID        int     `json:"type_id"`
	Name          string  `json:"name"`
	Quantity      int     `json:"quantity"`
	SkillID       *int    `json:"skill_id"`
	SkillName     *string `json:"skill_name"`
	UnitPrice     float64 `json:"unit_price"`
	TotalCost     float64 `json:"total_cost"`
	Volume        float64 `json:"volume"`
	TotalQuantity int     `json:"total_quantity"`
	TotalCostAll  float64 `json:"total_quantity_cost"`
}

type InventionInput struct {
	T2Materials      []Material
	T2PortionSize    int
	T2ItemTypeID     int
	T1ItemTypeID     int
	T1ItemName       string
	Datacores        []DatacoreSpec
	Decryptor        Decryptor
	Prices           map[int]float64
	Volumes          map[int]float64
	Components       map[int]*ComponentData
	BPCRuns          int
	InventionRuns    int
	SystemCostIndex  float64
	FacilityTaxRate  float64
	StructureMEBonus float64
	StructureTEBonus float64
}

type DatacoreSpec struct {
	TypeID    int
	Name      string
	Quantity  int
	SkillID   *int
	SkillName *string
}

type InventionResult struct {
	Probability             float64
	ExpectedAttempts        int
	InventionCostPerAttempt float64
	InventionCostPerSuccess float64
	TotalInventionCost      float64
	BPC                     T2BPC
	T2Materials             []PricedMaterial
	TotalT2MaterialCost     float64
	TotalT2BuildCost        float64
	Datacores               []InventionDatacore
	DatacoreCostPerAttempt  float64
	DecryptorUnitPrice      float64
	DecryptorVolume         float64
	TotalAttempts           int
	DecryptorTotalQuantity  int
	DecryptorTotalCost      float64
	T2JobFee                float64
	T2FacilityTax           float64
	T2TotalJobCost          float64
	TotalOutput             int
	TotalOutputValue        float64
	T2SellPrice             float64
	TotalCost               float64
	Profit                  float64
	ProfitMargin            float64
	ProfitPerUnit           float64
	BuildCheaper            bool
	TotalCostFullBuy        float64
	ProfitFullBuy           float64
	MarginFullBuy           float64
	PerUnitFullBuy          float64
	TotalCostFullBuild      float64
	ProfitFullBuild         float64
	MarginFullBuild         float64
	PerUnitFullBuild        float64
	StructureMEBonus        float64
	StructureTEBonus        float64
}

func CalculateInventionJob(in InventionInput, probability float64) InventionResult {
	totalMatCost, totalBuildCost := 0.0, 0.0
	mats := make([]PricedMaterial, 0, len(in.T2Materials))
	for _, m := range in.T2Materials {
		unit := in.Prices[m.TypeID]
		total := unit * float64(m.AdjustedQuantity)
		totalMatCost += total
		pm := PricedMaterial{Material: m, UnitPrice: unit, TotalCost: total, Volume: in.Volumes[m.TypeID]}
		if comp := in.Components[m.TypeID]; comp != nil {
			buildCost := 0.0
			subs := make([]PricedMaterial, 0, len(comp.Materials))
			for _, sub := range comp.Materials {
				unit := in.Prices[sub.TypeID]
				total := unit * float64(sub.AdjustedQuantity)
				buildCost += total
				subs = append(subs, PricedMaterial{Material: sub, UnitPrice: unit, TotalCost: total, Volume: in.Volumes[sub.TypeID]})
			}
			compFee := buildCost * (in.SystemCostIndex / 100)
			compTax := compFee * (in.FacilityTaxRate / 100)
			pm.Component = &ComponentResult{
				IsBuildable: true, BlueprintTypeID: comp.BlueprintTypeID,
				PortionSize: comp.PortionSize, RunsNeeded: comp.RunsNeeded,
				BaseTime: comp.BaseTime, Materials: subs,
				BuildCost:    Round2(buildCost + compFee + compTax),
				MaterialCost: Round2(buildCost), JobCost: Round2(compFee + compTax),
			}
			totalBuildCost += buildCost + compFee + compTax
		} else {
			totalBuildCost += total
		}
		mats = append(mats, pm)
	}

	dcCost := 0.0
	dcs := make([]InventionDatacore, 0, len(in.Datacores))
	for _, dc := range in.Datacores {
		unit := in.Prices[dc.TypeID]
		total := unit * float64(dc.Quantity)
		dcCost += total
		dcs = append(dcs, InventionDatacore{
			TypeID: dc.TypeID, Name: dc.Name, Quantity: dc.Quantity,
			SkillID: dc.SkillID, SkillName: dc.SkillName,
			UnitPrice: unit, TotalCost: total, Volume: in.Volumes[dc.TypeID],
		})
	}
	decPrice, decVol := 0.0, 0.0
	if in.Decryptor.TypeID != nil {
		decPrice = in.Prices[*in.Decryptor.TypeID]
		decVol = in.Volumes[*in.Decryptor.TypeID]
	}
	costPerAttempt := dcCost + decPrice

	expectedAttempts := 0
	costPerSuccess := 0.0
	if probability > 0 {
		expectedAttempts = int(math.Ceil(1 / probability))
		costPerSuccess = costPerAttempt / probability
	}
	totalInvCost := costPerAttempt * float64(in.InventionRuns) * float64(expectedAttempts)
	totalAttempts := expectedAttempts * in.InventionRuns
	for i := range dcs {
		dcs[i].TotalQuantity = int(math.Ceil(float64(dcs[i].Quantity) * float64(totalAttempts)))
		dcs[i].TotalCostAll = dcs[i].UnitPrice * float64(dcs[i].TotalQuantity)
	}
	decQty := 0
	if decPrice > 0 {
		decQty = int(math.Ceil(float64(totalAttempts)))
	}

	t2Fee := totalMatCost * (in.SystemCostIndex / 100)
	t2Tax := t2Fee * (in.FacilityTaxRate / 100)
	t2TotalFee := t2Fee + t2Tax

	t2Price := in.Prices[in.T2ItemTypeID]
	totalOutput := in.BPCRuns * in.InventionRuns * in.T2PortionSize
	totalValue := t2Price * float64(totalOutput)

	costBuy := totalMatCost + totalInvCost + t2TotalFee
	profitBuy := totalValue - costBuy
	costBuild := totalBuildCost + totalInvCost + t2TotalFee
	profitBuild := totalValue - costBuild
	buildCheaper := costBuild < costBuy
	totalCost := costBuy
	if buildCheaper {
		totalCost = costBuild
	}
	profit := totalValue - totalCost

	perUnitBuy, perUnitBuild, perUnit := 0.0, 0.0, 0.0
	if totalOutput > 0 {
		perUnitBuy = profitBuy / float64(totalOutput)
		perUnitBuild = profitBuild / float64(totalOutput)
		perUnit = profit / float64(totalOutput)
	}

	return InventionResult{
		Probability: probability, ExpectedAttempts: expectedAttempts,
		InventionCostPerAttempt: costPerAttempt, InventionCostPerSuccess: Round2(costPerSuccess),
		TotalInventionCost: Round2(totalInvCost), BPC: CalculateT2BPC(in.BPCRuns, in.Decryptor),
		T2Materials:         mats,
		TotalT2MaterialCost: totalMatCost, TotalT2BuildCost: Round2(totalBuildCost),
		Datacores: dcs, DatacoreCostPerAttempt: dcCost,
		DecryptorUnitPrice: decPrice, DecryptorVolume: decVol, TotalAttempts: totalAttempts,
		DecryptorTotalQuantity: decQty, DecryptorTotalCost: decPrice * float64(decQty),
		T2JobFee: t2Fee, T2FacilityTax: t2Tax, T2TotalJobCost: t2TotalFee,
		TotalOutput: totalOutput, TotalOutputValue: totalValue, T2SellPrice: t2Price,
		TotalCost: Round2(totalCost), Profit: Round2(profit),
		ProfitMargin: marginOf(profit, totalCost), ProfitPerUnit: Round2(perUnit),
		BuildCheaper:     buildCheaper,
		TotalCostFullBuy: Round2(costBuy), ProfitFullBuy: Round2(profitBuy),
		MarginFullBuy: marginOf(profitBuy, costBuy), PerUnitFullBuy: Round2(perUnitBuy),
		TotalCostFullBuild: Round2(costBuild), ProfitFullBuild: Round2(profitBuild),
		MarginFullBuild: marginOf(profitBuild, costBuild), PerUnitFullBuild: Round2(perUnitBuild),
		StructureMEBonus: in.StructureMEBonus, StructureTEBonus: in.StructureTEBonus,
	}
}

func marginOf(profit, cost float64) float64 {
	if cost <= 0 {
		return 0
	}
	return math.Round(profit/cost*100*10) / 10
}
