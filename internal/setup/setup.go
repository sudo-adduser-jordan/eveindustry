package setup

// Status mirrors SetupStatusService::status() in
// app/Services/SetupStatusService.php: whether a fresh install has its game
// data yet (SDE types, global prices, first completed order sweep).
type Status struct {
	Ready bool  `json:"ready"`
	Steps Steps `json:"steps"`
}

// Steps mirrors the steps array in the PHP service.
type Steps struct {
	SDE    bool `json:"sde"`
	Prices bool `json:"prices"`
	Orders bool `json:"orders"`
}

// Probe reports whether one setup step is complete. A nil or failing probe
// counts as incomplete, matching the PHP try/catch that defaults to false.
type Probe func() bool

// Resolve builds the status payload from three step probes.
func Resolve(sde, prices, orders Probe) Status {
	steps := Steps{
		SDE:    probe(sde),
		Prices: probe(prices),
		Orders: probe(orders),
	}
	return Status{Ready: steps.SDE && steps.Prices && steps.Orders, Steps: steps}
}

func probe(p Probe) bool {
	if p == nil {
		return false
	}
	ok := false
	func() {
		defer func() { _ = recover() }()
		ok = p()
	}()
	return ok
}
