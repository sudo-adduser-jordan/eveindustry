package main

import (
	"testing"
)

func TestParseJobArgs(t *testing.T) {
	if _, _, err := parseJobArgs(nil); err == nil {
		t.Fatal("empty args accepted")
	}
	if _, _, err := parseJobArgs([]string{"  "}); err == nil {
		t.Fatal("blank name accepted")
	}
	name, payload, err := parseJobArgs([]string{"demo-reset"})
	if err != nil || name != "demo-reset" || payload != nil {
		t.Fatalf("name only = %q %q %v", name, payload, err)
	}
	name, payload, err = parseJobArgs([]string{"sde-import", `{"force":true}`})
	if err != nil || name != "sde-import" || string(payload) != `{"force":true}` {
		t.Fatalf("with payload = %q %q %v", name, payload, err)
	}
	if _, _, err := parseJobArgs([]string{"sde-import", "{nope"}); err == nil {
		t.Fatal("malformed payload accepted")
	}
}

func TestCheckJobGate(t *testing.T) {
	t.Setenv("DEMO_ENABLED", "false")
	if err := checkJobGate("demo-reset"); err == nil {
		t.Fatal("demo gate open with DEMO_ENABLED=false")
	}
	t.Setenv("DEMO_ENABLED", "true")
	if err := checkJobGate("demo-reset"); err != nil {
		t.Fatalf("demo gate closed with DEMO_ENABLED=true: %v", err)
	}
	if err := checkJobGate("sde-import"); err != nil {
		t.Fatalf("unrelated job gated: %v", err)
	}
}
