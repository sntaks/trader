<?php

namespace App\Console\Commands;

use App\Models\CopyTrade;
use App\Services\DerivApiService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SettleOpenCopyTrades extends Command
{
    protected $signature = 'copy-trades:settle';

    protected $description = 'Settle placed copy trades that are still open, from each follower account\'s profit table';

    /** Trades younger than this are left to SettleCopyTradeJob. */
    private const MIN_AGE_SECONDS = 60;

    /** How far before the oldest open trade the profit table is read, to cover clock skew. */
    private const DATE_FROM_MARGIN_SECONDS = 60;

    public function handle(DerivApiService $deriv): int
    {
        $groups = CopyTrade::query()
            ->with('user.derivConnection')
            ->where('status', CopyTrade::STATUS_PLACED)
            ->whereNull('is_win')
            ->whereNotNull('follower_contract_id')
            ->whereNotNull('follower_account_id')
            ->where('traded_at', '<=', now()->subSeconds(self::MIN_AGE_SECONDS))
            ->orderBy('traded_at')
            ->get()
            ->groupBy(fn (CopyTrade $trade) => "{$trade->user_id}|{$trade->follower_account_id}");

        $settled = 0;

        foreach ($groups as $trades) {
            $settled += $this->settleGroup($deriv, $trades);
        }

        $this->line("Settled {$settled} open copy trade(s) across {$groups->count()} account(s).");

        return self::SUCCESS;
    }

    /**
     * Settle one follower account's open trades from a single profit table read.
     *
     * @param  Collection<int, CopyTrade>  $trades  Oldest first, all on the same user and account.
     */
    private function settleGroup(DerivApiService $deriv, Collection $trades): int
    {
        $first = $trades->first();
        $connection = $first->user?->derivConnection;

        if (! $connection || $connection->isExpired()) {
            return 0;
        }

        try {
            $transactions = $deriv->getProfitTableSince(
                $connection,
                $first->follower_account_id,
                $first->traded_at->timestamp - self::DATE_FROM_MARGIN_SECONDS,
            );
        } catch (\Throwable $e) {
            Log::warning("copy-trades:settle: profit table failed for user #{$first->user_id} on {$first->follower_account_id}: {$e->getMessage()}");

            return 0;
        }

        $byContract = collect($transactions)
            ->filter(fn (array $row) => isset($row['contract_id']))
            ->keyBy(fn (array $row) => (string) $row['contract_id']);

        $settled = 0;

        foreach ($trades as $trade) {
            $row = $byContract->get($trade->follower_contract_id);

            if ($row && $trade->settleFrom($row)) {
                $settled++;
            }
        }

        return $settled;
    }
}
