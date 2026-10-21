<?php

namespace App\Livewire\Dashboard;

use App\Exceptions\DerivApiException;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Summary extends Component
{
    #[Computed]
    public function balance(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired()) {
            return [];
        }

        try {
            return app(DerivApiService::class)->getBalance($connection, $this->accountId($connection))['balance'] ?? [];
        } catch (DerivApiException) {
            return [];
        }
    }

    #[Computed]
    public function recentProfitTable(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired()) {
            return ['transactions' => [], 'count' => 0];
        }

        try {
            $result = app(DerivApiService::class)->getProfitTable($connection, $this->accountId($connection), 20, 0);

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

    /**
     * The copy setting's follower account, else the first listed account. Replaced by the account picker.
     *
     * @throws DerivApiException
     */
    private function accountId(DerivConnection $connection): string
    {
        return auth()->user()->copySetting?->follower_account_id
            ?? app(DerivApiService::class)->getAccounts($connection)[0]['account_id']
            ?? throw new DerivApiException('No Deriv account found.');
    }

    public function render(): View
    {
        return view('livewire.dashboard.summary');
    }
}
