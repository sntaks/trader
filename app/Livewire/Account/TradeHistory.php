<?php

namespace App\Livewire\Account;

use App\Concerns\SelectsDerivAccount;
use App\Exceptions\DerivApiException;
use App\Services\DerivApiService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TradeHistory extends Component
{
    use SelectsDerivAccount;

    public string $activeTab = 'profit_table';

    public int $perPage = 25;

    public int $page = 1;

    public function nextPage(): void
    {
        $this->page++;
        unset($this->trades, $this->statement);
    }

    public function prevPage(): void
    {
        if ($this->page > 1) {
            $this->page--;
        }
        unset($this->trades, $this->statement);
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->page = 1;
        unset($this->trades, $this->statement);
    }

    #[Computed]
    public function trades(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired() || ! $this->selectedAccountId()) {
            return ['transactions' => [], 'count' => 0];
        }

        try {
            $offset = ($this->page - 1) * $this->perPage;
            $result = app(DerivApiService::class)->getProfitTable($connection, $this->accountId, $this->perPage, $offset);

            return $result['profit_table'] ?? ['transactions' => [], 'count' => 0];
        } catch (DerivApiException) {
            return ['transactions' => [], 'count' => 0];
        }
    }

    #[Computed]
    public function statement(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired() || ! $this->selectedAccountId()) {
            return ['transactions' => [], 'count' => 0];
        }

        try {
            $offset = ($this->page - 1) * $this->perPage;
            $result = app(DerivApiService::class)->getStatement($connection, $this->accountId, $this->perPage, $offset);

            return $result['statement'] ?? ['transactions' => [], 'count' => 0];
        } catch (DerivApiException) {
            return ['transactions' => [], 'count' => 0];
        }
    }

    /** Derived analytics from the profit table transactions. */
    #[Computed]
    public function analytics(): array
    {
        $transactions = $this->trades['transactions'] ?? [];

        if (empty($transactions)) {
            return [
                'total_trades' => 0,
                'wins' => 0,
                'losses' => 0,
                'win_rate' => 0,
                'total_profit' => 0,
                'total_staked' => 0,
                'best_trade' => 0,
                'worst_trade' => 0,
                'avg_stake' => 0,
            ];
        }

        $profits = array_map(fn ($t) => (float) ($t['sell_price'] ?? 0) - (float) ($t['buy_price'] ?? 0), $transactions);
        $wins = array_filter($profits, fn ($p) => $p > 0);
        $losses = array_filter($profits, fn ($p) => $p <= 0);
        $stakes = array_column($transactions, 'buy_price');

        return [
            'total_trades' => count($transactions),
            'wins' => count($wins),
            'losses' => count($losses),
            'win_rate' => count($transactions) > 0 ? round((count($wins) / count($transactions)) * 100, 1) : 0,
            'total_profit' => array_sum($profits),
            'total_staked' => array_sum($stakes),
            'best_trade' => ! empty($profits) ? max($profits) : 0,
            'worst_trade' => ! empty($profits) ? min($profits) : 0,
            'avg_stake' => ! empty($stakes) ? array_sum($stakes) / count($stakes) : 0,
        ];
    }

    protected function resetAccountData(): void
    {
        $this->page = 1;
        unset($this->trades, $this->statement, $this->analytics);
    }

    public function render(): View
    {
        return view('livewire.account.trade-history');
    }
}
