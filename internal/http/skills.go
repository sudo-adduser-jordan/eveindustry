package http

import (
	"context"
	"errors"
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/calc"
	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
)

// SkillReader is the subset of the ESI character client the skill endpoints
// need. *esi.CharacterClient satisfies it; tests stub it.
type SkillReader interface {
	GetSkills(ctx context.Context, characterID int64) (map[int]int, error)
}

// SkillsDeps carries the ESI character client for the skill endpoints.
// The client is nil until live credentials are wired; without it the
// endpoints 503 like any other unconfigured dependency.
type SkillsDeps struct {
	Characters SkillReader
}

// registerSkills mounts the three calculator skill endpoints. Ownership is
// checked locally (404 for another user's character); levels come from ESI.
func registerSkills(v1 *echo.Group, d Deps) {
	if d.Skills.Characters == nil || d.Users == nil {
		return
	}
	v1.GET("/calculators/manufacturing/skills", handleManufacturingSkills(d))
	v1.GET("/calculators/reactions/skills", handleReactionSkills(d))
	v1.GET("/calculators/invention/skills", handleInventionSkills(d))
}

// errHandled marks that the response was already written (the envelope
// helpers return nil on success, so callers need a non-nil error to stop).
var errHandled = errors.New("response already written")

// ownCharacter mirrors CharacterSkillsService::findForUser(): the character
// must belong to the caller, else 404.
func ownCharacter(c echo.Context, d Deps, uid int64) (auth.Character, error) {
	raw := c.QueryParam("character_id")
	id, err := strconv.ParseInt(raw, 10, 64)
	if err != nil || id < 1 {
		verrs := validationErrors{}
		verrs.add("character_id", "The character id field must be an integer greater than 0.")
		_ = verrs.invalid(c)
		return auth.Character{}, errHandled
	}
	chars, err := d.Users.CharactersForUser(c.Request().Context(), uid)
	if err != nil {
		return auth.Character{}, dbError(c, err)
	}
	for _, ch := range chars {
		if ch.CharacterID == id {
			return ch, nil
		}
	}
	_ = Failure(c, http.StatusNotFound, "Character not found.", nil)
	return auth.Character{}, errHandled
}

// skillMap loads trained levels. A dead credential yields zeros, mirroring
// the PHP `?? 0` defaults; other ESI failures are 500s.
func skillMap(c echo.Context, d Deps, characterID int64) (map[int]int, error) {
	levels, err := d.Skills.Characters.GetSkills(c.Request().Context(), characterID)
	if err != nil {
		if re, ok := err.(*esi.RequestError); ok && re.Outcome == esi.OutcomeCredential {
			return map[int]int{}, nil
		}
		return nil, Failure(c, http.StatusInternalServerError, "ESI request failed: "+err.Error(), nil)
	}
	if levels == nil {
		return map[int]int{}, nil
	}
	return levels, nil
}

// GET /calculators/manufacturing/skills?character_id= — mirrors
// ManufacturingController@skills.
func handleManufacturingSkills(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		ch, err := ownCharacter(c, d, c.Get(string(ctxUIDKey)).(int64))
		if err != nil {
			return err
		}
		levels, err := skillMap(c, d, ch.CharacterID)
		if err != nil {
			return err
		}
		return Success(c, http.StatusOK, map[string]any{
			"character_id": ch.CharacterID, "character_name": ch.Name,
			"skills": map[string]any{
				"industry":          levels[calc.SkillIndustry],
				"advanced_industry": levels[calc.SkillAdvancedIndustry],
			},
		}, nil)
	}
}

// GET /calculators/reactions/skills?character_id= — mirrors
// ReactionsController@skills.
func handleReactionSkills(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		ch, err := ownCharacter(c, d, c.Get(string(ctxUIDKey)).(int64))
		if err != nil {
			return err
		}
		levels, err := skillMap(c, d, ch.CharacterID)
		if err != nil {
			return err
		}
		return Success(c, http.StatusOK, map[string]any{
			"character_id": ch.CharacterID, "character_name": ch.Name,
			"skills": map[string]any{"reactions": levels[calc.SkillReactions]},
		}, nil)
	}
}

// GET /calculators/invention/skills?character_id= — mirrors
// InventionController@skills: encryption + datacore science skills, the full
// level map, and Industry / Advanced Industry.
func handleInventionSkills(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		ch, err := ownCharacter(c, d, c.Get(string(ctxUIDKey)).(int64))
		if err != nil {
			return err
		}
		levels, err := skillMap(c, d, ch.CharacterID)
		if err != nil {
			return err
		}
		enc := make([]any, 0, len(industry.EncryptionSkills))
		for id, name := range industry.EncryptionSkills {
			enc = append(enc, map[string]any{"skill_id": id, "name": name, "level": levels[id]})
		}
		sci := make([]any, 0, len(industry.DatacoreSkills))
		for id, name := range industry.DatacoreSkills {
			sci = append(sci, map[string]any{"skill_id": id, "name": name, "level": levels[id]})
		}
		lv := map[string]any{}
		for id, level := range levels {
			lv[strconv.Itoa(id)] = level
		}
		return Success(c, http.StatusOK, map[string]any{
			"character_id": ch.CharacterID, "character_name": ch.Name,
			"skills": map[string]any{
				"industry": levels[calc.SkillIndustry], "advanced_industry": levels[calc.SkillAdvancedIndustry],
				"encryption": enc, "science": sci, "levels": lv,
			},
		}, nil)
	}
}
