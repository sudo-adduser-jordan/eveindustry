package http

import (
	"encoding/json"
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

func registerPlanning(v1 *echo.Group, d Deps) {
	if d.Planning == nil {
		return
	}
	v1.GET("/station-configs", handleStationConfigsIndex(d))
	v1.POST("/station-configs", handleStationConfigStore(d))
	v1.PUT("/station-configs/:id", handleStationConfigUpdate(d))
	v1.DELETE("/station-configs/:id", handleStationConfigDestroy(d))
	v1.POST("/station-configs/:id/default", handleStationConfigDefault(d))
}

var stationStructures = map[string]bool{
	"none": true, "raitaru": true, "azbel": true, "sotiyo": true,
	"athanor": true, "tatara": true,
}
var stationRigs = map[string]bool{"none": true, "t1": true, "t2": true}
var stationSecurity = map[string]bool{"highsec": true, "lowsec": true, "nullsec": true}

func stationConfigShape(s store.StationConfig) map[string]any {
	return map[string]any{
		"id": s.ID, "name": s.Name, "structure_type": s.StructureType,
		"rig_me": s.RigME, "rig_te": s.RigTE, "system_security": s.SystemSecurity,
		"solar_system_id": s.SolarSystemID, "tax_rate": s.TaxRate, "is_default": s.IsDefault,
		"created_at": s.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": s.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

func stationConfigInput(c echo.Context, verrs validationErrors, required bool) store.StationConfigInput {
	var raw map[string]any
	if err := json.NewDecoder(c.Request().Body).Decode(&raw); err != nil {
		verrs.add("body", "The request body must be valid JSON.")
		return store.StationConfigInput{}
	}
	var in store.StationConfigInput
	getStr := func(field string, allowed map[string]bool) *string {
		v, present := raw[field]
		if !present || v == nil {
			if required {
				verrs.add(field, "The "+field+" field is required.")
			}
			return nil
		}
		s, ok := v.(string)
		if !ok {
			verrs.add(field, "The "+field+" field must be a string.")
			return nil
		}
		if field == "name" {
			if s == "" {
				verrs.add(field, "The "+field+" field is required.")
				return nil
			}
			if len([]rune(s)) > 100 {
				verrs.add(field, "The "+field+" field must not be greater than 100 characters.")
				return nil
			}
			return &s
		}
		if !allowed[s] {
			verrs.add(field, "The selected "+field+" is invalid.")
			return nil
		}
		return &s
	}
	if s := getStr("name", nil); s != nil {
		in.Name = s
	}
	if s := getStr("structure_type", stationStructures); s != nil {
		in.StructureType = s
	}
	if s := getStr("rig_me", stationRigs); s != nil {
		in.RigME = s
	}
	if s := getStr("rig_te", stationRigs); s != nil {
		in.RigTE = s
	}
	if s := getStr("system_security", stationSecurity); s != nil {
		in.SystemSecurity = s
	}
	if v, present := raw["is_default"]; present && v != nil {
		switch b := v.(type) {
		case bool:
			in.IsDefault = &b
		case float64:
			bb := b == 1
			if b == 0 || b == 1 {
				in.IsDefault = &bb
			} else {
				verrs.add("is_default", "The is default field must be true or false.")
			}
		default:
			verrs.add("is_default", "The is default field must be true or false.")
		}
	}
	return in
}

func handleStationConfigsIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		page, perPage := parsePagination(c, verrs)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		rows, total, err := d.Planning.StationConfigs(c.Request().Context(), uid, "manufacturing", perPage, (page-1)*perPage)
		if err != nil {
			return dbError(c, err)
		}
		_, pagination := paginate(total, page, perPage)
		out := make([]any, 0, len(rows))
		for _, r := range rows {
			out = append(out, stationConfigShape(r))
		}
		return Success(c, http.StatusOK, out, map[string]any{"pagination": pagination})
	}
}

func handleStationConfigStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		in := stationConfigInput(c, verrs, true)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.Planning.CreateStationConfig(c.Request().Context(), uid, "manufacturing", in)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, stationConfigShape(*row), nil)
	}
}

func stationConfigID(c echo.Context) (int64, bool) {
	id, err := strconv.ParseInt(c.Param("id"), 10, 64)
	if err != nil || id < 1 {
		_ = Failure(c, http.StatusNotFound, "Station config not found.", nil)
		return 0, false
	}
	return id, true
}

func handleStationConfigUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := stationConfigID(c)
		if !ok {
			return errHandled
		}
		verrs := validationErrors{}
		in := stationConfigInput(c, verrs, false)
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.Planning.UpdateStationConfig(c.Request().Context(), id, uid, in)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Station config not found.", nil)
		}
		return Success(c, http.StatusOK, stationConfigShape(*row), nil)
	}
}

func handleStationConfigDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := stationConfigID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		deleted, err := d.Planning.DeleteStationConfig(c.Request().Context(), id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if !deleted {
			return Failure(c, http.StatusNotFound, "Station config not found.", nil)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

func handleStationConfigDefault(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := stationConfigID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.Planning.SetDefaultStationConfig(c.Request().Context(), id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Station config not found.", nil)
		}
		return Success(c, http.StatusOK, stationConfigShape(*row), nil)
	}
}
