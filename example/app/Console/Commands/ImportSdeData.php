<?php

namespace App\Console\Commands;

use App\Services\SdeImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ImportSdeData extends Command
{
    protected $signature = 'sde:import
        {--check       : Only check if a new SDE version is available; do not import}
        {--force       : Skip the checksum check and always import}
        {--dataset=all : Datasets to import (comma-separated): types, marketgroups, groups, categories, stations, systems, stargates, materials, blueprints, all}';

    protected $description = 'Import EVE Online Static Data Export (SDE) JSONL ZIP data into the database. '
        .'By default only imports when the remote checksum differs from the stored one.';

    /** Human-readable dataset labels used in output. */
    private const DATASET_LABELS = [
        'systems' => 'Solar systems    (mapSolarSystems.jsonl)',
        'types' => 'Item types       (types.jsonl)',
        'marketgroups' => 'Market groups    (marketGroups.jsonl)',
        'groups' => 'Item groups      (groups.jsonl)',
        'categories' => 'Item categories  (categories.jsonl)',
        'stations' => 'NPC stations     (npcStations.jsonl)',
        'stargates' => 'Stargates        (mapStargates.jsonl)',
        'materials' => 'Type materials   (typeMaterials.jsonl)',
        'blueprints' => 'Blueprint data   (blueprints.jsonl)',
    ];

    /** Ordered import sequence — systems first (for station lookups), types before blueprints (for name resolution). */
    private const IMPORT_ORDER = ['systems', 'categories', 'groups', 'types', 'marketgroups', 'stations', 'stargates', 'materials', 'blueprints'];

    public function __construct(protected SdeImportService $sde)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // ── --check: only report if a new SDE version is available ────
        if ($this->option('check') && ! $this->option('force')) {
            return $this->runCheckOnly();
        }

        // ── Resolve which datasets to import ──────────────────────────
        $datasets = $this->resolveDatasets();
        if ($datasets === null) {
            return self::FAILURE;
        }

        // ── Checksum guard (skipped with --force) ─────────────────────
        $remoteChecksum = null;

        if (! $this->option('force')) {
            $remoteChecksum = $this->sde->getRemoteChecksum();

            if ($remoteChecksum === null) {
                $this->error('Could not fetch the remote SDE checksum. Aborting to avoid a partial import.');
                $this->line('Use --force to skip the checksum check.');

                return self::FAILURE;
            }

            $stored = $this->sde->getStoredChecksum();

            if ($remoteChecksum === $stored) {
                $this->info('SDE is up to date (checksum unchanged). Nothing to import.');
                $this->line('Use --force to import anyway.');

                return self::SUCCESS;
            }

            $this->info('New SDE version detected — importing '.(count($datasets) === count(self::IMPORT_ORDER) ? 'all datasets' : implode(', ', $datasets)).'...');
        } else {
            $this->warn('--force: skipping checksum check, importing unconditionally.');
        }

        // ── Download the SDE ZIP ──────────────────────────────────────
        $this->line('  Downloading SDE ZIP file...');

        if (! $this->sde->downloadZip()) {
            $this->error('Failed to download the SDE ZIP file. Aborting.');

            return self::FAILURE;
        }

        $this->line('  ✓ ZIP downloaded successfully.');

        // ── Run imports ───────────────────────────────────────────────
        try {
            $totals = [];
            $startTime = microtime(true);

            foreach (self::IMPORT_ORDER as $dataset) {
                if (! in_array($dataset, $datasets, true)) {
                    continue;
                }

                $label = self::DATASET_LABELS[$dataset];
                $this->line("  Importing {$label}...");

                $count = match ($dataset) {
                    'types' => $this->sde->importTypes(),
                    'marketgroups' => $this->sde->importMarketGroups(),
                    'groups' => $this->sde->importGroups(),
                    'categories' => $this->sde->importCategories(),
                    'stations' => $this->sde->importStations(),
                    'systems' => $this->sde->importSolarSystems(),
                    'stargates' => $this->sde->importStargates(),
                    'materials' => $this->sde->importTypeMaterials(),
                    'blueprints' => $this->sde->importBlueprints(),
                };

                $totals[$dataset] = $count;
                $this->line("    → {$count} rows upserted.");
            }

            $elapsed = round(microtime(true) - $startTime, 1);

            $this->newLine();
            $this->info("SDE import complete in {$elapsed}s.");

            foreach ($totals as $dataset => $count) {
                $this->line(sprintf('  %-12s %s rows', $dataset.':', number_format($count)));
            }

            // ── Persist checksum after a successful full import ───────────
            if ($remoteChecksum && $datasets === self::IMPORT_ORDER) {
                $this->sde->storeChecksum($remoteChecksum);
                $this->line("\n  Checksum stored: {$remoteChecksum}");
            }

            Log::info('sde:import completed', ['datasets' => $datasets, 'totals' => $totals, 'elapsed_s' => $elapsed]);

            return self::SUCCESS;
        } finally {
            // Always clean up the ZIP file, even if an import step fails
            $this->sde->cleanupZip();
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    /** Just check and report whether a new SDE version is available. */
    private function runCheckOnly(): int
    {
        $remote = $this->sde->getRemoteChecksum();

        if ($remote === null) {
            $this->error('Could not fetch the remote SDE checksum.');

            return self::FAILURE;
        }

        $stored = $this->sde->getStoredChecksum();

        if ($remote === $stored) {
            $this->info('SDE is up to date.');
            $this->line("  Stored checksum : {$stored}");
        } else {
            $this->warn('New SDE version available!');
            $this->line("  Remote checksum : {$remote}");
            $this->line('  Stored checksum : '.($stored ?? '(none — not yet imported)'));
            $this->line('Run <info>php artisan sde:import</info> to import the new data.');
        }

        return self::SUCCESS;
    }

    /**
     * Parse the --dataset option and return a validated array of dataset keys.
     * Returns null if an invalid dataset name is supplied.
     */
    private function resolveDatasets(): ?array
    {
        $raw = strtolower(trim($this->option('dataset')));

        if ($raw === 'all' || $raw === '') {
            return self::IMPORT_ORDER;
        }

        $requested = array_map('trim', explode(',', $raw));
        $valid = array_keys(self::DATASET_LABELS);
        $invalid = array_diff($requested, $valid);

        if (! empty($invalid)) {
            $this->error('Unknown dataset(s): '.implode(', ', $invalid));
            $this->line('Valid values: '.implode(', ', $valid).', all');

            return null;
        }

        // Preserve the canonical import order even when only a subset is requested.
        return array_values(array_filter(self::IMPORT_ORDER, fn ($d) => in_array($d, $requested, true)));
    }
}
