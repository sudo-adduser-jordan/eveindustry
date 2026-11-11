package jobs

import (
	"testing"

	"github.com/sudo-adduser-jordan/eveindustry/internal/esi"
)

func TestWireDemoNoDeps(t *testing.T) {
	WireDemo(&HandlerDeps{})
	WireDemo(nil)
	cc := esi.NewCharacterClient(nil, nil, nil, nil, nil)
	WireDemo(&HandlerDeps{ESIChars: cc})
	if cc.Demo != nil {
		t.Fatal("demo wired without a database")
	}
}
