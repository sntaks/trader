<?php

namespace App\Jobs;

use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\MasterContractParser;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PlaceFollowerTradeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /** @var list<string> Keys a master trade must carry; see MasterContractParser::parse(). */
    private const REQUIRED_TRADE_KEYS = ['symbol', 'contract_type', 'duration', 'duration_unit'];

    public function __construct(
        public readonly int $followerConnectionId,
        public readonly int $masterConnectionId,
        public readonly array $masterTrade,
        public readonly float $stake,
        public readonly int $userId,
        public readonly ?string $followerAccountId,
        public readonly bool $markPatternConsumed,
        public readonly bool $markWaitTrigger,
    ) {
        $this->onQueue('high');
    }

    public function handle(DerivApiService $deriv): void
    {
        $followerConnection = DerivConnection::find($this->followerConnectionId);

        if (! $followerConnection || $followerConnection->isExpired()) {
            return;
        }

        if (($this->masterTrade['shape_version'] ?? null) !== MasterContractParser::SHAPE_VERSION) {
            Log::warning("PlaceFollowerTradeJob: skipped for user {$this->userId} — master trade lacks the current shape (queued by an older listener).");

            return;
        }

        try {
            [$copyTrade, $stake] = Cache::lock("copy:budget:{$this->userId}", 10)
                ->block(5, fn () => $this->claimWithinBudget());
        } catch (LockTimeoutException) {
            Log::error("PlaceFollowerTradeJob: budget lock busy for user {$this->userId}; skipped master contract {$this->masterTrade['contract_id']}.");

            return;
        }

        if (! $copyTrade) {
            return;
        }

        if ($stake === null) {
            $this->recordFailure($copyTrade, 'Skipped: the stop-loss budget is already committed to open trades.');

            return;
        }

        if (! $this->followerAccountId) {
            $this->recordFailure($copyTrade, 'No follower account selected.');

            return;
        }

        $missing = array_filter(
            self::REQUIRED_TRADE_KEYS,
            fn (string $key) => ! isset($this->masterTrade[$key]) || $this->masterTrade[$key] === '',
        );

        if ($missing !== []) {
            $this->recordFailure($copyTrade, 'Master trade is missing '.implode(', ', $missing).'.');

            return;
        }

        $symbol = $this->masterTrade['symbol'];

        try {
            $result = $deriv->buyContract($followerConnection, [
                'contract_type' => $this->masterTrade['contract_type'],
                'symbol' => $symbol,
                'duration' => (int) $this->masterTrade['duration'],
                'duration_unit' => $this->masterTrade['duration_unit'],
                'stake' => $stake,
                'basis' => 'stake',
                'currency' => $deriv->accountCurrency($followerConnection, $this->followerAccountId),
                'barrier' => $this->masterTrade['barrier'] ?? null,
                'barrier2' => $this->masterTrade['barrier2'] ?? null,
                'follower_account_id' => $this->followerAccountId,
            ]);

            $followerContractId = (string) ($result['buy']['contract_id'] ?? '');

            $copyTrade->update([
                'status' => CopyTrade::STATUS_PLACED,
                'follower_trx_id' => $result['buy']['transaction_id'] ?? null,
                'follower_contract_id' => $followerContractId ?: null,
            ]);

            if ($this->markPatternConsumed) {
                $count = (int) (Redis::get("master_outcomes_count:{$this->masterConnectionId}") ?? 0);
                Redis::setex("master_outcomes_offset:{$this->masterConnectionId}:{$this->userId}", 86400, $count);
            }

            if ($this->markWaitTrigger) {
                Cache::put(CopyTradeJob::waitTriggerUsedKeyFor($this->masterConnectionId, $this->userId), true, now()->addHours(24));
            }

            if ($followerContractId) {
                SettleCopyTradeJob::dispatch($copyTrade->id, $followerConnection->id, $followerContractId)
                    ->delay(now()->addSeconds(5));
            }

            Log::info("PlaceFollowerTradeJob: trade placed for user {$this->userId} — {$symbol} stake={$stake}");
        } catch (\Throwable $e) {
            $this->recordFailure($copyTrade, $e->getMessage());
        }
    }

    /**
     * Insert the pending row that reserves this (user, master contract) pair.
     * Returns null when another attempt already holds it, so a duplicate job never reaches Deriv.
     */
    /**
     * Claim the trade and fit its stake to the stop-loss budget, as one step under the follower's budget lock,
     * so overlapping copies each see the others' pending stakes.
     *
     * @return array{0: ?CopyTrade, 1: ?float} The claimed row (null for a duplicate) and the stake to buy (null when the budget is spent).
     */
    private function claimWithinBudget(): array
    {
        $copyTrade = $this->claim();

        if (! $copyTrade) {
            return [null, null];
        }

        $budget = CopySetting::query()->where('user_id', $this->userId)->first()?->stopLossBudget($copyTrade->id);

        if ($budget === null) {
            return [$copyTrade, $this->stake];
        }

        if ($budget < 0.35) {
            return [$copyTrade, null];
        }

        $stake = min($this->stake, $budget);

        if ($stake !== $this->stake) {
            $copyTrade->update(['stake' => $stake]);
        }

        return [$copyTrade, $stake];
    }

    private function claim(): ?CopyTrade
    {
        try {
            return CopyTrade::create([
                'user_id' => $this->userId,
                'master_connection_id' => $this->masterConnectionId,
                'master_trx_id' => $this->masterTrade['transaction_id'] ?? null,
                'master_contract_id' => $this->masterTrade['contract_id'] ?? null,
                'symbol' => $this->masterTrade['symbol'] ?? null,
                'contract_type' => $this->masterTrade['contract_type'] ?? null,
                'duration' => ($this->masterTrade['duration'] ?? '').($this->masterTrade['duration_unit'] ?? ''),
                'barrier' => $this->masterTrade['barrier'] ?? null,
                'stake' => $this->stake,
                'status' => CopyTrade::STATUS_PENDING,
                'traded_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            Log::warning("PlaceFollowerTradeJob: master contract {$this->masterTrade['contract_id']} already copied for user {$this->userId}; skipped duplicate.");

            return null;
        }
    }

    private function recordFailure(CopyTrade $copyTrade, string $reason): void
    {
        Log::error("PlaceFollowerTradeJob: failed for user {$this->userId}: {$reason}");

        if ($this->markPatternConsumed) {
            Cache::forget(CopyTradeJob::patternConsumedKey($this->masterConnectionId, $this->userId));
        }

        $copyTrade->update([
            'status' => CopyTrade::STATUS_FAILED,
            'failure_reason' => mb_strimwidth($reason, 0, 255, '...'),
        ]);
    }
}
