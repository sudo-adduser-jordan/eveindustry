package store

import (
	"context"
	"database/sql"
	"time"
)

// Sponsor mirrors one sponsors row.
type Sponsor struct {
	ID        int64     `db:"id"`
	Name      string    `db:"name"`
	URL       string    `db:"url"`
	ImagePath string    `db:"image_path"`
	IsActive  bool      `db:"is_active"`
	SortOrder int       `db:"sort_order"`
	CreatedAt time.Time `db:"created_at"`
	UpdatedAt time.Time `db:"updated_at"`
}

// SponsorStore covers the admin sponsor manager.
type SponsorStore interface {
	Sponsors(ctx context.Context) ([]Sponsor, error)
	SponsorByID(ctx context.Context, id int64) (*Sponsor, error)
	CreateSponsor(ctx context.Context, s Sponsor) (*Sponsor, error)
	UpdateSponsor(ctx context.Context, s Sponsor) (*Sponsor, error)
	DeleteSponsor(ctx context.Context, id int64) error
}

const sponsorCols = `id, name, url, image_path, is_active, sort_order, created_at, updated_at`

// Sponsors lists all sponsors ordered (active first, sort_order, name).
func (s *SQL) Sponsors(ctx context.Context) ([]Sponsor, error) {
	var out []Sponsor
	err := s.db.SelectContext(ctx, &out, `SELECT `+sponsorCols+`
		FROM sponsors ORDER BY is_active DESC, sort_order ASC, name ASC`)
	if out == nil {
		out = []Sponsor{}
	}
	return out, err
}

func (s *SQL) SponsorByID(ctx context.Context, id int64) (*Sponsor, error) {
	var out Sponsor
	err := s.db.GetContext(ctx, &out, `SELECT `+sponsorCols+`
		FROM sponsors WHERE id = ?`, id)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

// CreateSponsor inserts a sponsor row.
func (s *SQL) CreateSponsor(ctx context.Context, sp Sponsor) (*Sponsor, error) {
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO sponsors (name, url, image_path, is_active, sort_order, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)`,
		sp.Name, sp.URL, sp.ImagePath, sp.IsActive, sp.SortOrder)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return s.SponsorByID(ctx, id)
}

// UpdateSponsor applies the sponsor edit.
func (s *SQL) UpdateSponsor(ctx context.Context, sp Sponsor) (*Sponsor, error) {
	if _, err := s.db.ExecContext(ctx, `UPDATE sponsors SET name = ?, url = ?,
		image_path = ?, is_active = ?, sort_order = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
		sp.Name, sp.URL, sp.ImagePath, sp.IsActive, sp.SortOrder, sp.ID); err != nil {
		return nil, err
	}
	return s.SponsorByID(ctx, sp.ID)
}

func (s *SQL) DeleteSponsor(ctx context.Context, id int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM sponsors WHERE id = ?`, id)
	return err
}

// AdminStats mirrors AdminStatsService::stats().
type AdminStats struct {
	Users        int `db:"users"`
	Characters   int `db:"characters"`
	ActiveUsers  int `db:"active_users"`
	RecentLogins int `db:"recent_logins"`
	NewWeek      int `db:"new_users_week"`
	NewMonth     int `db:"new_users_month"`
	Logged24h    int `db:"logged_in_24h"`
}

// AdminSeriesPoint is one day-count pair.
type AdminSeriesPoint struct {
	Date  string `db:"date"`
	Count int    `db:"count"`
}

// AdminStats loads the dashboard counters.
func (s *SQL) AdminStats(ctx context.Context) (*AdminStats, error) {
	var out AdminStats
	err := s.db.GetContext(ctx, &out, `
		SELECT
			(SELECT COUNT(*) FROM users) AS users,
			(SELECT COUNT(*) FROM characters) AS characters,
			(SELECT COUNT(*) FROM users WHERE is_active = 1) AS active_users,
			(SELECT COUNT(*) FROM users WHERE last_login_at IS NOT NULL AND last_login_at >= datetime('now', '-7 days')) AS recent_logins,
			(SELECT COUNT(*) FROM users WHERE created_at >= datetime('now', '-7 days')) AS new_users_week,
			(SELECT COUNT(*) FROM users WHERE created_at >= datetime('now', '-30 days')) AS new_users_month,
			(SELECT COUNT(*) FROM users WHERE last_login_at IS NOT NULL AND last_login_at >= datetime('now', '-1 days')) AS logged_in_24h`)
	if err != nil {
		return nil, err
	}
	return &out, nil
}

// AdminRegistrationSeries ports registrationSeries (days with signups only).
func (s *SQL) AdminRegistrationSeries(ctx context.Context) ([]AdminSeriesPoint, error) {
	var out []AdminSeriesPoint
	err := s.db.SelectContext(ctx, &out, `
		SELECT DATE(created_at) AS date, COUNT(*) AS count FROM users
		WHERE created_at >= datetime('now', '-30 days')
		GROUP BY date ORDER BY date`)
	if out == nil {
		out = []AdminSeriesPoint{}
	}
	return out, err
}

// AdminLoginSeries ports loginSeries.
func (s *SQL) AdminLoginSeries(ctx context.Context) ([]AdminSeriesPoint, error) {
	var out []AdminSeriesPoint
	err := s.db.SelectContext(ctx, &out, `
		SELECT DATE(last_login_at) AS date, COUNT(*) AS count FROM users
		WHERE last_login_at IS NOT NULL AND last_login_at >= datetime('now', '-30 days')
		GROUP BY date ORDER BY date`)
	if out == nil {
		out = []AdminSeriesPoint{}
	}
	return out, err
}
