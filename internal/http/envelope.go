package http

import (
	"net/http"

	"github.com/labstack/echo/v4"
)

var ErrorCodes = map[int]string{
	400: "bad_request",
	401: "unauthenticated",
	403: "forbidden",
	404: "not_found",
	405: "method_not_allowed",
	409: "conflict",
	419: "csrf_token_mismatch",
	422: "validation_failed",
	429: "too_many_requests",
	500: "server_error",
	503: "service_unavailable",
}

func codeForStatus(status int) string {
	if c, ok := ErrorCodes[status]; ok {
		return c
	}
	if status >= 500 {
		return "server_error"
	}
	return "http_error"
}

func Success(c echo.Context, status int, data any, meta map[string]any) error {
	if meta == nil {
		meta = map[string]any{}
	}
	return c.JSON(status, map[string]any{"data": data, "meta": meta})
}

func Failure(c echo.Context, status int, message string, errors map[string][]string) error {
	body := map[string]any{"message": message, "code": codeForStatus(status)}
	if errors != nil {
		body["errors"] = errors
	}
	return c.JSON(status, body)
}

func jsonNotFound(c echo.Context) error {
	return Failure(c, http.StatusNotFound, "Not Found.", nil)
}

func jsonMethodNotAllowed(c echo.Context) error {
	return Failure(c, http.StatusMethodNotAllowed, "Method Not Allowed.", nil)
}
