<?php

namespace App\Livewire\Account;

use App\Concerns\SelectsDerivAccount;
use App\Exceptions\DerivApiException;
use App\Services\DerivApiService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Overview extends Component
{
    use SelectsDerivAccount;

    public ?string $apiError = null;

    public ?string $resetSuccess = null;

    public ?string $resetError = null;

    public function refresh(): void
    {
        $connection = auth()->user()->derivConnection;

        if ($connection) {
            app(DerivApiService::class)->clearCache($connection);
        }

        $this->apiError = null;
        $this->resetSuccess = null;
        unset($this->accounts, $this->balance);
    }

    public function resetDemoBalance(string $accountId): void
    {
        $this->resetSuccess = null;
        $this->resetError = null;

        $connection = auth()->user()->derivConnection;

        if (! $connection) {
            return;
        }

        try {
            app(DerivApiService::class)->resetDemoBalance($connection, $accountId);
            $this->resetSuccess = 'Demo balance reset to $10,000 successfully.';
            unset($this->accounts, $this->balance);
        } catch (DerivApiException $e) {
            $this->resetError = $e->getMessage();
        }
    }

    /**
     * All options accounts (demo + real) from the REST API.
     */
    #[Computed]
    public function accounts(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired()) {
            return [];
        }

        try {
            $options = array_map(
                fn (array $a) => array_merge($a, ['product_type' => 'options']),
                app(DerivApiService::class)->getAccounts($connection)
            );
        } catch (DerivApiException $e) {
            $this->apiError = $e->getMessage();
            $options = [];
        }

        return $options;
    }

    /**
     * Current balance via WebSocket API.
     * May fail independently — shown in its own section.
     */
    #[Computed]
    public function balance(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired()) {
            return [];
        }

        if (! $this->selectedAccountId()) {
            return [];
        }

        try {
            return app(DerivApiService::class)->getBalance($connection, $this->accountId)['balance'] ?? [];
        } catch (DerivApiException) {
            return [];
        }
    }

    /** The demo accounts from the accounts list. */
    #[Computed]
    public function demoAccounts(): array
    {
        return array_values(array_filter($this->accounts, fn ($a) => $this->isDemo($a)));
    }

    /** The real accounts from the accounts list. */
    #[Computed]
    public function realAccounts(): array
    {
        return array_values(array_filter($this->accounts, fn ($a) => ! $this->isDemo($a)));
    }

    protected function resetAccountData(): void
    {
        unset($this->balance);
    }

    private function isDemo(array $account): bool
    {
        return ($account['is_demo'] ?? $account['is_virtual'] ?? false) == true
            || ($account['account_type'] ?? '') === 'demo';
    }

    public function render(): View
    {
        return view('livewire.account.overview');
    }
}
