package esi

import (
	"fmt"
	"time"
)

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

type RequestError struct {
	Outcome        Outcome
	RetryAfterSecs int
	Status         int
}

func (e *RequestError) Error() string {
	return fmt.Sprintf("esi %s (status %d, retry in %ds)", e.Outcome, e.Status, e.RetryAfterSecs)
}

const (
	ErrorLimitThreshold      = 20
	ErrorDefaultResetSeconds = 60
)

const (
	DefaultWindowSeconds = 900
	DefaultLimit         = 600
)

const BaseURL = "https://esi.evetech.net/latest"

const LoginBase = "https://login.eveonline.com"

func max(a, b int64) int64 {
	if a > b {
		return a
	}
	return b
}

func now() time.Time { return time.Now() }
