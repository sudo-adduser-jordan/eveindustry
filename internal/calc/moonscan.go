package calc

import (
	"math"
	"regexp"
	"strings"
)

// Moon scan constants mirroring MoonScanService.
const (
	TotalMoonM3       = 10_000_000
	DefaultOreVolume  = 10.0
	RefinedEfficiency = 0.85
)

// OreTiers mirrors MoonScanService::ORE_TIERS (moon ore type_id => tier 1-5).
var OreTiers = map[int]int{
	45490: 1, 45491: 1, 45492: 1, 45493: 1,
	45494: 2, 45495: 2, 45496: 2, 45497: 2,
	45498: 3, 45499: 3, 45500: 3, 45501: 3,
	45502: 4, 45503: 4, 45504: 4, 45505: 4,
	45506: 5, 45507: 5, 45508: 5, 45509: 5,
}

// MoonOre is one parsed ore row.
type MoonOre struct {
	Name     string
	TypeID   int
	Quantity float64
}

// ParsedMoon is one moon header with its ore rows.
type ParsedMoon struct {
	Name string
	Ores []MoonOre
}

// ParseMoonScan ports the first pass of MoonScanService::analyse: moon
// headers (first column set, rest empty) with ore rows (first column empty,
// ore name second, fraction third, type id fourth) under them. TSV, header
// row skipped, errors collected.
func ParseMoonScan(raw string) ([]ParsedMoon, []string) {
	var moons []ParsedMoon
	var errs []string
	cur := -1
	for _, line := range strings.Split(strings.TrimSpace(raw), "\n") {
		line = strings.TrimRight(line, "\r")
		if strings.TrimSpace(line) == "" {
			continue
		}
		parts := strings.Split(line, "\t")
		for len(parts) < 4 {
			parts = append(parts, "")
		}
		if strings.HasPrefix(strings.TrimSpace(parts[0]), "Moon") && strings.TrimSpace(parts[1]) == "Moon Product" {
			continue
		}
		if strings.TrimSpace(parts[0]) != "" && strings.TrimSpace(parts[1]) == "" {
			moons = append(moons, ParsedMoon{Name: strings.TrimSpace(parts[0])})
			cur = len(moons) - 1
			continue
		}
		if strings.TrimSpace(parts[0]) == "" && strings.TrimSpace(parts[1]) != "" {
			if cur < 0 {
				errs = append(errs, "Ore row found before any moon header: "+strings.TrimSpace(parts[1]))
				continue
			}
			qty := parseMoonFloat(strings.TrimSpace(parts[2]))
			tid := parseMoonInt(strings.TrimSpace(parts[3]))
			moons[cur].Ores = append(moons[cur].Ores, MoonOre{
				Name: strings.TrimSpace(parts[1]), TypeID: tid, Quantity: qty,
			})
		}
	}
	if len(moons) == 0 {
		errs = append(errs, "No moon data found. Please paste the full moon scan output.")
	}
	return moons, errs
}

func parseMoonFloat(s string) float64 {
	var v float64
	for _, f := range strings.Fields(s) {
		var n float64
		if _, err := scanNum(f, &n); err == nil {
			v = n
		}
	}
	return v
}

func parseMoonInt(s string) int {
	n := 0
	neg := false
	for _, c := range s {
		switch {
		case c == '-' && n == 0:
			neg = true
		case c >= '0' && c <= '9':
			n = n*10 + int(c-'0')
		}
	}
	if neg {
		n = -n
	}
	return n
}

func scanNum(s string, out *float64) (int, error) {
	// Accept plain decimals like "0.25" or "25".
	neg := false
	i := 0
	if len(s) > 0 && (s[0] == '-' || s[0] == '+') {
		neg = s[0] == '-'
		i = 1
	}
	intPart := 0.0
	seen := false
	for ; i < len(s) && s[i] >= '0' && s[i] <= '9'; i++ {
		intPart = intPart*10 + float64(s[i]-'0')
		seen = true
	}
	frac := 0.0
	div := 1.0
	if i < len(s) && s[i] == '.' {
		i++
		for ; i < len(s) && s[i] >= '0' && s[i] <= '9'; i++ {
			frac = frac*10 + float64(s[i]-'0')
			div *= 10
			seen = true
		}
	}
	if !seen || i != len(s) {
		return 0, errMoonNum
	}
	*out = intPart + frac/div
	if neg {
		*out = -*out
	}
	return i, nil
}

var errMoonNum = moonNumErr{}

type moonNumErr struct{}

func (moonNumErr) Error() string { return "not a number" }

// OreType carries the type-table data moon valuation needs.
type OreType struct {
	TypeID     int
	Name       string
	Volume     float64
	Portion    int
}

// ValuedOre is one enriched ore row.
type ValuedOre struct {
	Name             string
	TypeID           int
	Quantity         float64
	PricePerUnit     float64
	Tier             int
	OreUnits         int
	ValueContribution float64
}

// ValuedMoon is one enriched moon.
type ValuedMoon struct {
	Name       string
	TotalValue float64
	MaxTier    int
	Grade      string
	Ores       []ValuedOre
}

// ValueMoons ports the second pass: name/volume fallback, ore units from
// composition fractions, effective prices, tiers, grades; sorted by value
// descending.
func ValueMoons(moons []ParsedMoon, types map[int]OreType, prices map[int]float64) []ValuedMoon {
	out := make([]ValuedMoon, 0, len(moons))
	for _, m := range moons {
		vm := ValuedMoon{Name: m.Name}
		for _, o := range m.Ores {
			t, ok := types[o.TypeID]
			name := o.Name
			if ok && t.Name != "" {
				name = t.Name
			}
			vol := DefaultOreVolume
			if ok && t.Volume > 0 {
				vol = t.Volume
			}
			units := 0
			if o.TypeID != 0 {
				units = int(math.Floor(o.Quantity * TotalMoonM3 / vol))
			}
			price := 0.0
			if o.TypeID != 0 {
				price = prices[o.TypeID]
			}
			tier := 0
			if o.TypeID != 0 {
				tier = OreTiers[o.TypeID]
			}
			isk := float64(units) * price
			vm.TotalValue += isk
			if tier > vm.MaxTier {
				vm.MaxTier = tier
			}
			vm.Ores = append(vm.Ores, ValuedOre{
				Name: name, TypeID: o.TypeID, Quantity: o.Quantity,
				PricePerUnit: price, Tier: tier, OreUnits: units,
				ValueContribution: isk,
			})
		}
		vm.Grade = MoonGrade(vm.MaxTier)
		out = append(out, vm)
	}
	sortMoons(out)
	return out
}

// MoonGrade ports gradeFromTierAndValue (tier only; value unused in PHP).
func MoonGrade(maxTier int) string {
	switch {
	case maxTier >= 5:
		return "S"
	case maxTier >= 4:
		return "A"
	case maxTier >= 3:
		return "B"
	case maxTier >= 2:
		return "C"
	}
	return "D"
}

func sortMoons(moons []ValuedMoon) {
	for i := 1; i < len(moons); i++ {
		for j := i; j > 0 && moons[j].TotalValue > moons[j-1].TotalValue; j-- {
			moons[j], moons[j-1] = moons[j-1], moons[j]
		}
	}
}

// EffectivePricesRaw resolves per-unit prices for raw mode (identity).
func EffectivePricesRaw(ids []int, prices map[int]float64) map[int]float64 {
	out := make(map[int]float64, len(ids))
	for _, id := range ids {
		out[id] = prices[id]
	}
	return out
}

// EffectivePricesCompressed maps raw ore ids to their compressed variant's
// price (0 when the compressed type is unknown or unpriced).
func EffectivePricesCompressed(ids []int, byName map[string]int, prices map[int]float64, rawNames map[int]string) map[int]float64 {
	out := make(map[int]float64, len(ids))
	for _, id := range ids {
		compID := byName["Compressed "+rawNames[id]]
		if compID != 0 {
			out[id] = prices[compID]
		} else {
			out[id] = 0
		}
	}
	return out
}

// RefinedMaterial is one type_materials row for refined-mode pricing.
type RefinedMaterial struct {
	Quantity int
	Mineral  int
}

// EffectivePricesRefined ports the refined branch: per-unit value =
// sum(floor(mat_qty * efficiency) * mineral_price) / portion_size.
func EffectivePricesRefined(ids []int, portions map[int]int, mats map[int][]RefinedMaterial, mineralPrices map[int]float64) map[int]float64 {
	out := make(map[int]float64, len(ids))
	for _, id := range ids {
		portion := portions[id]
		if portion < 1 {
			portion = 1
		}
		batch := 0.0
		for _, m := range mats[id] {
			batch += float64(int(math.Floor(float64(m.Quantity)*RefinedEfficiency))) * mineralPrices[m.Mineral]
		}
		out[id] = batch / float64(portion)
	}
	return out
}

var romanSuffix = regexp.MustCompile(`\s+[IVXLC]+$`)

// ParsePlanetName ports MoonScanService::parsePlanetName ("Aldali II - Moon 12" -> "Aldali II").
func ParsePlanetName(moonName string) string {
	if pos := strings.Index(moonName, " - Moon"); pos >= 0 {
		return strings.TrimSpace(moonName[:pos])
	}
	if pos := strings.Index(moonName, " - "); pos >= 0 {
		return strings.TrimSpace(moonName[:pos])
	}
	return ""
}

// ParseSystemName ports parseSystemName ("Aldali II" -> "Aldali").
func ParseSystemName(planetName string) string {
	if planetName == "" {
		return ""
	}
	cleaned := romanSuffix.ReplaceAllString(planetName, "")
	if strings.TrimSpace(cleaned) == "" {
		return planetName
	}
	return strings.TrimSpace(cleaned)
}
