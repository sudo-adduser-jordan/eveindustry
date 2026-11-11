package http

import (
	"context"
	"net/http"
	"sort"
	"time"

	"github.com/labstack/echo/v4"

	"github.com/sudo-adduser-jordan/eveindustry/internal/auth"
	"github.com/sudo-adduser-jordan/eveindustry/internal/store"
)

type DonationESI interface {
	GetCorporationWallets(ctx context.Context, characterID, corporationID int64) ([]map[string]any, error)
	GetCorporationWalletJournal(ctx context.Context, characterID, corporationID int64, division int) ([]map[string]any, error)
	ClearCorporationWalletCache(corporationID int64)
	GetCharacter(ctx context.Context, characterID int64) (map[string]any, error)
}

func registerDonations(v1 *echo.Group, d Deps) {
	if d.Donations == nil || d.Users == nil || d.Corp == nil {
		return
	}
	v1.GET("/corporation/donations", handleDonationIndex(d))
	v1.GET("/corporation/donations/status", handleDonationStatus(d))
	v1.POST("/corporation/donations/enable", handleDonationEnable(d))
	v1.POST("/corporation/donations/disable", handleDonationDisable(d))
	v1.POST("/corporation/donations/refresh", handleDonationRefresh(d))
}

func walletChars(chars []auth.Character) []auth.Character {
	var out []auth.Character
	for _, ch := range chars {
		if hasScope(ch, "esi-wallet.read_corporation_wallets.v1") {
			out = append(out, ch)
		}
	}
	return out
}

func donationStatusShape(cfg *store.DonationConfig, chars []auth.Character, uid int64) map[string]any {
	wallet := walletChars(chars)
	wl := make([]any, 0, len(wallet))
	for _, ch := range wallet {
		var corpName any
		if ch.CorporationName != nil {
			corpName = *ch.CorporationName
		}
		wl = append(wl, map[string]any{
			"character_id": ch.CharacterID, "name": ch.Name, "corporation_name": corpName,
		})
	}
	out := map[string]any{
		"enabled": false, "enabled_by_me": false,
		"corporation_name": nil, "provider_character_name": nil,
		"can_enable": len(wallet) > 0, "wallet_characters": wl,
	}
	if cfg == nil {
		return out
	}
	out["enabled"] = true
	out["enabled_by_me"] = cfg.EnabledByUserID == uid
	if cfg.CorporationName.Valid {
		out["corporation_name"] = cfg.CorporationName.String
	}
	for _, ch := range chars {
		if ch.CharacterID == cfg.CharacterID {
			out["provider_character_name"] = ch.Name
			break
		}
	}
	return out
}

func handleDonationStatus(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		cfg, err := donationConfigForUser(ctx, d, chars)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, donationStatusShape(cfg, chars, uid), nil)
	}
}

func handleDonationEnable(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		eligible := walletChars(chars)
		verrs := validationErrors{}
		raw := decodePlanBody(c, verrs)
		if raw == nil {
			raw = map[string]any{}
		}
		if len(verrs) > 0 {
			return verrs.invalid(c)
		}
		var picked *auth.Character
		if v, present := raw["character_id"]; present && v != nil {
			n, isNum := toFloat(v)
			if !isNum || n != float64(int64(n)) {
				verrs.add("character_id", "The character id field must be an integer.")
				return verrs.invalid(c)
			}
			for i := range eligible {
				if eligible[i].CharacterID == int64(n) {
					picked = &eligible[i]
					break
				}
			}
			if picked == nil {
				if len(eligible) == 0 {
					verrs.add("character_id", "No character with corporate wallet access found. Ensure your character has the esi-wallet.read_corporation_wallets.v1 scope.")
				} else {
					verrs.add("character_id", "The selected character does not have corporate wallet access.")
				}
				return verrs.invalid(c)
			}
		} else if len(eligible) > 0 {
			picked = &eligible[0]
		}
		if picked == nil {
			verrs.add("character_id", "No character with corporate wallet access found. Ensure your character has the esi-wallet.read_corporation_wallets.v1 scope.")
			return verrs.invalid(c)
		}
		var corpName string
		if picked.CorporationName != nil {
			corpName = *picked.CorporationName
		}
		if _, err := d.Donations.UpsertDonationConfig(ctx, *picked.CorporationID, corpName, picked.CharacterID, uid); err != nil {
			return dbError(c, err)
		}
		cfg, err := donationConfigForUser(ctx, d, chars)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, donationStatusShape(cfg, chars, uid), nil)
	}
}

func handleDonationDisable(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		cfg, err := donationConfigForUser(ctx, d, chars)
		if err != nil {
			return dbError(c, err)
		}
		if cfg == nil || cfg.EnabledByUserID != uid {
			return Failure(c, http.StatusForbidden, "Only the user who enabled this feature can disable it.", nil)
		}
		if err := d.Donations.DeactivateDonationConfig(ctx, cfg.CorporationID); err != nil {
			return dbError(c, err)
		}
		cfg, err = donationConfigForUser(ctx, d, chars)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, donationStatusShape(cfg, chars, uid), nil)
	}
}

func handleDonationRefresh(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		cfg, err := donationConfigForUser(ctx, d, chars)
		if err != nil {
			return dbError(c, err)
		}
		if cfg != nil && d.DonationCharacters != nil {
			d.DonationCharacters.ClearCorporationWalletCache(cfg.CorporationID)
		}
		return c.NoContent(http.StatusNoContent)
	}
}

func handleDonationIndex(d Deps) echo.HandlerFunc {
	return func(c echo.Context) error {
		qp := c.QueryParams()
		var from, to *time.Time
		if raw := firstParam(qp["date_from"]); raw != "" {
			t, err := time.Parse("2006-01-02", raw)
			if err != nil {
				verrs := validationErrors{}
				verrs.add("date_from", "The date from field must be a valid date.")
				return verrs.invalid(c)
			}
			from = &t
		}
		if raw := firstParam(qp["date_to"]); raw != "" {
			t, err := time.Parse("2006-01-02", raw)
			if err != nil {
				verrs := validationErrors{}
				verrs.add("date_to", "The date to field must be a valid date.")
				return verrs.invalid(c)
			}
			eod := time.Date(t.Year(), t.Month(), t.Day(), 23, 59, 59, 0, time.UTC)
			to = &eod
			if from != nil && t.Format("2006-01-02") < from.Format("2006-01-02") {
				verrs := validationErrors{}
				verrs.add("date_to", "The date to field must be a date after or equal to date from.")
				return verrs.invalid(c)
			}
		}
		var division *int
		if raw := firstParam(qp["division"]); raw != "" {
			n, err := parseIntProg(raw)
			if err != nil || n < 1 || n > 7 {
				verrs := validationErrors{}
				verrs.add("division", "The division field must be an integer between 1 and 7.")
				return verrs.invalid(c)
			}
			division = &n
		}
		page, perPage, ok := listPage(c)
		if !ok {
			return errHandled
		}
		uid := c.Get(string(ctxUIDKey)).(int64)
		ctx := c.Request().Context()
		chars, err := d.Users.CharactersForUser(ctx, uid)
		if err != nil {
			return dbError(c, err)
		}
		cfg, err := donationConfigForUser(ctx, d, chars)
		if err != nil {
			return dbError(c, err)
		}
		if cfg == nil {
			return Failure(c, http.StatusForbidden, "Donation tracking is not enabled for your corporation.", nil)
		}
		result, err := donationsForConfig(ctx, d, cfg, chars, from, to, division)
		if err != nil {
			return dbError(c, err)
		}
		return Success(c, http.StatusOK, pagedSlice(result.donations, page, perPage), map[string]any{
			"summary": result.summary, "pagination": paginationOf(len(result.donations), page, perPage),
		})
	}
}

func paginationOf(total, page, perPage int) map[string]any {
	return map[string]any{
		"page": page, "per_page": perPage, "total": total,
		"last_page": (total + perPage - 1) / perPage,
	}
}

type donationResult struct {
	donations []any
	summary   map[string]any
}

func donationsForConfig(ctx context.Context, d Deps, cfg *store.DonationConfig, chars []auth.Character, from, to *time.Time, division *int) (donationResult, error) {
	now := time.Now().UTC()
	defFrom := time.Date(now.Year(), now.Month(), now.Day(), 0, 0, 0, 0, time.UTC).AddDate(0, 0, -30)
	defTo := time.Date(now.Year(), now.Month(), now.Day(), 23, 59, 59, 0, time.UTC)
	if from == nil {
		from = &defFrom
	} else {
		f := time.Date(from.Year(), from.Month(), from.Day(), 0, 0, 0, 0, time.UTC)
		from = &f
	}
	if to == nil {
		to = &defTo
	}
	if from.After(*to) {
		f := time.Date(to.Year(), to.Month(), to.Day(), 0, 0, 0, 0, time.UTC)
		t := time.Date(from.Year(), from.Month(), from.Day(), 23, 59, 59, 0, time.UTC)
		from, to = &f, &t
	}
	empty := donationResult{donations: []any{}, summary: map[string]any{
		"corporation_id": cfg.CorporationID, "corporation_name": corpNameStr(cfg),
		"date_from": from.Format("2006-01-02"), "date_to": to.Format("2006-01-02"),
		"total": 0.0, "wallet_divisions": []any{},
	}}
	if d.DonationCharacters == nil {
		return empty, nil
	}
	var provider *auth.Character
	for i := range chars {
		if chars[i].CharacterID == cfg.CharacterID {
			provider = &chars[i]
			break
		}
	}
	if provider == nil {
		return empty, nil
	}
	wallets, err := d.DonationCharacters.GetCorporationWallets(ctx, provider.CharacterID, cfg.CorporationID)
	if err != nil {
		return emptyResult2(cfg, from, to), nil
	}
	divisions := []map[string]any{}
	if len(wallets) == 0 {
		divisions = []map[string]any{{"division": 1, "name": "Master Wallet"}}
	} else {
		for _, w := range wallets {
			div, _ := int64OfJob(w["division"])
			if div == 0 {
				continue
			}
			name := "Division " + itoaJob(int(div))
			if div == 1 {
				name = "Master Wallet"
			}
			divRow := map[string]any{"division": int(div), "name": name}
			if b, ok := numFloatJob(w["balance"]); ok {
				divRow["balance"] = b
			}
			divisions = append(divisions, divRow)
		}
	}
	type donation struct {
		id       any
		date     string
		amount   float64
		party    int64
		reason   string
		balance  any
		division int
		wallet   string
	}
	var all []donation
	for _, dv := range divisions {
		div := dv["division"].(int)
		if division != nil && div != *division {
			continue
		}
		journal, err := d.DonationCharacters.GetCorporationWalletJournal(ctx, provider.CharacterID, cfg.CorporationID, div)
		if err != nil {
			continue
		}
		for _, e := range journal {
			if strOfJob(e["ref_type"]) != "player_donation" {
				continue
			}
			dt, ok := parseJobTime(strOfJob(e["date"]))
			if !ok || dt.Before(*from) || dt.After(*to) {
				continue
			}
			amt, _ := numFloatJob(e["amount"])
			if amt <= 0 {
				continue
			}
			party, _ := int64OfJob(e["first_party_id"])
			if party == 0 {
				continue
			}
			all = append(all, donation{
				id: e["id"], date: dt.Format("2006-01-02 15:04:05"), amount: amt,
				party: party, reason: strOfJob(e["reason"]), balance: e["balance"],
				division: div, wallet: dv["name"].(string),
			})
		}
	}
	byParty := map[int64][]donation{}
	for _, dn := range all {
		byParty[dn.party] = append(byParty[dn.party], dn)
	}
	type group struct {
		total float64
		row   map[string]any
	}
	var groups []group
	var grand float64
	for party, list := range byParty {
		var total float64
		rows := make([]any, 0, len(list))
		for _, dn := range list {
			total += dn.amount
			rows = append(rows, map[string]any{
				"id": dn.id, "date": dn.date, "amount": dn.amount,
				"first_party_id": party, "first_party_name": nil,
				"reason": dn.reason, "balance": dn.balance,
				"wallet_division": dn.division, "wallet_name": dn.wallet,
			})
		}
		name := "Character #" + itoaJob(int(party))
		if info, err := d.DonationCharacters.GetCharacter(ctx, party); err == nil && info != nil {
			if n, ok := info["name"].(string); ok && n != "" {
				name = n
			}
		}
		grand += total
		groups = append(groups, group{total: total, row: map[string]any{
			"donator_id": party, "donator_name": name,
			"portrait_url": "https://images.evetech.net/characters/" + itoaJob(int(party)) + "/portrait?size=64",
			"total_amount": total, "donation_count": len(list), "donations": rows,
		}})
	}
	sort.Slice(groups, func(i, j int) bool { return groups[i].total > groups[j].total })
	donations := make([]any, 0, len(groups))
	for _, g := range groups {
		donations = append(donations, g.row)
	}
	divsOut := make([]any, 0, len(divisions))
	for _, dv := range divisions {
		row := map[string]any{"division": dv["division"], "name": dv["name"], "balance": nil}
		if b, ok := dv["balance"]; ok {
			row["balance"] = b
		}
		divsOut = append(divsOut, row)
	}
	return donationResult{donations: donations, summary: map[string]any{
		"corporation_id": cfg.CorporationID, "corporation_name": corpNameStr(cfg),
		"date_from": from.Format("2006-01-02"), "date_to": to.Format("2006-01-02"),
		"total": grand, "wallet_divisions": divsOut,
	}}, nil
}

func emptyResult2(cfg *store.DonationConfig, from, to *time.Time) donationResult {
	return donationResult{donations: []any{}, summary: map[string]any{
		"corporation_id": cfg.CorporationID, "corporation_name": corpNameStr(cfg),
		"date_from": from.Format("2006-01-02"), "date_to": to.Format("2006-01-02"),
		"total": 0.0, "wallet_divisions": []any{},
	}}
}

func corpNameStr(cfg *store.DonationConfig) any {
	if cfg.CorporationName.Valid {
		return cfg.CorporationName.String
	}
	return nil
}

func donationConfigForUser(ctx context.Context, d Deps, chars []auth.Character) (*store.DonationConfig, error) {
	var ids []int64
	seen := map[int64]bool{}
	for _, ch := range chars {
		if ch.CorporationID == nil || *ch.CorporationID == 0 || seen[*ch.CorporationID] {
			continue
		}
		seen[*ch.CorporationID] = true
		ids = append(ids, *ch.CorporationID)
	}
	return d.Donations.DonationConfigForCorps(ctx, ids)
}
