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

    /** Trades are left to SettleCopyTradeJob until this long after their expected expiry. */
    private const GRACE_SECONDS = 60;

    /** Rows per profit table page (Deriv's maximum) and the most pages read per account per run. */
    private const PAGE_SIZE = 500;

    private const MAX_PAGES = 4;

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
            ->where(fn ($query) => $query
                ->where('expires_at', '<=', now()->subSeconds(self::GRACE_SECONDS))
                ->orWhere(fn ($query) => $query->whereNull('expires_at')->where('traded_at', '<=', now()->subSeconds(self::GRACE_SECONDS))))
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
     * Settle one follower account's expired open trades from its profit table, reading up to MAX_PAGES pages
     * until every trade is found. A failed read settles whatever earlier pages matched.
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

        $dateFrom = $first->traded_at->timestamp - self::DATE_FROM_MARGIN_SECONDS;
        $wanted = $trades->pluck('follower_contract_id')->flip();
        $byContract = collect();

        try {
            for ($page = 0; $page < self::MAX_PAGES && $byContract->count() < $wanted->count(); $page++) {
                $rows = $deriv->getProfitTableSince($connection, $first->follower_account_id, $dateFrom, self::PAGE_SIZE, $page * self::PAGE_SIZE);

                $byContract = $byContract->replace(collect($rows)
                    ->filter(fn (array $row) => isset($row['contract_id']) && $wanted->has((string) $row['contract_id']))
                    ->keyBy(fn (array $row) => (string) $row['contract_id']));

                if (count($rows) < self::PAGE_SIZE) {
                    break;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("copy-trades:settle: profit table failed for user #{$first->user_id} on {$first->follower_account_id}: {$e->getMessage()}");
        }

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
