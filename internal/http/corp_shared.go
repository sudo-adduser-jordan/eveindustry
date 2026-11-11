package http

import (
	"context"
	"database/sql"
	"net/http"
	"strconv"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

func strField(raw map[string]any, verrs validationErrors, field string, maxLen int, required, allowEmpty bool) *string {
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
	if s == "" {
		if required && !allowEmpty {
			verrs.add(field, "The "+field+" field is required.")
			return nil
		}
		if allowEmpty {
			return &s
		}
		return nil
	}
	if maxLen > 0 && len([]rune(s)) > maxLen {
		verrs.add(field, "The "+field+" field must not be greater than "+strconv.Itoa(maxLen)+" characters.")
		return nil
	}
	return &s
}

func derefStr(s *string, fallback string) string {
	if s == nil {
		return fallback
	}
	return *s
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
