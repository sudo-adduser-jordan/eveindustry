package migrations

import "embed"

// FS holds the ported Laravel migrations: one <version>_<name>.up.sql /
// .down.sql pair per example/database/migrations/*.php, applied in filename
// order like Laravel.
//
//go:embed *.up.sql *.down.sql
var FS embed.FS
