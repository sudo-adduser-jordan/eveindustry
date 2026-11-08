package market

import (
	"strings"
)

// ParseAppraisalItems ports ContractAppraisalService::parseItems(): one item
// name per line, trimmed, blanks skipped, duplicates aggregated. Names come
// back in first-seen order with their line counts.
func ParseAppraisalItems(rawText string) (names []string, counts map[string]int) {
	counts = map[string]int{}
	for _, line := range strings.Split(rawText, "\n") {
		name := strings.TrimSpace(strings.TrimSuffix(line, "\r"))
		if name == "" {
			continue
		}
		if _, ok := counts[name]; !ok {
			names = append(names, name)
		}
		counts[name]++
	}
	return names, counts
}
