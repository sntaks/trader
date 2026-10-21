<?php

namespace App\Jobs;

use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SettleCopyTradeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 20;

    public int $timeout = 30;

    public function __construct(
        public readonly int $copyTradeId,
        public readonly int $followerConnectionId,
        public readonly string $contractId,
    ) {}

    public function handle(DerivApiService $deriv): void
    {
        $copyTrade = CopyTrade::find($this->copyTradeId);

        if (! $copyTrade || $copyTrade->is_win !== null) {
            return;
        }

        $connection = DerivConnection::find($this->followerConnectionId);

        if (! $connection) {
            return;
        }

        try {
            $contract = $deriv->getContractDetails($connection, (string) CopySetting::query()->where('user_id', $copyTrade->user_id)->value('follower_account_id'), $this->contractId);
        } catch (\Throwable $e) {
            Log::warning("SettleCopyTradeJob: could not fetch contract #{$this->contractId}: {$e->getMessage()}");
            $this->release(5);

            return;
        }

        $status = $contract['status'] ?? null;

        if ($status === 'open') {
            $this->release(5);

            return;
        }

        $sellPrice = isset($contract['sell_price']) ? (float) $contract['sell_price'] : null;
        $buyPrice = isset($contract['buy_price']) ? (float) $contract['buy_price'] : null;
        $profit = ($sellPrice !== null && $buyPrice !== null) ? round($sellPrice - $buyPrice, 2) : null;

        $copyTrade->update([
            'is_win' => $profit !== null && $profit > 0,
            'payout' => $sellPrice,
            'profit' => $profit,
        ]);

        Log::info("SettleCopyTradeJob: copy trade #{$this->copyTradeId} settled — ".($profit !== null && $profit > 0 ? 'WIN' : 'LOSS')." (profit: {$profit})");
    }
}
