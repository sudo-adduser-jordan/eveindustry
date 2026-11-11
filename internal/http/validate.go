package http

import (
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

const (
	defaultPerPage = 50
	maxPerPage     = 200
)

type validationErrors map[string][]string

func (v validationErrors) add(field, msg string) {
	v[field] = append(v[field], msg)
}

func (v validationErrors) invalid(c echo.Context) error {
	return c.JSON(http.StatusUnprocessableEntity, map[string]any{
		"message": "The given data was invalid.",
		"code":    "validation_failed",
		"errors":  map[string][]string(v),
	})
}

func parseRegionID(c echo.Context, verrs validationErrors) int {
	raw := c.QueryParam("region_id")
	if raw == "" {
		return market.DefaultRegion
	}
	id, err := strconv.Atoi(raw)
	if err != nil || !market.IsTradeRegion(id) {
		verrs.add("region_id", "The selected region id is invalid.")
		return market.DefaultRegion
	}
	return id
}

func parseSearchTerm(c echo.Context, verrs validationErrors) (string, bool) {
	q := c.QueryParam("q")
	if q == "" {
		verrs.add("q", "The q field is required.")
		return "", false
	}
	if len([]rune(q)) < 2 {
		verrs.add("q", "The q field must be at least 2 characters.")
		return "", false
	}
	if len([]rune(q)) > 100 {
		verrs.add("q", "The q field must not be greater than 100 characters.")
		return "", false
	}
	return q, true
}

func parsePagination(c echo.Context, verrs validationErrors) (page, perPage int) {
	page, perPage = 1, defaultPerPage
	if raw := c.QueryParam("page"); raw != "" {
		p, err := strconv.Atoi(raw)
		if err != nil || p < 1 {
			verrs.add("page", "The page field must be at least 1.")
		} else {
			page = p
		}
	}
	if raw := c.QueryParam("per_page"); raw != "" {
		pp, err := strconv.Atoi(raw)
		if err != nil || pp < 1 || pp > maxPerPage {
			verrs.add("per_page", "The per page field must be between 1 and 200.")
		} else {
			perPage = pp
		}
	}
	return page, perPage
}

func parseTypeIDs(c echo.Context, verrs validationErrors) []int {
	raws, ok := c.QueryParams()["type_ids[]"]
	if !ok {
		raws, ok = c.QueryParams()["type_ids"]
	}
	if !ok || len(raws) == 0 {
		verrs.add("type_ids", "The type ids field is required.")
		return nil
	}
	if len(raws) > market.MaxTypeIDs {
		verrs.add("type_ids", "The type ids field must not have more than 200 items.")
		return nil
	}
	seen := map[int]bool{}
	var out []int
	for _, raw := range raws {
		id, err := strconv.Atoi(raw)
		if err != nil || id < 1 {
			verrs.add("type_ids", "Each type id must be an integer greater than 0.")
			continue
		}
		if !seen[id] {
			seen[id] = true
			out = append(out, id)
		}
	}
	return out
}

func paginate(total int, page, perPage int) (offset int, meta map[string]any) {
	lastPage := (total + perPage - 1) / perPage
	if lastPage < 1 {
		lastPage = 1
	}
	if page > lastPage {
		page = lastPage
	}
	return (page - 1) * perPage, map[string]any{
		"page": page, "per_page": perPage, "total": total, "last_page": lastPage,
	}
}
