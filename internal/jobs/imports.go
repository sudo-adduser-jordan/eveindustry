package jobs

import (
	"archive/tar"
	"bytes"
	"context"
	"encoding/csv"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"time"

	"github.com/dsnet/compress/bzip2"

	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

// RegisterImportJobs wires market:update-prices (default, daily),
// market:import-daily-history (updates, every 3h, 2 tries) and
// contracts:import (updates, hourly, single-flight via the go_locks table).
func RegisterImportJobs(r *Runner, h HandlerDeps) {
	r.Register("market-update-prices", JobDef{
		Queue: QueueDefault, MaxTries: 2, Timeout: 300 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			return UpdateMarketPrices(ctx, h)
		},
	})
	r.Register("import-daily-market-history", JobDef{
		Queue: QueueUpdates, MaxTries: 2, Timeout: 600 * time.Second,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			var p struct {
				Date string `json:"date"`
			}
			_ = json.Unmarshal(raw, &p)
			return ImportDailyMarketHistory(ctx, h, p.Date)
		},
	})
	r.Register("import-public-contracts", JobDef{
		Queue: QueueUpdates, MaxTries: 1, Timeout: 900 * time.Second, Unique: 20 * time.Minute,
		Run: func(ctx context.Context, d Deps, raw json.RawMessage) error {
			return ImportPublicContracts(ctx, h)
		},
	})
}

// ImportSchedule mirrors the price/history/contract cadences. History covers
// today and yesterday; contracts run hourly.
func ImportSchedule(h HandlerDeps, forgeID int) []ScheduleEntry {
	return []ScheduleEntry{
		{
			Name: "market:update-prices", Every: 24 * time.Hour, At: "04:00", Overlap: 24 * time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return r.Dispatch(ctx, q, "market-update-prices", nil)
			},
		},
		{
			Name: "market:import-daily-history", Every: 3 * time.Hour, Overlap: 3 * time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				for _, d := range []string{time.Now().Format("2006-01-02"), time.Now().AddDate(0, 0, -1).Format("2006-01-02")} {
					raw, _ := json.Marshal(map[string]any{"date": d})
					if err := r.Dispatch(ctx, q, "import-daily-market-history", raw); err != nil {
						return err
					}
				}
				return nil
			},
		},
		{
			Name: "contracts:import", Every: time.Hour, Overlap: time.Hour,
			Run: func(ctx context.Context, r *Runner, q Queue) error {
				return r.Dispatch(ctx, q, "import-public-contracts", nil)
			},
		},
	}
}

// UpdateMarketPrices ports fetchAndStoreGlobalPrices: ESI global prices into
// market_prices for the Forge region.
func UpdateMarketPrices(ctx context.Context, h HandlerDeps) error {
	live, err := h.ESIMarket.GetMarketPrices(ctx)
	if err != nil || len(live) == 0 {
		return err
	}
	var rows []store.GlobalPrice
	for _, p := range live {
		tid, ok := intOf(p["type_id"])
		if !ok || tid == 0 {
			continue
		}
		r := store.GlobalPrice{TypeID: int(tid)}
		if _, ok := p["average_price"]; ok {
			v := floatOf(p["average_price"])
			r.Average = &v
		}
		if _, ok := p["adjusted_price"]; ok {
			v := floatOf(p["adjusted_price"])
			r.Adjusted = &v
		}
		rows = append(rows, r)
	}
	_, err = h.DB.UpsertGlobalPrices(ctx, 10000002, rows, time.Now())
	return err
}

// ImportDailyMarketHistory ports the everef CSV import for one date.
func ImportDailyMarketHistory(ctx context.Context, h HandlerDeps, date string) error {
	if date == "" {
		date = time.Now().Format("2006-01-02")
	}
	year := date[:4]
	url := "https://data.everef.net/market-history/" + year + "/market-history-" + date + ".csv.bz2"
	body, err := downloadURL(ctx, url, 120*time.Second)
	if err != nil {
		return nil
	}
	raw, err := bunzip2(body)
	if err != nil {
		return err
	}
	known := map[int]bool{10000002: true, 10000043: true, 10000032: true, 10000042: true, 10000030: true}
	r := csv.NewReader(bytes.NewReader(raw))
	r.FieldsPerRecord = -1
	header, err := r.Read()
	if err != nil {
		return err
	}
	idx := map[string]int{}
	for i, h := range header {
		idx[h] = i
	}
	get := func(row []string, keys ...string) string {
		for _, k := range keys {
			if i, ok := idx[k]; ok && i < len(row) {
				return row[i]
			}
		}
		return ""
	}
	var batch []store.MarketHistoryImportRow
	var total int64
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		n, err := h.DB.UpsertMarketHistory(ctx, batch)
		total += n
		batch = nil
		return err
	}
	for {
		row, err := r.Read()
		if err == io.EOF {
			break
		}
		if err != nil {
			return err
		}
		region, _ := strconv.Atoi(get(row, "region_id", "regionID"))
		if !known[region] {
			continue
		}
		tid, _ := strconv.Atoi(get(row, "type_id", "typeID"))
		avg, _ := strconv.ParseFloat(get(row, "average"), 64)
		hi, _ := strconv.ParseFloat(get(row, "highest"), 64)
		lo, _ := strconv.ParseFloat(get(row, "lowest"), 64)
		vol, _ := strconv.ParseInt(get(row, "volume"), 10, 64)
		oc, _ := strconv.ParseInt(get(row, "order_count", "orderCount"), 10, 64)
		batch = append(batch, store.MarketHistoryImportRow{
			RegionID: region, TypeID: tid, Date: get(row, "date"),
			Average: avg, Highest: hi, Lowest: lo, Volume: vol, OrderCount: oc,
		})
		if len(batch) >= 1000 {
			if err := flush(); err != nil {
				return err
			}
		}
	}
	if err := flush(); err != nil {
		return err
	}
	h.logger().Info("imported market history", "date", date, "rows", total)
	return nil
}

// ImportPublicContracts ports the everef public-contract snapshot import:
// download, extract, stage, orphan-check, atomic publish.
func ImportPublicContracts(ctx context.Context, h HandlerDeps) error {
	const dataURL = "https://data.everef.net/public-contracts/public-contracts.tar.bz2"
	dir, err := os.MkdirTemp("", "eve_pc_*")
	if err != nil {
		return err
	}
	defer os.RemoveAll(dir)
	archive := filepath.Join(dir, "contracts.tar.bz2")
	if err := downloadFile(ctx, dataURL, archive, 300*time.Second); err != nil {
		return err
	}
	if fi, err := os.Stat(archive); err != nil || fi.Size() == 0 {
		return fmt.Errorf("empty contract archive")
	}
	extractDir := filepath.Join(dir, "extract")
	if err := os.MkdirAll(extractDir, 0o755); err != nil {
		return err
	}
	if err := extractTarBz2(archive, extractDir); err != nil {
		return err
	}
	contractsCSV := findFile(extractDir, "contracts.csv")
	itemsCSV := findFile(extractDir, "contract_items.csv")
	if contractsCSV == "" || itemsCSV == "" {
		return fmt.Errorf("archive missing contracts.csv or contract_items.csv")
	}
	genID, err := h.DB.CreateContractGeneration(ctx)
	if err != nil {
		return err
	}
	fail := func(err error) error {
		_ = h.DB.MarkContractGeneration(ctx, genID, "failed", 0, 0)
		return err
	}
	contracts, err := importContractsCSV(ctx, h, contractsCSV, genID)
	if err != nil {
		return fail(err)
	}
	items, err := importContractItemsCSV(ctx, h, itemsCSV, genID)
	if err != nil {
		return fail(err)
	}
	orphans, err := h.DB.OrphanSnapshotItems(ctx, genID)
	if err != nil {
		return fail(err)
	}
	if orphans > 0 {
		return fail(fmt.Errorf("snapshot contains %d items without a contract", orphans))
	}
	if err := h.DB.MarkContractGeneration(ctx, genID, "complete", int64(contracts), int64(items)); err != nil {
		return fail(err)
	}
	published, err := h.DB.PublishContractGeneration(ctx, genID)
	if err != nil {
		return fail(err)
	}
	if !published {
		_ = h.DB.MarkContractGeneration(ctx, genID, "superseded", 0, 0)
		return nil
	}
	h.logger().Info("published contract generation", "id", genID, "contracts", contracts, "items", items)
	return nil
}

func findFile(dir, name string) string {
	if _, err := os.Stat(filepath.Join(dir, name)); err == nil {
		return filepath.Join(dir, name)
	}
	entries, _ := os.ReadDir(dir)
	for _, e := range entries {
		if e.IsDir() {
			if p := findFile(filepath.Join(dir, e.Name()), name); p != "" {
				return p
			}
		}
	}
	return ""
}

func importContractsCSV(ctx context.Context, h HandlerDeps, path string, genID int64) (int, error) {
	rows, err := readCSVMap(path, []string{"contract_id", "region_id", "type", "price"})
	if err != nil {
		return 0, err
	}
	var batch []store.SnapshotContractRow
	count := 0
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.InsertSnapshotContracts(ctx, genID, batch)
		count += len(batch)
		batch = nil
		return err
	}
	for _, d := range rows {
		cid, _ := strconv.ParseInt(d["contract_id"], 10, 64)
		if cid <= 0 {
			return 0, fmt.Errorf("invalid contract_id")
		}
		region, _ := strconv.Atoi(d["region_id"])
		price, _ := strconv.ParseFloat(d["price"], 64)
		batch = append(batch, store.SnapshotContractRow{
			ContractID: cid, RegionID: region, Type: strOr(d["type"], "unknown"),
			Title: nilStr(d["title"]), ForCorporation: isTruthy(d["for_corporation"]),
			IssuerCorpID: nilInt64(d["issuer_corporation_id"]), IssuerID: nilInt64(d["issuer_id"]),
			DateIssued: nilTime(d["date_issued"]), DateExpired: nilTime(d["date_expired"]),
			DaysToComplete:  atoiOr(d["days_to_complete"], 0),
			StartLocationID: nilInt64(d["start_location_id"]), EndLocationID: nilInt64(d["end_location_id"]),
			StationID: nilInt64(d["station_id"]), SystemID: nilInt(d["system_id"]),
			ConstellationID: nilInt(d["constellation_id"]),
			Price:           price, Buyout: nilFloat(d["buyout"]), Reward: nilFloat(d["reward"]),
			Collateral: nilFloat(d["collateral"]), Volume: atofOr(d["volume"], 0),
			HTTPModified: nilTime(d["http_last_modified"]),
		})
		if len(batch) >= 500 {
			if err := flush(); err != nil {
				return 0, err
			}
		}
	}
	if err := flush(); err != nil {
		return 0, err
	}
	return count, nil
}

func importContractItemsCSV(ctx context.Context, h HandlerDeps, path string, genID int64) (int, error) {
	rows, err := readCSVMap(path, []string{"contract_id", "type_id", "quantity", "is_included"})
	if err != nil {
		return 0, err
	}
	var batch []store.SnapshotItemRow
	count := 0
	flush := func() error {
		if len(batch) == 0 {
			return nil
		}
		err := h.DB.InsertSnapshotItems(ctx, genID, batch)
		count += len(batch)
		batch = nil
		return err
	}
	for _, d := range rows {
		cid, _ := strconv.ParseInt(d["contract_id"], 10, 64)
		tid, _ := strconv.Atoi(d["type_id"])
		if cid <= 0 || tid <= 0 {
			return 0, fmt.Errorf("invalid contract_id or type_id")
		}
		qty, _ := strconv.Atoi(d["quantity"])
		if qty == 0 {
			qty = 1
		}
		batch = append(batch, store.SnapshotItemRow{
			ContractID: cid, TypeID: tid, Quantity: qty,
			IsIncluded:         !isFalsy(d["is_included"]),
			IsBlueprintCopy:    nilBool(d["is_blueprint_copy"]),
			MaterialEfficiency: nilInt(d["material_efficiency"]), TimeEfficiency: nilInt(d["time_efficiency"]),
			Runs: nilInt(d["runs"]), RecordID: nilInt64(d["record_id"]), ItemID: nilInt64(d["item_id"]),
			HTTPModified: nilTime(d["http_last_modified"]),
		})
		if len(batch) >= 500 {
			if err := flush(); err != nil {
				return 0, err
			}
		}
	}
	if err := flush(); err != nil {
		return 0, err
	}
	return count, nil
}

// readCSVMap parses a CSV with required headers; malformed rows fail.
func readCSVMap(path string, required []string) ([]map[string]string, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	r := csv.NewReader(f)
	r.FieldsPerRecord = -1
	r.LazyQuotes = true
	header, err := r.Read()
	if err != nil {
		return nil, fmt.Errorf("empty CSV")
	}
	have := map[string]bool{}
	for _, h := range header {
		have[h] = true
	}
	for _, req := range required {
		if !have[req] {
			return nil, fmt.Errorf("CSV missing required column %s", req)
		}
	}
	var out []map[string]string
	for {
		row, err := r.Read()
		if err == io.EOF {
			break
		}
		if err != nil {
			return nil, err
		}
		if len(row) != len(header) {
			return nil, fmt.Errorf("malformed CSV row")
		}
		m := map[string]string{}
		for i, h := range header {
			m[h] = row[i]
		}
		out = append(out, m)
	}
	return out, nil
}

func downloadURL(ctx context.Context, url string, timeout time.Duration) ([]byte, error) {
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, err
	}
	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("HTTP %d", resp.StatusCode)
	}
	return io.ReadAll(io.LimitReader(resp.Body, 2<<30))
}

func downloadFile(ctx context.Context, url, dest string, timeout time.Duration) error {
	body, err := downloadURL(ctx, url, timeout)
	if err != nil {
		return err
	}
	return os.WriteFile(dest, body, 0o600)
}

func bunzip2(data []byte) ([]byte, error) {
	r, err := bzip2.NewReader(bytes.NewReader(data), nil)
	if err != nil {
		return nil, err
	}
	return io.ReadAll(r)
}

func extractTarBz2(archive, dest string) error {
	f, err := os.Open(archive)
	if err != nil {
		return err
	}
	defer f.Close()
	bz, err := bzip2.NewReader(f, nil)
	if err != nil {
		return err
	}
	tr := tar.NewReader(bz)
	for {
		hdr, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return err
		}
		target := filepath.Join(dest, filepath.Clean("/"+hdr.Name))
		if !strings.HasPrefix(target, dest) {
			return fmt.Errorf("unsafe archive path")
		}
		switch hdr.Typeflag {
		case tar.TypeDir:
			if err := os.MkdirAll(target, 0o755); err != nil {
				return err
			}
		case tar.TypeReg:
			if err := os.MkdirAll(filepath.Dir(target), 0o755); err != nil {
				return err
			}
			out, err := os.OpenFile(target, os.O_CREATE|os.O_WRONLY|os.O_TRUNC, 0o644)
			if err != nil {
				return err
			}
			if _, err := io.Copy(out, tr); err != nil {
				out.Close()
				return err
			}
			out.Close()
		}
	}
	return nil
}

func strOr(s, def string) string {
	if s == "" {
		return def
	}
	return s
}

func nilStr(s string) *string {
	if s == "" {
		return nil
	}
	return &s
}

func nilInt64(s string) *int64 {
	if s == "" {
		return nil
	}
	if n, err := strconv.ParseInt(s, 10, 64); err == nil {
		return &n
	}
	return nil
}

func nilInt(s string) *int {
	if s == "" {
		return nil
	}
	if n, err := strconv.Atoi(s); err == nil {
		return &n
	}
	return nil
}

func nilFloat(s string) *float64 {
	if s == "" {
		return nil
	}
	if n, err := strconv.ParseFloat(s, 64); err == nil {
		return &n
	}
	return nil
}

func nilBool(s string) *bool {
	if s == "" {
		return nil
	}
	b := isTruthy(s)
	return &b
}

func nilTime(s string) *time.Time {
	if s == "" {
		return nil
	}
	for _, layout := range []string{time.RFC3339, "2006-01-02 15:04:05", "2006-01-02"} {
		if t, err := time.Parse(layout, s); err == nil {
			u := t.UTC()
			return &u
		}
	}
	return nil
}

func isTruthy(s string) bool {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "1", "true", "yes", "on":
		return true
	}
	return false
}

func isFalsy(s string) bool {
	switch strings.ToLower(strings.TrimSpace(s)) {
	case "0", "false", "no", "off", "":
		return true
	}
	return false
}

func atoiOr(s string, def int) int {
	if n, err := strconv.Atoi(s); err == nil {
		return n
	}
	return def
}

func atofOr(s string, def float64) float64 {
	if n, err := strconv.ParseFloat(s, 64); err == nil {
		return n
	}
	return def
}
