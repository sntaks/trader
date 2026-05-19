<?php

namespace App\Jobs;

use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class PlaceFollowerTradeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly int $followerConnectionId,
        public readonly int $masterConnectionId,
        public readonly array $masterTrade,
        public readonly float $stake,
        public readonly int $userId,
        public readonly ?string $followerAccountId,
        public readonly bool $markPatternConsumed,
        public readonly bool $markWaitTrigger,
    ) {}

    public function handle(DerivApiService $deriv): void
    {
        $followerConnection = DerivConnection::find($this->followerConnectionId);

        if (! $followerConnection || $followerConnection->isExpired()) {
            return;
        }

        $symbol = $this->masterTrade['symbol'] ?? $this->masterTrade['underlying'] ?? 'R_50';

        try {
            $result = $deriv->buyContract($followerConnection, [
                'contract_type' => $this->masterTrade['contract_type'] ?? 'CALL',
                'symbol' => $symbol,
                'duration' => $this->masterTrade['duration'] ?? 1,
                'duration_unit' => $this->masterTrade['duration_unit'] ?? 't',
                'stake' => $this->stake,
                'basis' => 'stake',
                'barrier' => $this->masterTrade['barrier'] ?? null,
                'follower_account_id' => $this->followerAccountId,
            ]);

            $followerContractId = (string) ($result['buy']['contract_id'] ?? '');

            $copyTrade = CopyTrade::create([
                'user_id' => $this->userId,
                'master_connection_id' => $this->masterConnectionId,
                'follower_trx_id' => $result['buy']['transaction_id'] ?? null,
                'follower_contract_id' => $followerContractId ?: null,
                'master_trx_id' => $this->masterTrade['transaction_id'] ?? null,
                'symbol' => $symbol,
                'contract_type' => $this->masterTrade['contract_type'] ?? null,
                'duration' => ($this->masterTrade['duration'] ?? '').($this->masterTrade['duration_unit'] ?? ''),
                'barrier' => $this->masterTrade['barrier'] ?? null,
                'stake' => $this->stake,
                'traded_at' => now(),
            ]);

            if ($this->markPatternConsumed) {
                Cache::put(CopyTradeJob::patternConsumedKey($this->masterConnectionId, $this->userId), true, now()->addMinutes(10));
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

            Log::info("PlaceFollowerTradeJob: trade placed for user {$this->userId} — {$symbol} stake={$this->stake}");
        } catch (\Throwable $e) {
            Log::error("PlaceFollowerTradeJob: failed for user {$this->userId}: {$e->getMessage()}");
        }
    }
}
