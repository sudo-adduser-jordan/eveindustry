package http

import (
	"database/sql"
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func registerReference(v1 *echo.Group, d Deps) {
	if d.Reference == nil {
		return
	}
	v1.GET("/types", handleTypeSearch(d))
	v1.GET("/types/:typeId", handleTypeShow(d))
	v1.GET("/market-groups", handleRootGroups(d))
	v1.GET("/market-groups/tree", handleGroupTree(d))
	v1.GET("/market-groups/:marketGroupId", handleGroupContents(d))
	v1.GET("/systems", handleSystemSearch(d))
	v1.GET("/systems/:systemId", handleSystemShow(d))
	v1.GET("/systems/:systemId/cost-indices", handleCostIndices(d))
	v1.GET("/systems/:systemId/stations", handleSystemStations(d))
	v1.GET("/stations", handleStationSearch(d))
	v1.GET("/stations/:stationId", handleStationShow(d))
}

func requireDB(d Deps) (store.ReferenceStore, bool) {
	return d.Reference, d.Reference != nil
}

func nullableString(ns sql.NullString) any {
	if ns.Valid {
		return ns.String
	}
	return nil
}

func nullableInt64(ni sql.NullInt64) any {
	if ni.Valid {
		return ni.Int64
	}
	return nil
}

func nullableFloat64(nf sql.NullFloat64) any {
	if nf.Valid {
		return nf.Float64
	}
	return nil
}

func nullableTime(nt sql.NullTime) any {
	if !nt.Valid {
		return nil
	}
	return nt.Time.UTC().Format("2006-01-02T15:04:05Z")
}

func nullStrPtr(ns sql.NullString) *string {
	if !ns.Valid {
		return nil
	}
	return &ns.String
}

func handleTypeSearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		verrs := validationErrors{}
		q, ok := parseSearchTerm(c, verrs)
		if !ok {
			return verrs.invalid(c)
		}
		exclude := parseIntList(c, "exclude_meta_groups[]", "exclude_meta_groups")
		types, err := rs.SearchTypes(c.Request().Context(), q, market.TypeSearchLimit, exclude)
		if err != nil {
			return dbError(c, err)
		}
		out := make([]any, 0, len(types))
		for _, t := range types {
			out = append(out, map[string]any{
				"type_id":         t.TypeID,
				"name":            t.Name,
				"icon_url":        market.TypeImageURL(t.TypeID),
				"market_group_id": nullableInt64(t.MarketGroupID),
			})
		}
		return Success(c, http.StatusOK, out, map[string]any{"limit": market.TypeSearchLimit})
	}
}

func handleTypeShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		id, err := strconv.Atoi(c.Param("typeId"))
		if err != nil || id < 1 {
			return Failure(c, http.StatusNotFound, "Type not found.", nil)
		}
		t, err := rs.FindType(c.Request().Context(), id)
		if err != nil {
			return dbError(c, err)
		}
		if t == nil {
			return Failure(c, http.StatusNotFound, "Type not found.", nil)
		}
		return Success(c, http.StatusOK, map[string]any{
			"type_id":         t.TypeID,
			"name":            t.Name,
			"description":     nullableString(t.Description),
			"group_id":        nullableInt64(t.GroupID),
			"market_group_id": nullableInt64(t.MarketGroupID),
			"volume":          nullableFloat64(t.Volume),
			"mass":            nullableFloat64(t.Mass),
			"portion_size":    nullableInt64(t.PortionSize),
			"published":       t.Published,
		}, nil)
	}
}

func handleRootGroups(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		ctx := c.Request().Context()
		groups, err := rs.RootGroups(ctx)
		if err != nil {
			return dbError(c, err)
		}
		out := make([]any, 0, len(groups))
		for _, g := range groups {
			n, err := rs.ChildCount(ctx, g.MarketGroupID)
			if err != nil {
				return dbError(c, err)
			}
			out = append(out, map[string]any{
				"market_group_id": g.MarketGroupID,
				"name":            g.Name,
				"description":     nullableString(g.Description),
				"has_types":       g.HasTypes,
				"child_count":     n,
			})
		}
		return Success(c, http.StatusOK, out, nil)
	}
}

func handleGroupTree(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		ctx := c.Request().Context()
		groups, err := rs.AllGroups(ctx)
		if err != nil {
			return dbError(c, err)
		}
		keep, err := rs.ManufacturableGroupIDs(ctx)
		if err != nil {
			return dbError(c, err)
		}
		byParent := map[int][]store.MarketGroup{}
		for _, g := range groups {
			p := 0
			if g.ParentGroupID.Valid {
				p = int(g.ParentGroupID.Int64)
			}
			byParent[p] = append(byParent[p], g)
		}
		var build func(parent int) []any
		build = func(parent int) []any {
			var out []any
			for _, g := range byParent[parent] {
				if !keep[g.MarketGroupID] {
					continue
				}
				out = append(out, map[string]any{
					"market_group_id": g.MarketGroupID,
					"name":            g.Name,
					"has_types":       g.HasTypes,
					"children":        build(g.MarketGroupID),
				})
			}
			if out == nil {
				out = []any{}
			}
			return out
		}
		tree := build(0)
		return Success(c, http.StatusOK, tree, nil)
	}
}

func handleGroupContents(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		id, err := strconv.Atoi(c.Param("marketGroupId"))
		if err != nil || id < 1 {
			return Failure(c, http.StatusNotFound, "Market group not found.", nil)
		}
		ctx := c.Request().Context()
		exclude := parseIntList(c, "exclude_meta_groups[]", "exclude_meta_groups")
		g, err := rs.MarketGroup(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if g == nil {
			return Failure(c, http.StatusNotFound, "Market group not found.", nil)
		}
		children, err := rs.ChildGroups(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		childOut := make([]any, 0, len(children))
		for _, ch := range children {
			n, err := rs.ChildCount(ctx, ch.MarketGroupID)
			if err != nil {
				return dbError(c, err)
			}
			childOut = append(childOut, map[string]any{
				"market_group_id": ch.MarketGroupID,
				"name":            ch.Name,
				"description":     nullableString(ch.Description),
				"has_types":       ch.HasTypes,
				"child_count":     n,
			})
		}
		var items []any
		if g.HasTypes {
			types, err := rs.TypesInGroup(ctx, id, exclude)
			if err != nil {
				return dbError(c, err)
			}
			items = make([]any, 0, len(types))
			for _, t := range types {
				items = append(items, map[string]any{
					"type_id":  t.TypeID,
					"name":     t.Name,
					"icon_url": market.TypeImageURL(t.TypeID),
				})
			}
		} else {
			items = []any{}
		}
		crumbs := []any{}
		for cur := g; cur != nil; {
			crumbs = append([]any{map[string]any{
				"market_group_id": cur.MarketGroupID,
				"name":            cur.Name,
			}}, crumbs...)
			if !cur.ParentGroupID.Valid {
				break
			}
			cur, err = rs.MarketGroup(ctx, int(cur.ParentGroupID.Int64))
			if err != nil {
				return dbError(c, err)
			}
		}
		return Success(c, http.StatusOK, map[string]any{
			"group": map[string]any{
				"market_group_id": g.MarketGroupID,
				"name":            g.Name,
				"description":     nullableString(g.Description),
				"parent_group_id": nullableInt64(g.ParentGroupID),
			},
			"breadcrumbs": crumbs,
			"children":    childOut,
			"items":       items,
		}, nil)
	}
}

func handleSystemSearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		verrs := validationErrors{}
		q, ok := parseSearchTerm(c, verrs)
		if !ok {
			return verrs.invalid(c)
		}
		systems, err := rs.SearchSystems(c.Request().Context(), q, market.SystemSearchLimit)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, systemList(systems), map[string]any{"limit": market.SystemSearchLimit})
	}
}

func handleSystemShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		id, err := strconv.Atoi(c.Param("systemId"))
		if err != nil || id < 1 {
			return Failure(c, http.StatusNotFound, "Solar system not found.", nil)
		}
		sys, err := rs.FindSystem(c.Request().Context(), id)
		if err != nil {
			return dbError(c, err)
		}
		if sys == nil {
			return Failure(c, http.StatusNotFound, "Solar system not found.", nil)
		}
		return Success(c, http.StatusOK, systemShape(sys), nil)
	}
}

func handleCostIndices(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		return Failure(c, http.StatusNotImplemented, "Cost indices are not ported to the Go API yet.", nil)
	}
}

func handleSystemStations(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		id, err := strconv.Atoi(c.Param("systemId"))
		if err != nil || id < 1 {
			return Failure(c, http.StatusNotFound, "Solar system not found.", nil)
		}
		ctx := c.Request().Context()
		if sys, err := rs.FindSystem(ctx, id); err != nil {
			return dbError(c, err)
		} else if sys == nil {
			return Failure(c, http.StatusNotFound, "Solar system not found.", nil)
		}
		stations, err := rs.StationsInSystem(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, stationList(stations), nil)
	}
}

func handleStationSearch(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		verrs := validationErrors{}
		q, qok := parseSearchTerm(c, verrs)
		var systemID *int
		if raw := c.QueryParam("solar_system_id"); raw != "" {
			id, err := strconv.Atoi(raw)
			if err != nil || id < 1 {
				verrs.add("solar_system_id", "The solar system id field must be an integer greater than 0.")
			} else {
				systemID = &id
			}
		}
		if !qok || len(verrs) > 0 {
			return verrs.invalid(c)
		}
		stations, err := rs.SearchStations(c.Request().Context(), q, systemID, market.StationSearchLimit)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, stationList(stations), map[string]any{"limit": market.StationSearchLimit})
	}
}

func handleStationShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rs, ok := requireDB(d)
		if !ok {
			return Failure(c, http.StatusServiceUnavailable, "Database not configured.", nil)
		}
		id, err := strconv.ParseUint(c.Param("stationId"), 10, 64)
		if err != nil || id == 0 {
			return Failure(c, http.StatusNotFound, "Station not found.", nil)
		}
		st, err := rs.FindStation(c.Request().Context(), id)
		if err != nil {
			return dbError(c, err)
		}
		if st == nil {
			return Failure(c, http.StatusNotFound, "Station not found.", nil)
		}
		return Success(c, http.StatusOK, stationShape(st), nil)
	}
}

func systemShape(s *store.SolarSystem) map[string]any {
	return map[string]any{
		"solar_system_id":   s.SolarSystemID,
		"solar_system_name": s.SolarSystemName,
		"constellation_id":  nullableInt64(s.ConstellationID),
		"region_id":         nullableInt64(s.RegionID),
		"security":          nullableFloat64(s.Security),
		"security_class":    nullableString(s.SecurityClass),
	}
}

func systemList(systems []store.SolarSystem) []any {
	out := make([]any, 0, len(systems))
	for _, s := range systems {
		s := s
		out = append(out, systemShape(&s))
	}
	return out
}

func stationShape(s *store.Station) map[string]any {
	return map[string]any{
		"station_id":      s.StationID,
		"station_name":    s.StationName,
		"solar_system_id": nullableInt64(s.SolarSystemID),
		"region_id":       nullableInt64(s.RegionID),
		"security":        nullableFloat64(s.Security),
	}
}

func stationList(stations []store.Station) []any {
	out := make([]any, 0, len(stations))
	for _, s := range stations {
		s := s
		out = append(out, stationShape(&s))
	}
	return out
}

func parseIntList(c echo.Context, keys ...string) []int {
	var out []int
	seen := map[int]bool{}
	for _, k := range keys {
		for _, raw := range c.QueryParams()[k] {
			id, err := strconv.Atoi(raw)
			if err != nil || seen[id] {
				continue
			}
			seen[id] = true
			out = append(out, id)
		}
	}
	return out
}

func dbError(c echo.Context, err error) error {
	return Failure(c, http.StatusInternalServerError, "Database query failed: "+err.Error(), nil)
}
