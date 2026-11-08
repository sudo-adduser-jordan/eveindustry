package main

import (
	"fmt"
	"os"
)

// Phase 0 placeholder for the golang-migrate runner.
//
// The 74 Laravel migrations in database/migrations are still authoritative;
// each one gets a ported *.up.sql / *.down.sql pair under migrations/ in
// Phase 1, at which point this command will run them with golang-migrate and
// replace `php artisan migrate` for the Go services.
func main() {
	fmt.Fprintln(os.Stderr, "migrate: no Go migrations yet (Phase 1 ports database/migrations); nothing to do")
}
