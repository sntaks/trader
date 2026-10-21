@php
$setting = $this->setting;
$initialSeconds = ($setting?->is_running && $setting?->session_started_at)
    ? max(0, now()->diffInSeconds($setting->session_started_at))
    : 0;
@endphp
<div class="space-y-6" wire:poll.15s x-data="{ runSeconds: {{ $initialSeconds }}, timer: null }"
    x-init="
        if ($wire.setting?.is_running) { timer = setInterval(() => runSeconds++, 1000); }
        $watch('$wire.setting?.is_running', val => {
            clearInterval(timer);
            if (val) { runSeconds = 0; timer = setInterval(() => runSeconds++, 1000); }
            else { runSeconds = 0; }
        });
    ">

    {{-- ================================================================ --}}
    {{-- Stop Reason Popup (take profit / stop loss / max martingale)      --}}
    {{-- ================================================================ --}}
    @if(! empty($this->stoppedReason))
    @php $sr = $this->stoppedReason; @endphp
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 backdrop-blur-sm px-4">
        <div class="w-full max-w-md rounded-2xl border border-[#1F2937] bg-[#0B1220] shadow-2xl">
            {{-- Header --}}
            <div class="flex items-center gap-3 border-b border-[#1F2937] px-6 py-5">
                @if($sr['reason'] === 'take_profit')
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[#22C55E]/15">
                        <flux:icon.check-badge class="size-6 text-[#22C55E]" />
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-[#22C55E]">Take Profit Reached!</h3>
                        <p class="text-xs text-zinc-400">Trading stopped automatically</p>
                    </div>
                @elseif($sr['reason'] === 'stop_loss')
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-red-500/15">
                        <flux:icon.x-circle class="size-6 text-red-400" />
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-red-400">Stop Loss Reached</h3>
                        <p class="text-xs text-zinc-400">Trading stopped automatically</p>
                    </div>
                @else
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-500/15">
                        <flux:icon.exclamation-triangle class="size-6 text-amber-400" />
                    </div>
                    <div>
                        <h3 class="text-lg font-bold text-amber-400">Max Martingale Reached</h3>
                        <p class="text-xs text-zinc-400">Trading stopped automatically</p>
                    </div>
                @endif
            </div>

            {{-- Stats --}}
            <div class="px-6 py-5 space-y-4">
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-lg bg-[#111827] px-3 py-3 text-center">
                        <p class="text-xs text-zinc-400 uppercase tracking-wide">Trades</p>
                        <p class="mt-1 text-xl font-bold text-white">{{ $sr['total'] }}</p>
                    </div>
                    <div class="rounded-lg bg-[#111827] px-3 py-3 text-center">
                        <p class="text-xs text-zinc-400 uppercase tracking-wide">W / L</p>
                        <p class="mt-1 text-xl font-bold text-white">{{ $sr['wins'] }}/{{ $sr['losses'] }}</p>
                    </div>
                    <div class="rounded-lg bg-[#111827] px-3 py-3 text-center">
                        <p class="text-xs text-zinc-400 uppercase tracking-wide">Profit</p>
                        <p class="mt-1 text-xl font-bold {{ $sr['profit'] >= 0 ? 'text-[#22C55E]' : 'text-red-400' }}">
                            {{ $sr['profit'] >= 0 ? '+' : '' }}{{ number_format($sr['profit'], 2) }}
                        </p>
                    </div>
                </div>

                @if($sr['reason'] === 'take_profit' && $sr['take_profit_target'])
                    <div class="rounded-lg border border-[#22C55E]/20 bg-[#22C55E]/5 px-4 py-3 text-sm text-[#22C55E]">
                        Target of <strong>+{{ number_format($sr['take_profit_target'], 2) }} USD</strong> hit. Well done!
                    </div>
                @elseif($sr['reason'] === 'stop_loss' && $sr['stop_loss_target'])
                    <div class="rounded-lg border border-red-500/20 bg-red-500/5 px-4 py-3 text-sm text-red-400">
                        Loss limit of <strong>{{ number_format($sr['stop_loss_target'], 2) }} USD</strong> reached. Bot has stopped to protect your balance.
                    </div>
                @else
                    <div class="rounded-lg border border-amber-500/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-400">
                        Maximum martingale levels exhausted. Bot stopped to prevent further stake escalation.
                    </div>
                @endif
            </div>

            <div class="flex gap-3 border-t border-[#1F2937] px-6 py-4">
                <flux:button wire:click="dismissStopPopup" variant="primary" class="flex-1">
                    Dismiss
                </flux:button>
                <flux:button wire:click="startBot" variant="ghost" class="flex-1">
                    Start New Session
                </flux:button>
            </div>
        </div>
    </div>
    @endif

    {{-- ================================================================ --}}
    {{-- Reset Modal                                                       --}}
    {{-- ================================================================ --}}
    @if($showResetModal)
    @php $isRunning = (bool) ($setting?->is_running); @endphp
    <div class="fixed inset-0 z-40 flex items-start justify-center overflow-y-auto bg-black/70 backdrop-blur-sm px-4 py-8">
        <div class="w-full max-w-lg rounded-2xl border border-[#1F2937] bg-[#0B1220] shadow-2xl" x-data="{ moreSettings: false }">

            {{-- Header --}}
            <div class="flex items-center gap-3 border-b border-[#1F2937] px-6 py-5">
                <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-500/15">
                    <flux:icon.arrow-path class="size-5 text-amber-400" />
                </div>
                <div>
                    <h3 class="font-bold text-white">Reset</h3>
                    <p class="text-xs {{ $isRunning ? 'text-[#22C55E]' : 'text-zinc-400' }}">
                        @if($isRunning)
                            <span class="mr-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-[#22C55E]"></span>Bot is currently running
                        @else
                            <span class="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-zinc-500"></span>Bot is stopped
                        @endif
                    </p>
                </div>
            </div>

            {{-- Options --}}
            <div class="space-y-3 px-6 py-5">
                {{-- Always: clear history --}}
                <label class="flex cursor-default items-center gap-3 rounded-lg border border-[#1F2937] bg-[#111827] px-4 py-3 opacity-60">
                    <input type="checkbox" checked disabled class="rounded border-zinc-600 bg-[#111827] text-[#1E45FC]" />
                    <div>
                        <p class="text-sm font-medium text-white">Clear trade history</p>
                        <p class="text-xs text-zinc-500">All recorded trades for this session will be deleted</p>
                    </div>
                </label>

                {{-- Reset balance --}}
                <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-[#1F2937] bg-[#111827] px-4 py-3 hover:border-zinc-600 transition-colors">
                    <input type="checkbox" wire:model="resetBalance" class="rounded border-zinc-600 bg-[#111827] text-[#1E45FC]" />
                    <div>
                        <p class="text-sm font-medium text-white">Reset balance snapshot</p>
                        <p class="text-xs text-zinc-500">
                            @if($isRunning) Updates start balance to your current balance @else Clears the start balance @endif
                        </p>
                    </div>
                </label>

                {{-- Stop bot (only if running) --}}
                @if($isRunning)
                <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-[#1F2937] bg-[#111827] px-4 py-3 hover:border-zinc-600 transition-colors">
                    <input type="checkbox" wire:model="stopBotOnReset" class="rounded border-zinc-600 bg-[#111827] text-red-500" />
                    <div>
                        <p class="text-sm font-medium text-white">Stop bot</p>
                        <p class="text-xs text-zinc-500">Bot will be stopped before resetting. Uncheck to keep it running.</p>
                    </div>
                </label>
                @endif
            </div>

            {{-- More Settings --}}
            <div class="border-t border-[#1F2937] px-6 pb-2 pt-3">
                <button @click="moreSettings = !moreSettings"
                    class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium text-zinc-400 transition-colors hover:bg-[#111827] hover:text-white">
                    <span x-text="moreSettings ? 'Hide Settings' : 'More Settings'"></span>
                    <svg x-bind:class="moreSettings ? 'rotate-180' : ''" class="size-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                    </svg>
                </button>

                <div x-show="moreSettings" x-collapse class="pb-4 pt-2">
                    <p class="mb-3 text-xs text-zinc-500">Adjust settings here — they will be saved when you click Apply Reset.</p>
                    <div class="grid grid-cols-2 gap-3">
                        <flux:input wire:model="stake" label="Stake (USD)" type="number" step="0.01" min="0.35" />
                        <flux:input wire:model="stakeMultiplier" label="Stake Multiplier ×" type="number" step="0.1" min="1" />
                        <flux:input wire:model="takeProfit" label="Take Profit" type="number" min="0" placeholder="—" />
                        <flux:input wire:model="stopLoss" label="Stop Loss" type="number" min="0" placeholder="—" />
                        <flux:input wire:model="doMartingaleAt" label="Do Martingale at" type="number" min="1" />
                        <flux:input wire:model="maxMartingale" label="Max Martingale" type="number" min="0" />
                        <flux:input wire:model="maxCompound" label="Max Compound" type="number" min="0" />
                        <flux:input wire:model="waitForLoss" label="Wait for Loss" type="number" min="0" />
                    </div>
                    <div class="mt-3 flex flex-wrap gap-4">
                        <flux:checkbox wire:model="followMasterStake" label="Follow Master Stake" />
                        <flux:checkbox wire:model="safeMode" label="Safe Mode" />
                        <flux:checkbox wire:model="onlyUse1xWaitForLoss" label="Only 1× Wait for Loss" />
                    </div>
                </div>
            </div>

            {{-- Buttons --}}
            <div class="flex gap-3 border-t border-[#1F2937] px-6 py-4">
                <flux:button wire:click="performReset" wire:loading.attr="disabled" wire:target="performReset"
                    variant="primary" class="flex-1">
                    <span wire:loading.remove wire:target="performReset">Apply Reset</span>
                    <span wire:loading wire:target="performReset">Resetting…</span>
                </flux:button>
                <flux:button wire:click="performFullReset" wire:loading.attr="disabled" wire:target="performFullReset"
                    wire:confirm="This will clear all history, restore all settings to defaults, and stop the bot. Are you sure?"
                    variant="danger">
                    <span wire:loading.remove wire:target="performFullReset">Full Reset</span>
                    <span wire:loading wire:target="performFullReset">…</span>
                </flux:button>
                <flux:button wire:click="$set('showResetModal', false)" variant="ghost">Cancel</flux:button>
            </div>
        </div>
    </div>
    @endif

    {{-- ================================================================ --}}
    {{-- VIEW A: No master followed → show master list / configure form   --}}
    {{-- ================================================================ --}}
    @if(! $setting)

        @if($showForm && $selectedMasterId)
            {{-- ---- Configure Form (before first follow) ---- --}}
            @php $master = $this->masters->firstWhere('id', $selectedMasterId); @endphp
            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
                <div class="flex items-center gap-3 border-b border-[#1F2937] px-6 py-4">
                    <button wire:click="cancelForm" class="text-zinc-400 hover:text-white transition-colors">
                        <flux:icon.arrow-left class="size-5" />
                    </button>
                    <div>
                        <flux:heading size="lg">Configure Copy Settings</flux:heading>
                        <flux:text class="mt-0.5 text-sm text-zinc-500">
                            @if($ownAccountMode)
                                Trading your own accounts
                            @else
                                Following: <span class="font-medium text-white">{{ $master?->user->name }}</span>
                            @endif
                        </flux:text>
                    </div>
                </div>

                <div class="space-y-5 px-6 py-5">

                    {{-- Own-account mode: master account selector --}}
                    @if($ownAccountMode && count($this->myAccounts) > 0)
                        <div>
                            <flux:label>Master Account <span class="text-amber-400">(trades to copy FROM)</span></flux:label>
                            <flux:text class="mb-3 text-xs text-zinc-500">Choose which of your accounts to copy trades from.</flux:text>
                            <div class="space-y-2">
                                @foreach($this->myAccounts as $account)
                                    @php
                                        $isDemo = ($account['is_demo'] ?? false) || ($account['account_type'] ?? '') === 'demo';
                                        $accLabel = ($isDemo ? 'Demo' : 'Real') . ' — ' . $account['account_id'];
                                        $accSub = number_format($account['balance'] ?? 0, 2) . ' ' . strtoupper($account['currency'] ?? 'USD');
                                    @endphp
                                    <label wire:key="master-acc-{{ $account['account_id'] }}"
                                        class="flex cursor-pointer items-center gap-4 rounded-lg border p-4 transition-colors
                                            {{ $masterAccountId === $account['account_id']
                                                ? 'border-amber-500/50 bg-amber-500/5'
                                                : 'border-[#1F2937] bg-[#111827] hover:border-[#1F2937]/80' }}"
                                    >
                                        <input type="radio" wire:model.live="masterAccountId" value="{{ $account['account_id'] }}"
                                            class="text-amber-400 focus:ring-amber-400" />
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-medium text-white">{{ $accLabel }}</span>
                                                <span @class([
                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                    'bg-amber-500/10 text-amber-400' => $isDemo,
                                                    'bg-[#22C55E]/10 text-[#22C55E]' => !$isDemo,
                                                ])>{{ $isDemo ? 'Demo' : 'Real' }}</span>
                                                <span class="inline-flex items-center rounded-full bg-amber-900/30 px-2 py-0.5 text-xs font-medium text-amber-400">MASTER</span>
                                            </div>
                                            <p class="mt-0.5 text-xs text-zinc-500">Balance: {{ $accSub }}</p>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                            @error('masterAccountId')
                                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    {{-- Follower account selector --}}
                    @if(count($this->myAccounts) > 0)
                        <div>
                            <flux:label>
                                @if($ownAccountMode)
                                    Follower Account <span class="text-[#1E45FC]">(trades to copy TO)</span>
                                @else
                                    Your Trading Account
                                @endif
                            </flux:label>
                            <flux:text class="mb-3 text-xs text-zinc-500">
                                @if($ownAccountMode)
                                    Choose which account will receive copied trades.
                                @else
                                    Choose which account (real or demo) will copy trades.
                                @endif
                            </flux:text>
                            <div class="space-y-2">
                                @foreach($this->myAccounts as $account)
                                    @php
                                        $isDemo = ($account['is_demo'] ?? false) || ($account['account_type'] ?? '') === 'demo';
                                        $accLabel = ($isDemo ? 'Demo' : 'Real') . ' — ' . $account['account_id'];
                                        $accSub = number_format($account['balance'] ?? 0, 2) . ' ' . strtoupper($account['currency'] ?? 'USD');
                                    @endphp
                                    <label wire:key="setup-acc-{{ $account['account_id'] }}"
                                        class="flex cursor-pointer items-center gap-4 rounded-lg border p-4 transition-colors
                                            {{ $followerAccountId === $account['account_id']
                                                ? 'border-[#1E45FC]/50 bg-[#1E45FC]/5'
                                                : 'border-[#1F2937] bg-[#111827] hover:border-[#1F2937]/80' }}"
                                    >
                                        <input type="radio" wire:model.live="followerAccountId" value="{{ $account['account_id'] }}"
                                            class="text-[#1E45FC] focus:ring-[#1E45FC]" />
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <span class="text-sm font-medium text-white">{{ $accLabel }}</span>
                                                <span @class([
                                                    'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                    'bg-amber-500/10 text-amber-400' => $isDemo,
                                                    'bg-[#22C55E]/10 text-[#22C55E]' => !$isDemo,
                                                ])>{{ $isDemo ? 'Demo' : 'Real' }}</span>
                                            </div>
                                            <p class="mt-0.5 text-xs text-zinc-500">Balance: {{ $accSub }}</p>
                                        </div>
                                    </label>
                                @endforeach
                            </div>
                            @error('followerAccountId')
                                <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                            @enderror
                        </div>
                    @endif

                    {{-- Pattern --}}
                    <flux:checkbox wire:model.live="patternEnabled" label="Enable Follower Pattern" />
                    <div>
                        <flux:input
                            wire:model.live="followerPattern"
                            label="Slave / Follower Pattern"
                            placeholder="e.g. 111 or 101"
                            :disabled="!$patternEnabled"
                            description="Use 1 for Win, 0 for Loss. Copying starts when master's last outcomes match this sequence."
                        />
                        @error('followerPattern')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                        @if($patternEnabled && strlen($followerPattern) > 0 && preg_match('/^[01]+$/', $followerPattern))
                            <div class="mt-3 flex flex-wrap items-center gap-2">
                                <span class="text-xs text-zinc-400">Pattern:</span>
                                @foreach(str_split($followerPattern) as $bit)
                                    <span @class([
                                        'inline-flex h-8 w-8 items-center justify-center rounded-lg text-sm font-bold',
                                        'bg-[#22C55E]/20 text-[#22C55E] border border-[#22C55E]/40' => $bit === '1',
                                        'bg-red-500/20 text-red-400 border border-red-500/40' => $bit === '0',
                                    ])>{{ $bit === '1' ? 'W' : 'L' }}</span>
                                @endforeach
                                <span class="ml-1 text-xs text-zinc-500">({{ strlen($followerPattern) }} trade{{ strlen($followerPattern) !== 1 ? 's' : '' }} needed)</span>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-lg border border-amber-800/40 bg-amber-900/20 p-4">
                        <div class="flex gap-3">
                            <flux:icon.information-circle class="size-5 shrink-0 text-amber-400" />
                            <p class="text-xs text-amber-400">
                                The bot reads the master's last <strong>{{ strlen($followerPattern) }}</strong> trade result{{ strlen($followerPattern) !== 1 ? 's' : '' }}.
                                When they match <strong class="font-mono text-[#CDF12B]">{{ $followerPattern }}</strong>, the next trade is copied.
                                <strong>1</strong> = master won, <strong>0</strong> = master lost.
                            </p>
                        </div>
                    </div>

                    @error('selectedMasterId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror

                    <div class="flex gap-2 pt-1">
                        <flux:button wire:click="follow" wire:loading.attr="disabled" wire:target="follow" variant="primary">
                            <span wire:loading.remove wire:target="follow">Start Following</span>
                            <span wire:loading wire:target="follow">Saving…</span>
                        </flux:button>
                        <flux:button wire:click="cancelForm" variant="ghost">Cancel</flux:button>
                    </div>
                </div>
            </div>
        @endif

        {{-- ---- Available Masters + Own Account option ---- --}}
        <div>
            @if(! $showForm)
                <div class="mb-4">
                    <flux:heading size="lg">Copy Trading Setup</flux:heading>
                    <flux:text class="text-sm text-zinc-500">Follow a platform master or use your own accounts.</flux:text>
                </div>
            @endif

            {{-- Own account card --}}
            @php $ownConnection = auth()->user()->derivConnection; @endphp
            @if($ownConnection && !$showForm)
                <div class="mb-6">
                    <p class="mb-3 text-xs font-semibold uppercase tracking-wider text-zinc-400">Your Own Accounts</p>
                    <div class="rounded-xl border border-amber-800/40 bg-[#0B1220] p-5 hover:border-amber-500/40 transition-colors">
                        <div class="mb-3 flex items-start gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-900/40">
                                <flux:icon.arrows-right-left class="size-5 text-amber-400" />
                            </div>
                            <div>
                                <p class="font-semibold text-white">Use My Own Account</p>
                                <p class="text-xs text-zinc-500">Copy trades between your own Deriv accounts — e.g. demo → real, or demo → demo.</p>
                            </div>
                        </div>
                        <div class="mb-4 rounded-lg bg-amber-900/10 px-4 py-3 text-xs text-amber-300/80">
                            You can set any of your accounts (demo or real) as the master, and copy trades into any other account.
                        </div>
                        <flux:button wire:click="enterOwnAccountMode" variant="filled" size="sm" class="w-full bg-amber-600 hover:bg-amber-500 text-white">
                            Configure Account Trading
                        </flux:button>
                        @error('selectedMasterId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                    </div>
                </div>
            @endif

            {{-- Platform masters (coming soon) --}}
            @if(! $showForm)
                <div class="flex items-center gap-3 mb-3">
                    <p class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Platform Masters</p>
                    <span class="inline-flex items-center rounded-full border border-zinc-600/50 bg-zinc-800/60 px-2.5 py-0.5 text-xs font-medium text-zinc-400">
                        Coming Soon
                    </span>
                </div>
                <div class="rounded-xl border border-dashed border-zinc-700/60 bg-zinc-900/40 px-6 py-10 text-center">
                    <div class="mx-auto mb-3 w-fit rounded-full bg-zinc-800/80 p-4">
                        <flux:icon.user-group class="size-6 text-zinc-500" />
                    </div>
                    <p class="text-sm font-medium text-zinc-400">Platform Masters — Coming Soon</p>
                    <p class="mt-1 text-xs text-zinc-600">For now, use your own accounts to set up copy trading above.</p>
                </div>
            @endif
        </div>

    @else
    {{-- ================================================================ --}}
    {{-- VIEW B: Following a master → full trading dashboard              --}}
    {{-- ================================================================ --}}

        {{-- ===== TOP CONTROL BAR ===== --}}
        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-[#1F2937] bg-[#0B1220] px-5 py-4">
            <div class="flex flex-wrap items-center gap-3">
                {{-- Run / Stop --}}
                @if($setting->is_running)
                    <flux:button wire:click="stopBot" wire:loading.attr="disabled" wire:target="stopBot"
                        variant="danger" icon="stop-circle" size="sm">
                        Stop Bot
                    </flux:button>
                @else
                    <flux:button wire:click="startBot" wire:loading.attr="disabled" wire:target="startBot"
                        variant="primary" icon="play" size="sm">
                        Run
                    </flux:button>
                @endif

                {{-- Pause / Resume --}}
                @if($setting->is_running)
                    <flux:button wire:click="pauseBot" size="sm"
                        variant="{{ $paused ? 'primary' : 'ghost' }}"
                        icon="{{ $paused ? 'play' : 'pause' }}">
                        {{ $paused ? 'Resume' : 'Pause Trade' }}
                    </flux:button>
                @endif

                {{-- Running time --}}
                @if($setting->is_running)
                    <span class="text-xs text-zinc-400">
                        Running:
                        <span x-text="new Date(runSeconds * 1000).toISOString().substr(11, 8)" class="font-mono text-[#CDF12B]">00:00:00</span>
                    </span>
                @endif

                {{-- Status indicator --}}
                <span class="text-sm {{ $setting->is_running ? 'text-[#22C55E]' : 'text-zinc-400' }}">
                    @if($setting->is_running)
                        <span class="mr-1 inline-block h-2 w-2 animate-pulse rounded-full bg-[#22C55E]"></span>
                        Bot is running{{ $paused ? ' (paused)' : '' }}
                    @else
                        <span class="mr-1 inline-block h-2 w-2 rounded-full bg-zinc-500"></span>
                        Bot is not running
                    @endif
                </span>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button wire:click="$toggle('settingsOpen')" size="sm" variant="ghost" icon="cog-6-tooth">
                    Settings
                </flux:button>
                <flux:button wire:click="openResetModal" wire:loading.attr="disabled" wire:target="openResetModal"
                    size="sm" variant="ghost" icon="arrow-path">
                    Reset
                </flux:button>
                <flux:button wire:click="disconnect" wire:confirm="Stop copy trading and disconnect from this master?" size="sm" variant="ghost" icon="x-circle">
                    Disconnect
                </flux:button>
            </div>
        </div>

        {{-- ===== LISTENER STATUS + MASTER OUTCOMES TICKER + SLAVE PATTERN ===== --}}
        <div class="flex flex-wrap items-start gap-4">
            @if($setting->is_running)
                <div @class([
                    'flex shrink-0 items-center gap-3 rounded-xl border px-4 py-3',
                    'border-[#22C55E]/30 bg-[#22C55E]/5' => $this->listenerAlive,
                    'border-amber-700/40 bg-amber-900/10' => !$this->listenerAlive,
                ])>
                    @if($this->listenerAlive)
                        <span class="inline-block h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-[#22C55E]"></span>
                        <span class="text-sm font-medium text-[#22C55E]">Listener active</span>
                    @else
                        <span class="inline-block h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-amber-400"></span>
                        <span class="text-sm font-medium text-amber-300">Listener starting…</span>
                    @endif
                </div>
            @endif

            {{-- Master outcomes + slave pattern --}}
            <div class="flex-1 min-w-0 rounded-xl border border-[#1F2937] bg-[#0B1220] px-4 py-3 space-y-2">
                @livewire('copy-trading.master-outcomes-ticker', [
                    'connectionId'   => $setting->master_connection_id,
                    'pattern'        => $setting->follower_pattern ?? '',
                    'patternEnabled' => (bool) $setting->pattern_enabled,
                ], key('ticker-'.$setting->master_connection_id))

                {{-- Slave pattern display --}}
                @if($setting->pattern_enabled && $setting->follower_pattern)
                    <div class="flex flex-wrap items-center gap-1.5 border-t border-[#1F2937] pt-2">
                        <span class="mr-1 text-xs font-medium uppercase tracking-wide text-zinc-500">Slave Pattern:</span>
                        @foreach(str_split($setting->follower_pattern) as $bit)
                            <span @class([
                                'inline-flex h-6 w-6 items-center justify-center rounded font-mono text-xs font-bold',
                                'border border-[#22C55E]/30 bg-[#22C55E]/15 text-[#22C55E]' => $bit === '1',
                                'border border-red-500/30 bg-red-500/15 text-red-400' => $bit === '0',
                            ])>{{ $bit }}</span>
                        @endforeach
                        <span class="ml-1 text-xs text-zinc-500 font-mono">({{ strlen($setting->follower_pattern) }} trades)</span>
                    </div>
                @endif
            </div>
        </div>

        {{-- ===== EXPANDABLE SETTINGS PANEL ===== --}}
        @if($settingsOpen)
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]" x-data="{ moreSettings: false }">
            <div class="flex items-center justify-between border-b border-[#1F2937] px-6 py-4">
                <flux:heading size="lg">Trade Settings</flux:heading>
                <flux:button wire:click="$toggle('settingsOpen')" size="sm" variant="ghost" icon="x-mark" />
            </div>

            <div class="grid grid-cols-1 gap-6 p-6 md:grid-cols-2 xl:grid-cols-3">

                {{-- Master / Change master --}}
                <div class="col-span-full rounded-lg border border-[#1F2937] bg-[#111827] p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Master</p>
                            @if($setting->master_account_id)
                                <p class="mt-1 font-semibold text-white">
                                    Own account — <span class="font-mono text-amber-400">{{ $setting->master_account_id }}</span>
                                </p>
                                <p class="text-xs text-zinc-500">Your own account is the master</p>
                            @else
                                <p class="mt-1 font-semibold text-white">{{ $setting->masterConnection?->user?->name }}</p>
                                <p class="text-xs text-zinc-500">{{ $setting->masterConnection?->user?->email }}</p>
                            @endif
                        </div>
                        <flux:button wire:click="$toggle('showMasterList')" size="sm" variant="ghost" icon="arrows-right-left">
                            {{ $showMasterList ? 'Cancel' : 'Change Master' }}
                        </flux:button>
                    </div>

                    @if($showMasterList)
                        <div class="mt-4 space-y-3">
                            @error('selectedMasterId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                            @error('masterAccountId') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror

                            {{-- Own account option --}}
                            @php $ownConn = auth()->user()->derivConnection; @endphp
                            @if($ownConn)
                                <div class="rounded-lg border border-amber-800/40 bg-[#0B1220] p-3">
                                    <p class="mb-2 text-xs font-semibold text-amber-400">Use My Own Account</p>

                                    {{-- Master account picker --}}
                                    @if(count($this->myAccounts) > 0)
                                        <p class="mb-1 text-xs text-zinc-500">Master account (copy FROM):</p>
                                        <div class="mb-2 space-y-1">
                                            @foreach($this->myAccounts as $account)
                                                @php
                                                    $isDemoAcc = ($account['is_demo'] ?? false) || ($account['account_type'] ?? '') === 'demo';
                                                @endphp
                                                <label class="flex cursor-pointer items-center gap-2 rounded p-1.5 hover:bg-zinc-800">
                                                    <input type="radio" wire:model.live="masterAccountId" value="{{ $account['account_id'] }}"
                                                        class="text-amber-400 focus:ring-amber-400" />
                                                    <span class="text-xs text-white">{{ $isDemoAcc ? 'Demo' : 'Real' }} — {{ $account['account_id'] }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    @endif

                                    @if(! ($setting->master_account_id && $setting->master_connection_id === $ownConn->id))
                                        <flux:button wire:click="switchToOwnAccounts" wire:loading.attr="disabled" wire:target="switchToOwnAccounts"
                                            size="xs" class="w-full bg-amber-600 hover:bg-amber-500 text-white">
                                            <span wire:loading.remove wire:target="switchToOwnAccounts">Switch to Own Account</span>
                                            <span wire:loading wire:target="switchToOwnAccounts">…</span>
                                        </flux:button>
                                    @else
                                        <span class="inline-flex w-full items-center justify-center gap-1 rounded-full bg-amber-500/10 py-1 text-xs font-medium text-amber-400">
                                            <span class="h-1.5 w-1.5 rounded-full bg-amber-400"></span>
                                            Current (own account)
                                        </span>
                                    @endif
                                </div>
                            @endif

                            {{-- Platform masters (coming soon) --}}
                            <div class="flex items-center gap-2 pt-1">
                                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Platform Masters</p>
                                <span class="inline-flex items-center rounded-full border border-zinc-600/50 bg-zinc-800/60 px-2 py-0.5 text-xs font-medium text-zinc-500">
                                    Coming Soon
                                </span>
                            </div>
                            <div class="rounded-lg border border-dashed border-zinc-700/60 bg-zinc-900/40 px-4 py-4 text-center">
                                <p class="text-xs text-zinc-500">Platform masters will be available soon. Use your own accounts above for now.</p>
                            </div>
                        </div>
                    @endif
                </div>

                {{-- Own-account mode: master account in settings --}}
                @if($ownAccountMode && count($this->myAccounts) > 0)
                <div class="col-span-full">
                    <flux:label class="mb-2 block">Master Account <span class="text-amber-400">(copy FROM)</span></flux:label>
                    <flux:text class="mb-3 text-xs text-zinc-500">Which account's trades are being copied.</flux:text>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($this->myAccounts as $account)
                            @php $isDemo = ($account['is_demo'] ?? false) || ($account['account_type'] ?? '') === 'demo'; @endphp
                            <label wire:key="master-set-{{ $account['account_id'] }}"
                                class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors
                                    {{ $masterAccountId === $account['account_id']
                                        ? 'border-amber-500/50 bg-amber-500/5'
                                        : 'border-[#1F2937] bg-[#111827] hover:border-[#1F2937]/80' }}"
                            >
                                <input type="radio" wire:model.live="masterAccountId" value="{{ $account['account_id'] }}"
                                    class="text-amber-400 focus:ring-amber-400" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-white">{{ $isDemo ? 'Demo' : 'Real' }} — {{ $account['account_id'] }}</span>
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-amber-500/10 text-amber-400' => $isDemo,
                                            'bg-[#22C55E]/10 text-[#22C55E]' => !$isDemo,
                                        ])>{{ $isDemo ? 'Demo' : 'Real' }}</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-zinc-500">{{ number_format($account['balance'] ?? 0, 2) }} {{ strtoupper($account['currency'] ?? 'USD') }}</p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Follower account --}}
                @if(count($this->myAccounts) > 0)
                <div class="col-span-full">
                    <flux:label class="mb-2 block">
                        @if($ownAccountMode)
                            Follower Account <span class="text-[#1E45FC]">(copy TO)</span>
                        @else
                            Your Trading Account
                        @endif
                    </flux:label>
                    <flux:text class="mb-3 text-xs text-zinc-500">Choose which account (real or demo) will copy trades.</flux:text>
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($this->myAccounts as $account)
                            @php $isDemo = ($account['is_demo'] ?? false) || ($account['account_type'] ?? '') === 'demo'; @endphp
                            <label wire:key="acc-{{ $account['account_id'] }}"
                                class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition-colors
                                    {{ $followerAccountId === $account['account_id']
                                        ? 'border-[#1E45FC]/50 bg-[#1E45FC]/5'
                                        : 'border-[#1F2937] bg-[#111827] hover:border-[#1F2937]/80' }}"
                            >
                                <input type="radio" wire:model.live="followerAccountId" value="{{ $account['account_id'] }}"
                                    class="text-[#1E45FC] focus:ring-[#1E45FC]" />
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <span class="text-sm font-medium text-white">{{ $isDemo ? 'Demo' : 'Real' }} — {{ $account['account_id'] }}</span>
                                        <span @class([
                                            'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                            'bg-amber-500/10 text-amber-400' => $isDemo,
                                            'bg-[#22C55E]/10 text-[#22C55E]' => !$isDemo,
                                        ])>{{ $isDemo ? 'Demo' : 'Real' }}</span>
                                    </div>
                                    <p class="mt-0.5 text-xs text-zinc-500">{{ number_format($account['balance'] ?? 0, 2) }} {{ strtoupper($account['currency'] ?? 'USD') }}</p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Slave/Follower Pattern --}}
                <div class="col-span-full rounded-lg border border-[#1E45FC]/30 bg-[#1E45FC]/5 p-4">
                    <flux:checkbox wire:model.live="patternEnabled" label="Enable Slave Pattern" class="mb-3" />
                    <flux:input
                        wire:model.live="followerPattern"
                        label="Follower Pattern (1=Win, 0=Loss)"
                        placeholder="e.g. 111 or 101"
                        description="Copy will start when master's last N outcomes match this pattern."
                        :disabled="!$patternEnabled"
                    />
                    @error('followerPattern')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror
                    @if($patternEnabled && strlen($followerPattern) > 0)
                        <div class="mt-2 flex gap-1">
                            @foreach(str_split($followerPattern) as $bit)
                                <span @class([
                                    'inline-flex h-7 w-7 items-center justify-center rounded text-xs font-bold',
                                    'bg-[#22C55E]/20 text-[#22C55E] border border-[#22C55E]/40' => $bit === '1',
                                    'bg-red-500/20 text-red-400 border border-red-500/40' => $bit === '0',
                                ])>{{ $bit === '1' ? 'W' : 'L' }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- More Settings toggle --}}
                <div class="col-span-full border-t border-[#1F2937] pt-2">
                    <button
                        @click="moreSettings = !moreSettings"
                        class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-sm font-medium text-zinc-400 transition-colors hover:bg-[#111827] hover:text-white"
                    >
                        <span x-text="moreSettings ? 'Hide Advanced Settings' : 'More Settings'"></span>
                        <svg x-bind:class="moreSettings ? 'rotate-180' : ''" class="size-4 transition-transform duration-200" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7" />
                        </svg>
                    </button>
                </div>

                {{-- Collapsible advanced settings --}}
                <div class="col-span-full" x-show="moreSettings" x-collapse>
                    <div class="grid grid-cols-1 gap-6 pt-2 md:grid-cols-2 xl:grid-cols-3">

                        {{-- Stake --}}
                        <div>
                            <flux:input wire:model="stake" label="Default Stake (USD)" type="number" step="0.01" min="0.35" max="50000" />
                            <flux:checkbox wire:model="followMasterStake" label="Follow Master Stake" class="mt-2" />
                            @error('stake') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                        </div>

                        {{-- Martingale & TP/SL --}}
                        <div class="space-y-2">
                            <div>
                                <flux:input wire:model="stakeMultiplier" label="Stake Multiplier ×" type="number" step="0.1" min="1" max="100" />
                                @if($stakeMultiplier <= 1 && $maxMartingale > 0)
                                    <p class="mt-1 text-xs text-amber-400">Multiplier must be &gt; 1 for martingale to activate.</p>
                                @endif
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <flux:input wire:model="takeProfit" label="Take Profit" type="number" min="0" placeholder="—" />
                                <flux:input wire:model="stopLoss" label="Stop Loss" type="number" min="0" placeholder="—" />
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <flux:input wire:model="doMartingaleAt" label="Do Martingale at" type="number" min="1" />
                                <flux:input wire:model="maxMartingale" label="Max Martingale" type="number" min="0" />
                            </div>
                            @if($maxMartingale > 0 && $stakeMultiplier <= 1)
                                <p class="text-xs text-amber-400">Set multiplier &gt; 1 to enable martingale stake scaling.</p>
                            @endif
                            <flux:input wire:model="maxCompound" label="Max Compound" type="number" min="0" />
                            <div>
                                <flux:label>If Hit Max Martingale</flux:label>
                                <flux:select wire:model="ifHitMaxMartingale" size="sm">
                                    <flux:select.option value="stop">Stop</flux:select.option>
                                    <flux:select.option value="continue">Continue</flux:select.option>
                                </flux:select>
                            </div>
                        </div>

                        {{-- Options --}}
                        <div class="space-y-3">
                            <flux:checkbox wire:model="safeMode" label="Safe Mode" />
                            <div>
                                <flux:label>Wait for Loss</flux:label>
                                <flux:input wire:model="waitForLoss" type="number" min="0" class="mt-1 w-24" />
                            </div>
                            <flux:checkbox wire:model="onlyUse1xWaitForLoss" label="Only Use 1× Wait for Loss" />
                        </div>

                        {{-- Filter Indices Market --}}
                        <div>
                            <flux:label class="mb-2 block">Filter Indices Market</flux:label>
                            <div class="flex flex-wrap gap-2">
                                @foreach(self::AVAILABLE_MARKETS as $market)
                                    <button wire:click="toggleMarket('{{ $market }}')"
                                        @class([
                                            'rounded px-2 py-1 text-xs font-medium transition-colors',
                                            'bg-[#1E45FC] text-white' => in_array($market, $filterMarkets),
                                            'bg-[#111827] text-zinc-400 hover:bg-[#1F2937]' => !in_array($market, $filterMarkets),
                                        ])>
                                        {{ $market }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Trade Types --}}
                        <div>
                            <flux:label class="mb-2 block">Trade Types</flux:label>
                            <div class="flex flex-wrap gap-2">
                                @foreach(array_keys(self::AVAILABLE_SYNTHETIC) as $index)
                                    <button wire:click="toggleSynthetic('{{ $index }}')"
                                        @class([
                                            'rounded px-2 py-1 text-xs font-medium transition-colors',
                                            'bg-[#1E45FC] text-white' => in_array($index, $syntheticIndices),
                                            'bg-[#111827] text-zinc-400 hover:bg-[#1F2937]' => !in_array($index, $syntheticIndices),
                                        ])>
                                        {{ $index }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                        {{-- Forex Pairs --}}
                        <div>
                            <flux:label class="mb-2 block">Forex Pairs</flux:label>
                            <div class="flex flex-wrap gap-2">
                                @foreach(array_keys(self::AVAILABLE_FOREX) as $pair)
                                    <button wire:click="toggleForex('{{ $pair }}')"
                                        @class([
                                            'rounded px-2 py-1 text-xs font-medium transition-colors',
                                            'bg-[#1E45FC] text-white' => in_array($pair, $forexPairs),
                                            'bg-[#111827] text-zinc-400 hover:bg-[#1F2937]' => !in_array($pair, $forexPairs),
                                        ])>
                                        {{ $pair }}
                                    </button>
                                @endforeach
                            </div>
                        </div>

                    </div>
                </div>

            </div>

            <div class="flex gap-3 border-t border-[#1F2937] px-6 py-4">
                <flux:button wire:click="saveSettings" wire:loading.attr="disabled" wire:target="saveSettings" variant="primary">
                    <span wire:loading.remove wire:target="saveSettings">Save Settings</span>
                    <span wire:loading wire:target="saveSettings">Saving…</span>
                </flux:button>
                <flux:button wire:click="$toggle('settingsOpen')" variant="ghost">Cancel</flux:button>
            </div>
        </div>
        @endif

        {{-- ===== STATUS CARDS ===== --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">

            {{-- Master account --}}
            @php $masterConn = $setting->masterConnection; @endphp
            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Master Account</p>
                <!--<p class="mt-1 text-sm font-semibold text-white">
                    {{--@if($setting->master_account_id) Your own account
                    @else {{ $setting->masterConnection?->user?->name ?? '—' }}
                    @endif --}}
                </p>-->
                <span @class([
                    'mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                    'bg-[#22C55E]/10 text-[#22C55E]' => $masterConn && !$masterConn->isExpired(),
                    'bg-red-500/10 text-red-400' => !$masterConn || $masterConn->isExpired(),
                ])>
                    <span class="h-1.5 w-1.5 rounded-full {{ $masterConn && !$masterConn->isExpired() ? 'bg-[#22C55E]' : 'bg-red-400' }}"></span>
                    {{ $masterConn && !$masterConn->isExpired() ? 'Connected' : 'Disconnected' }}
                </span>
            </div>

            {{-- Follower account --}}
            @php $myConnection = auth()->user()->derivConnection; @endphp
            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Follower Account</p>
                <!--<p class="mt-1 text-sm font-semibold text-white">Your account</p>-->
                <span @class([
                    'mt-1 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                    'bg-[#22C55E]/10 text-[#22C55E]' => $myConnection && !$myConnection->isExpired(),
                    'bg-red-500/10 text-red-400' => !$myConnection || $myConnection->isExpired(),
                ])>
                    <span class="h-1.5 w-1.5 rounded-full {{ $myConnection && !$myConnection->isExpired() ? 'bg-[#22C55E]' : 'bg-red-400' }}"></span>
                    {{ $myConnection && !$myConnection->isExpired() ? 'Connected' : 'Disconnected' }}
                </span>
            </div>

            {{-- Start balance --}}
            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Start Balance</p>
                <p class="mt-1 text-lg font-semibold text-white">
                    {{ $setting->start_balance !== null ? number_format($setting->start_balance, 2) . ' USD' : '—' }}
                </p>
            </div>

            {{-- Current balance / P&L --}}
            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] px-4 py-3">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Current Balance</p>
                @if($this->currentBalance !== null)
                    @php
                        $pl = $setting->start_balance !== null ? $this->currentBalance - $setting->start_balance : null;
                        $plPositive = $pl === null || $pl >= 0;
                    @endphp
                    <p class="mt-1 text-lg font-semibold text-white">{{ number_format($this->currentBalance, 2) }} USD</p>
                    @if($pl !== null)
                        <p class="text-xs {{ $plPositive ? 'text-[#22C55E]' : 'text-red-400' }}">
                            P/L: {{ $plPositive ? '+' : '' }}{{ number_format($pl, 2) }} USD
                        </p>
                    @endif
                @else
                    <p class="mt-1 text-lg font-semibold text-zinc-500">—</p>
                @endif
            </div>
        </div>

        {{-- ===== TABS: Transactions / Summary ===== --}}
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]"
            x-data="{
                colWidths: {
                    num: 50, result: 85, datetime: 155, symbol: 100,
                    followerTrxId: 130, dur: 65, stake: 85, payout: 85, profit: 85, masterTrxId: 130
                },
                resizing: null, startX: 0, startWidth: 0,
                showColMenu: false,
                startResize(col, e) {
                    this.resizing = col;
                    this.startX = e.clientX;
                    this.startWidth = this.colWidths[col];
                    e.preventDefault();
                },
                doResize(e) {
                    if (!this.resizing) return;
                    this.colWidths[this.resizing] = Math.max(40, this.startWidth + (e.clientX - this.startX));
                },
                stopResize() { this.resizing = null; }
            }"
            @mousemove.window="doResize($event)"
            @mouseup.window="stopResize()"
        >
            {{-- Tab bar --}}
            <div class="flex items-center justify-between border-b border-[#1F2937] px-2">
                <div class="flex">
                    <button wire:click="$set('activeTab', 'transactions')"
                        @class(['px-5 py-3 text-sm font-medium border-b-2 transition-colors',
                            'border-[#1E45FC] text-[#1E45FC]' => $activeTab === 'transactions',
                            'border-transparent text-zinc-400 hover:text-zinc-200' => $activeTab !== 'transactions'])>
                        Transactions
                        @if($this->stats['trade_count'] > 0)
                            <span class="ml-1.5 rounded-full bg-[#1E45FC]/20 px-2 py-0.5 text-xs text-[#1E45FC]">{{ $this->stats['trade_count'] }}</span>
                        @endif
                    </button>
                    <button wire:click="$set('activeTab', 'summary')"
                        @class(['px-5 py-3 text-sm font-medium border-b-2 transition-colors',
                            'border-[#1E45FC] text-[#1E45FC]' => $activeTab === 'summary',
                            'border-transparent text-zinc-400 hover:text-zinc-200' => $activeTab !== 'summary'])>
                        Summary
                    </button>
                </div>

                {{-- Column visibility toggle (only shown on transactions tab) --}}
                @if($activeTab === 'transactions')
                <div class="relative mr-2" x-data="{ open: false }" @click.outside="open = false">
                    <button @click="open = !open"
                        class="flex items-center gap-1.5 rounded-lg border border-[#1F2937] bg-[#111827] px-3 py-1.5 text-xs text-zinc-400 hover:text-zinc-200 transition-colors">
                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 4.5v15m6-15v15M3 9h18M3 15h18" />
                        </svg>
                        Columns
                    </button>
                    <div x-show="open" x-transition
                        class="absolute right-0 top-full z-20 mt-1 w-44 rounded-xl border border-[#1F2937] bg-[#0B1220] p-2 shadow-xl">
                        @foreach(['num' => '#', 'result' => 'Result', 'datetime' => 'DateTime', 'symbol' => 'Symbol', 'followerTrxId' => 'FollowerTrxID', 'dur' => 'Dur', 'stake' => 'Stake', 'payout' => 'Payout', 'profit' => 'Profit', 'masterTrxId' => 'MasterTrxID'] as $col => $label)
                            <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 hover:bg-[#111827]">
                                <input type="checkbox" wire:click="toggleColumn('{{ $col }}')"
                                    {{ ($visibleColumns[$col] ?? true) ? 'checked' : '' }}
                                    class="rounded border-zinc-600 bg-[#111827] text-[#1E45FC]" />
                                <span class="text-xs text-zinc-300">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>

            {{-- Transactions tab --}}
            @if($activeTab === 'transactions')
            <div>
                @if($trades->isEmpty())
                    <div class="py-10 text-center text-zinc-500">
                        <flux:icon.table-cells class="mx-auto mb-3 size-10 opacity-30" />
                        <p class="text-sm">No transactions yet.</p>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="text-left text-xs" style="table-layout: fixed; min-width: 900px; width: 100%;">
                            <thead>
                                <tr class="border-b border-[#1F2937] text-zinc-400">
                                    @if($visibleColumns['num'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.num}px`">
                                        #
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('num', $event)"></div>
                                    </th>
                                    @endif
                                    @if($visibleColumns['result'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.result}px`">
                                        Result
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('result', $event)"></div>
                                    </th>
                                    @endif
                                    @if($visibleColumns['datetime'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.datetime}px`">
                                        DateTime
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('datetime', $event)"></div>
                                    </th>
                                    @endif
                                    @if($visibleColumns['symbol'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.symbol}px`">
                                        Symbol
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('symbol', $event)"></div>
                                    </th>
                                    @endif
                                    @if($visibleColumns['followerTrxId'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.followerTrxId}px`">
                                        FollowerTrxID
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('followerTrxId', $event)"></div>
                                    </th>
                                    @endif
                                    @if($visibleColumns['dur'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.dur}px`">
                                        Dur
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('dur', $event)"></div>
                                    </th>
                                    @endif
                                    @if($visibleColumns['stake'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.stake}px`">
                                        Stake
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('stake', $event)"></div>
                                    </th>
                                    @endif
                                    @if($visibleColumns['payout'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.payout}px`">
                                        Payout
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('payout', $event)"></div>
                                    </th>
                                    @endif
                                    @if($visibleColumns['profit'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.profit}px`">
                                        Profit
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('profit', $event)"></div>
                                    </th>
                                    @endif
                                    @if($visibleColumns['masterTrxId'] ?? true)
                                    <th class="relative select-none px-3 py-3 font-medium"
                                        :style="`width: ${colWidths.masterTrxId}px`">
                                        MasterTrxID
                                        <div class="absolute right-0 top-0 h-full w-1 cursor-col-resize hover:bg-[#1E45FC]/40 active:bg-[#1E45FC]"
                                            @mousedown.prevent="startResize('masterTrxId', $event)"></div>
                                    </th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($trades as $i => $trade)
                                    @php $rowNum = ($trades->currentPage() - 1) * $trades->perPage() + $i + 1; @endphp
                                    <tr wire:key="{{ $trade->id }}"
                                        @class([
                                            'border-b border-[#1F2937]/50 transition-colors',
                                            'bg-[#0d2318]' => $trade->is_win === true,
                                            'bg-[#1a0d0d]' => $trade->is_win === false,
                                            'bg-[#0B1220] hover:bg-[#111827]' => $trade->is_win === null,
                                        ])>
                                        @if($visibleColumns['num'] ?? true)
                                        <td class="overflow-hidden text-ellipsis whitespace-nowrap px-3 py-2 text-zinc-400"
                                            :style="`width: ${colWidths.num}px; max-width: ${colWidths.num}px`">
                                            {{ $rowNum }}
                                        </td>
                                        @endif
                                        @if($visibleColumns['result'] ?? true)
                                        <td class="overflow-hidden px-3 py-2"
                                            :style="`width: ${colWidths.result}px; max-width: ${colWidths.result}px`">
                                            @if($trade->is_win === true)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-[#22C55E]/15 px-2 py-0.5 font-medium text-[#22C55E]">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-[#22C55E]"></span>WIN
                                                </span>
                                            @elseif($trade->is_win === false)
                                                <span class="inline-flex items-center gap-1 rounded-full bg-red-500/15 px-2 py-0.5 font-medium text-red-400">
                                                    <span class="h-1.5 w-1.5 rounded-full bg-red-400"></span>LOSS
                                                </span>
                                            @else
                                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-amber-400">
                                                    <span class="h-1.5 w-1.5 animate-pulse rounded-full bg-amber-400"></span>Open
                                                </span>
                                            @endif
                                        </td>
                                        @endif
                                        @if($visibleColumns['datetime'] ?? true)
                                        <td class="overflow-hidden text-ellipsis whitespace-nowrap px-3 py-2 font-mono text-zinc-300"
                                            :style="`width: ${colWidths.datetime}px; max-width: ${colWidths.datetime}px`">
                                            {{ $trade->traded_at?->format('Y-m-d H:i:s') ?? '—' }}
                                        </td>
                                        @endif
                                        @if($visibleColumns['symbol'] ?? true)
                                        <td class="overflow-hidden text-ellipsis whitespace-nowrap px-3 py-2 font-mono text-zinc-300"
                                            :style="`width: ${colWidths.symbol}px; max-width: ${colWidths.symbol}px`">
                                            {{ $trade->symbol ?? '—' }}
                                        </td>
                                        @endif
                                        @if($visibleColumns['followerTrxId'] ?? true)
                                        <td class="overflow-hidden text-ellipsis whitespace-nowrap px-3 py-2 font-mono text-zinc-400"
                                            :style="`width: ${colWidths.followerTrxId}px; max-width: ${colWidths.followerTrxId}px`">
                                            {{ $trade->follower_trx_id ?? '—' }}
                                        </td>
                                        @endif
                                        @if($visibleColumns['dur'] ?? true)
                                        <td class="overflow-hidden text-ellipsis whitespace-nowrap px-3 py-2 text-zinc-300"
                                            :style="`width: ${colWidths.dur}px; max-width: ${colWidths.dur}px`">
                                            {{ $trade->duration ?? '—' }}
                                        </td>
                                        @endif
                                        @if($visibleColumns['stake'] ?? true)
                                        <td class="overflow-hidden text-ellipsis whitespace-nowrap px-3 py-2 font-medium text-white"
                                            :style="`width: ${colWidths.stake}px; max-width: ${colWidths.stake}px`">
                                            {{ number_format($trade->stake, 2) }}
                                        </td>
                                        @endif
                                        @if($visibleColumns['payout'] ?? true)
                                        <td class="overflow-hidden text-ellipsis whitespace-nowrap px-3 py-2 text-zinc-300"
                                            :style="`width: ${colWidths.payout}px; max-width: ${colWidths.payout}px`">
                                            {{ $trade->payout !== null ? number_format($trade->payout, 2) : '—' }}
                                        </td>
                                        @endif
                                        @if($visibleColumns['profit'] ?? true)
                                        <td class="overflow-hidden text-ellipsis whitespace-nowrap px-3 py-2 font-medium {{ $trade->profit !== null && $trade->profit >= 0 ? 'text-[#22C55E]' : 'text-red-400' }}"
                                            :style="`width: ${colWidths.profit}px; max-width: ${colWidths.profit}px`">
                                            {{ $trade->profit !== null ? ($trade->profit >= 0 ? '+' : '').number_format($trade->profit, 2) : '—' }}
                                        </td>
                                        @endif
                                        @if($visibleColumns['masterTrxId'] ?? true)
                                        <td class="overflow-hidden text-ellipsis whitespace-nowrap px-3 py-2 font-mono text-zinc-400"
                                            :style="`width: ${colWidths.masterTrxId}px; max-width: ${colWidths.masterTrxId}px`">
                                            {{ $trade->master_trx_id ?? '—' }}
                                        </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    {{-- Pagination controls --}}
                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-[#1F2937] px-4 py-3">
                        <div class="flex items-center gap-3">
                            <span class="text-xs text-zinc-400">
                                {{ $trades->firstItem() }}–{{ $trades->lastItem() }} of {{ $trades->total() }} trades
                            </span>
                            <select wire:model.live="perPage"
                                class="rounded-lg border border-[#1F2937] bg-[#111827] px-2 py-1 text-xs text-zinc-300 focus:outline-none focus:ring-1 focus:ring-[#1E45FC]">
                                <option value="10">10 / page</option>
                                <option value="25">25 / page</option>
                                <option value="50">50 / page</option>
                                <option value="100">100 / page</option>
                            </select>
                        </div>
                        <div class="flex items-center gap-2">
                            <button wire:click="previousPage" {{ $trades->onFirstPage() ? 'disabled' : '' }}
                                class="rounded-lg border border-[#1F2937] bg-[#111827] px-3 py-1 text-xs text-zinc-400 transition-colors hover:bg-[#1F2937] hover:text-zinc-200 disabled:cursor-not-allowed disabled:opacity-30">
                                ← Prev
                            </button>
                            <span class="text-xs text-zinc-400">
                                Page {{ $trades->currentPage() }} of {{ $trades->lastPage() }}
                            </span>
                            <button wire:click="nextPage" {{ $trades->hasMorePages() ? '' : 'disabled' }}
                                class="rounded-lg border border-[#1F2937] bg-[#111827] px-3 py-1 text-xs text-zinc-400 transition-colors hover:bg-[#1F2937] hover:text-zinc-200 disabled:cursor-not-allowed disabled:opacity-30">
                                Next →
                            </button>
                        </div>
                    </div>
                @endif
            </div>
            @endif

            {{-- Summary tab --}}
            @if($activeTab === 'summary')
            <div class="p-6">
                @if($this->stats['trade_count'] === 0)
                    <div class="py-10 text-center text-zinc-500">
                        <flux:icon.chart-bar class="mx-auto mb-3 size-10 opacity-30" />
                        <p class="text-sm">When you're ready to trade, hit <strong class="text-white">Run</strong>.</p>
                        <p class="mt-1 text-xs">You'll be able to track performance here.</p>
                    </div>
                @else
                    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                        <div class="rounded-lg bg-[#111827] px-4 py-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Trades</p>
                            <p class="mt-1 text-xl font-bold text-white">{{ $this->stats['trade_count'] }}</p>
                            <p class="text-xs text-zinc-500">{{ $this->stats['win_count'] }}W / {{ $this->stats['loss_count'] }}L</p>
                        </div>
                        <div class="rounded-lg bg-[#111827] px-4 py-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Total Stake</p>
                            <p class="mt-1 text-xl font-bold text-white">{{ number_format($this->stats['total_stake'], 2) }}</p>
                            <p class="text-xs text-zinc-500">USD</p>
                        </div>
                        <div class="rounded-lg bg-[#111827] px-4 py-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Total Payout</p>
                            <p class="mt-1 text-xl font-bold text-white">{{ number_format($this->stats['total_payout'], 2) }}</p>
                            <p class="text-xs text-zinc-500">USD</p>
                        </div>
                        <div class="rounded-lg bg-[#111827] px-4 py-3">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Total Profit</p>
                            @php $profit = $this->stats['total_profit']; @endphp
                            <p class="mt-1 text-xl font-bold {{ $profit >= 0 ? 'text-[#22C55E]' : 'text-red-400' }}">
                                {{ $profit >= 0 ? '+' : '' }}{{ number_format($profit, 2) }}
                            </p>
                            <p class="text-xs text-zinc-500">USD</p>
                        </div>
                    </div>
                @endif
            </div>
            @endif
        </div>

        {{-- ===== SUMMARY STATS FOOTER ===== --}}
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-4 text-sm">
                <div class="flex flex-wrap gap-6 text-zinc-300">
                    <span>Trade: <strong class="text-white">{{ $this->stats['trade_count'] }}</strong></span>
                    <span>Win: <strong class="text-[#22C55E]">{{ $this->stats['win_count'] }}</strong></span>
                    <span>Loss: <strong class="text-red-400">{{ $this->stats['loss_count'] }}</strong></span>
                    <span>Profit:
                        <strong @class(['font-mono', 'text-[#22C55E]' => $this->stats['total_profit'] >= 0, 'text-red-400' => $this->stats['total_profit'] < 0])>
                            {{ $this->stats['total_profit'] >= 0 ? '+' : '' }}{{ number_format($this->stats['total_profit'], 2) }}
                        </strong>
                    </span>
                </div>
                <div class="flex flex-wrap gap-6 text-zinc-300">
                    <span>Total Stake: <strong class="text-white">{{ number_format($this->stats['total_stake'], 2) }} USD</strong></span>
                    <span>Total Payout: <strong class="text-white">{{ number_format($this->stats['total_payout'], 2) }} USD</strong></span>
                    <span>Contracts Won: <strong class="text-[#22C55E]">{{ $this->stats['contracts_won'] }}</strong></span>
                    <span>Contracts Lost: <strong class="text-red-400">{{ $this->stats['contracts_lost'] }}</strong></span>
                </div>
            </div>

        </div>

    @endif {{-- end VIEW B --}}

</div>
