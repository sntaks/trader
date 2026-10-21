<?php

namespace App\Jobs;

use App\Models\CopyTrade;
use App\Services\DerivApiService;
use Carbon\CarbonInterval;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Settles one copy trade from Deriv's own result, checked first at the contract's expected expiry.
 * It never records a result Deriv has not reported as sold; whatever it cannot settle within its
 * retries is left open for the `copy-trades:settle` sweeper.
 */
class SettleCopyTradeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** Seconds per tick when estimating expiry; `1HZ*` symbols tick every second. */
    private const SECONDS_PER_TICK = 2;

    /** @var array<string, int> */
    private const SECONDS_PER_UNIT = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];

    public function __construct(public readonly int $copyTradeId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * How long after purchase the copied contract should have finished.
     *
     * @param  array{symbol?: string, duration?: int|string, duration_unit?: string}  $masterTrade
     */
    public static function expectedExpiry(array $masterTrade): CarbonInterval
    {
        $duration = (int) ($masterTrade['duration'] ?? 0);
        $unit = $masterTrade['duration_unit'] ?? 's';

        $seconds = $unit === 't'
            ? $duration * (str_starts_with((string) ($masterTrade['symbol'] ?? ''), '1HZ') ? 1 : self::SECONDS_PER_TICK)
            : $duration * (self::SECONDS_PER_UNIT[$unit] ?? 1);

        return CarbonInterval::seconds($seconds);
    }

    public function handle(DerivApiService $deriv): void
    {
        $copyTrade = CopyTrade::find($this->copyTradeId);

        if (! $copyTrade || $copyTrade->is_win !== null || ! $copyTrade->follower_contract_id) {
            return;
        }

        $connection = $copyTrade->user?->derivConnection;

        if (! $connection || ! $copyTrade->follower_account_id) {
            Log::warning("SettleCopyTradeJob: copy trade #{$copyTrade->id} has no connection or account to settle on.");

            return;
        }

        try {
            $contract = $deriv->getContractDetails($connection, $copyTrade->follower_account_id, $copyTrade->follower_contract_id);
        } catch (\Throwable $e) {
            Log::warning("SettleCopyTradeJob: could not fetch contract #{$copyTrade->follower_contract_id}: {$e->getMessage()}");
            $this->retryLater();

            return;
        }

        if ((int) ($contract['is_sold'] ?? 0) !== 1) {
            $this->retryLater();

            return;
        }

        if (! $copyTrade->settleFrom($contract)) {
            Log::warning("SettleCopyTradeJob: contract #{$copyTrade->follower_contract_id} is sold but carries no profit or prices.", $contract);
            $this->retryLater();

            return;
        }

        Log::info("SettleCopyTradeJob: copy trade #{$copyTrade->id} settled — ".($copyTrade->is_win ? 'WIN' : 'LOSS')." (profit: {$copyTrade->profit})");
    }

    /** Check again after the next backoff step, or leave the trade to the sweeper once the retries are spent. */
    private function retryLater(): void
    {
        if ($this->attempts() >= $this->tries) {
            Log::info("SettleCopyTradeJob: copy trade #{$this->copyTradeId} still open after {$this->tries} checks; left for the sweeper.");

            return;
        }

        $this->release($this->backoff()[$this->attempts() - 1] ?? 60);
    }
}
