package calc

import (
	"testing"
)

func TestInventionProbability(t *testing.T) {
	// 0.3 * (1 + 5/40 + 5/30 + 5/30) = 0.3 * 1.458333 = 0.4375
	if got := InventionProbability(0.3, 5, 5, 5, 1.0); got != 0.4375 {
		t.Fatalf("got %v", got)
	}
	if got := InventionProbability(0.9, 5, 5, 5, 1.8); got != 1.0 {
		t.Fatalf("clamped: %v", got)
	}
	if got := InventionProbability(0, 0, 0, 0, 1.0); got != 0 {
		t.Fatalf("zero: %v", got)
	}
}

func TestCalculateT2BPC(t *testing.T) {
	bpc := CalculateT2BPC(4, DecryptorByKey("none"))
	if bpc.ME != 2 || bpc.TE != 4 || bpc.Runs != 4 {
		t.Fatalf("got %+v", bpc)
	}
	bpc = CalculateT2BPC(4, DecryptorByKey("augmentation"))
	// ME 2-2=0, TE 4-2=2, runs 4+9=13
	if bpc.ME != 0 || bpc.TE != 2 || bpc.Runs != 13 {
		t.Fatalf("got %+v", bpc)
	}
	if DecryptorByKey("bogus").Key != "none" {
		t.Fatal("unknown decryptor must fall back to none")
	}
	if len(Decryptors()) != 7 {
		t.Fatalf("want 7 decryptors, got %d", len(Decryptors()))
	}
}

func TestCombineMeBonuses(t *testing.T) {
	// 1% role + 3.8% rig -> 1-(0.99*0.962) = 4.762%
	if got := CombineMeBonuses(1.0, 3.8); got < 4.761 || got > 4.763 {
		t.Fatalf("got %v", got)
	}
	if got := ManufacturingME("raitaru", "t1", "lowsec"); got < 4.76 || got > 4.77 {
		t.Fatalf("raitaru t1 lowsec: %v", got)
	}
	// nullsec T2 rig: 24 * 2.1 = 50.4 + 3 role
	if got := ManufacturingTE("sotiyo", "t2", "nullsec"); got < 53.399 || got > 53.401 {
		t.Fatalf("got %v", got)
	}
	if got := ReactionME("t2", "nullsec"); got < 2.639 || got > 2.641 {
		t.Fatalf("got %v", got)
	}
}
