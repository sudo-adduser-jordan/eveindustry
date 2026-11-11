package setup

type Status struct {
	Ready bool  `json:"ready"`
	Steps Steps `json:"steps"`
}

type Steps struct {
	SDE    bool `json:"sde"`
	Prices bool `json:"prices"`
	Orders bool `json:"orders"`
}

type Probe func() bool

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
