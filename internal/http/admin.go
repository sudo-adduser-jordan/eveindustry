package http

import (
	"net/http"
	"strings"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/market"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// requireAdmin mirrors AdminMiddleware: 403 JSON when the caller is not an
// admin (super admins count).
func requireAdminDeps(d Deps) echo.MiddlewareFunc {
	return func(next echo.HandlerFunc) echo.HandlerFunc {
		return func(c echo.Context) error {
			uid, ok := c.Get(string(ctxUIDKey)).(int64)
			if !ok || uid == 0 {
				return Failure(c, http.StatusForbidden, "Admin access required.", nil)
			}
			u, err := d.Users.UserByID(c.Request().Context(), uid)
			if err != nil {
				return dbError(c, err)
			}
			if u == nil || !(u.IsAdmin || u.IsSuperAdmin) {
				return Failure(c, http.StatusForbidden, "Admin access required.", nil)
			}
			return next(c)
		}
	}
}

// registerAdmin mounts user management, account and the admin jobs view.
func registerAdmin(v1 *echo.Group, d Deps) {
	if d.AdminUsers == nil || d.Users == nil {
		return
	}
	g := v1.Group("/admin", requireAdminDeps(d))
	g.GET("/users", handleAdminUserIndex(d))
	g.POST("/users/:id/toggle-status", handleAdminToggle(d, "status"))
	g.POST("/users/:id/toggle-admin", handleAdminToggle(d, "admin"))
	g.POST("/users/:id/toggle-stock-manager", handleAdminToggle(d, "stock"))
	g.DELETE("/users/:id", handleAdminUserDelete(d))
	g.GET("/users/:id/industry-jobs", handleAdminUserJobs(d))
	g.GET("/account", handleAdminAccountShow(d))
	g.PUT("/account", handleAdminAccountUpdate(d))
}

func adminUserShape(u store.AdminUser) map[string]any {
	display := u.Name
	if u.MainCharacterName.Valid && u.MainCharacterName.String != "" {
		display = u.MainCharacterName.String
	}
	var portrait any
	if u.MainCharacterID.Valid {
		portrait = auth.PortraitURL(u.MainCharacterID.Int64)
	}
	var email any
	if u.Email.Valid {
		email = u.Email.String
	}
	var lastLogin any
	if u.LastLoginAt.Valid {
		lastLogin = u.LastLoginAt.Time.UTC().Format("2006-01-02T15:04:05Z")
	}
	role := "user"
	if u.IsSuperAdmin {
		role = "super_admin"
	} else if u.IsAdmin {
		role = "admin"
	}
	return map[string]any{
		"id": u.ID, "display_name": display, "email": email,
		"portrait_url": portrait, "character_count": u.CharacterCount,
		"role": role, "is_active": u.IsActive,
		"is_stock_manager_enabled": u.IsStockManager,
		"last_login_at":            lastLogin,
		"created_at":               u.CreatedAt.UTC().Format("2006-01-02T15:04:05Z"),
	}
}

var adminSorts = map[string]bool{
	"name": true, "email": true, "is_admin": true,
	"is_active": true, "last_login_at": true, "created_at": true,
}

// GET /admin/users — searchable, sortable, paginated.
func handleAdminUserIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		qp := c.QueryParams()
		search := firstParam(qp["search"])
		if len([]rune(search)) > 100 {
			verrs := validationErrors{}
			verrs.add("search", "The search field must not be greater than 100 characters.")
			return verrs.invalid(c)
		}
		sortBy := firstParam(qp["sort"])
		if sortBy == "" {
			sortBy = "created_at"
		}
		if !adminSorts[sortBy] {
			verrs := validationErrors{}
			verrs.add("sort", "The selected sort is invalid.")
			return verrs.invalid(c)
		}
		dir := firstParam(qp["direction"])
		if dir == "" {
			dir = "desc"
		}
		if dir != "asc" && dir != "desc" {
			verrs := validationErrors{}
			verrs.add("direction", "The selected direction is invalid.")
			return verrs.invalid(c)
		}
		page, perPage, ok := adminPage(c)
		if !ok {
			return errHandled
		}
		rows, total, err := d.AdminUsers.AdminUsers(c.Request().Context(), search, sortBy, dir, perPage, (page-1)*perPage)
		if err != nil {
			return dbError(c, err)
		}
		items := make([]any, 0, len(rows))
		for _, u := range rows {
			items = append(items, adminUserShape(u))
		}
		return Success(c, http.StatusOK, items, map[string]any{
			"pagination": paginationOf(total, page, perPage),
		})
	}
}

// POST toggles + DELETE. Super admins are immutable (403).
func handleAdminToggle(d Deps, which string) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		ctx := c.Request().Context()
		u, err := d.AdminUsers.AdminUserByID(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if u == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if u.IsSuperAdmin {
			return Failure(c, http.StatusForbidden, "Cannot modify super admin.", nil)
		}
		var active, admin, stock *bool
		switch which {
		case "status":
			v := !u.IsActive
			active = &v
		case "admin":
			v := !u.IsAdmin
			admin = &v
		case "stock":
			v := !u.IsStockManager
			stock = &v
		}
		updated, err := d.AdminUsers.UpdateAdminUserFlags(ctx, id, active, admin, stock)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, adminUserShape(*updated), nil)
	}
}

func handleAdminUserDelete(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		ctx := c.Request().Context()
		u, err := d.AdminUsers.AdminUserByID(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if u == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if u.IsSuperAdmin {
			return Failure(c, http.StatusForbidden, "Cannot delete super admin.", nil)
		}
		if err := d.AdminUsers.DeleteAdminUser(ctx, id); err != nil {
			return dbError(c, err)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

// GET /admin/users/:id/industry-jobs — the user's jobs priced at the hub.
func handleAdminUserJobs(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		verrs := validationErrors{}
		regionID := 10000002
		if raw := c.QueryParam("region_id"); raw != "" {
			n, err := parseIntProg(raw)
			if err != nil {
				verrs.add("region_id", "The region id field must be an integer.")
				return verrs.invalid(c)
			}
			if _, known := market.Regions[n]; known {
				regionID = n
			}
		}
		ctx := c.Request().Context()
		u, err := d.AdminUsers.AdminUserByID(ctx, id)
		if err != nil {
			return dbError(c, err)
		}
		if u == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		rows, err := adminJobsFor(ctx, d, id, regionID)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, rows, nil)
	}
}

// GET /admin/account — the admin's login email.
func handleAdminAccountShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		u, err := d.AdminUsers.AdminUserByID(c.Request().Context(), uid)
		if err != nil {
			return dbError(c, err)
		}
		if u == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		email := ""
		if u.Email.Valid {
			email = u.Email.String
		}
		return Success(c, http.StatusOK, map[string]any{"email": email}, nil)
	}
}

// PUT /admin/account — email + optional password change (current verified).
func handleAdminAccountUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil && len(verrs) > 0 {
			return verrs.invalid(c)
		}
		if raw == nil {
			raw = map[string]any{}
		}
		email, _ := raw["email"].(string)
		if strings.TrimSpace(email) == "" {
			verrs.add("email", "The email field is required.")
		} else if len([]rune(email)) > 255 || !strings.Contains(email, "@") {
			verrs.add("email", "The email field must be a valid email address.")
		}
		var newHash *string
		if v, present := raw["password"]; present && v != nil && v != "" {
			pw, isStr := v.(string)
			if !isStr || len([]rune(pw)) < 8 {
				verrs.add("password", "The password field must be at least 8 characters.")
			}
			conf, _ := raw["password_confirmation"].(string)
			if conf != pw {
				verrs.add("password", "The password field confirmation does not match.")
			}
			cur, _ := raw["current_password"].(string)
			if cur == "" {
				verrs.add("current_password", "The current password field is required.")
			}
			if len(verrs) == 0 {
				u, err := d.AdminUsers.AdminUserByID(c.Request().Context(), uid)
				if err != nil {
					return dbError(c, err)
				}
				if u == nil || !u.PasswordHash.Valid || !checkPassword(u.PasswordHash.String, cur) {
					verrs.add("current_password", "The current password is incorrect.")
				} else if h, herr := hashPassword(pw); herr != nil {
					return dbError(c, herr)
				} else {
					newHash = &h
				}
			}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		taken, err := d.AdminUsers.AdminUserEmailTaken(c.Request().Context(), email, uid)
		if err != nil {
			return dbError(c, err)
		}
		if taken {
			verrs.add("email", "The email has already been taken.")
			return verrs.invalid(c)
		}
		updated, err := d.AdminUsers.UpdateAdminAccount(c.Request().Context(), uid, email, newHash)
		if err != nil {
			return dbError(c, err)
		}
		out := ""
		if updated.Email.Valid {
			out = updated.Email.String
		}
		return Success(c, http.StatusOK, map[string]any{"email": out}, nil)
	}
}

// adminPage parses ?page=&per_page= with the admin default of 20 (max 200).
func adminPage(c echo.Context) (int, int, bool) {
	page, ok := queryInt(c, "page", 1, 1, 1<<31-1)
	if !ok {
		return 0, 0, false
	}
	perPage, ok := queryInt(c, "per_page", 20, 1, 200)
	if !ok {
		return 0, 0, false
	}
	return page, perPage, true
}
