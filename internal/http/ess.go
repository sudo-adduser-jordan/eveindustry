package http

import (
	"context"
	"database/sql"
	"net/http"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// recentPayouts mirrors EssTrackerService::RECENT_PAYOUTS.
const recentPayouts = 5

// registerEss mounts the corporation ESS tracker (systems, payouts).
func registerEss(v1 *echo.Group, d Deps) {
	if d.Ess == nil || d.Users == nil || d.Reference == nil {
		return
	}
	v1.GET("/corporation/ess", handleEssIndex(d))
	v1.POST("/corporation/ess", handleEssStore(d))
	v1.PUT("/corporation/ess/:id", handleEssUpdate(d))
	v1.DELETE("/corporation/ess/:id", handleEssDestroy(d))
	v1.POST("/corporation/ess/:id/payouts", handleEssPayout(d))
}

// essCorpOf mirrors EssTrackerService::corporationIdForUser: main's corp,
// else the first character's corp. Nil without a corporation (callers 403
// like the policy).
// GET /corporation/ess — tracked systems by name with next payout + recent.
func handleEssIndex(d Deps) echo.HandlerFunc {
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
		rows, total, err := d.Ess.EssSystems(ctx, *corpID, perPage, (page-1)*perPage)
		if err != nil {
			return dbError(c, err)
		}
		items := make([]any, 0, len(rows))
		now := time.Now().UTC()
		for _, r := range rows {
			payouts, err := d.Ess.EssPayouts(ctx, r.ID, recentPayouts)
			if err != nil {
				return dbError(c, err)
			}
			items = append(items, essShape(r, payouts, now))
		}
		return Success(c, http.StatusOK, items, map[string]any{
			"pagination": map[string]any{
				"page": page, "per_page": perPage, "total": total,
				"last_page": (total + perPage - 1) / perPage,
			},
		})
	}
}

// essShape renders a tracked system: next payout math + recent payouts.
func essShape(r store.EssSystem, payouts []store.EssPayout, now time.Time) map[string]any {
	next := essNextPayout(r.CycleAnchorAt, r.CycleMinutes, now)
	secs := int(next.Sub(now).Seconds())
	if secs < 0 {
		secs = 0
	}
	recent := make([]any, 0, len(payouts))
	for _, p := range payouts {
		var amt any
		if p.AmountISK.Valid {
			amt = p.AmountISK.Float64
		}
		recent = append(recent, map[string]any{
			"id": p.ID, "occurred_at": p.OccurredAt.UTC().Format(time.RFC3339),
			"amount_isk": amt,
		})
	}
	var notes any
	if r.Notes.Valid {
		notes = r.Notes.String
	}
	return map[string]any{
		"id": r.ID, "solar_system_id": r.SolarSystemID, "solar_system_name": r.SolarSystemName,
		"cycle_minutes": r.CycleMinutes, "cycle_anchor_at": r.CycleAnchorAt.UTC().Format(time.RFC3339),
		"next_payout_at": next.Format(time.RFC3339), "seconds_until_next_payout": secs,
		"notes": notes, "recent_payouts": recent,
	}
}

// essNextPayout ports EssTrackedSystem::nextPayoutAt.
func essNextPayout(anchor time.Time, cycleMinutes int, now time.Time) time.Time {
	cycle := cycleMinutes
	if cycle < 1 {
		cycle = 1
	}
	anchor = anchor.UTC()
	if !now.After(anchor) {
		return anchor
	}
	diff := int64(now.Sub(anchor).Seconds())
	cycleSecs := int64(cycle * 60)
	elapsed := diff/cycleSecs + 1
	return anchor.Add(time.Duration(elapsed*cycleSecs) * time.Second)
}

// POST /corporation/ess — track a system (201), or change its cycle.
func handleEssStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
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
		in, ok := decodeEssInput(c, nil)
		if !ok {
			return errHandled
		}
		name := essSystemName(ctx, d, in.systemID, in.systemName)
		row, err := d.Ess.UpsertEssSystem(ctx, store.EssSystem{
			CorporationID: *corpID, SolarSystemID: in.systemID, SolarSystemName: name,
			CycleMinutes: in.cycleMinutes, CycleAnchorAt: in.anchor,
			Notes: sqlNullStrOpt(in.notes), CreatedByUserID: sqlNullInt64Opt(uid),
		})
		if err != nil {
			return dbError(c, err)
		}
		payouts, err := d.Ess.EssPayouts(ctx, row.ID, recentPayouts)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, essShape(*row, payouts, time.Now().UTC()), nil)
	}
}

// PUT /corporation/ess/:id — cycle edit; foreign systems 404.
func handleEssUpdate(d Deps) echo.HandlerFunc {
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
		row, err := d.Ess.EssSystem(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil || row.CorporationID != *corpID {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		in, ok := decodeEssCycleInput(c, &row.CycleAnchorAt)
		if !ok {
			return errHandled
		}
		updated, err := d.Ess.UpdateEssSystem(ctx, store.EssSystem{
			ID: row.ID, CycleMinutes: in.cycleMinutes, CycleAnchorAt: in.anchor,
			Notes: sqlNullStrOpt(in.notes),
		})
		if err != nil {
			return dbError(c, err)
		}
		payouts, err := d.Ess.EssPayouts(ctx, updated.ID, recentPayouts)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, essShape(*updated, payouts, time.Now().UTC()), nil)
	}
}

// DELETE /corporation/ess/:id — 204; foreign systems 404.
func handleEssDestroy(d Deps) echo.HandlerFunc {
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
		row, err := d.Ess.EssSystem(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil || row.CorporationID != *corpID {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if err := d.Ess.DeleteEssSystem(ctx, id); err != nil {
			return dbError(c, err)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

// POST /corporation/ess/:id/payouts — record a payout, 201.
func handleEssPayout(d Deps) echo.HandlerFunc {
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
		row, err := d.Ess.EssSystem(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil || row.CorporationID != *corpID {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil {
			raw = map[string]any{}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		occurred := time.Now().UTC()
		if v, present := raw["occurred_at"]; present && v != nil {
			s, isStr := v.(string)
			if !isStr {
				verrs.add("occurred_at", "The occurred at field must be a valid date.")
				return verrs.invalid(c)
			}
			t, terr := parseEssTime(s)
			if terr != nil {
				verrs.add("occurred_at", "The occurred at field must be a valid date.")
				return verrs.invalid(c)
			}
			occurred = t
		}
		var amount *float64
		if v, present := raw["amount_isk"]; present && v != nil {
			n, isNum := toFloat(v)
			if !isNum || n < 0 {
				verrs.add("amount_isk", "The amount isk field must be at least 0.")
				return verrs.invalid(c)
			}
			amount = &n
		}
		var notes *string
		if v, present := raw["notes"]; present && v != nil {
			s, isStr := v.(string)
			if !isStr || len([]rune(s)) > 500 {
				verrs.add("notes", "The notes field is invalid.")
				return verrs.invalid(c)
			}
			notes = &s
		}
		if _, err := d.Ess.CreateEssPayout(ctx, store.EssPayout{
			SystemID: id, OccurredAt: occurred,
			AmountISK: sqlNullFloatOpt(amount), RecordedByUserID: sqlNullInt64Opt(uid),
			Notes: sqlNullStrOpt(notes),
		}); err != nil {
			return dbError(c, err)
		}
		payouts, err := d.Ess.EssPayouts(ctx, id, recentPayouts)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, essShape(*row, payouts, time.Now().UTC()), nil)
	}
}

func essID(c echo.Context) (int64, bool) {
	id, err := parseIntProg(c.Param("id"))
	if err != nil || id < 1 {
		_ = Failure(c, http.StatusNotFound, "Not found.", nil)
		return 0, false
	}
	return int64(id), true
}

func parseEssTime(s string) (time.Time, error) {
	for _, layout := range []string{time.RFC3339, "2006-01-02T15:04:05Z", "2006-01-02 15:04:05", "2006-01-02"} {
		if t, err := time.Parse(layout, s); err == nil {
			return t.UTC(), nil
		}
	}
	return time.Time{}, errBadInt
}

func sqlNullStrOpt(s *string) sql.NullString {
	if s == nil {
		return sql.NullString{}
	}
	return sql.NullString{String: *s, Valid: true}
}

func sqlNullInt64Opt(v int64) sql.NullInt64 {
	return sql.NullInt64{Int64: v, Valid: true}
}

func sqlNullFloatOpt(v *float64) sql.NullFloat64 {
	if v == nil {
		return sql.NullFloat64{}
	}
	return sql.NullFloat64{Float64: *v, Valid: true}
}

type essInput struct {
	systemID     int
	systemName   *string
	cycleMinutes int
	anchor       time.Time
	notes        *string
}

// decodeEssInput validates store (system required) or cycle payloads.
func decodeEssInput(c echo.Context, defAnchor *time.Time) (essInput, bool) {
	var in essInput
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
		return in, false
	} else if n, isNum := toFloat(v); !isNum || n != float64(int(n)) {
		verrs.add("solar_system_id", "The solar system id field must be an integer.")
		return in, false
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
	cycle, anchor, notes, ok := decodeEssCycle(raw, verrs, defAnchor)
	if !ok {
		verrs.invalid(c)
		return in, false
	}
	in.cycleMinutes, in.anchor, in.notes = cycle, anchor, notes
	return in, true
}

func decodeEssCycleInput(c echo.Context, defAnchor *time.Time) (essInput, bool) {
	var in essInput
	verrs := validationErrors{}
	raw := decodePlanBody(c, verrs)
	if raw == nil && len(verrs) > 0 {
		return in, false
	}
	if raw == nil {
		raw = map[string]any{}
	}
	cycle, anchor, notes, ok := decodeEssCycle(raw, verrs, defAnchor)
	if !ok {
		verrs.invalid(c)
		return in, false
	}
	in.cycleMinutes, in.anchor, in.notes = cycle, anchor, notes
	return in, true
}

// decodeEssCycle ports resolveCycleMinutes + resolveCycleAnchor.
func decodeEssCycle(raw map[string]any, verrs validationErrors, defAnchor *time.Time) (int, time.Time, *string, bool) {
	var minutes *int
	if v, present := raw["cycle_minutes"]; !present || v == nil {
		verrs.add("cycle_minutes", "The cycle minutes field is required.")
		return 0, time.Time{}, nil, false
	} else if n, isNum := toFloat(v); !isNum || n != float64(int(n)) || int(n) < 0 || int(n) > 1440 {
		verrs.add("cycle_minutes", "The cycle minutes field must be between 0 and 1440.")
		return 0, time.Time{}, nil, false
	} else {
		m := int(n)
		minutes = &m
	}
	if v, present := raw["cycle_hours"]; present && v != nil {
		if n, isNum := toFloat(v); !isNum || n != float64(int(n)) || int(n) < 0 || int(n) > 24 {
			verrs.add("cycle_hours", "The cycle hours field must be between 0 and 24.")
			return 0, time.Time{}, nil, false
		} else {
			*minutes = int(n)*60 + *minutes
		}
	}
	optInt := func(key string, min, max int) *int {
		v, present := raw[key]
		if !present || v == nil {
			return nil
		}
		n, isNum := toFloat(v)
		if !isNum || n != float64(int(n)) || int(n) < min || int(n) > max {
			verrs.add(key, "The "+key+" field is invalid.")
			return nil
		}
		m := int(n)
		return &m
	}
	nextH := optInt("next_payout_in_hours", 0, 24)
	nextM := optInt("next_payout_in_minutes", 0, 1440)
	if len(verrs) > 0 {
		return 0, time.Time{}, nil, false
	}
	now := time.Now().UTC()
	var anchor time.Time
	switch {
	case nextH != nil || nextM != nil:
		h, m := 0, 0
		if nextH != nil {
			h = *nextH
		}
		if nextM != nil {
			m = *nextM
		}
		anchor = now.Add(time.Duration(h*3600+m*60) * time.Second).Add(-time.Duration(*minutes*60) * time.Second)
	case raw["cycle_anchor_at"] != nil:
		s, isStr := raw["cycle_anchor_at"].(string)
		if !isStr {
			verrs.add("cycle_anchor_at", "The cycle anchor at field must be a valid date.")
			return 0, time.Time{}, nil, false
		}
		t, terr := parseEssTime(s)
		if terr != nil {
			verrs.add("cycle_anchor_at", "The cycle anchor at field must be a valid date.")
			return 0, time.Time{}, nil, false
		}
		anchor = t
	case defAnchor != nil:
		anchor = *defAnchor
	default:
		anchor = now
	}
	if *minutes < 1 || *minutes > 1440 {
		verrs.add("cycle_minutes", "Cycle length must be between 1 and 1440 minutes.")
		return 0, time.Time{}, nil, false
	}
	var notes *string
	if v, present := raw["notes"]; present && v != nil {
		s, isStr := v.(string)
		if !isStr || len([]rune(s)) > 500 {
			verrs.add("notes", "The notes field is invalid.")
			return 0, time.Time{}, nil, false
		}
		notes = &s
	}
	return *minutes, anchor, notes, true
}

func essSystemName(ctx context.Context, d Deps, id int, provided *string) string {
	if provided != nil && *provided != "" {
		return *provided
	}
	if d.Reference != nil {
		if s, err := d.Reference.FindSystem(ctx, id); err == nil && s != nil {
			return s.SolarSystemName
		}
	}
	return "System #" + itoaProg(id)
}

// essCorpOf mirrors EssTrackerService::corporationIdForUser.
func essCorpOf(chars []auth.Character) *int64 {
	for _, ch := range chars {
		if ch.IsMain && ch.CorporationID != nil && *ch.CorporationID != 0 {
			return ch.CorporationID
		}
	}
	for _, ch := range chars {
		if ch.CorporationID != nil && *ch.CorporationID != 0 {
			return ch.CorporationID
		}
	}
	return nil
}
