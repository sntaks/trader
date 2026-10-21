<?php

namespace App\Livewire\CopyTrading;

use App\Exceptions\DerivApiException;
use App\Jobs\CopyTradeJob;
use App\Jobs\MasterListenerJob;
use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

class Dashboard extends Component
{
    use WithPagination;

    // -- View state --
    public string $activeTab = 'transactions';

    public bool $settingsOpen = false;

    public bool $showForm = false;

    public bool $showMasterList = false;

    public bool $showResetModal = false;

    public bool $resetBalance = true;

    public bool $stopBotOnReset = false;

    /** Whether the user is using their own account as master (own-account trading mode). */
    #[Locked]
    public bool $ownAccountMode = false;

    // -- Master selection --
    #[Locked]
    public ?int $selectedMasterId = null;

    /** The specific Deriv account ID to listen on as master (used in own-account mode). */
    public ?string $masterAccountId = null;

    // -- Settings form properties --
    public float $stake = 1.00;

    public bool $followMasterStake = false;

    public bool $safeMode = false;

    public float $stakeMultiplier = 1.00;

    public ?float $takeProfit = null;

    public ?float $stopLoss = null;

    public int $maxCompound = 0;

    public int $doMartingaleAt = 1;

    public int $maxMartingale = 0;

    public string $ifHitMaxMartingale = 'stop';

    public int $waitForLoss = 0;

    public bool $onlyUse1xWaitForLoss = false;

    public string $followerPattern = '111';

    public bool $patternEnabled = true;

    /** @var array<string> */
    public array $filterMarkets = [];

    /** @var array<string> */
    public array $syntheticIndices = [];

    /** @var array<string> */
    public array $forexPairs = [];

    public ?string $followerAccountId = null;

    // -- Runtime state --
    public bool $paused = false;

    // -- Transactions table --
    public int $perPage = 25;

    /** @var array<string, bool> */
    public array $visibleColumns = [
        'num' => true,
        'result' => true,
        'datetime' => true,
        'symbol' => true,
        'followerTrxId' => true,
        'dur' => true,
        'stake' => true,
        'payout' => true,
        'profit' => true,
        'masterTrxId' => true,
    ];

    public const AVAILABLE_MARKETS = CopySetting::MARKET_SYMBOLS;

    /** @var array<string, list<string>> Trade-type code => contract types. */
    public const AVAILABLE_SYNTHETIC = CopySetting::TRADE_TYPES;

    /** @var array<string, string> Forex label => Deriv symbol. */
    public const AVAILABLE_FOREX = CopySetting::FOREX_SYMBOLS;

    /** Shown when a public master tries to follow anyone, which would allow copy loops. */
    private const MASTER_CANNOT_FOLLOW = 'Master traders cannot follow other traders or use own-account mode.';

    /** Shown instead of saving a setting without a follower account when Deriv accounts cannot be loaded. */
    private const ACCOUNTS_UNAVAILABLE = "We couldn't load your Deriv accounts. Reconnect or try again.";

    /** The dashboard polls every 15 s; reading the balance at most every 2 min keeps that to one Deriv session per viewer per 2 min. */
    private const POLLED_BALANCE_MAX_AGE_SECONDS = 120;

    /** Shown when the bot is started before a follower account is chosen; every copy is bought on that account. */
    private const FOLLOWER_ACCOUNT_REQUIRED = 'Choose the account to copy trades into before starting.';

    public function mount(): void
    {
        $setting = auth()->user()->copySetting;

        if (! $setting) {
            return;
        }

        $this->selectedMasterId = $setting->master_connection_id;
        $this->stake = (float) ($setting->stake ?? 1.00);
        $this->followMasterStake = $setting->follow_master_stake ?? false;
        $this->safeMode = $setting->safe_mode ?? false;
        $this->stakeMultiplier = (float) ($setting->stake_multiplier ?? 1.00);
        $this->takeProfit = $setting->take_profit ? (float) $setting->take_profit : null;
        $this->stopLoss = $setting->stop_loss ? (float) $setting->stop_loss : null;
        $this->maxCompound = (int) ($setting->max_compound ?? 0);
        $this->doMartingaleAt = (int) ($setting->do_martingale_at ?? 1);
        $this->maxMartingale = (int) ($setting->max_martingale ?? 0);
        $this->ifHitMaxMartingale = $setting->if_hit_max_martingale ?? 'stop';
        $this->waitForLoss = (int) ($setting->wait_for_loss ?? 0);
        $this->onlyUse1xWaitForLoss = $setting->only_use_1x_wait_for_loss ?? false;
        $this->followerPattern = $setting->follower_pattern ?? '111';
        $this->patternEnabled = $setting->pattern_enabled ?? true;
        $this->filterMarkets = $setting->filter_markets ?? [];
        $this->syntheticIndices = CopySetting::knownTradeTypeCodes($setting->synthetic_indices ?? []);
        $this->forexPairs = $setting->forex_pairs ?? [];
        $this->masterAccountId = $setting->master_account_id;
        $this->followerAccountId = $setting->follower_account_id;
        $this->paused = ! ($setting->is_active ?? true);

        $ownConnection = auth()->user()->derivConnection;
        if ($ownConnection && $setting->master_connection_id === $ownConnection->id) {
            $this->ownAccountMode = true;
        }
    }

    // ---- Pre-follow flow ----

    public function enterOwnAccountMode(): void
    {
        $ownConnection = auth()->user()->derivConnection;

        if (! $ownConnection) {
            return;
        }

        if ($ownConnection->isMaster()) {
            $this->addError('selectedMasterId', self::MASTER_CANNOT_FOLLOW);

            return;
        }

        $this->ownAccountMode = true;
        $this->selectedMasterId = $ownConnection->id;
        $this->showForm = true;
        $this->showMasterList = false;
    }

    public function selectMaster(int $connectionId): void
    {
        $this->ownAccountMode = false;
        $this->masterAccountId = null;
        $this->selectedMasterId = $connectionId;
        $this->showForm = true;
        $this->showMasterList = false;
    }

    public function switchMaster(int $connectionId): void
    {
        if ($this->ownConnectionIsMaster()) {
            $this->addError('selectedMasterId', self::MASTER_CANNOT_FOLLOW);

            return;
        }

        $master = DerivConnection::query()
            ->where('type', 'master')
            ->where('user_id', '!=', auth()->id())
            ->findOrFail($connectionId);

        auth()->user()->copySetting?->update([
            'master_connection_id' => $master->id,
            'master_account_id' => null,
        ]);

        $this->selectedMasterId = $connectionId;
        $this->masterAccountId = null;
        $this->ownAccountMode = false;
        $this->showMasterList = false;
    }

    public function switchToOwnAccounts(): void
    {
        $ownConnection = auth()->user()->derivConnection;

        if (! $ownConnection) {
            return;
        }

        if ($ownConnection->isMaster()) {
            $this->addError('selectedMasterId', self::MASTER_CANNOT_FOLLOW);

            return;
        }

        $this->validate([
            'masterAccountId' => [
                'required',
                Rule::in(array_column($this->myAccounts, 'account_id')),
                Rule::notIn([auth()->user()->copySetting?->follower_account_id]),
            ],
        ], [
            'masterAccountId.not_in' => 'The master account must differ from the account you copy into.',
        ]);

        auth()->user()->copySetting?->update([
            'master_connection_id' => $ownConnection->id,
            'master_account_id' => $this->masterAccountId,
        ]);

        $this->selectedMasterId = $ownConnection->id;
        $this->ownAccountMode = true;
        $this->showMasterList = false;
    }

    public function cancelForm(): void
    {
        $setting = auth()->user()->copySetting;

        $this->selectedMasterId = $setting?->master_connection_id;
        $this->masterAccountId = $setting?->master_account_id;
        $this->followerPattern = $setting?->follower_pattern ?? '111';
        $this->patternEnabled = $setting?->pattern_enabled ?? true;
        $this->followerAccountId = $setting?->follower_account_id;
        $this->showForm = false;
        $this->showMasterList = false;
        $this->ownAccountMode = false;
    }

    public function follow(): void
    {
        $ownConnection = auth()->user()->derivConnection;

        if ($ownConnection?->isMaster()) {
            $this->addError('selectedMasterId', self::MASTER_CANNOT_FOLLOW);

            return;
        }

        if ($this->ownAccountMode) {
            $this->selectedMasterId = $ownConnection?->id;
        }

        $rules = [
            'selectedMasterId' => $this->ownAccountMode
                ? ['required']
                : ['required', Rule::exists('deriv_connections', 'id')->where('type', 'master')->whereNot('user_id', auth()->id())],
            'followerPattern' => ['required', 'regex:/^[01]+$/', 'min_digits:1', 'max:20'],
            'patternEnabled' => ['boolean'],
        ];

        $validAccountIds = array_column($this->myAccounts, 'account_id');

        if (empty($validAccountIds)) {
            $this->addError('followerAccountId', self::ACCOUNTS_UNAVAILABLE);

            return;
        }

        $rules['followerAccountId'] = ['required', 'in:'.implode(',', $validAccountIds)];

        if ($this->ownAccountMode) {
            $rules['masterAccountId'] = ['required', 'in:'.implode(',', $validAccountIds), 'different:followerAccountId'];
        }

        $this->validate($rules, [
            'selectedMasterId.exists' => 'Choose a master trader from the list.',
            'masterAccountId.different' => 'The master account must differ from the account you copy into.',
        ]);

        CopySetting::updateOrCreate(
            ['user_id' => auth()->id()],
            [
                'master_connection_id' => $this->selectedMasterId,
                'master_account_id' => $this->ownAccountMode ? $this->masterAccountId : null,
                'follower_pattern' => $this->followerPattern,
                'pattern_enabled' => $this->patternEnabled,
                'follower_account_id' => $this->followerAccountId,
                'is_active' => true,
            ]
        );

        $this->showForm = false;
        $this->dispatch('copy-setting-saved');
        session()->flash('success', 'Copy trading settings saved.');
    }

    public function disconnect(): void
    {
        auth()->user()->copySetting?->delete();

        $this->selectedMasterId = null;
        $this->masterAccountId = null;
        $this->ownAccountMode = false;
        $this->followerPattern = '111';
        $this->patternEnabled = true;
        $this->followerAccountId = null;
        $this->showForm = false;
        $this->showMasterList = false;
        $this->paused = false;

        session()->flash('success', 'Copy trading disconnected.');
    }

    // ---- Settings (post-follow) ----

    public function saveSettings(): void
    {
        $this->validate($this->settingsRules());

        $setting = auth()->user()->copySetting;

        if (! $setting) {
            return;
        }

        $validAccountIds = array_column($this->myAccounts, 'account_id');

        if (empty($validAccountIds)) {
            $this->addError('followerAccountId', self::ACCOUNTS_UNAVAILABLE);

            return;
        }

        $this->validate(['followerAccountId' => ['required', 'in:'.implode(',', $validAccountIds)]]);

        $isOwnAccountSetting = $setting->master_connection_id === auth()->user()->derivConnection?->id;

        if ($isOwnAccountSetting) {
            $this->validate(
                ['masterAccountId' => ['required', 'in:'.implode(',', $validAccountIds), 'different:followerAccountId']],
                ['masterAccountId.different' => 'The master account must differ from the account you copy into.'],
            );
        }

        $setting->update([
            'master_account_id' => $isOwnAccountSetting ? $this->masterAccountId : null,
            'stake' => $this->stake,
            'follow_master_stake' => $this->followMasterStake,
            'safe_mode' => $this->safeMode,
            'stake_multiplier' => $this->stakeMultiplier,
            'take_profit' => $this->takeProfit,
            'stop_loss' => $this->stopLoss,
            'max_compound' => $this->maxCompound,
            'do_martingale_at' => $this->doMartingaleAt,
            'max_martingale' => $this->maxMartingale,
            'if_hit_max_martingale' => $this->ifHitMaxMartingale,
            'wait_for_loss' => $this->waitForLoss,
            'only_use_1x_wait_for_loss' => $this->onlyUse1xWaitForLoss,
            'follower_pattern' => $this->followerPattern,
            'pattern_enabled' => $this->patternEnabled,
            'filter_markets' => $this->filterMarkets,
            'synthetic_indices' => $this->syntheticIndices,
            'forex_pairs' => $this->forexPairs,
            'follower_account_id' => $this->followerAccountId,
        ]);

        $this->settingsOpen = false;
        session()->flash('settings_saved', 'Settings saved.');
    }

    // ---- Bot controls ----

    public function startBot(): void
    {
        $setting = auth()->user()->copySetting;

        if (! $setting) {
            return;
        }

        if (! $setting->follower_account_id) {
            $this->addError('bot', self::FOLLOWER_ACCOUNT_REQUIRED);

            return;
        }

        $derivConnection = auth()->user()->derivConnection;

        if ($setting->start_balance === null && $derivConnection) {
            try {
                $balance = app(DerivApiService::class)->getBalance($derivConnection, $setting->follower_account_id, allowStale: false);
            } catch (DerivApiException $e) {
                $this->addError('bot', $e->getMessage());

                return;
            }

            $setting->update(['start_balance' => $balance['balance']['balance'] ?? null]);
        }

        $setting->update([
            'is_running' => true,
            'is_active' => true,
            'session_started_at' => now(),
            'stop_reason' => null,
            'stopped_at_profit' => null,
        ]);

        Cache::forget(CopyTradeJob::patternConsumedKey($setting->master_connection_id, auth()->id()));
        Cache::forget(CopyTradeJob::waitTriggerUsedKeyFor($setting->master_connection_id, auth()->id()));

        $currentCount = (int) (Redis::get("master_outcomes_count:{$setting->master_connection_id}") ?? 0);
        Redis::setex("master_outcomes_offset:{$setting->master_connection_id}:".auth()->id(), 86400, $currentCount);

        MasterListenerJob::dispatch($setting->master_connection_id);

        $this->paused = false;
    }

    public function stopBot(): void
    {
        $setting = auth()->user()->copySetting;

        if (! $setting) {
            return;
        }

        $setting->update(['is_running' => false]);

        Cache::forget(MasterListenerJob::heartbeatKey($setting->master_connection_id));

        $currentCount = (int) (Redis::get("master_outcomes_count:{$setting->master_connection_id}") ?? 0);
        Redis::setex("master_outcomes_offset:{$setting->master_connection_id}:".auth()->id(), 86400, $currentCount);
    }

    public function pauseBot(): void
    {
        $setting = auth()->user()->copySetting;

        if (! $setting) {
            return;
        }

        $this->paused = ! $this->paused;
        $setting->update(['is_active' => ! $this->paused]);
    }

    // ---- Reset ----

    public function openResetModal(): void
    {
        $this->resetBalance = true;
        $this->stopBotOnReset = false;
        $this->showResetModal = true;
    }

    public function performReset(): void
    {
        $setting = auth()->user()->copySetting;

        if (! $setting) {
            $this->showResetModal = false;

            return;
        }

        $this->validate($this->settingsRules());

        $isRunning = (bool) $setting->is_running;

        CopyTrade::query()->where('user_id', auth()->id())->delete();

        if ($this->stopBotOnReset && $isRunning) {
            $setting->update(['is_running' => false]);
            Cache::forget(MasterListenerJob::heartbeatKey($setting->master_connection_id));
            $this->paused = false;
            $isRunning = false;
        }

        $updates = [
            'stop_reason' => null,
            'stopped_at_profit' => null,
            'session_started_at' => now(),
        ];

        if ($this->resetBalance) {
            if ($isRunning) {
                try {
                    $connection = auth()->user()->derivConnection;
                    $followerAccountId = auth()->user()->copySetting?->follower_account_id;

                    if ($connection && $followerAccountId) {
                        $result = app(DerivApiService::class)->getBalance($connection, $followerAccountId, allowStale: false);
                        $updates['start_balance'] = (float) ($result['balance']['balance'] ?? 0);
                    }
                } catch (\Throwable) {
                    $updates['start_balance'] = null;
                }
            } else {
                $updates['start_balance'] = null;
            }
        }

        // Save any settings the user may have edited in the "More Settings" section
        $updates = array_merge($updates, [
            'stake' => $this->stake,
            'follow_master_stake' => $this->followMasterStake,
            'safe_mode' => $this->safeMode,
            'stake_multiplier' => $this->stakeMultiplier,
            'take_profit' => $this->takeProfit,
            'stop_loss' => $this->stopLoss,
            'max_compound' => $this->maxCompound,
            'do_martingale_at' => $this->doMartingaleAt,
            'max_martingale' => $this->maxMartingale,
            'if_hit_max_martingale' => $this->ifHitMaxMartingale,
            'wait_for_loss' => $this->waitForLoss,
            'only_use_1x_wait_for_loss' => $this->onlyUse1xWaitForLoss,
            'filter_markets' => $this->filterMarkets,
            'synthetic_indices' => $this->syntheticIndices,
            'forex_pairs' => $this->forexPairs,
        ]);

        $setting->update($updates);

        Redis::del("master_outcomes_offset:{$setting->master_connection_id}:".auth()->id());
        Cache::forget(CopyTradeJob::patternConsumedKey($setting->master_connection_id, auth()->id()));
        Cache::forget(CopyTradeJob::waitTriggerUsedKeyFor($setting->master_connection_id, auth()->id()));

        $this->showResetModal = false;
        $this->resetPage();
    }

    public function performFullReset(): void
    {
        $setting = auth()->user()->copySetting;

        if (! $setting) {
            $this->showResetModal = false;

            return;
        }

        CopyTrade::query()->where('user_id', auth()->id())->delete();

        if ($setting->is_running) {
            $setting->update(['is_running' => false]);
            Cache::forget(MasterListenerJob::heartbeatKey($setting->master_connection_id));
        }

        $setting->update([
            'start_balance' => null,
            'session_started_at' => null,
            'is_running' => false,
            'stop_reason' => null,
            'stopped_at_profit' => null,
            'stake' => 1.00,
            'follow_master_stake' => false,
            'safe_mode' => false,
            'stake_multiplier' => 1.00,
            'take_profit' => null,
            'stop_loss' => null,
            'max_compound' => 0,
            'do_martingale_at' => 1,
            'max_martingale' => 0,
            'if_hit_max_martingale' => 'stop',
            'wait_for_loss' => 0,
            'only_use_1x_wait_for_loss' => false,
            'filter_markets' => [],
            'synthetic_indices' => [],
            'forex_pairs' => [],
        ]);

        $this->stake = 1.00;
        $this->stakeMultiplier = 1.00;
        $this->takeProfit = null;
        $this->stopLoss = null;
        $this->maxCompound = 0;
        $this->doMartingaleAt = 1;
        $this->maxMartingale = 0;
        $this->ifHitMaxMartingale = 'stop';
        $this->waitForLoss = 0;
        $this->onlyUse1xWaitForLoss = false;
        $this->followMasterStake = false;
        $this->safeMode = false;
        $this->filterMarkets = [];
        $this->syntheticIndices = [];
        $this->forexPairs = [];
        $this->paused = false;

        Redis::del("master_outcomes_offset:{$setting->master_connection_id}:".auth()->id());
        Cache::forget(CopyTradeJob::patternConsumedKey($setting->master_connection_id, auth()->id()));
        Cache::forget(CopyTradeJob::waitTriggerUsedKeyFor($setting->master_connection_id, auth()->id()));

        $this->showResetModal = false;
        $this->resetPage();
    }

    public function dismissStopPopup(): void
    {
        auth()->user()->copySetting?->update([
            'stop_reason' => null,
            'stopped_at_profit' => null,
        ]);

        unset($this->stoppedReason);
    }

    public function exportTransactions(): void
    {
        $this->dispatch('export-transactions');
    }

    // ---- Column & market toggles ----

    public function toggleColumn(string $col): void
    {
        $this->visibleColumns[$col] = ! ($this->visibleColumns[$col] ?? true);
    }

    public function updatingPerPage(): void
    {
        $this->resetPage();
    }

    public function toggleMarket(string $market): void
    {
        if (in_array($market, $this->filterMarkets)) {
            $this->filterMarkets = array_values(array_filter($this->filterMarkets, fn ($m) => $m !== $market));
        } else {
            $this->filterMarkets[] = $market;
        }
    }

    public function toggleSynthetic(string $index): void
    {
        if (in_array($index, $this->syntheticIndices)) {
            $this->syntheticIndices = array_values(array_filter($this->syntheticIndices, fn ($i) => $i !== $index));
        } else {
            $this->syntheticIndices[] = $index;
        }
    }

    public function toggleForex(string $pair): void
    {
        if (in_array($pair, $this->forexPairs)) {
            $this->forexPairs = array_values(array_filter($this->forexPairs, fn ($p) => $p !== $pair));
        } else {
            $this->forexPairs[] = $pair;
        }
    }

    /**
     * Bounds for every setting saved from the settings panel or the reset modal.
     *
     * @return array<string, array<int, mixed>>
     */
    private function settingsRules(): array
    {
        return [
            'stake' => ['required', 'numeric', 'min:0.35', 'max:'.config('deriv.max_stake')],
            'stakeMultiplier' => ['required', 'numeric', 'min:1', 'max:100'],
            'takeProfit' => ['nullable', 'numeric', 'gt:0'],
            'stopLoss' => ['nullable', 'numeric', 'gt:0'],
            'followerPattern' => ['required', 'regex:/^[01]+$/', 'max:20'],
            'maxCompound' => ['integer', 'min:0', 'max:50'],
            'waitForLoss' => ['integer', 'min:0', 'max:50'],
            'maxMartingale' => ['integer', 'min:0', 'max:50'],
            'doMartingaleAt' => ['integer', 'min:1', 'max:50'],
            'ifHitMaxMartingale' => ['required', 'in:stop,continue'],
            'filterMarkets' => ['array'],
            'filterMarkets.*' => [Rule::in(CopySetting::MARKET_SYMBOLS)],
            'syntheticIndices' => ['array'],
            'syntheticIndices.*' => [Rule::in(array_keys(CopySetting::TRADE_TYPES))],
            'forexPairs' => ['array'],
            'forexPairs.*' => [Rule::in(array_keys(CopySetting::FOREX_SYMBOLS))],
        ];
    }

    /** Public masters may not follow anyone: every follow edge then points at a master, so copy loops cannot form. */
    private function ownConnectionIsMaster(): bool
    {
        return (bool) auth()->user()->derivConnection?->isMaster();
    }

    #[On('copy-setting-saved')]
    public function refreshSetting(): void
    {
        $this->mount();
    }

    // ---- Computed ----

    #[Computed]
    public function setting(): ?CopySetting
    {
        return auth()->user()->load('copySetting.masterConnection.user')->copySetting;
    }

    /** Info about why the bot was auto-stopped; empty array if nothing to show. */
    #[Computed]
    public function stoppedReason(): array
    {
        $setting = $this->setting;

        if (! $setting || ! $setting->stop_reason) {
            return [];
        }

        $query = CopyTrade::query()->where('user_id', auth()->id())->where('status', '!=', CopyTrade::STATUS_FAILED);

        if ($setting->session_started_at) {
            $query->where('traded_at', '>=', $setting->session_started_at);
        }

        $wins = (clone $query)->where('is_win', true)->count();
        $losses = (clone $query)->where('is_win', false)->count();
        $profit = (float) (clone $query)->sum('profit');

        return [
            'reason' => $setting->stop_reason,
            'profit' => (float) ($setting->stopped_at_profit ?? $profit),
            'wins' => $wins,
            'losses' => $losses,
            'total' => $wins + $losses,
            'take_profit_target' => $setting->take_profit,
            'stop_loss_target' => $setting->stop_loss,
        ];
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
    public function stats(): array
    {
        $query = CopyTrade::query()->where('user_id', auth()->id())->where('status', '!=', CopyTrade::STATUS_FAILED);

        $sessionStart = $this->setting?->session_started_at;

        if ($sessionStart) {
            $query->where('traded_at', '>=', $sessionStart);
        }

        $wins = (clone $query)->where('is_win', true)->count();
        $losses = (clone $query)->where('is_win', false)->count();
        $total = $wins + $losses;

        return [
            'trade_count' => $total,
            'win_count' => $wins,
            'loss_count' => $losses,
            'total_stake' => (float) (clone $query)->sum('stake'),
            'total_payout' => (float) (clone $query)->sum('payout'),
            'total_profit' => (float) (clone $query)->sum('profit'),
            'contracts_won' => $wins,
            'contracts_lost' => $losses,
        ];
    }

    #[Computed]
    public function myAccounts(): array
    {
        $connection = auth()->user()->derivConnection;

        if (! $connection || $connection->isExpired()) {
            return [];
        }

        try {
            return app(DerivApiService::class)->getAccounts($connection);
        } catch (DerivApiException) {
            return [];
        }
    }

    #[Computed]
    public function currentBalance(): ?float
    {
        $connection = auth()->user()->derivConnection;
        $followerAccountId = $this->setting?->follower_account_id;

        if (! $connection || ! $followerAccountId) {
            return null;
        }

        try {
            $result = app(DerivApiService::class)->getBalance($connection, $followerAccountId, self::POLLED_BALANCE_MAX_AGE_SECONDS);

            return (float) ($result['balance']['balance'] ?? 0);
        } catch (\Throwable) {
            return null;
        }
    }

    #[Computed]
    public function listenerAlive(): bool
    {
        $setting = $this->setting;

        if (! $setting?->master_connection_id) {
            return false;
        }

        return Cache::has(MasterListenerJob::heartbeatKey($setting->master_connection_id));
    }

    public function render(): View
    {
        $query = CopyTrade::query()->where('user_id', auth()->id());

        $sessionStart = $this->setting?->session_started_at;
        if ($sessionStart) {
            $query->where('traded_at', '>=', $sessionStart);
        }

        $trades = $query->orderBy('traded_at')->paginate($this->perPage);

        return view('livewire.copy-trading.dashboard', compact('trades'));
    }
}
