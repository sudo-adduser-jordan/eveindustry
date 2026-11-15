package http

import (
	"context"
	"net/http"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
)

type EsiStatusUniverse interface {
	GetServerStatus(ctx context.Context) (*esi.ServerStatus, error)
}

type EsiStatusDeps struct {
	Universe EsiStatusUniverse
}

func registerEsiStatus(v1 *echo.Group, d Deps) {
	if d.EsiStatus.Universe == nil {
		return
	}
	v1.GET("/esi/status", handleEsiStatus(d))
}

func handleEsiStatus(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		st, err := d.EsiStatus.Universe.GetServerStatus(c.Request().Context())
		if err != nil {
			return Failure(c, http.StatusBadGateway, "ESI is unreachable.", nil)
		}
		if st == nil {
			return Success(c, http.StatusOK, map[string]any{
				"online": false, "players": nil, "server_version": nil, "vip": false,
			}, nil)
		}
		return Success(c, http.StatusOK, map[string]any{
			"online": true, "players": st.Players, "server_version": st.ServerVersion, "vip": st.VIP,
		}, nil)
	}
}
