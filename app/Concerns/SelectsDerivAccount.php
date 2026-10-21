<?php

namespace App\Concerns;

use App\Exceptions\DerivApiException;
use App\Services\DerivApiService;

/**
 * Gives a Livewire component an explicit Deriv account picker. The selection defaults to
 * the user's preferred account and is always one of their own active accounts.
 */
trait SelectsDerivAccount
{
    public ?string $accountId = null;

    public function mountSelectsDerivAccount(): void
    {
        $this->accountId = auth()->user()->preferredDerivAccountId($this->selectableAccounts());
    }

    public function updatedAccountId(): void
    {
        $accounts = $this->selectableAccounts();

        if (! in_array($this->accountId, array_column($accounts, 'account_id'), true)) {
            $this->accountId = auth()->user()->preferredDerivAccountId($accounts);
            $this->addError('accountId', 'Choose one of your Deriv accounts.');
        }

        $this->resetAccountData();
    }

    /**
     * The user's active Deriv accounts, or none when there is no usable connection or Deriv is unreachable.
     *
     * @return list<array<string, mixed>>
     */
    public function selectableAccounts(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired()) {
            return [];
        }

        try {
            return DerivApiService::activeAccounts(app(DerivApiService::class)->getAccounts($connection));
        } catch (DerivApiException) {
            return [];
        }
    }

    /**
     * The selected account, choosing the preferred one first when none is selected yet
     * (e.g. Deriv was unreachable when the page loaded).
     */
    protected function selectedAccountId(): ?string
    {
        return $this->accountId ??= auth()->user()->preferredDerivAccountId($this->selectableAccounts());
    }

    /** Forget every computed value that was read from the previously selected account. */
    abstract protected function resetAccountData(): void;
}
