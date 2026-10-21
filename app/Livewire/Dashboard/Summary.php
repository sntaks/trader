<?php

namespace App\Livewire\Dashboard;

use App\Concerns\SelectsDerivAccount;
use App\Exceptions\DerivApiException;
use App\Services\DerivApiService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Summary extends Component
{
    use SelectsDerivAccount;

    #[Computed]
    public function balance(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired() || ! $this->accountId) {
            return [];
        }

        try {
            return app(DerivApiService::class)->getBalance($connection, $this->accountId)['balance'] ?? [];
        } catch (DerivApiException) {
            return [];
        }
    }

    #[Computed]
    public function recentProfitTable(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired() || ! $this->accountId) {
            return ['transactions' => [], 'count' => 0];
        }

        try {
            $result = app(DerivApiService::class)->getProfitTable($connection, $this->accountId, 20, 0);

            return $result['profit_table'] ?? ['transactions' => [], 'count' => 0];
        } catch (DerivApiException) {
            return ['transactions' => [], 'count' => 0];
        }
    }

    /** Quick performance analytics from the last 20 trades. */
    #[Computed]
    public function performance(): array
    {
        $transactions = $this->recentProfitTable['transactions'] ?? [];

        if (empty($transactions)) {
            return [
                'trades' => 0,
                'win_rate' => 0,
                'wins' => 0,
                'losses' => 0,
                'pnl' => 0,
                'pnl_positive' => true,
            ];
        }

        $profits = array_map(fn ($t) => (float) ($t['sell_price'] ?? 0) - (float) ($t['buy_price'] ?? 0), $transactions);
        $wins = count(array_filter($profits, fn ($p) => $p > 0));
        $total = count($profits);
        $pnl = array_sum($profits);

        return [
            'trades' => $total,
            'wins' => $wins,
            'losses' => $total - $wins,
            'win_rate' => $total > 0 ? round(($wins / $total) * 100, 1) : 0,
            'pnl' => $pnl,
            'pnl_positive' => $pnl >= 0,
        ];
    }

    protected function resetAccountData(): void
    {
        unset($this->balance, $this->recentProfitTable, $this->performance);
    }

    public function render(): View
    {
        return view('livewire.dashboard.summary');
    }
}
