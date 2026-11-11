package market

import (
	"math"
	"sort"
)

const (
	DefaultBrokerFee      = 3.0
	DefaultSalesTax       = 3.6
	DefaultMinMargin      = 5.0
	DefaultMinDailyVolume = 5
	DefaultTransportIskM3 = 0.0
)

func RegionName(regionID int) string {
	if n, ok := Regions[regionID]; ok {
		return n
	}
	return "Region " + itoa(regionID)
}

type StationProfit struct {
	GrossMarginPct   float64 `json:"gross_margin_pct"`
	NetProfitPerUnit float64 `json:"net_profit_per_unit"`
	NetMarginPct     float64 `json:"net_margin_pct"`
	RoiPct           float64 `json:"roi_pct"`
	BreakEven        float64 `json:"break_even"`
}

func CalcStationTradeProfit(buyPrice, sellPrice, brokerFeePct, salesTaxPct float64) StationProfit {
	if buyPrice <= 0 || sellPrice <= 0 || sellPrice <= buyPrice {
		return StationProfit{}
	}
	gross := (sellPrice - buyPrice) / sellPrice * 100
	buyBroker := buyPrice * brokerFeePct / 100
	sellBroker := sellPrice * brokerFeePct / 100
	salesTax := sellPrice * salesTaxPct / 100
	net := sellPrice - buyPrice - buyBroker - sellBroker - salesTax
	netMargin := net / sellPrice * 100
	totalCost := buyPrice + buyBroker
	roi := 0.0
	if totalCost > 0 {
		roi = net / totalCost * 100
	}
	breakEven := (buyPrice + buyBroker) / (1 - (brokerFeePct+salesTaxPct)/100)
	return StationProfit{
		GrossMarginPct:   round2(gross),
		NetProfitPerUnit: round2(net),
		NetMarginPct:     round2(netMargin),
		RoiPct:           round2(roi),
		BreakEven:        round2(breakEven),
	}
}

type ArbitrageProfit struct {
	NetProfitPerUnit float64 `json:"net_profit_per_unit"`
	NetMarginPct     float64 `json:"net_margin_pct"`
	RoiPct           float64 `json:"roi_pct"`
}

func CalcArbitrageProfit(buySellMin, sellBuyMax, brokerFeePct, salesTaxPct, transportIskPerM3, volumeM3 float64) ArbitrageProfit {
	if buySellMin <= 0 || sellBuyMax <= 0 {
		return ArbitrageProfit{}
	}
	transport := transportIskPerM3 * volumeM3
	salesTax := sellBuyMax * salesTaxPct / 100
	net := sellBuyMax - buySellMin - salesTax - transport
	netMargin := net / sellBuyMax * 100
	roi := 0.0
	if buySellMin > 0 {
		roi = net / buySellMin * 100
	}
	return ArbitrageProfit{
		NetProfitPerUnit: round2(net),
		NetMarginPct:     round2(netMargin),
		RoiPct:           round2(roi),
	}
}

type StationCandidate struct {
	TypeID         int
	TypeName       *string
	BestBuy        float64
	BestSell       float64
	BuyOrderCount  int
	SellOrderCount int
}

type StationOpportunity struct {
	TypeID           int     `json:"type_id"`
	TypeName         string  `json:"type_name"`
	BestBuy          float64 `json:"best_buy"`
	BestSell         float64 `json:"best_sell"`
	GrossMarginPct   float64 `json:"gross_margin_pct"`
	NetProfitPerUnit float64 `json:"net_profit_per_unit"`
	NetMarginPct     float64 `json:"net_margin_pct"`
	RoiPct           float64 `json:"roi_pct"`
	BreakEven        float64 `json:"break_even"`
	DailyVolume      int     `json:"daily_volume"`
	DailyIskProfit   float64 `json:"daily_isk_profit"`
	BuyOrderCount    int     `json:"buy_order_count"`
	SellOrderCount   int     `json:"sell_order_count"`
}

type StationScanInput struct {
	BrokerFeePct   float64
	SalesTaxPct    float64
	MinMarginPct   float64
	MinDailyVolume int
}

func BuildStationOpportunities(cands []StationCandidate, in StationScanInput, volumes map[int]*int) []StationOpportunity {
	out := []StationOpportunity{}
	for _, c := range cands {
		profit := CalcStationTradeProfit(c.BestBuy, c.BestSell, in.BrokerFeePct, in.SalesTaxPct)
		vol, hasHistory := volumes[c.TypeID]
		daily := 0
		if hasHistory {
			if vol != nil {
				daily = *vol
			}
			if daily < in.MinDailyVolume {
				continue
			}
		}
		name := "Type #" + itoa(c.TypeID)
		if c.TypeName != nil {
			name = *c.TypeName
		}
		out = append(out, StationOpportunity{
			TypeID: c.TypeID, TypeName: name,
			BestBuy: c.BestBuy, BestSell: c.BestSell,
			GrossMarginPct: profit.GrossMarginPct, NetProfitPerUnit: profit.NetProfitPerUnit,
			NetMarginPct: profit.NetMarginPct, RoiPct: profit.RoiPct, BreakEven: profit.BreakEven,
			DailyVolume: daily, DailyIskProfit: math.Round(profit.NetProfitPerUnit * float64(daily)),
			BuyOrderCount: c.BuyOrderCount, SellOrderCount: c.SellOrderCount,
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].DailyIskProfit > out[j].DailyIskProfit })
	return out
}

type ArbitrageOpportunity struct {
	TypeID           int      `json:"type_id"`
	TypeName         string   `json:"type_name"`
	VolumeM3         float64  `json:"volume_m3"`
	BuyPrice         float64  `json:"buy_price"`
	SellPrice        float64  `json:"sell_price"`
	NetProfitPerUnit float64  `json:"net_profit_per_unit"`
	NetMarginPct     float64  `json:"net_margin_pct"`
	RoiPct           float64  `json:"roi_pct"`
	DailyVolume      int      `json:"daily_volume"`
	DailyIskProfit   float64  `json:"daily_isk_profit"`
	CargoValueM3     *float64 `json:"cargo_value_m3"`
}

type TypeVolume struct {
	Name     string
	VolumeM3 float64
}

type ArbitrageScanInput struct {
	BrokerFeePct      float64
	SalesTaxPct       float64
	TransportIskPerM3 float64
	MinMarginPct      float64
	MinDailyVolume    int
}

func BuildArbitrageOpportunities(buyPrices, sellPrices map[int]float64, types map[int]TypeVolume, in ArbitrageScanInput, volumes map[int]*int) []ArbitrageOpportunity {
	out := []ArbitrageOpportunity{}
	for typeID, buyPrice := range buyPrices {
		sellPrice, ok := sellPrices[typeID]
		if !ok || sellPrice <= buyPrice {
			continue
		}
		tv := types[typeID]
		name := tv.Name
		if name == "" {
			name = "Type #" + itoa(typeID)
		}
		profit := CalcArbitrageProfit(buyPrice, sellPrice, in.BrokerFeePct, in.SalesTaxPct, in.TransportIskPerM3, tv.VolumeM3)
		if profit.NetMarginPct < in.MinMarginPct || profit.NetProfitPerUnit <= 0 {
			continue
		}
		vol, hasHistory := volumes[typeID]
		daily := 0
		if hasHistory {
			if vol != nil {
				daily = *vol
			}
			if daily < in.MinDailyVolume {
				continue
			}
		}
		var cargo *float64
		if tv.VolumeM3 > 0 {
			v := math.Round(profit.NetProfitPerUnit / tv.VolumeM3)
			cargo = &v
		}
		out = append(out, ArbitrageOpportunity{
			TypeID: typeID, TypeName: name, VolumeM3: tv.VolumeM3,
			BuyPrice: buyPrice, SellPrice: sellPrice,
			NetProfitPerUnit: profit.NetProfitPerUnit, NetMarginPct: profit.NetMarginPct, RoiPct: profit.RoiPct,
			DailyVolume: daily, DailyIskProfit: math.Round(profit.NetProfitPerUnit * float64(daily)),
			CargoValueM3: cargo,
		})
	}
	sort.Slice(out, func(i, j int) bool { return out[i].DailyIskProfit > out[j].DailyIskProfit })
	return out
}

func DailyVolume(volumes []uint64) int {
	if len(volumes) == 0 {
		return 0
	}
	var sum uint64
	for _, v := range volumes {
		sum += v
	}
	return int(float64(sum) / float64(len(volumes)))
}

func round2(v float64) float64 { return math.Round(v*100) / 100 }
