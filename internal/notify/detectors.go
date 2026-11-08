package notify

import (
	"context"
	"sort"
	"strings"
	"time"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/industry"
	"github.com/sudo-adduser-jordan/eveindustry/internal/pi"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// DetectorDeps carries the data sources detectors need.
type DetectorDeps struct {
	Industry    IndustryFinishedJobs
	Planetary   PlanetaryColonies
	CorpChars   func(ctx context.Context, userID int64) ([]auth.Character, error)
	Windows     func(ctx context.Context, corpIDs []int64) ([]store.StructureWindow, error)
}

// IndustryFinishedJobs mirrors getFinishedJobsForUser.
type IndustryFinishedJobs interface {
	FinishedJobs(ctx context.Context, userID int64, since, until time.Time) ([]industry.FinishedJob, error)
}

// PlanetaryColonies mirrors getCharacterPlanetaryData per character.
type PlanetaryColonies interface {
	CharacterColonies(ctx context.Context, ch auth.Character, now time.Time) ([]PiPlanet, error)
}

// PiPlanet is one analyzed colony for the extractor detector.
type PiPlanet struct {
	PlanetID   int
	PlanetName string
	Pins       []PiPin
}

// PiPin is one pin with optional extractor expiry.
type PiPin struct {
	Expiry *time.Time
}

// DetectIndustryCompleted ports IndustryJobCompletedDetector.
func DetectIndustryCompleted(jobs []industry.FinishedJob, charIDs map[int64]bool) []Message {
	var out []Message
	for _, j := range jobs {
		if len(charIDs) > 0 && !charIDs[j.CharacterID] {
			continue
		}
		if j.ItemName == "" {
			j.ItemName = "an item"
		}
		out = append(out, Message{
			Type:      "industry.job_completed",
			DedupeKey: "industry:" + itoa64(j.JobID) + ":done",
			Title:     "Industry job completed",
			Body:      j.ActivityName + ": " + j.ItemName + " (" + j.CharacterName + ") is ready to deliver.",
			URL:       "/app/industry/jobs",
		})
	}
	return out
}

// DetectPiExpiring ports PiExtractorExpiringDetector.
func DetectPiExpiring(planets []PiPlanetWithChar, charIDs map[int64]bool, lead time.Duration, now, since time.Time) []Message {
	horizon := now.Add(lead)
	var out []Message
	for _, p := range planets {
		if len(charIDs) > 0 && !charIDs[p.CharacterID] {
			continue
		}
		var expiries []time.Time
		for _, pin := range p.Pins {
			if pin.Expiry == nil {
				continue
			}
			if pin.Expiry.After(since) && !pin.Expiry.After(horizon) {
				expiries = append(expiries, *pin.Expiry)
			}
		}
		if len(expiries) == 0 {
			continue
		}
		sort.Slice(expiries, func(i, j int) bool { return expiries[i].Before(expiries[j]) })
		earliest := expiries[0]
		when := "stop " + humanIn(earliest.Sub(now)) + " from now"
		if !earliest.After(now) {
			when = "have stopped"
		}
		noun := "extractors"
		if len(expiries) == 1 {
			noun = "extractor"
		}
		out = append(out, Message{
			Type:      "pi.extractor_expiring",
			DedupeKey: "pi:" + itoa64(p.CharacterID) + ":" + itoa(p.PlanetID) + ":" + itoa64(earliest.Unix()),
			Title:     "Planetary extractors expiring",
			Body:      itoa(len(expiries)) + " " + noun + " on " + p.PlanetName + " (" + p.CharacterName + ") " + when + ".",
			URL:       "/app/planetary",
		})
	}
	return out
}

// PiPlanetWithChar carries owner identity for messages.
type PiPlanetWithChar struct {
	CharacterID   int64
	CharacterName string
	PlanetID      int
	PlanetName    string
	Pins          []PiPin
}

// DetectStructureWindows ports StructureWindowDetector (openings + timers).
func DetectStructureWindows(windows []store.StructureWindow, isTimer bool, lead time.Duration, now time.Time) []Message {
	horizon := now.Add(lead)
	var out []Message
	for _, w := range windows {
		var at *time.Time
		if isTimer {
			if w.ReinforceExitAt.Valid {
				t := w.ReinforceExitAt.Time.UTC()
				at = &t
			}
		} else {
			at = nextWindowOpening(w, now)
		}
		if at == nil || !at.After(now) || at.After(horizon) {
			continue
		}
		name := w.StructureName
		if name == "" {
			name = strings.ToUpper(w.StructureType[:1]) + w.StructureType[1:]
		}
		kind, title, verb := "open", "Vulnerability window opening", "window opens"
		if isTimer {
			kind, title, verb = "timer", "Structure timer ending", "reinforcement ends"
		}
		out = append(out, Message{
			Type:      timerType(isTimer),
			DedupeKey: "structure:" + itoa64(w.ID) + ":" + kind + ":" + itoa64(at.Unix()),
			Title:     title,
			Body:      name + " in " + w.SolarSystemName + ": " + verb + " at " + at.Format("15:04") + " UTC.",
			URL:       "/app/corporate/structure-vulnerability",
		})
	}
	return out
}

func timerType(isTimer bool) string {
	if isTimer {
		return "structure.timer"
	}
	return "structure.window_opening"
}

// nextWindowOpening ports the PHP next-opening math (day 0 = Sunday).
func parseHM(s string) (int, int) {
	var h, m int
	if len(s) >= 5 && s[2] == ':' {
		for _, ch := range s[:2] {
			if ch >= '0' && ch <= '9' {
				h = h*10 + int(ch-'0')
			}
		}
		for _, ch := range s[3:5] {
			if ch >= '0' && ch <= '9' {
				m = m*10 + int(ch-'0')
			}
		}
	}
	return h, m
}

func nextWindowOpening(w store.StructureWindow, now time.Time) *time.Time {
	hour, minute := parseHM(w.StartTimeUTC)
	cand := time.Date(now.Year(), now.Month(), now.Day(), hour, minute, 0, 0, time.UTC)
	if w.DayOfWeek.Valid {
		dow := int(w.DayOfWeek.Int64)
		cand = cand.AddDate(0, 0, (dow-int(cand.Weekday())+7)%7)
	}
	if !cand.After(now) {
		if w.DayOfWeek.Valid {
			cand = cand.AddDate(0, 0, 7)
		} else {
			cand = cand.AddDate(0, 0, 1)
		}
	}
	return &cand
}

func humanIn(d time.Duration) string {
	if d < 0 {
		d = 0
	}
	h := int(d.Hours())
	m := int(d.Minutes()) % 60
	if h > 0 {
		return itoa(h) + "h " + itoa(m) + "m"
	}
	return itoa(m) + "m"
}

func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var b [20]byte
	p := len(b)
	for n > 0 {
		p--
		b[p] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		p--
		b[p] = '-'
	}
	return string(b[p:])
}

func itoa64(n int64) string {
	if n == 0 {
		return "0"
	}
	neg := n < 0
	if neg {
		n = -n
	}
	var b [20]byte
	p := len(b)
	for n > 0 {
		p--
		b[p] = byte('0' + n%10)
		n /= 10
	}
	if neg {
		p--
		b[p] = '-'
	}
	return string(b[p:])
}

var _ = pi.BuildCapacityAnalysis
