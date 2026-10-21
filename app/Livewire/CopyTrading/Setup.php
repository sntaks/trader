<?php

namespace App\Livewire\CopyTrading;

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivRateLimitedException;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\DerivLegacyApiService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class Setup extends Component
{
    public ?int $selectedMasterId = null;

    public string $followerPattern = '111';

    public bool $patternEnabled = true;

    public ?string $followerAccountId = null;

    public bool $showForm = false;

    public string $patToken = '';

    public ?string $patError = null;

    public ?string $patSuccess = null;

    public function mount(): void
    {
        $setting = auth()->user()->copySetting;

        if ($setting) {
            $this->selectedMasterId = $setting->master_connection_id;
            $this->followerPattern = $setting->follower_pattern ?? '111';
            $this->patternEnabled = $setting->pattern_enabled ?? true;
            $this->followerAccountId = $setting->follower_account_id;
        }
    }

    public function selectMaster(int $connectionId): void
    {
        $this->selectedMasterId = $connectionId;
        $this->showForm = true;
    }

    public function cancelForm(): void
    {
        $setting = auth()->user()->copySetting;

        $this->selectedMasterId = $setting?->master_connection_id;
        $this->followerPattern = $setting?->follower_pattern ?? '111';
        $this->patternEnabled = $setting?->pattern_enabled ?? true;
        $this->followerAccountId = $setting?->follower_account_id;
        $this->showForm = false;
    }

    public function save(): void
    {
        $rules = [
            'selectedMasterId' => ['required', 'exists:deriv_connections,id'],
            'followerPattern' => ['required', 'regex:/^[01]+$/', 'min_digits:1', 'max:20'],
            'patternEnabled' => ['boolean'],
        ];

        $validAccountIds = array_column($this->followerAccounts, 'account_id');

        if (! empty($validAccountIds)) {
            $rules['followerAccountId'] = ['required', 'in:'.implode(',', $validAccountIds)];
        }

        $this->validate($rules);

        $master = DerivConnection::where('id', $this->selectedMasterId)
            ->where('type', 'master')
            ->firstOrFail();

        CopySetting::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'master_connection_id' => $master->id,
                'follower_pattern' => $this->followerPattern,
                'pattern_enabled' => $this->patternEnabled,
                'follower_account_id' => $this->followerAccountId ?: null,
                'is_active' => true,
            ]
        );

        $this->showForm = false;
        $this->dispatch('copy-setting-saved');
        session()->flash('success', 'Copy trading settings saved.');
    }

    public function toggleActive(): void
    {
        $setting = auth()->user()->copySetting;

        if (! $setting) {
            return;
        }

        $setting->update(['is_active' => ! $setting->is_active]);
    }

    public function disconnect(): void
    {
        auth()->user()->copySetting?->delete();

        $this->selectedMasterId = null;
        $this->followerPattern = '111';
        $this->patternEnabled = true;
        $this->followerAccountId = null;
        $this->showForm = false;

        session()->flash('success', 'Copy trading disconnected.');
    }

    public function connectViaPat(): void
    {
        $this->patError = null;
        $this->patSuccess = null;

        $this->validate(['patToken' => ['required', 'string', 'min:10']]);

        // Try the new REST API first (works for new-style PATs).
        try {
            $tokenType = $this->tryNewRestApi($this->patToken);
        } catch (DerivRateLimitedException) {
            $this->patError = 'Deriv is busy right now — try again in a minute.';

            return;
        }

        // If the new REST API rejects it, fall back to the legacy WebSocket API.
        if ($tokenType === null) {
            $tokenType = $this->tryLegacyWsApi($this->patToken);
        }

        if ($tokenType === null) {
            $this->patError = 'Invalid token. Your token was not accepted by Deriv. Please check it and try again.';

            return;
        }

        DerivConnection::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'access_token' => $this->patToken,
                'refresh_token' => null,
                'token_type' => $tokenType,
                'expires_at' => null,
                'scope' => null,
            ]
        );

        $this->patToken = '';
        $this->patSuccess = 'Deriv account connected successfully.';

        unset($this->followerAccounts);
    }

    /**
     * Try validating the PAT against the new REST API.
     * Returns 'pat' on success, null on 401/failure. Counts against the Deriv rate limit.
     *
     * @throws DerivRateLimitedException When the budget is spent, so the token is not misjudged as legacy.
     */
    private function tryNewRestApi(string $token): ?string
    {
        try {
            $response = app(DerivApiService::class)->checkPat($token);
        } catch (DerivRateLimitedException $e) {
            throw $e;
        } catch (DerivApiException) {
            $this->patError = 'Could not reach Deriv. Please check your connection and try again.';

            return null;
        }

        if ($response->successful()) {
            return 'pat';
        }

        return null;
    }

    /**
     * Try validating the PAT against the legacy WebSocket API.
     * Returns 'pat_legacy' on success, null if the token is invalid.
     */
    private function tryLegacyWsApi(string $token): ?string
    {
        try {
            app(DerivLegacyApiService::class)->authorize($token);

            return 'pat_legacy';
        } catch (DerivApiException) {
            return null;
        }
    }

    #[Computed]
    public function followerAccounts(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired()) {
            return [];
        }

        try {
            if ($connection->token_type === 'pat_legacy') {
                return app(DerivLegacyApiService::class)->getAccounts($connection);
            }

            return app(DerivApiService::class)->getAccounts($connection);
        } catch (DerivApiException) {
            return [];
        }
    }

    #[Computed]
    public function masters(): Collection
    {
        return DerivConnection::query()
            ->where('type', 'master')
            ->with('user')
            ->withCount('followers')
            ->get();
    }

    #[Computed]
    public function currentSetting(): ?CopySetting
    {
        return auth()->user()->load('copySetting.masterConnection.user')->copySetting;
    }

    public function render(): View
    {
        return view('livewire.copy-trading.setup');
    }
}
