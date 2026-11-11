package http

import (
	"bytes"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type SponsorStorage interface {
	Save(filename string, data []byte) (string, error)
	Delete(path string) error
}

type DiskSponsorStorage struct {
	Dir string
}

func (d DiskSponsorStorage) Save(filename string, data []byte) (string, error) {
	if err := os.MkdirAll(d.Dir, 0o755); err != nil {
		return "", err
	}
	name := fmt.Sprintf("%d_%s", time.Now().UnixNano(), filepath.Base(filename))
	if err := os.WriteFile(filepath.Join(d.Dir, name), data, 0o644); err != nil {
		return "", err
	}
	return "sponsors/" + name, nil
}

func (d DiskSponsorStorage) Delete(path string) error {
	if path == "" {
		return nil
	}
	clean := filepath.Clean(path)
	if strings.Contains(clean, "..") {
		return nil
	}
	return os.Remove(filepath.Join(filepath.Dir(d.Dir), clean))
}

func registerSponsors(v1 *echo.Group, d Deps) {
	if d.Sponsors == nil || d.AdminStats == nil {
		return
	}
	g := v1.Group("/admin", requireAdminDeps(d))
	g.GET("/dashboard", handleAdminDashboard(d))
	g.GET("/sponsors", handleSponsorIndex(d))
	g.POST("/sponsors", handleSponsorStore(d))
	g.GET("/sponsors/:id", handleSponsorShow(d))
	g.POST("/sponsors/:id", handleSponsorUpdate(d))
	g.DELETE("/sponsors/:id", handleSponsorDestroy(d))
}

func sponsorShape(s store.Sponsor) map[string]any {
	return map[string]any{
		"id": s.ID, "name": s.Name, "url": s.URL,
		"image_url": "/storage/" + s.ImagePath,
		"is_active": s.IsActive, "sort_order": s.SortOrder,
	}
}

func handleAdminDashboard(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		ctx := c.Request().Context()
		stats, err := d.AdminStats.AdminStats(ctx)
		if err != nil {
			return dbError(c, err)
		}
		regs, err := d.AdminStats.AdminRegistrationSeries(ctx)
		if err != nil {
			return dbError(c, err)
		}
		logins, err := d.AdminStats.AdminLoginSeries(ctx)
		if err != nil {
			return dbError(c, err)
		}
		regOut := make([]any, 0, len(regs))
		for _, p := range regs {
			regOut = append(regOut, map[string]any{"date": p.Date, "count": p.Count})
		}
		loginOut := make([]any, 0, len(logins))
		for _, p := range logins {
			loginOut = append(loginOut, map[string]any{"date": p.Date, "count": p.Count})
		}
		return Success(c, http.StatusOK, map[string]any{
			"stats": map[string]any{
				"users": stats.Users, "characters": stats.Characters,
				"active_users": stats.ActiveUsers, "recent_logins": stats.RecentLogins,
				"new_users_week": stats.NewWeek, "new_users_month": stats.NewMonth,
				"logged_in_24h": stats.Logged24h,
			},
			"registration_series": regOut, "login_series": loginOut,
		}, nil)
	}
}

func handleSponsorIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		rows, err := d.Sponsors.Sponsors(c.Request().Context())
		if err != nil {
			return dbError(c, err)
		}
		items := make([]any, 0, len(rows))
		for _, s := range rows {
			items = append(items, sponsorShape(s))
		}
		return Success(c, http.StatusOK, items, nil)
	}
}

func handleSponsorShow(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		row, err := d.Sponsors.SponsorByID(c.Request().Context(), id)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		return Success(c, http.StatusOK, sponsorShape(*row), nil)
	}
}

func sponsorForm(c echo.Context, imageRequired bool) (name, url string, image []byte, filename string, active bool, order int, ok bool) {
	verrs := validationErrors{}
	active = true
	if err := c.Request().ParseMultipartForm(2<<20 + 1024); err != nil {
		verrs.add("image", "The image field is required.")
		verrs.invalid(c)
		return "", "", nil, "", false, 0, false
	}
	form := c.Request().MultipartForm
	val := func(key string) string {
		if form != nil && len(form.Value[key]) > 0 {
			return form.Value[key][0]
		}
		return c.Request().FormValue(key)
	}
	name = strings.TrimSpace(val("name"))
	if name == "" || len([]rune(name)) > 255 {
		verrs.add("name", "The name field is required.")
	}
	url = strings.TrimSpace(val("url"))
	if url == "" || len([]rune(url)) > 255 || !strings.Contains(url, ".") {
		verrs.add("url", "The url field must be a valid URL.")
	}
	if raw := val("is_active"); raw != "" {
		active = raw == "1" || strings.EqualFold(raw, "true") || strings.EqualFold(raw, "yes") || strings.EqualFold(raw, "on")
	}
	if raw := val("sort_order"); raw != "" {
		n, err := parseIntProg(raw)
		if err != nil || n < 0 {
			verrs.add("sort_order", "The sort order field must be at least 0.")
		} else {
			order = n
		}
	}
	var fname string
	hasFile := form != nil && len(form.File["image"]) > 0
	if hasFile {
		fname = form.File["image"][0].Filename
	}
	if !hasFile {
		if imageRequired {
			verrs.add("image", "The image field is required.")
		}
	} else {
		f, err := form.File["image"][0].Open()
		if err != nil {
			verrs.add("image", "The image field is required.")
		} else {
			defer f.Close()
			var buf bytes.Buffer
			if _, err := io.Copy(&buf, io.LimitReader(f, 2<<20+1)); err != nil {
				verrs.add("image", "The image field is required.")
			} else if buf.Len() > 2<<20 {
				verrs.add("image", "The image field must not be greater than 2048 kilobytes.")
			} else if !sniffSponsorImage(buf.Bytes()) {
				verrs.add("image", "The image field must be an image.")
			} else {
				image = buf.Bytes()
				filename = fname
			}
		}
	}
	if len(verrs) > 0 {
		verrs.invalid(c)
		return "", "", nil, "", false, 0, false
	}
	return name, url, image, filename, active, order, true
}

func sniffSponsorImage(data []byte) bool {
	if len(data) < 4 {
		return false
	}
	if bytes.HasPrefix(data, []byte("\xff\xd8\xff")) ||
		bytes.HasPrefix(data, []byte("\x89PNG")) ||
		bytes.HasPrefix(data, []byte("GIF8")) ||
		bytes.HasPrefix(data, []byte("RIFF")) ||
		bytes.HasPrefix(bytes.TrimSpace(data), []byte("<svg")) {
		return true
	}
	return false
}

func handleSponsorStore(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		if d.SponsorImages == nil {
			return Failure(c, http.StatusInternalServerError, "Upload storage is not configured.", nil)
		}
		name, url, image, filename, active, order, ok := sponsorForm(c, true)
		if !ok {
			return errHandled
		}
		path, err := d.SponsorImages.Save(filename, image)
		if err != nil {
			return dbError(c, err)
		}
		row, err := d.Sponsors.CreateSponsor(c.Request().Context(), store.Sponsor{
			Name: name, URL: url, ImagePath: path, IsActive: active, SortOrder: order,
		})
		if err != nil {
			_ = d.SponsorImages.Delete(path)
			return dbError(c, err)
		}
		return Success(c, http.StatusCreated, sponsorShape(*row), nil)
	}
}

func handleSponsorUpdate(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		row, err := d.Sponsors.SponsorByID(c.Request().Context(), id)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if d.SponsorImages == nil {
			return Failure(c, http.StatusInternalServerError, "Upload storage is not configured.", nil)
		}
		name, url, image, filename, active, order, ok := sponsorForm(c, false)
		if !ok {
			return errHandled
		}
		path := row.ImagePath
		if image != nil {
			np, err := d.SponsorImages.Save(filename, image)
			if err != nil {
				return dbError(c, err)
			}
			_ = d.SponsorImages.Delete(path)
			path = np
		}
		updated, err := d.Sponsors.UpdateSponsor(c.Request().Context(), store.Sponsor{
			ID: id, Name: name, URL: url, ImagePath: path, IsActive: active, SortOrder: order,
		})
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, sponsorShape(*updated), nil)
	}
}

func handleSponsorDestroy(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		id, ok := essID(c)
		if !ok {
			return errHandled
		}
		row, err := d.Sponsors.SponsorByID(c.Request().Context(), id)
		if err != nil {
			return dbError(c, err)
		}
		if row == nil {
			return Failure(c, http.StatusNotFound, "Not found.", nil)
		}
		if err := d.Sponsors.DeleteSponsor(c.Request().Context(), id); err != nil {
			return dbError(c, err)
		}
		if d.SponsorImages != nil {
			_ = d.SponsorImages.Delete(row.ImagePath)
		}
		return c.NoContent(http.StatusNoContent)
	}
}
