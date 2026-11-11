package jobs

import (
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
)

func TestWireDemoNoDeps(t *testing.T) {
	// Zero or nil handlers are a no-op, never a panic.
	WireDemo(&HandlerDeps{})
	WireDemo(nil)
	// A character client without a database stays unwired.
	cc := esi.NewCharacterClient(nil, nil, nil, nil, nil)
	WireDemo(&HandlerDeps{ESIChars: cc})
	if cc.Demo != nil {
		t.Fatal("demo wired without a database")
	}
}
