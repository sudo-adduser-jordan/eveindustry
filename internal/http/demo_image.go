package http

import (
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

// registerDemoImages mounts the generated demo portraits and logos. Demo ids
// never resolve on the EVE image server, so the API draws deterministic SVGs
// instead (like the PHP DemoImageController). Public: <img> tags fetch them
// without a session.
func registerDemoImages(e *echo.Echo) {
	e.GET("/demo/portrait/:id", handleDemoImage(true))
	e.GET("/demo/logo/:id", handleDemoImage(false))
}

func handleDemoImage(portrait bool) echo.HandlerFunc {
	defSize := 64
	if portrait {
		defSize = 128
	}
	return func(c echo.Context) error {
		raw := strings.TrimSuffix(c.Param("id"), ".svg")
		id, err := strconv.ParseInt(raw, 10, 64)
		if err != nil || !auth.IsDemoID(id) {
			return Failure(c, http.StatusNotFound, "Not Found.", nil)
		}
		size := defSize
		if q := c.QueryParam("size"); q != "" {
			if n, err := strconv.Atoi(q); err == nil {
				size = n
			}
		}
		if size < 16 {
			size = 16
		}
		if size > 1024 {
			size = 1024
		}
		var body string
		if portrait {
			body = demoPortraitSVG(id, size)
		} else {
			body = demoLogoSVG(id, size)
		}
		res := c.Response()
		res.Header().Set("Content-Type", "image/svg+xml")
		res.Header().Set("Cache-Control", "public, max-age=31536000, immutable")
		return c.String(http.StatusOK, body)
	}
}

// demoHue derives a stable hue from a demo id.
func demoHue(id int64) int {
	h := id % 360
	if h < 0 {
		h += 360
	}
	return int(h)
}

// demoPortraitSVG draws a pilot-silhouette avatar on a dark gradient.
func demoPortraitSVG(id int64, size int) string {
	h := demoHue(id)
	h2 := (h + 50) % 360
	return fmt.Sprintf(`<svg xmlns="http://www.w3.org/2000/svg" width="%[1]d" height="%[1]d" viewBox="0 0 128 128">`+
		`<defs><radialGradient id="g" cx="50%%" cy="32%%" r="80%%">`+
		`<stop offset="0%%" stop-color="hsl(%[2]d,42%%,30%%)"/>`+
		`<stop offset="100%%" stop-color="hsl(%[2]d,45%%,11%%)"/></radialGradient>`+
		`<clipPath id="c"><rect width="128" height="128" rx="26"/></clipPath></defs>`+
		`<g clip-path="url(#c)"><rect width="128" height="128" fill="url(#g)"/>`+
		`<circle cx="102" cy="18" r="34" fill="hsl(%[3]d,80%%,60%%)" fill-opacity="0.16"/>`+
		`<circle cx="22" cy="112" r="26" fill="hsl(%[3]d,80%%,60%%)" fill-opacity="0.12"/>`+
		`<circle cx="64" cy="54" r="23" fill="hsl(%[2]d,75%%,64%%)"/>`+
		`<path d="M22 132c4-32 22-46 42-46s38 14 42 46z" fill="hsl(%[2]d,75%%,64%%)"/>`+
		`<circle cx="64" cy="64" r="57" fill="none" stroke="hsl(%[2]d,85%%,72%%)" stroke-opacity="0.55" stroke-width="3"/>`+
		`</g></svg>`, size, h, h2)
}

// demoLogoSVG draws a hex emblem for corporations and alliances.
func demoLogoSVG(id int64, size int) string {
	h := demoHue(id + 7)
	return fmt.Sprintf(`<svg xmlns="http://www.w3.org/2000/svg" width="%[1]d" height="%[1]d" viewBox="0 0 128 128">`+
		`<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">`+
		`<stop offset="0%%" stop-color="hsl(%[2]d,40%%,26%%)"/>`+
		`<stop offset="100%%" stop-color="hsl(%[2]d,45%%,10%%)"/></linearGradient>`+
		`<clipPath id="c"><rect width="128" height="128" rx="26"/></clipPath></defs>`+
		`<g clip-path="url(#c)"><rect width="128" height="128" fill="url(#g)"/>`+
		`<polygon points="98,64 81,93.4 47,93.4 30,64 47,34.6 81,34.6" fill="hsl(%[2]d,75%%,60%%)"/>`+
		`<polygon points="84,64 74,81.2 54,81.2 44,64 54,46.8 74,46.8" fill="hsl(%[2]d,45%%,13%%)"/>`+
		`<circle cx="64" cy="64" r="9" fill="hsl(%[2]d,85%%,70%%)"/>`+
		`</g></svg>`, size, h)
}
