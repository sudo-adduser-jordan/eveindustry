// Package esi is the Go port of the EVE ESI transport: outcome
// classification, error circuit, sliding-window rate coordination, token
// refresh and the HTTP client. Diagnostics recording, the response cache,
// demo responses and the eight resource sub-clients arrive in later slices.
package esi

import (
	"fmt"
	"time"
)

// Outcome mirrors the EsiRequestException outcome vocabulary.
type Outcome string

const (
	OutcomeErrorLimited Outcome = "error_limited"
	OutcomeRateLimited  Outcome = "rate_limited"
	OutcomeCoordination Outcome = "coordination"
	OutcomeTransient    Outcome = "transient"
	OutcomeCredential   Outcome = "credential"
	OutcomeMissingScope Outcome = "missing_scope"
	OutcomeForbidden    Outcome = "forbidden"
	OutcomeNotFound     Outcome = "not_found"
	OutcomeInvalid      Outcome = "invalid_response"
	OutcomeIncomplete   Outcome = "incomplete"
)

// RequestError mirrors EsiRequestException: an outcome plus how long the
// caller should wait before retrying (0 = do not retry on this path).
type RequestError struct {
	Outcome        Outcome
	RetryAfterSecs int
	Status         int
}

func (e *RequestError) Error() string {
	return fmt.Sprintf("esi %s (status %d, retry in %ds)", e.Outcome, e.Status, e.RetryAfterSecs)
}

// ESI error-limit constants mirror EsiService.
const (
	ErrorLimitThreshold      = 20
	ErrorDefaultResetSeconds = 60
)

// Rate limiter defaults mirror EsiRateLimiter.
const (
	DefaultWindowSeconds = 900
	DefaultLimit         = 600
)

// BaseURL is the ESI host all relative endpoints resolve against.
const BaseURL = "https://esi.evetech.net/latest"

// LoginBase is the SSO host for token refresh.
const LoginBase = "https://login.eveonline.com"

func max(a, b int64) int64 {
	if a > b {
		return a
	}
	return b
}

func now() time.Time { return time.Now() }
