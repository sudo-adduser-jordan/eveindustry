package http

import (
	"database/sql"
	"encoding/json"
	"net/http"
	"strconv"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerContracts mounts POST /market/appraisals and the contract browser,
// mirroring routes/api_v1/market.php. Freshness here describes the public
// contract snapshot, not regional orders.
func registerContracts(v1 *echo.Group, d Deps) {
	if d.Contracts == nil {
		return
	}
	v1.POST("/market/appraisals", handleAppraisal(d))
	v1.GET("/market/contracts", handleContractIndex(d))
	v1.GET("/market/contracts/:contractId", handleContractShow(d))
}

// POST /market/appraisals — mirrors AppraisalController@store. Item names
// come from the JSON `items` string (one per line); each type is priced from
// single-type item exchange contracts across all regions. Nothing is stored.
func handleAppraisal(d Deps) echo.HandlerFunc {
	type input struct {
		Items  *string `json:"items"`
		Filter *string `json:"filter"`
		Method *string `json:"method"`
	}
	return func(c echo.Context) error {
		var in input
		if err := json.NewDecoder(c.Request().Body).Decode(&in); err != nil {
			verrs := validationErrors{}
			verrs.add("body", "The request body must be valid JSON.")
			return verrs.invalid(c)
		}
		verrs := validationErrors{}
		if in.Items == nil || *in.Items == "" {
			verrs.add("items", "The items field is required.")
		} else if len([]rune(*in.Items)) > 50000 {
			verrs.add("items", "The items field must not be greater than 50000 characters.")
		}
		filter := "all"
		if in.Filter != nil {
			if *in.Filter != "all" && *in.Filter != "sell" && *in.Filter != "buy" {
				verrs.add("filter", "The selected filter is invalid.")
			} else {
				filter = *in.Filter
			}
		}
		method := "average"
		if in.Method != nil {
			if *in.Method != "average" && *in.Method != "lowest" {
				verrs.add("method", "The selected method is invalid.")
			} else {
				method = *in.Method
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()

		gen, err := d.Contracts.ActiveGeneration(ctx)
		if err != nil {
			return dbError(c, err)
		}
		out := []any{}
		unmatched := []string{}
		total := 0.0
		if gen != nil {
			names, counts := market.ParseAppraisalItems(*in.Items)
			types, err := d.Contracts.TypesByName(ctx, names)
			if err != nil {
				return dbError(c, err)
			}
			for _, name := range names {
				t, ok := types[name]
				if !ok {
					unmatched = append(unmatched, name)
					continue
				}
				unit, found, err := d.Contracts.ContractPricing(ctx, *gen, t.TypeID, filter == "sell", filter == "buy", method == "lowest")
				if err != nil {
					return dbError(c, err)
				}
				line := unit * float64(counts[name])
				total += line
				out = append(out, map[string]any{
					"type_id": t.TypeID, "name": t.Name, "quantity": counts[name],
					"unit_price": unit, "line_total": line, "contracts_found": found,
				})
			}
		} else if in.Items != nil {
			// No active generation: every name is unmatched, like the PHP
			// early return that finds no pricing.
			_, counts := market.ParseAppraisalItems(*in.Items)
			for name := range counts {
				unmatched = append(unmatched, name)
			}
		}
		return Success(c, http.StatusOK, map[string]any{
			"items": out, "total_value": total, "unmatched": unmatched,
		}, map[string]any{
			"freshness": contractsFreshness(c, d, gen),
		})
	}
}

// GET /market/contracts — mirrors ContractController@index: active-generation
// contracts newest first, stats over the whole generation.
func handleContractIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		typeFilter := ""
		if raw := c.QueryParam("type"); raw != "" {
			if raw != "item_exchange" && raw != "auction" && raw != "courier" {
				verrs.add("type", "The selected type is invalid.")
			} else {
				typeFilter = raw
			}
		}
		search := c.QueryParam("search")
		if len([]rune(search)) > 100 {
			verrs.add("search", "The search field must not be greater than 100 characters.")
		}
		page, perPage := parsePagination(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		ctx := c.Request().Context()
		gen, err := d.Contracts.ActiveGeneration(ctx)
		if err != nil {
			return dbError(c, err)
		}
		rows := []any{}
		total := 0
		stats := map[string]int{}
		if gen != nil {
			list, n, err := d.Contracts.ContractList(ctx, *gen, typeFilter, search, perPage, (page-1)*perPage)
			if err != nil {
				return dbError(c, err)
			}
			total = n
			for _, cc := range list {
				rows = append(rows, contractShape(cc, nil))
			}
			counts, err := d.Contracts.ContractTypeStats(ctx, *gen)
			if err != nil {
				return dbError(c, err)
			}
			sum := 0
			for _, n := range counts {
				sum += n
			}
			stats = map[string]int{
				"total": sum, "item_exchange": counts["item_exchange"],
				"auction": counts["auction"], "courier": counts["courier"],
			}
		} else {
			stats = map[string]int{"total": 0, "item_exchange": 0, "auction": 0, "courier": 0}
		}
		_, pagination := paginate(total, page, perPage)
		return Success(c, http.StatusOK, rows, map[string]any{
			"pagination": pagination, "stats": stats,
			"freshness": contractsFreshness(c, d, gen),
		})
	}
}

// GET /market/contracts/:contractId — mirrors ContractController@show.
func handleContractShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, err := strconv.ParseUint(c.Param("contractId"), 10, 64)
		if err != nil || id == 0 {
			return Failure(c, http.StatusNotFound, "Contract not found.", nil)
		}
		ctx := c.Request().Context()
		gen, err := d.Contracts.ActiveGeneration(ctx)
		if err != nil {
			return dbError(c, err)
		}
		if gen == nil {
			return Failure(c, http.StatusNotFound, "Contract not found.", nil)
		}
		cc, err := d.Contracts.ContractShow(ctx, *gen, id)
		if err != nil {
			return dbError(c, err)
		}
		if cc == nil {
			return Failure(c, http.StatusNotFound, "Contract not found.", nil)
		}
		items, err := d.Contracts.ContractItems(ctx, *gen, id)
		if err != nil {
			return dbError(c, err)
		}
		names := map[int]string{}
		if len(items) > 0 {
			ids := make([]int, 0, len(items))
			for _, it := range items {
				ids = append(ids, it.TypeID)
			}
			rows, err := d.Contracts.TypesByID(ctx, ids)
			if err != nil {
				return dbError(c, err)
			}
			names = rows
		}
		out := make([]any, 0, len(items))
		for _, it := range items {
			name := names[it.TypeID]
			if name == "" {
				name = "Unknown Type #" + strconv.Itoa(it.TypeID)
			}
			out = append(out, map[string]any{
				"type_id": it.TypeID, "type_name": name, "quantity": it.Quantity,
				"is_included": it.IsIncluded, "is_blueprint_copy": nullableBool(it.IsBlueprintCopy),
				"material_efficiency": nullableInt64(it.MaterialEfficiency),
				"time_efficiency": nullableInt64(it.TimeEfficiency),
				"runs": nullableInt64(it.Runs),
			})
		}
		return Success(c, http.StatusOK, contractShape(*cc, out), nil)
	}
}

func contractShape(cc store.SnapshotContract, items []any) map[string]any {
	m := map[string]any{
		"contract_id": cc.ContractID, "type": cc.Type, "region_id": cc.RegionID,
		"title": nullOrEmpty(cc.Title), "issuer_id": nullableInt64(cc.IssuerID),
		"price": cc.Price, "volume": cc.Volume,
		"date_issued": isoNullTime(cc.DateIssued), "date_expired": isoNullTime(cc.DateExpired),
		"items_count": cc.ItemsCount,
	}
	if items != nil {
		m["items"] = items
		m["buyout"] = positiveOrNull(cc.Buyout)
		m["reward"] = positiveOrNull(cc.Reward)
		m["collateral"] = positiveOrNull(cc.Collateral)
	}
	return m
}

// contractsFreshness mirrors MarketFreshnessService::contractsFreshnessMeta():
// the snapshot's source timestamp, costs always null.
func contractsFreshness(c echo.Context, d Deps, gen *uint64) map[string]any {
	if gen == nil {
		return map[string]any{
			"prices_as_of": nil, "costs_as_of": nil, "source": "none", "stale": true,
		}
	}
	asOf, err := d.Contracts.ContractsFreshnessTime(c.Request().Context(), *gen)
	if err != nil || asOf == nil {
		return map[string]any{
			"prices_as_of": nil, "costs_as_of": nil, "source": "none", "stale": true,
		}
	}
	s := asOf.UTC().Format(time.RFC3339)
	return map[string]any{
		"prices_as_of": s, "costs_as_of": nil,
		"source": "public_contracts", "stale": time.Since(*asOf) > 2*time.Hour,
	}
}

func nullableBool(nb sql.NullBool) any {
	if !nb.Valid {
		return nil
	}
	return nb.Bool
}

func nullOrEmpty(ns sql.NullString) any {
	if !ns.Valid || ns.String == "" {
		return nil
	}
	return ns.String
}

func isoNullTime(nt sql.NullTime) any {
	if !nt.Valid {
		return nil
	}
	return nt.Time.UTC().Format(time.RFC3339)
}

func positiveOrNull(nf sql.NullFloat64) any {
	if !nf.Valid || nf.Float64 <= 0 {
		return nil
	}
	return nf.Float64
}
