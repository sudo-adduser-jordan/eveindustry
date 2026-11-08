<?php

namespace App\Jobs;

use App\Services\MarketOrderSweepService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ends a region order sweep once its page batch has finished: when every
 * page arrived, delete unseen orders, rebuild market_aggregates and start
 * precompute; otherwise mark the sweep failed and keep every order.
 */
class CompleteMarketOrderSweep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** Jita's aggregate rebuild reads every order in The Forge. */
    public int $timeout = 300;

    public function __construct(
        protected string $sweepId,
        protected bool $allPagesArrived,
    ) {
        $this->onQueue('market');
    }

    public function handle(MarketOrderSweepService $sweeps): void
    {
        if ($this->allPagesArrived) {
            $sweeps->complete($this->sweepId);

            return;
        }

        $sweeps->fail($this->sweepId, 'One or more pages failed or the batch was cancelled');
    }

    public function failed(\Throwable $e): void
    {
        app(MarketOrderSweepService::class)->fail($this->sweepId, 'Completion failed: '.$e->getMessage());
    }
}
