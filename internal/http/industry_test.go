package http

import (
	"context"
	"net/http"
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
)

type stubIndustryJobs struct {
	jobs     map[int64][]map[string]any
	corpJobs map[int64][]map[string]any
}

func (s *stubIndustryJobs) GetIndustryJobs(ctx context.Context, id int64, inc bool) ([]map[string]any, error) {
	return s.jobs[id], nil
}
func (s *stubIndustryJobs) GetCorporationIndustryJobs(ctx context.Context, charID, corpID int64, inc bool) ([]map[string]any, error) {
	return s.corpJobs[charID], nil
}
func (s *stubIndustryJobs) GetSkills(ctx context.Context, id int64) (map[int]int, error) {
	return map[int]int{}, nil
}
func (s *stubIndustryJobs) GetAssets(ctx context.Context, id int64) ([]map[string]any, error) {
	return nil, nil
}

type stubUniverse struct{}

func (stubUniverse) GetTypeInfo(ctx context.Context, id int) (map[string]any, error) {
	if id == 587 {
		return map[string]any{"type_id": 587, "name": "Rifter", "portion_size": 1}, nil
	}
	return nil, nil
}
func (stubUniverse) GetBestTypeImageUrl(ctx context.Context, id int, bp bool) string { return "" }
func (stubUniverse) GetStation(ctx context.Context, id uint64) (map[string]any, error) {
	if id == 60003760 {
		return map[string]any{"station_id": id, "name": "Jita 4-4"}, nil
	}
	return nil, nil
}
func (stubUniverse) GetSystem(ctx context.Context, id int) (map[string]any, error) { return nil, nil }
func (stubUniverse) GetStructure(ctx context.Context, id uint64, char int64) (map[string]any, error) {
	return nil, nil
}
func (stubUniverse) GetDogmaAttribute(ctx context.Context, id int) (map[string]any, error) {
	return nil, nil
}
func (stubUniverse) DogmaTypeAttributes(ctx context.Context, id int) (map[int]float64, error) {
	return map[int]float64{}, nil
}

func industryJobsTestDeps() Deps {
	stub := testAuth()
	stub.chars = []auth.Character{
		{CharacterID: 11, Name: "Main", IsMain: true, IsEsiActive: true,
			CorporationID: int64Ptr(987), Scopes: []string{"esi-industry.read_character_jobs.v1"}},
	}
	d := calcTestDeps()
	d.Users = stub
	d.Sessions = stub
	d.IndustryDeps.Characters = &stubIndustryJobs{
		jobs: map[int64][]map[string]any{
			11: {{
				"job_id": 101, "activity_id": 1, "status": "active", "runs": 10,
				"start_date": "2026-11-08T11:00:00Z", "end_date": "2026-11-08T15:00:00Z",
				"blueprint_type_id": 786, "product_type_id": 587, "station_id": 60003760,
			}},
		},
	}
	d.IndustryDeps.Universe = stubUniverse{}
	ind := d.Industry.(*stubIndustry)
	ind.activities[[2]int{786, 1}] = industryActivityRow(786, 1, 100,
		`[{"type_id":34,"name":"Tritanium","quantity":100}]`,
		`[{"type_id":587,"name":"Rifter","quantity":1}]`)
	m := d.Market.(*stubMarket)
	m.aggregates = map[int]float64{587: 1000}
	m.hasSnapshot = true
	return d
}

func int64Ptr(i int64) *int64 { return &i }

func TestIndustryJobsOverview(t *testing.T) {
	d := industryJobsTestDeps()
	code, body := get(t, d, "/api/v1/industry/jobs?region_id=10000002")
	if code != http.StatusOK {
		t.Fatalf("code = %d %v", code, body)
	}
	data := body["data"].(map[string]any)
	groups := data["groups"].([]any)
	if len(groups) != 1 {
		t.Fatalf("groups = %v", data)
	}
	jobs := groups[0].(map[string]any)["jobs"].([]any)
	row := jobs[0].(map[string]any)
	if row["expected_output"].(float64) != 10 || row["estimated_value"].(float64) != 10000 {
		t.Fatalf("row = %v", row)
	}
	if row["location"] != "Jita 4-4" {
		t.Fatalf("location = %v", row)
	}
	summary := data["summary"].([]any)
	if len(summary) != 1 || data["estimated_total"].(float64) != 10000 {
		t.Fatalf("summary = %v", data)
	}
	if data["total_jobs"].(float64) != 1 {
		t.Fatalf("total = %v", data)
	}
}
