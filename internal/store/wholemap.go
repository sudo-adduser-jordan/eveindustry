package store

import (
	"context"
	"database/sql"
	"time"
)

// WholemapSignature mirrors one wholemap_signatures row.
type WholemapSignature struct {
	ID           int64     `db:"id"`
	UserID       int64     `db:"user_id"`
	SignatureID  *string   `db:"signature_id"`
	SystemID     int       `db:"system_id"`
	SystemName   *string   `db:"system_name"`
	Type         string    `db:"type"`
	Name         *string   `db:"name"`
	Bookmark     *string   `db:"bookmark"`
	Description  *string   `db:"description"`
	Locked       bool      `db:"locked"`
	CreatedBy    *string   `db:"created_by"`
	UpdatedBy    *string   `db:"updated_by"`
	CreatedAt    time.Time `db:"created_at"`
	UpdatedAt    time.Time `db:"updated_at"`
}

// WormholeConnection mirrors one wormhole_connections row.
type WormholeConnection struct {
	ID            int64     `db:"id"`
	UserID        int64     `db:"user_id"`
	SignatureID   int64     `db:"signature_id"`
	FromSystemID  int       `db:"from_system_id"`
	FromSystemName *string  `db:"from_system_name"`
	ToSystemID    *int      `db:"to_system_id"`
	ToSystemName  *string   `db:"to_system_name"`
	WormholeType  *string   `db:"wormhole_type"`
	Size          string    `db:"size"`
	Life          string    `db:"life"`
	Mass          string    `db:"mass"`
	CreatedAt     time.Time `db:"created_at"`
	UpdatedAt     time.Time `db:"updated_at"`
}

// WholemapNote mirrors one wholemap_system_notes row.
type WholemapNote struct {
	ID         int64     `db:"id"`
	UserID     int64     `db:"user_id"`
	SystemID   int       `db:"system_id"`
	SystemName *string   `db:"system_name"`
	Status     string    `db:"status"`
	Comment    *string   `db:"comment"`
	CreatedAt  time.Time `db:"created_at"`
	UpdatedAt  time.Time `db:"updated_at"`
}

// WormholeJumpLog mirrors one wormhole_jump_logs row.
type WormholeJumpLog struct {
	ID          int64     `db:"id"`
	ConnectionID int64    `db:"connection_id"`
	UserID      int64     `db:"user_id"`
	CharacterID *int64    `db:"character_id"`
	CharacterName *string  `db:"character_name"`
	ShipTypeID  *int      `db:"ship_type_id"`
	ShipTypeName *string   `db:"ship_type_name"`
	ShipMass    *float64  `db:"ship_mass"`
	JumpedAt    time.Time `db:"jumped_at"`
}

// WholemapStore covers wormhole-mapper writes. Ownership is the handler's
// job (403 for foreign rows, like the WholemapRecordPolicy).
type WholemapStore interface {
	SignatureByID(ctx context.Context, id int64) (*WholemapSignature, error)
	SignatureByKey(ctx context.Context, userID int64, systemID int, signatureID string) (*WholemapSignature, error)
	CreateSignature(ctx context.Context, s WholemapSignature) (*WholemapSignature, error)
	UpdateSignature(ctx context.Context, s WholemapSignature) (*WholemapSignature, error)
	DeleteSignature(ctx context.Context, id int64) error
	ConnectionByID(ctx context.Context, id int64) (*WormholeConnection, error)
	CreateConnection(ctx context.Context, c WormholeConnection) (*WormholeConnection, error)
	UpdateConnection(ctx context.Context, c WormholeConnection) (*WormholeConnection, error)
	DeleteConnection(ctx context.Context, id int64) error
	ConnectionForSignature(ctx context.Context, signatureID int64) (*WormholeConnection, error)
	CreateJumpLog(ctx context.Context, j WormholeJumpLog) (*WormholeJumpLog, error)
	NoteByID(ctx context.Context, id int64) (*WholemapNote, error)
	UpsertNote(ctx context.Context, n WholemapNote) (*WholemapNote, bool, error)
	DeleteNote(ctx context.Context, id int64) error
}

const wholemapSignatureCols = `id, user_id, signature_id, system_id, system_name, type, name,
	bookmark, description, locked, created_by, updated_by, created_at, updated_at`

const wormholeConnectionCols = `id, user_id, signature_id, from_system_id, from_system_name,
	to_system_id, to_system_name, wormhole_type, size, life, mass, created_at, updated_at`

const wholemapNoteCols = `id, user_id, system_id, system_name, status, comment, created_at, updated_at`

const jumpLogCols = `id, connection_id, user_id, character_id, character_name,
	ship_type_id, ship_type_name, ship_mass, jumped_at`

func (s *SQL) SignatureByID(ctx context.Context, id int64) (*WholemapSignature, error) {
	var out WholemapSignature
	err := s.db.GetContext(ctx, &out, `SELECT `+wholemapSignatureCols+` FROM wholemap_signatures WHERE id = ?`, id)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) SignatureByKey(ctx context.Context, userID int64, systemID int, signatureID string) (*WholemapSignature, error) {
	var out WholemapSignature
	err := s.db.GetContext(ctx, &out, `SELECT `+wholemapSignatureCols+`
		FROM wholemap_signatures WHERE user_id = ? AND system_id = ? AND signature_id = ?`,
		userID, systemID, signatureID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) CreateSignature(ctx context.Context, sig WholemapSignature) (*WholemapSignature, error) {
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO wholemap_signatures
			(user_id, signature_id, system_id, system_name, type, name, bookmark,
			 description, locked, created_by, updated_by, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
		sig.UserID, sig.SignatureID, sig.SystemID, sig.SystemName, sig.Type, sig.Name,
		sig.Bookmark, sig.Description, sig.Locked, sig.CreatedBy, sig.UpdatedBy)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return s.SignatureByID(ctx, id)
}

func (s *SQL) UpdateSignature(ctx context.Context, sig WholemapSignature) (*WholemapSignature, error) {
	_, err := s.db.ExecContext(ctx, `
		UPDATE wholemap_signatures SET signature_id = ?, type = ?, name = ?, bookmark = ?,
			description = ?, locked = ?, updated_by = ?, updated_at = NOW() WHERE id = ?`,
		sig.SignatureID, sig.Type, sig.Name, sig.Bookmark, sig.Description,
		sig.Locked, sig.UpdatedBy, sig.ID)
	if err != nil {
		return nil, err
	}
	return s.SignatureByID(ctx, sig.ID)
}

func (s *SQL) DeleteSignature(ctx context.Context, id int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM wholemap_signatures WHERE id = ?`, id)
	return err
}

func (s *SQL) ConnectionByID(ctx context.Context, id int64) (*WormholeConnection, error) {
	var out WormholeConnection
	err := s.db.GetContext(ctx, &out, `SELECT `+wormholeConnectionCols+` FROM wormhole_connections WHERE id = ?`, id)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) CreateConnection(ctx context.Context, c WormholeConnection) (*WormholeConnection, error) {
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO wormhole_connections
			(user_id, signature_id, from_system_id, from_system_name, to_system_id,
			 to_system_name, wormhole_type, size, life, mass, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())`,
		c.UserID, c.SignatureID, c.FromSystemID, c.FromSystemName, c.ToSystemID,
		c.ToSystemName, c.WormholeType, c.Size, c.Life, c.Mass)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	return s.ConnectionByID(ctx, id)
}

func (s *SQL) UpdateConnection(ctx context.Context, c WormholeConnection) (*WormholeConnection, error) {
	_, err := s.db.ExecContext(ctx, `
		UPDATE wormhole_connections SET to_system_id = ?, to_system_name = ?, wormhole_type = ?,
			size = ?, life = ?, mass = ?, updated_at = NOW() WHERE id = ?`,
		c.ToSystemID, c.ToSystemName, c.WormholeType, c.Size, c.Life, c.Mass, c.ID)
	if err != nil {
		return nil, err
	}
	return s.ConnectionByID(ctx, c.ID)
}

func (s *SQL) DeleteConnection(ctx context.Context, id int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM wormhole_connections WHERE id = ?`, id)
	return err
}

func (s *SQL) ConnectionForSignature(ctx context.Context, signatureID int64) (*WormholeConnection, error) {
	var out WormholeConnection
	err := s.db.GetContext(ctx, &out, `SELECT `+wormholeConnectionCols+`
		FROM wormhole_connections WHERE signature_id = ?`, signatureID)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) CreateJumpLog(ctx context.Context, j WormholeJumpLog) (*WormholeJumpLog, error) {
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO wormhole_jump_logs
			(connection_id, user_id, character_id, character_name, ship_type_id,
			 ship_type_name, ship_mass, jumped_at, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())`,
		j.ConnectionID, j.UserID, j.CharacterID, j.CharacterName, j.ShipTypeID,
		j.ShipTypeName, j.ShipMass)
	if err != nil {
		return nil, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, err
	}
	var out WormholeJumpLog
	err = s.db.GetContext(ctx, &out, `SELECT `+jumpLogCols+` FROM wormhole_jump_logs WHERE id = ?`, id)
	return &out, err
}

func (s *SQL) NoteByID(ctx context.Context, id int64) (*WholemapNote, error) {
	var out WholemapNote
	err := s.db.GetContext(ctx, &out, `SELECT `+wholemapNoteCols+` FROM wholemap_system_notes WHERE id = ?`, id)
	if err == sql.ErrNoRows {
		return nil, nil
	}
	return &out, err
}

func (s *SQL) UpsertNote(ctx context.Context, n WholemapNote) (*WholemapNote, bool, error) {
	var existing WholemapNote
	err := s.db.GetContext(ctx, &existing, `SELECT `+wholemapNoteCols+`
		FROM wholemap_system_notes WHERE user_id = ? AND system_id = ?`, n.UserID, n.SystemID)
	if err != nil && err != sql.ErrNoRows {
		return nil, false, err
	}
	if err == nil {
		_, err = s.db.ExecContext(ctx, `UPDATE wholemap_system_notes
			SET system_name = ?, status = ?, comment = ?, updated_at = NOW() WHERE id = ?`,
			n.SystemName, n.Status, n.Comment, existing.ID)
		if err != nil {
			return nil, false, err
		}
		out, err := s.NoteByID(ctx, existing.ID)
		return out, false, err
	}
	res, err := s.db.ExecContext(ctx, `
		INSERT INTO wholemap_system_notes
			(user_id, system_id, system_name, status, comment, created_at, updated_at)
		VALUES (?, ?, ?, ?, ?, NOW(), NOW())`,
		n.UserID, n.SystemID, n.SystemName, n.Status, n.Comment)
	if err != nil {
		return nil, false, err
	}
	id, err := res.LastInsertId()
	if err != nil {
		return nil, false, err
	}
	out, err := s.NoteByID(ctx, id)
	return out, true, err
}

func (s *SQL) DeleteNote(ctx context.Context, id int64) error {
	_, err := s.db.ExecContext(ctx, `DELETE FROM wholemap_system_notes WHERE id = ?`, id)
	return err
}
