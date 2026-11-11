package http

import (
	"database/sql"
	"net/http"
	"strings"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

var validWindowTypes = map[string]bool{"upwell": true, "skyhook": true}

func registerWindows(v1 *echo.Group, d Deps) {
	if d.Windows == nil || d.Users == nil || d.Reference == nil {
		return
	}
	v1.GET("/corporation/structure-vulnerability", handleWindowsIndex(d))
	v1.POST("/corporation/structure-vulnerability", handleWindowStore(d))
	v1.PUT("/corporation/structure-vulnerability/:id", handleWindowUpdate(d))
	v1.DELETE("/corporation/structure-vulnerability/:id", handleWindowDestroy(d))
}

func windowShape(w store.StructureWindow) map[string]any {
	var day any
	if w.DayOfWeek.Valid {
		day = int(w.DayOfWeek.Int64)
	}
	start := w.StartTimeUTC
	if len(start) > 5 {
		start = start[:5]
	}
	var reinforce any
	if w.ReinforceExitAt.Valid {
		reinforce = w.ReinforceExitAt.Time.UTC().Format(time.RFC3339)
	}
	var notes any
	if w.Notes.Valid {
		notes = w.Notes.String
	}
	return map[string]any{
		"id": w.ID, "solar_system_id": w.SolarSystemID, "solar_system_name": w.SolarSystemName,
		"structure_type": w.StructureType, "structure_name": w.StructureName,
		"day_of_week": day, "start_time_utc": start, "duration_minutes": w.DurationMinutes,
		"reinforce_exit_at": reinforce, "notes": notes,
	}
}

func handleWindowsIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		page, perPage, ok := listPage(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		corpID := essCorpOf(chars)
		if corpID == nil {
			return Failure(c, http.StatusForbidden, "No corporation associated with your account.", nil)
		}
		rows, total, err := d.Windows.StructureWindows(ctx, *corpID, perPage, (page-1)*perPage)
		if err != nil {
			return dbError(c, err)
		}
		items := make([]any, 0, len(rows))
		for _, w := range rows {
			items = append(items, windowShape(w))
		}
		return Success(c, http.StatusOK, items, map[string]any{
			"pagination": map[string]any{
				"page": page, "per_page": perPage, "total": total,
				"last_page": (total + perPage - 1) / perPage,
			},
		})
	}
}

type windowInput struct {
	systemID   int
	systemName *string
	stype      string
	name       string
	day        *int
	start      string
	duration   int
	reinforce  *time.Time
	notes      *string
}

func decodeWindow(c echo.Context) (windowInput, bool) {
	var in windowInput
	verrs := validationErrors{}
	raw := decodePlanBody(c, verrs)
	if raw == nil && len(verrs) > 0 {
		return in, false
	}
	if raw == nil {
		raw = map[string]any{}
	}
	if v, present := raw["solar_system_id"]; !present || v == nil {
		verrs.add("solar_system_id", "The solar system id field is required.")
	} else if n, isNum := toFloat(v); !isNum || n != float64(int(n)) {
		verrs.add("solar_system_id", "The solar system id field must be an integer.")
	} else {
		in.systemID = int(n)
	}
	if v, present := raw["solar_system_name"]; present && v != nil {
		if s, isStr := v.(string); !isStr || len([]rune(s)) > 100 {
			verrs.add("solar_system_name", "The solar system name is invalid.")
			return in, false
		} else {
			in.systemName = &s
		}
	}
	if v, present := raw["structure_type"]; !present || v == nil {
		verrs.add("structure_type", "The structure type field is required.")
	} else if s, isStr := v.(string); !isStr || !validWindowTypes[s] {
		verrs.add("structure_type", "The structure type field must be one of upwell, skyhook.")
	} else {
		in.stype = s
	}
	if v, present := raw["structure_name"]; !present || v == nil {
		verrs.add("structure_name", "The structure name field is required.")
	} else if s, isStr := v.(string); !isStr || s == "" || len([]rune(s)) > 150 {
		verrs.add("structure_name", "The structure name is invalid.")
	} else {
		in.name = strings.TrimSpace(s)
		if in.name == "" {
			verrs.add("structure_name", "The structure name is invalid.")
		}
	}
	if v, present := raw["day_of_week"]; present && v != nil {
		if n, isNum := toFloat(v); !isNum || n != float64(int(n)) || int(n) < 0 || int(n) > 6 {
			verrs.add("day_of_week", "The day of week field must be between 0 and 6.")
		} else {
			in.day = newInt(int(n))
		}
	}
	if v, present := raw["start_time_utc"]; !present || v == nil {
		verrs.add("start_time_utc", "The start time utc field is required.")
	} else if s, isStr := v.(string); !isStr {
		verrs.add("start_time_utc", "The start time utc field must be HH:MM.")
	} else {
		t, terr := time.Parse("15:04", strings.TrimSpace(s))
		if terr != nil {
			verrs.add("start_time_utc", "The start time utc field must be HH:MM.")
		} else {
			in.start = t.Format("15:04")
		}
	}
	if v, present := raw["duration_minutes"]; !present || v == nil {
		verrs.add("duration_minutes", "The duration minutes field is required.")
	} else if n, isNum := toFloat(v); !isNum || n != float64(int(n)) || int(n) < 5 || int(n) > 1440 {
		verrs.add("duration_minutes", "The duration minutes field must be between 5 and 1440.")
	} else {
		in.duration = int(n)
	}
	if v, present := raw["reinforce_exit_at"]; present && v != nil {
		s, isStr := v.(string)
		if !isStr {
			verrs.add("reinforce_exit_at", "The reinforce exit at field must be a valid date.")
		} else if t, terr := parseEssTime(s); terr != nil {
			verrs.add("reinforce_exit_at", "The reinforce exit at field must be a valid date.")
		} else {
			in.reinforce = &t
		}
	}
	if v, present := raw["notes"]; present && v != nil {
		if s, isStr := v.(string); !isStr || len([]rune(s)) > 500 {
			verrs.add("notes", "The notes field is invalid.")
			return in, false
		} else {
			in.notes = &s
		}
	}
	if len(verrs) > 0 {
		verrs.invalid(c)
		return in, false
	}
	return in, true
}

func handleWindowStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		in, ok := decodeWindow(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		corpID := essCorpOf(chars)
		if corpID == nil {
			return Failure(c, http.StatusForbidden, "No corporation associated with your account.", nil)
		}
		name := essSystemName(ctx, d, in.systemID, in.systemName)
		var day sql.NullInt64
		if in.day != nil {
			day = sql.NullInt64{Int64: int64(*in.day), Valid: true}
		}
		var reinforce sql.NullTime
		if in.reinforce != nil {
			reinforce = sql.NullTime{Time: *in.reinforce, Valid: true}
		}
		row, err := d.Windows.CreateStructureWindow(ctx, store.StructureWindow{
			CorporationID: *corpID, SolarSystemID: in.systemID, SolarSystemName: name,
			StructureType: in.stype, StructureName: in.name, DayOfWeek: day,
			StartTimeUTC: in.start, DurationMinutes: in.duration,
			ReinforceExitAt: reinforce, Notes: sqlNullStrOpt(in.notes),
			CreatedByUserID: sqlNullInt64Opt(uid),
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, windowShape(*row), nil)
	}
}

func handleWindowUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		in, ok := decodeWindow(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		corpID := essCorpOf(chars)
		if corpID == nil {
			return Failure(c, http.StatusForbidden, "No corporation associated with your account.", nil)
		}
		row, err := d.Windows.StructureWindow(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil || row.CorporationID != *corpID {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		name := essSystemName(ctx, d, in.systemID, in.systemName)
		var day sql.NullInt64
		if in.day != nil {
			day = sql.NullInt64{Int64: int64(*in.day), Valid: true}
		}
		var reinforce sql.NullTime
		if in.reinforce != nil {
			reinforce = sql.NullTime{Time: *in.reinforce, Valid: true}
		}
		updated, err := d.Windows.UpdateStructureWindow(ctx, store.StructureWindow{
			ID: id, CorporationID: row.CorporationID, SolarSystemID: in.systemID,
			SolarSystemName: name, StructureType: in.stype, StructureName: in.name,
			DayOfWeek: day, StartTimeUTC: in.start, DurationMinutes: in.duration,
			ReinforceExitAt: reinforce, Notes: sqlNullStrOpt(in.notes),
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, windowShape(*updated), nil)
	}
}

func handleWindowDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		corpID := essCorpOf(chars)
		if corpID == nil {
			return Failure(c, http.StatusForbidden, "No corporation associated with your account.", nil)
		}
		row, err := d.Windows.StructureWindow(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil || row.CorporationID != *corpID {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if err := d.Windows.DeleteStructureWindow(ctx, id); err != nil {
			return dbError(c, err)
		}
		return c.NoContent(http.StatusNoContent)
	}
}
