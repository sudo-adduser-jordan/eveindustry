package http

import (
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// registerStructures mounts the generic station-config CRUD. Other users'
// structures 404.
func registerStructures(v1 *echo.Group, d Deps) {
	if d.Planning == nil {
		return
	}
	v1.POST("/structures", handleStructureStore(d))
	v1.PUT("/structures/:id", handleStructureUpdate(d))
	v1.DELETE("/structures/:id", handleStructureDestroy(d))
	v1.POST("/structures/:id/default", handleStructureDefault(d))
}

var validActivities = map[string]bool{"manufacturing": true, "invention": true, "reaction": true}
var validStructureTypes = map[string]bool{
	"none": true, "raitaru": true, "azbel": true, "sotiyo": true, "athanor": true, "tatara": true,
}
var validRigs = map[string]bool{"none": true, "t1": true, "t2": true}
var validSecurity = map[string]bool{"highsec": true, "lowsec": true, "nullsec": true}

func structureShape(r store.StationConfig) map[string]any {
	m := map[string]any{
		"id": r.ID, "activity_type": r.ActivityType, "name": r.Name,
		"structure_type": r.StructureType, "rig_me": r.RigME, "rig_te": r.RigTE,
		"system_security": r.SystemSecurity, "is_default": r.IsDefault,
		"created_at": r.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
		"updated_at": r.UpdatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
	if r.SolarSystemID != nil {
		m["solar_system_id"] = *r.SolarSystemID
	} else {
		m["solar_system_id"] = nil
	}
	if r.TaxRate != nil {
		m["tax_rate"] = *r.TaxRate
	} else {
		m["tax_rate"] = nil
	}
	return m
}

// POST /structures — 201. With is_default it becomes the default for its
// activity type.
func handleStructureStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		in, ok := decodeStationInput(raw, verrs, true)
		if !ok {
			return verrs.invalid(c)
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.Planning.CreateStationConfig(c.Request().Context(), uid, *strOf2(raw["activity_type"]), in)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, structureShape(*row), nil)
	}
}

// PUT /structures/:id — partial update, only sent fields change.
func handleStructureUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		in, ok := decodeStationInput(raw, verrs, false)
		if !ok {
			return verrs.invalid(c)
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.Planning.UpdateStationConfig(c.Request().Context(), id, uid, in)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return Success(c, http.StatusOK, structureShape(*row), nil)
	}
}

// DELETE /structures/:id — 204, 404 foreign/missing.
func handleStructureDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		deleted, err := d.Planning.DeleteStationConfig(c.Request().Context(), id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if !deleted {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

// POST /structures/:id/default — default for its activity type.
func handleStructureDefault(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := planID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		row, err := d.Planning.SetDefaultStationConfig(c.Request().Context(), id, uid)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return Success(c, http.StatusOK, structureShape(*row), nil)
	}
}

// decodeStationInput validates store (required=true) or update payloads.
func decodeStationInput(raw map[string]any, verrs validationErrors, required bool) (store.StationConfigInput, bool) {
	var in store.StationConfigInput
	if v, present := raw["activity_type"]; required {
		s, isStr := v.(string)
		if !present || v == nil || !isStr || !validActivities[s] {
			verrs.add("activity_type", "The activity type field must be one of manufacturing, invention, reaction.")
			return in, false
		}
	}
	if v, present := raw["name"]; present || required {
		if s, isStr := v.(string); !isStr || s == "" || len([]rune(s)) > 100 {
			verrs.add("name", "The name field is required and at most 100 characters.")
			if required {
				return in, false
			}
		} else {
			in.Name = &s
		}
	}
	oneOf := func(field string, allowed map[string]bool) *string {
		v, present := raw[field]
		if !present || v == nil {
			if required {
				verrs.add(field, "The "+field+" field is required.")
			}
			return nil
		}
		s, isStr := v.(string)
		if !isStr || !allowed[s] {
			verrs.add(field, "The "+field+" field is invalid.")
			return nil
		}
		return &s
	}
	if s := oneOf("structure_type", validStructureTypes); s != nil {
		in.StructureType = s
	} else if v, present := raw["structure_type"]; required && (present && v == nil) {
		return in, false
	}
	if s := oneOf("rig_me", validRigs); s != nil {
		in.RigME = s
	}
	if s := oneOf("rig_te", validRigs); s != nil {
		in.RigTE = s
	}
	if s := oneOf("system_security", validSecurity); s != nil {
		in.SystemSecurity = s
	}
	if v, present := raw["solar_system_id"]; present && v != nil {
		if n, isNum := toFloat(v); !isNum || n != float64(int(n)) {
			verrs.add("solar_system_id", "The solar system id field must be an integer.")
			return in, false
		} else {
			nv := int(n)
			in.SolarSystemID = &nv
		}
	}
	if v, present := raw["tax_rate"]; present && v != nil {
		if n, isNum := toFloat(v); !isNum || n < 0 || n > 50 {
			verrs.add("tax_rate", "The tax rate field must be between 0 and 50.")
			return in, false
		} else {
			in.TaxRate = &n
		}
	}
	if v, present := raw["is_default"]; present && v != nil {
		if b, isBool := v.(bool); !isBool {
			verrs.add("is_default", "The is default field must be true or false.")
			return in, false
		} else {
			in.IsDefault = &b
		}
	}
	if required && len(verrs) > 0 {
		return in, false
	}
	return in, true
}

func strOf2(v any) *string {
	if s, ok := v.(string); ok {
		return &s
	}
	e := ""
	return &e
}
