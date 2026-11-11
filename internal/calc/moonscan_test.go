package calc

import "testing"

func TestParseMoonScan(t *testing.T) {
	raw := "Moon\tMoon Product\tQuantity\tTypeID\nAldali II - Moon 1\t\t\t\n\tZeolites\t0.5\t45490\n\tSylvite\t0.5\t45491\n"
	moons, errs := ParseMoonScan(raw)
	if len(errs) != 0 {
		t.Fatalf("errs = %v", errs)
	}
	if len(moons) != 1 || moons[0].Name != "Aldali II - Moon 1" {
		t.Fatalf("moons = %+v", moons)
	}
	if len(moons[0].Ores) != 2 || moons[0].Ores[0].TypeID != 45490 || moons[0].Ores[0].Quantity != 0.5 {
		t.Fatalf("ores = %+v", moons[0].Ores)
	}
	_, errs = ParseMoonScan("\tZeolites\t0.5\t45490\n")
	if len(errs) == 0 {
		t.Fatal("expected orphan-ore error")
	}
	m2, _ := ParseMoonScan("nothing here\n")
	if len(m2) != 1 || len(m2[0].Ores) != 0 {
		t.Fatalf("lone line = %+v", m2)
	}
	_, errs = ParseMoonScan("\n")
	if len(errs) == 0 {
		t.Fatal("expected no-data error")
	}
	if ParsePlanetName("Aldali II - Moon 12") != "Aldali II" {
		t.Fatal("planet")
	}
	if ParseSystemName("Aldali II") != "Aldali" {
		t.Fatal("system")
	}
	if ParseSystemName("Jita") != "Jita" {
		t.Fatal("system plain")
	}
	if MoonGrade(5) != "S" || MoonGrade(1) != "D" || MoonGrade(0) != "D" {
		t.Fatal("grades")
	}
}

func TestValueMoons(t *testing.T) {
	moons := []ParsedMoon{{
		Name: "Aldali II - Moon 1",
		Ores: []MoonOre{
			{Name: "Zeolites", TypeID: 45490, Quantity: 0.5},
			{Name: "Mystery", TypeID: 999999, Quantity: 0.5},
		},
	}}
	types := map[int]OreType{45490: {TypeID: 45490, Name: "Zeolites", Volume: 10, Portion: 100}}
	valued := ValueMoons(moons, types, map[int]float64{45490: 2})
	if len(valued) != 1 {
		t.Fatalf("valued = %+v", valued)
	}
	vm := valued[0]
	if vm.TotalValue != 1000000 || vm.MaxTier != 1 || vm.Grade != "D" {
		t.Fatalf("vm = %+v", vm)
	}
	if vm.Ores[0].OreUnits != 500000 || vm.Ores[0].ValueContribution != 1000000 {
		t.Fatalf("ore0 = %+v", vm.Ores[0])
	}
	if vm.Ores[1].PricePerUnit != 0 || vm.Ores[1].Tier != 0 {
		t.Fatalf("ore1 = %+v", vm.Ores[1])
	}
}

func TestEffectivePrices(t *testing.T) {
	raw := EffectivePricesRaw([]int{1}, map[int]float64{1: 5})
	if raw[1] != 5 {
		t.Fatalf("raw = %v", raw)
	}
	comp := EffectivePricesCompressed([]int{1, 2}, map[string]int{"Compressed A": 11}, map[int]float64{11: 7}, map[int]string{1: "A", 2: "B"})
	if comp[1] != 7 || comp[2] != 0 {
		t.Fatalf("comp = %v", comp)
	}
	ref := EffectivePricesRefined([]int{1}, map[int]int{1: 100}, map[int][]RefinedMaterial{
		1: {{Quantity: 100, Mineral: 34}},
	}, map[int]float64{34: 10})
	if ref[1] != 8.5 {
		t.Fatalf("ref = %v", ref)
	}
}
