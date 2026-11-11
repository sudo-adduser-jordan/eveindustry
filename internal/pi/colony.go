package pi

import (
	"math"
	"strings"
	"time"
)

const (
	PinExtractor       = "extractor"
	PinBasicFactory    = "basic_factory"
	PinAdvancedFactory = "advanced_factory"
	PinHightechFactory = "hightech_factory"
	PinStorage         = "storage"
	PinLaunchpad       = "launchpad"
	PinCommandCenter   = "command_center"
	PinUnknown         = "unknown"
)

const (
	LaunchpadCapacity = 10000.0
	StorageCapacity   = 12000.0
	DefaultVolume     = 0.01
	DecayFactor       = 0.012
	NoiseFactor       = 0.8
)

var PinTypeMap = map[int]string{
	2848: PinExtractor, 3060: PinExtractor, 3061: PinExtractor, 3062: PinExtractor,
	3063: PinExtractor, 3064: PinExtractor, 3067: PinExtractor, 3068: PinExtractor,
	2469: PinBasicFactory, 2471: PinBasicFactory, 2473: PinBasicFactory, 2481: PinBasicFactory,
	2483: PinBasicFactory, 2490: PinBasicFactory, 2492: PinBasicFactory, 2493: PinBasicFactory,
	2470: PinAdvancedFactory, 2472: PinAdvancedFactory, 2474: PinAdvancedFactory, 2480: PinAdvancedFactory,
	2484: PinAdvancedFactory, 2485: PinAdvancedFactory, 2491: PinAdvancedFactory, 2494: PinAdvancedFactory,
	2475: PinHightechFactory, 2482: PinHightechFactory,
	2257: PinStorage, 2535: PinStorage, 2536: PinStorage, 2541: PinStorage,
	2558: PinStorage, 2560: PinStorage, 2561: PinStorage, 2562: PinStorage,
	2256: PinLaunchpad, 2542: PinLaunchpad, 2543: PinLaunchpad, 2544: PinLaunchpad,
	2552: PinLaunchpad, 2555: PinLaunchpad, 2556: PinLaunchpad, 2557: PinLaunchpad,
	2254: PinCommandCenter, 2505: PinCommandCenter, 2506: PinCommandCenter, 2507: PinCommandCenter,
	2508: PinCommandCenter, 2509: PinCommandCenter, 2510: PinCommandCenter, 2511: PinCommandCenter,
}

type TypeResolver struct {
	Names   map[int]string
	Volumes map[int]float64
}

func (r TypeResolver) Name(id int) string {
	if id <= 0 {
		return "Unknown"
	}
	if n, ok := r.Names[id]; ok && n != "" {
		return n
	}
	return "Type " + itoaPI(id)
}

func (r TypeResolver) Volume(id int) float64 {
	if v, ok := r.Volumes[id]; ok && v > 0 {
		return v
	}
	return DefaultVolume
}

func CategorizePinType(typeID int, name string) string {
	if c, ok := PinTypeMap[typeID]; ok {
		return c
	}
	lower := strings.ToLower(name)
	switch {
	case strings.Contains(lower, "extractor"):
		return PinExtractor
	case strings.Contains(lower, "basic") && strings.Contains(lower, "industry"):
		return PinBasicFactory
	case strings.Contains(lower, "advanced") && strings.Contains(lower, "industry"):
		return PinAdvancedFactory
	case strings.Contains(lower, "high") && strings.Contains(lower, "tech"):
		return PinHightechFactory
	case strings.Contains(lower, "storage"):
		return PinStorage
	case strings.Contains(lower, "launchpad"):
		return PinLaunchpad
	case strings.Contains(lower, "command") && strings.Contains(lower, "center"):
		return PinCommandCenter
	}
	return PinUnknown
}

func ExtractorTotals(qtyPerCycle, cycleTime, duration int) (total float64, avgPerHour float64, cycles int) {
	if qtyPerCycle <= 0 || cycleTime <= 0 || duration <= 0 {
		return 0, 0, 0
	}
	cycles = duration / cycleTime
	barWidth := float64(cycleTime) / 900.0
	for i := 0; i < cycles; i++ {
		t := (float64(i) + 0.5) * barWidth
		decay := float64(qtyPerCycle) / (1 + t*DecayFactor)
		phase := math.Pow(float64(qtyPerCycle), 0.7)
		sinA := math.Cos(phase + t*(1.0/12.0))
		sinB := math.Cos(phase/2.0 + t*0.2)
		sinC := math.Cos(t * 0.5)
		sinStuff := math.Max((sinA+sinB+sinC)/3.0, 0)
		barHeight := decay * (1 + NoiseFactor*sinStuff)
		total += math.Floor(barWidth * barHeight)
	}
	hours := float64(duration) / 3600.0
	if hours > 0 {
		avgPerHour = math.Round(total/hours*100) / 100
	}
	return total, avgPerHour, cycles
}

func StorageUsage(contents []map[string]any, capacity float64, r TypeResolver) map[string]any {
	var totalItems int64
	var totalVolume float64
	items := make([]any, 0, len(contents))
	for _, item := range contents {
		typeID := intOf(item["type_id"])
		amount := int64Of(item["amount"])
		unitVol := r.Volume(typeID)
		vol := float64(amount) * unitVol
		totalItems += amount
		totalVolume += vol
		items = append(items, map[string]any{
			"type_id": typeID, "name": r.Name(typeID), "amount": amount,
			"volume": math.Round(vol*100) / 100,
		})
	}
	usage := 0.0
	if capacity > 0 {
		usage = math.Min(100, math.Round(totalVolume/capacity*100*10)/10)
	}
	return map[string]any{
		"capacity": capacity, "used_volume": math.Round(totalVolume*100) / 100,
		"usage_percent": usage, "total_items": totalItems, "items": items,
	}
}

type PinAnalysis struct {
	PinID    int
	TypeID   int
	TypeName string
	Category string
	Contents []any
	Raw      map[string]any
}

type PlanetAnalysis struct {
	Summary map[string]int
	Pins    []any
	Outputs []any
	Inputs  []any
	Alerts  []any
	Routes  []any
}

func AnalyzePlanet(details map[string]any, r TypeResolver, schematic func(id int) map[string]any, now time.Time) PlanetAnalysis {
	pins := listOf(details["pins"])
	routes := listOf(details["routes"])
	links := listOf(details["links"])
	var extCount, facCount, storCount, launchCount int
	pinOut := make([]any, 0, len(pins))
	outputs := []any{}
	alerts := []any{}
	var routeOutputs []any
	extPerHour := map[int]float64{}
	extNames := map[int]string{}
	for _, pv := range pins {
		pin, _ := pv.(map[string]any)
		if pin == nil {
			continue
		}
		typeID := intOf(pin["type_id"])
		tname := r.Name(typeID)
		cat := CategorizePinType(typeID, tname)
		info := map[string]any{
			"pin_id": intOf(pin["pin_id"]), "type_id": typeID, "type_name": tname,
			"category": cat, "contents": pin["contents"],
		}
		if info["contents"] == nil {
			info["contents"] = []any{}
		}
		if ext, ok := pin["extractor_details"].(map[string]any); ok {
			extCount++
			prodID := intOf(ext["product_type_id"])
			qty := intOf(ext["qty_per_cycle"])
			cycle := intOf(ext["cycle_time"])
			perHour := 0.0
			if cycle > 0 {
				perHour = float64(qty) / float64(cycle) * 3600
			}
			expiry, _ := pin["expiry_time"].(string)
			install, _ := pin["install_time"].(string)
			active := false
			if expiry != "" {
				if t, err := time.Parse(time.RFC3339, expiry); err == nil {
					active = t.After(now)
				}
			}
			duration := 0
			if expiry != "" && install != "" {
				if e, err1 := time.Parse(time.RFC3339, expiry); err1 == nil {
					if s, err2 := time.Parse(time.RFC3339, install); err2 == nil {
						if d := int(e.Sub(s).Seconds()); d > 0 {
							duration = d
						}
					}
				}
			}
			total, avg, cycles := ExtractorTotals(qty, cycle, duration)
			info["extractor"] = map[string]any{
				"product_type_id": prodID, "product_name": r.Name(prodID),
				"qty_per_cycle": qty, "cycle_time": cycle,
				"per_hour": math.Round(perHour*100) / 100, "avg_per_hour": avg,
				"total_output": total, "total_cycles": cycles,
				"expiry_time": expiry, "install_time": install, "is_active": active,
			}
			if prodID != 0 {
				extPerHour[prodID] += perHour
				extNames[prodID] = r.Name(prodID)
			}
			if expiry != "" {
				if t, err := time.Parse(time.RFC3339, expiry); err == nil && t.Before(now) {
					alerts = append(alerts, map[string]any{
						"type": "extractor_expired", "severity": "warning",
						"message": "Extractor for " + r.Name(prodID) + " has expired",
						"pin_id":  intOf(pin["pin_id"]),
					})
				}
			}
		}
		if schemID := intOf(pin["schematic_id"]); schemID > 0 {
			facCount++
			fac := map[string]any{"schematic_id": schemID}
			if s := schematic(schemID); s != nil {
				fac["schematic_name"] = strOr(s["schematic_name"], "Unknown Schematic")
				fac["cycle_time"] = intOf(s["cycle_time"])
			}
			info["factory"] = fac
		}
		if cat == PinStorage {
			storCount++
			info["storage"] = StorageUsage(listOfMap(pin["contents"]), StorageCapacity, r)
		}
		if cat == PinLaunchpad {
			launchCount++
			info["storage"] = StorageUsage(listOfMap(pin["contents"]), LaunchpadCapacity, r)
		}
		pinOut = append(pinOut, info)
	}
	routeOutputs = AnalyzeRoutes(routes, r)
	for typeID, perHour := range extPerHour {
		outputs = append(outputs, map[string]any{
			"type_id": typeID, "name": extNames[typeID],
			"per_hour": math.Round(perHour*100) / 100, "source": "extractor",
		})
	}
	return PlanetAnalysis{
		Summary: map[string]int{
			"extractor_count": extCount, "factory_count": facCount,
			"storage_count": storCount, "launchpad_count": launchCount,
			"total_pins": len(pins), "total_routes": len(routes), "total_links": len(links),
		},
		Pins: pinOut, Outputs: outputs, Inputs: []any{}, Alerts: alerts, Routes: routeOutputs,
	}
}

func AnalyzeRoutes(routes []any, r TypeResolver) []any {
	out := make([]any, 0, len(routes))
	for _, rv := range routes {
		route, _ := rv.(map[string]any)
		if route == nil {
			continue
		}
		tid := intOf(route["content_type_id"])
		out = append(out, map[string]any{
			"content_type_id": tid, "content_name": r.Name(tid),
			"quantity":           route["quantity"],
			"source_pin_id":      route["source_pin_id"],
			"destination_pin_id": route["destination_pin_id"],
		})
	}
	return out
}

type CapacityEntry struct {
	CharacterName  string
	PlanetName     string
	PlanetType     string
	ExtractorCount int
	FactoryCount   int
	Status         string
}

func BuildCapacityAnalysis(planets []map[string]any) []any {
	out := make([]any, 0, len(planets))
	for _, p := range planets {
		ext := intOf(nested(p, "summary", "extractor_count"))
		fac := intOf(nested(p, "summary", "factory_count"))
		status := "balanced"
		switch {
		case ext > 0 && fac == 0:
			status = "under_capacity"
		case fac > 0 && ext == 0:
			status = "idle_factories"
		case ext > 0 && fac > 0:
			ratio := float64(fac) / math.Max(float64(ext), 1)
			if ratio < 1.5 {
				status = "under_capacity"
			} else if ratio > 4 {
				status = "over_capacity"
			}
		}
		out = append(out, map[string]any{
			"character_name":  strOr(p["character_name"], ""),
			"planet_name":     strOr(p["planet_name"], ""),
			"planet_type":     strOr(p["planet_type"], ""),
			"extractor_count": ext, "factory_count": fac, "status": status,
		})
	}
	return out
}

func listOf(v any) []any {
	if l, ok := v.([]any); ok {
		return l
	}
	if l, ok := v.([]map[string]any); ok {
		out := make([]any, 0, len(l))
		for _, m := range l {
			out = append(out, m)
		}
		return out
	}
	return []any{}
}

func listOfMap(v any) []map[string]any {
	out := []map[string]any{}
	for _, item := range listOf(v) {
		if m, ok := item.(map[string]any); ok {
			out = append(out, m)
		}
	}
	return out
}

func intOf(v any) int {
	switch n := v.(type) {
	case int:
		return n
	case int64:
		return int(n)
	case float64:
		return int(n)
	}
	return 0
}

func int64Of(v any) int64 {
	switch n := v.(type) {
	case int:
		return int64(n)
	case int64:
		return n
	case float64:
		return int64(n)
	}
	return 0
}

func strOr(v any, def string) string {
	if s, ok := v.(string); ok && s != "" {
		return s
	}
	return def
}

func nested(m map[string]any, keys ...string) any {
	var cur any = m
	for _, k := range keys {
		mm, ok := cur.(map[string]any)
		if !ok {
			return nil
		}
		cur = mm[k]
	}
	return cur
}

func itoaPI(i int) string {
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
