package store

import (
	"context"
	"database/sql"
	"time"
)

type AdminUser struct {
	ID                int64          `db:"id"`
	Name              string         `db:"name"`
	Email             sql.NullString `db:"email"`
	IsAdmin           bool           `db:"is_admin"`
	IsSuperAdmin      bool           `db:"is_super_admin"`
	IsActive          bool           `db:"is_active"`
	IsStockManager    bool           `db:"is_stock_manager_enabled"`
	LastLoginAt       sql.NullTime   `db:"last_login_at"`
	CreatedAt         time.Time      `db:"created_at"`
	MainCharacterID   sql.NullInt64  `db:"main_character_id"`
	MainCharacterName sql.NullString `db:"main_character_name"`
	CharacterCount    int            `db:"character_count"`
	PasswordHash      sql.NullString `db:"password"`
}

type AdminUserStore interface {
	AdminUsers(ctx context.Context, search, sort, dir string, limit, offset int) ([]AdminUser, int, error)
	AdminUserByID(ctx context.Context, id int64) (*AdminUser, error)
	UpdateAdminUserFlags(ctx context.Context, id int64, active, admin, stockManager *bool) (*AdminUser, error)
	DeleteAdminUser(ctx context.Context, id int64) error
	AdminUserEmailTaken(ctx context.Context, email string, exceptID int64) (bool, error)
	UpdateAdminAccount(ctx context.Context, id int64, email string, passwordHash *string) (*AdminUser, error)
}

type AdminStatsStore interface {
	AdminStats(ctx context.Context) (*AdminStats, error)
	AdminRegistrationSeries(ctx context.Context) ([]AdminSeriesPoint, error)
	AdminLoginSeries(ctx context.Context) ([]AdminSeriesPoint, error)
}

var adminSorts = map[string]bool{
	"name": true, "email": true, "is_admin": true,
	"is_active": true, "last_login_at": true, "created_at": true,
}

func (s *SQL) AdminUsers(ctx context.Context, search, sort, dir string, limit, offset int) ([]AdminUser, int, error) {
	if !adminSorts[sort] {
		sort = "created_at"
	}
	if dir != "asc" {
		dir = "desc"
	}
	filter := ""
	var args []any
	if search != "" {
		filter = `WHERE (u.name LIKE ? OR u.email LIKE ? OR EXISTS
			(SELECT 1 FROM characters c WHERE c.user_id = u.id AND c.name LIKE ?))`
		like := "%" + search + "%"
		args = append(args, like, like, like)
	}
	var total int
	if err := s.db.GetContext(ctx, &total, `SELECT COUNT(*) FROM users u `+filter, args...); err != nil {
		return nil, 0, err
	}
	qargs := append(append([]any{}, args...), limit, offset)
	var out []AdminUser
	err := s.db.SelectContext(ctx, &out, `
		SELECT u.id, u.name, u.email, u.is_admin, u.is_super_admin, u.is_active,
			u.is_stock_manager_enabled, u.last_login_at, u.created_at, NULL AS password,
			m.character_id AS main_character_id, m.name AS main_character_name,
			(SELECT COUNT(*) FROM characters c WHERE c.user_id = u.id) AS character_count
		FROM users u
		LEFT JOIN characters m ON m.user_id = u.id AND m.is_main = 1
		`+filter+` ORDER BY u.`+sort+` `+dir+` LIMIT ? OFFSET ?`, qargs...)
	if out == nil {
		out = []AdminUser{}
	}
	return out, total, err
}

func (s *SQL) AdminUserByID(ctx context.Context, id int64) (*AdminUser, error) {
	var out AdminUser
	err := s.db.GetContext(ctx, &out, `
		SELECT u.id, u.name, u.email, u.is_admin, u.is_super_admin, u.is_active,
			u.is_stock_manager_enabled, u.last_login_at, u.created_at, u.password,
			m.character_id AS main_character_id, m.name AS main_character_name,
			(SELECT COUNT(*) FROM characters c WHERE c.user_id = u.id) AS character_count
		FROM users u
		LEFT JOIN characters m ON m.user_id = u.id AND m.is_main = 1
		WHERE u.id = ?`, id)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) UpdateAdminUserFlags(ctx context.Context, id int64, active, admin, stockManager *bool) (*AdminUser, error) {
	set := ""
	var args []any
	if active != nil {
		set += "is_active = ?, "
		args = append(args, *active)
	}
	if admin != nil {
		set += "is_admin = ?, "
		args = append(args, *admin)
	}
	if stockManager != nil {
		set += "is_stock_manager_enabled = ?, "
		args = append(args, *stockManager)
	}
	if set != "" {
		args = append(args, id)
		if _, err := s.db.ExecContext(ctx, `UPDATE users SET `+set+`updated_at = CURRENT_TIMESTAMP WHERE id = ?`, args...); err != nil {
			return nil, err
		}
	}
	return s.AdminUserByID(ctx, id)
}

func (s *SQL) DeleteAdminUser(ctx context.Context, id int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM users WHERE id = ?`, id)
	return err
}

func (s *SQL) AdminUserEmailTaken(ctx context.Context, email string, exceptID int64) (bool, error) {
	var n int
	err := s.db.GetContext(ctx, &n, `SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?`, email, exceptID)
	return n > 0, err
}

func (s *SQL) UpdateAdminAccount(ctx context.Context, id int64, email string, passwordHash *string) (*AdminUser, error) {
	if passwordHash != nil {
		_, err := s.db.ExecContext(ctx, `UPDATE users SET email = ?, password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`,
			email, *passwordHash, id)
		if err != nil {
			return nil, err
		}
	} else {
		_, err := s.db.ExecContext(ctx, `UPDATE users SET email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?`, email, id)
		if err != nil {
			return nil, err
		}
	}
	return s.AdminUserByID(ctx, id)
}
