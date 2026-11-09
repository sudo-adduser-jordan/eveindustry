package http

import (
	"net/http"
	"strconv"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
)

// Pagination defaults mirror ApiRequest::DEFAULT_PER_PAGE / MAX_PER_PAGE.
const (
	defaultPerPage = 50
	maxPerPage     = 200
)

// validationErrors collects field errors; invalid() writes the 422 envelope
// mirroring Laravel's {"message": "The given data was invalid.", "errors"}.
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

// parseRegionID validates ?region_id= against the five trade hubs, defaulting
// to Jita. Mirrors MarketRequest::regionRules() + regionInput().
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

// parseSearchTerm validates ?q= per SearchRequest rules: required, 2 to 100
// characters. Mirrors the 422s the PHP search endpoints return.
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

// parsePagination mirrors ApiRequest::page()/perPage(): page >= 1,
// 1 <= per_page <= 200.
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

// parseTypeIDs validates ?type_ids[]= (1..200 unique ints >= 1), preserving
// request order. Mirrors PricesRequest::typeIds().
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

// paginate slices an in-memory list like ApiResponse::paginatedArray() and
// returns the page items plus the meta.pagination block.
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
