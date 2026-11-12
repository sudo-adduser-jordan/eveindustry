package store

import (
	"context"
	"time"
)

type DemoPrice struct {
	TypeID   int
	RegionID int
	SellMin  float64
	BuyMax   float64
	Average  float64
	Adjusted float64
}

type DemoIndustrySeed struct {
	Types        []SdeType
	MarketGroups []SdeMarketGroup
	Groups       []SdeGroup
	Categories   []SdeCategory
	Blueprints   []SdeBlueprint
	Materials    []SdeMaterial
	Prices       []DemoPrice
}

func (s *SQL) SeedDemoIndustry(ctx context.Context, seed DemoIndustrySeed) error {
	now := time.Now().UTC().Format("2006-01-02 15:04:05")
	for _, t := range seed.Types {
		if _, err := s.db.ExecContext(ctx, `INSERT INTO eve_types
			(type_id, name, description, group_id, market_group_id, volume, packaged_volume, mass, capacity,
			 portion_size, icon_id, graphic_id, meta_group_id, published, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON CONFLICT(type_id) DO NOTHING`,
			t.TypeID, t.Name, t.Description, t.GroupID, t.MarketGroupID, t.Volume,
			t.PackagedVolume, t.Mass, t.Capacity, t.PortionSize, t.IconID, t.GraphicID,
			t.MetaGroupID, t.Published, now, now); err != nil {
			return err
		}
	}
	for _, g := range seed.MarketGroups {
		if _, err := s.db.ExecContext(ctx, `INSERT INTO market_groups
			(market_group_id, name, description, parent_group_id, icon_id, has_types, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?)
			ON CONFLICT(market_group_id) DO NOTHING`,
			g.GroupID, g.Name, g.Description, g.ParentGroupID, g.IconID, g.HasTypes, now, now); err != nil {
			return err
		}
	}
	for _, g := range seed.Groups {
		if _, err := s.db.ExecContext(ctx, `INSERT INTO inv_groups
			(group_id, name, category_id, published, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?)
			ON CONFLICT(group_id) DO NOTHING`,
			g.GroupID, g.Name, g.CategoryID, g.Published, now, now); err != nil {
			return err
		}
	}
	for _, c := range seed.Categories {
		if _, err := s.db.ExecContext(ctx, `INSERT INTO inv_categories
			(category_id, name, published, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?)
			ON CONFLICT(category_id) DO NOTHING`,
			c.CategoryID, c.Name, c.Published, now, now); err != nil {
			return err
		}
	}
	for _, b := range seed.Blueprints {
		if _, err := s.db.ExecContext(ctx, `INSERT INTO blueprint_activities
			(blueprint_type_id, activity_id, materials, products, time, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?)
			ON CONFLICT(blueprint_type_id, activity_id) DO NOTHING`,
			b.BlueprintTypeID, b.ActivityID, b.MaterialsJSON, b.ProductsJSON, b.Time, now, now); err != nil {
			return err
		}
	}
	for _, m := range seed.Materials {
		if _, err := s.db.ExecContext(ctx, `INSERT INTO type_materials
			(type_id, material_type_id, quantity, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?)
			ON CONFLICT(type_id, material_type_id) DO NOTHING`,
			m.TypeID, m.MaterialTypeID, m.Quantity, now, now); err != nil {
			return err
		}
	}
	for _, p := range seed.Prices {
		if _, err := s.db.ExecContext(ctx, `INSERT INTO market_prices
			(type_id, region_id, average_price, adjusted_price, sell_min, buy_max, fetched_at, created_at, updated_at)
			VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
			ON CONFLICT(type_id, region_id) DO NOTHING`,
			p.TypeID, p.RegionID, p.Average, p.Adjusted, p.SellMin, p.BuyMax, now, now, now); err != nil {
			return err
		}
	}
	return nil
}

func (s *SQL) RepairDemoTypes(ctx context.Context, types []SdeType) error {
	for _, t := range types {
		if t.TypeID < 99000 {
			continue
		}
		if _, err := s.db.ExecContext(ctx, `UPDATE eve_types SET name = ?, group_id = ?, market_group_id = ?,
			portion_size = ?, meta_group_id = ?, published = ?, updated_at = CURRENT_TIMESTAMP WHERE type_id = ?`,
			t.Name, t.GroupID, t.MarketGroupID, t.PortionSize, t.MetaGroupID, t.Published, t.TypeID); err != nil {
			return err
		}
	}
	return nil
}
