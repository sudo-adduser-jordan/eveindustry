package market

import (
	"strings"
)

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
