<div class="space-y-8">

    {{-- Deriv Connection Prompt (shown when no account connected) --}}
    @if(! auth()->user()->hasDerivConnected())
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="border-b border-[#1F2937] px-6 py-4">
                <flux:heading size="lg">Connect Your Deriv Account</flux:heading>
                <flux:text class="mt-0.5 text-sm text-zinc-500">
                    You need a Deriv account connection to copy trades.
                </flux:text>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-[1fr_auto_1fr]">

                {{-- OAuth2 option --}}
                <div class="flex flex-col gap-3 px-6 py-6">
                    <div class="flex items-center gap-2">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-indigo-500/10">
                            <flux:icon.arrow-top-right-on-square class="size-4 text-indigo-500" />
                        </div>
                        <flux:heading size="sm">OAuth2</flux:heading>
                        <span class="rounded-full bg-[#CDF12B]/10 px-2 py-0.5 text-xs font-medium text-[#CDF12B]">
                            Recommended
                        </span>
                    </div>
                    <flux:text class="text-sm text-zinc-400">
                        For <strong class="text-white">new Deriv accounts</strong>. Sign in securely
                        via Deriv's website — no token copying required.
                    </flux:text>
                    <flux:button
                        href="{{ route('deriv.connect') }}"
                        variant="primary"
                        icon="link"
                        class="mt-auto w-full"
                    >
                        Connect via Deriv
                    </flux:button>
                </div>

                {{-- OR separator --}}
                <div class="flex items-center justify-center px-4 sm:flex-col">
                    <div class="h-px w-full bg-[#1F2937] sm:h-full sm:w-px"></div>
                    <span class="shrink-0 px-3 py-1 text-xs font-semibold uppercase tracking-widest text-zinc-500 sm:px-0 sm:py-3">
                        or
                    </span>
                    <div class="h-px w-full bg-[#1F2937] sm:h-full sm:w-px"></div>
                </div>

                {{-- PAT option --}}
                <div class="flex flex-col gap-3 px-6 py-6">
                    <div class="flex items-center gap-2">
                        <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-amber-500/10">
                            <flux:icon.key class="size-4 text-amber-500" />
                        </div>
                        <flux:heading size="sm">Personal Access Token</flux:heading>
                    </div>
                    <flux:text class="text-sm text-zinc-400">
                        For <strong class="text-white">legacy Deriv accounts</strong>. Deriv issues
                        separate tokens for real and demo accounts — paste the one matching the
                        account you want to copy trades with. Generate tokens in your
                        <a href="https://app.deriv.com/account/api-token"
                           target="_blank"
                           rel="noopener noreferrer"
                           class="underline underline-offset-2 text-zinc-300 hover:text-white"
                        >Deriv account settings</a>.
                    </flux:text>

                    @if($patSuccess)
                        <div class="flex items-center gap-2 rounded-lg border border-[#22C55E]/30 bg-[#22C55E]/10 px-3 py-2 text-sm text-[#22C55E]">
                            <flux:icon.check-circle class="size-4 shrink-0" />
                            {{ $patSuccess }}
                        </div>
                    @endif

                    @if($patError)
                        <div class="flex items-center gap-2 rounded-lg border border-red-800/40 bg-red-900/20 px-3 py-2 text-sm text-red-400">
                            <flux:icon.x-circle class="size-4 shrink-0" />
                            {{ $patError }}
                        </div>
                    @endif

                    @error('patToken')
                        <p class="text-xs text-red-400">{{ $message }}</p>
                    @enderror

                    <div class="mt-auto flex flex-col gap-2">
                        <flux:input
                            type="password"
                            wire:model="patToken"
                            placeholder="Paste your API token here"
                        />
                        <flux:button
                            wire:click="connectViaPat"
                            wire:loading.attr="disabled"
                            wire:target="connectViaPat"
                            variant="ghost"
                            icon="key"
                            class="w-full"
                        >
                            <span wire:loading.remove wire:target="connectViaPat">Connect with Token</span>
                            <span wire:loading wire:target="connectViaPat">Verifying…</span>
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Current Setup Banner --}}
    @if($this->currentSetting)
        @php $setting = $this->currentSetting; @endphp
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="border-b border-[#1F2937] px-6 py-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">Your Copy Setup</flux:heading>
                    @if($setting->is_active)
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-[#22C55E]/10 px-3 py-1 text-sm font-medium text-[#22C55E]">
                            <span class="h-2 w-2 animate-pulse rounded-full bg-[#22C55E]"></span>
                            Active
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-800 px-3 py-1 text-sm font-medium text-zinc-400">
                            <span class="h-2 w-2 rounded-full bg-zinc-400"></span>
                            Paused
                        </span>
                    @endif
                </div>
            </div>

            <div class="px-6 py-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
                    <div class="rounded-lg bg-[#111827] px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Following Master</p>
                        <p class="mt-1 font-semibold text-white">{{ $setting->masterConnection?->user?->name ?? 'No master' }}</p>
                        <p class="text-xs text-zinc-500">{{ $setting->masterConnection?->user?->email }}</p>
                    </div>
                    <div class="rounded-lg bg-[#111827] px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Your Account</p>
                        @if($setting->follower_account_id)
                            <p class="mt-1 font-mono font-semibold text-white text-sm">{{ $setting->follower_account_id }}</p>
                        @else
                            <p class="mt-1 text-sm text-zinc-500">Not set</p>
                        @endif
                    </div>
                    <div class="rounded-lg bg-[#111827] px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Follower Pattern</p>
                        <div class="mt-1 flex items-center gap-2">
                            <span class="font-mono font-bold text-[#CDF12B] text-lg tracking-widest">
                                {{ $setting->follower_pattern ?? '111' }}
                            </span>
                            @if(!$setting->pattern_enabled)
                                <span class="text-xs text-zinc-500">(disabled)</span>
                            @endif
                        </div>
                        <p class="text-xs text-zinc-500">
                            {{ strlen($setting->follower_pattern ?? '111') }} trade pattern
                        </p>
                    </div>
                    <div class="rounded-lg bg-[#111827] px-4 py-3">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Status</p>
                        <p class="mt-1 font-semibold {{ $setting->is_active ? 'text-[#22C55E]' : 'text-zinc-500' }}">
                            {{ $setting->is_active ? 'Copying trades' : 'Paused' }}
                        </p>
                        <p class="text-xs text-zinc-500">updated {{ $setting->updated_at->diffForHumans() }}</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <flux:button
                        wire:click="toggleActive"
                        wire:loading.attr="disabled"
                        wire:target="toggleActive"
                        size="sm"
                        variant="{{ $setting->is_active ? 'ghost' : 'primary' }}"
                        icon="{{ $setting->is_active ? 'pause' : 'play' }}"
                    >
                        {{ $setting->is_active ? 'Pause Copying' : 'Resume Copying' }}
                    </flux:button>

                    <flux:button
                        wire:click="selectMaster({{ $setting->master_connection_id }})"
                        size="sm"
                        variant="ghost"
                        icon="pencil"
                    >
                        Edit Pattern
                    </flux:button>

                    <flux:button
                        wire:click="disconnect"
                        wire:confirm="Stop copy trading and disconnect from this master?"
                        wire:loading.attr="disabled"
                        wire:target="disconnect"
                        size="sm"
                        variant="danger"
                    >
                        Disconnect
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Configure Form --}}
    @if($showForm && $selectedMasterId)
        @php $master = $this->masters->firstWhere('id', $selectedMasterId); @endphp
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="border-b border-[#1F2937] px-6 py-4">
                <flux:heading size="lg">Configure Copy Settings</flux:heading>
                <flux:text class="mt-0.5 text-sm text-zinc-500">
                    Following: <span class="font-medium text-white">{{ $master?->user->name }}</span>
                </flux:text>
            </div>

            <div class="space-y-5 px-6 py-5">

                {{-- Follower account selector --}}
                @if(count($this->followerAccounts) > 0)
                    <div>
                        <flux:label>Your Trading Account</flux:label>
                        <flux:text class="mb-2 text-xs text-zinc-500">Choose which account (real or demo) will copy trades.</flux:text>
                        <div class="space-y-2">
                            @foreach($this->followerAccounts as $account)
                                @php
                                    $isDemo = ($account['account_type'] ?? '') === 'demo';
                                    $label = ($isDemo ? 'Demo' : 'Real') . ' — ' . $account['account_id'];
                                    $sub = number_format($account['balance'] ?? 0, 2) . ' ' . strtoupper($account['currency'] ?? 'USD');
                                @endphp
                                <label wire:key="{{ $account['account_id'] }}"
                                    class="flex cursor-pointer items-center gap-4 rounded-lg border p-4 transition-colors
                                        {{ $followerAccountId === $account['account_id']
                                            ? 'border-[#1E45FC]/50 bg-[#1E45FC]/5'
                                            : 'border-[#1F2937] bg-[#111827] hover:border-[#1F2937]/80' }}"
                                >
                                    <input
                                        type="radio"
                                        wire:model.live="followerAccountId"
                                        value="{{ $account['account_id'] }}"
                                        class="text-[#1E45FC] focus:ring-[#1E45FC]"
                                    />
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="font-medium text-white text-sm">{{ $label }}</span>
                                            <span @class([
                                                'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium',
                                                'bg-amber-500/10 text-amber-400' => $isDemo,
                                                'bg-[#22C55E]/10 text-[#22C55E]' => !$isDemo,
                                            ])>{{ $isDemo ? 'Demo' : 'Real' }}</span>
                                        </div>
                                        <p class="text-xs text-zinc-500 mt-0.5">Balance: {{ $sub }}</p>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                        @error('followerAccountId')
                            <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                        @enderror
                    </div>
                @endif

                {{-- Pattern toggle --}}
                <flux:checkbox wire:model.live="patternEnabled" label="Enable Follower Pattern" />

                {{-- Pattern input --}}
                <div>
                    <flux:input
                        wire:model.live="followerPattern"
                        label="Slave / Follower Pattern"
                        placeholder="e.g. 111 or 101"
                        :disabled="!$patternEnabled"
                        description="Use 1 for Win and 0 for Loss. Copy starts when master's last outcomes match this sequence."
                    />
                    @error('followerPattern')
                        <p class="mt-1 text-xs text-red-500">{{ $message }}</p>
                    @enderror

                    {{-- Visual pattern preview --}}
                    @if($patternEnabled && strlen($followerPattern) > 0 && preg_match('/^[01]+$/', $followerPattern))
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <span class="text-xs text-zinc-400">Pattern:</span>
                            @foreach(str_split($followerPattern) as $bit)
                                <span @class([
                                    'inline-flex h-8 w-8 items-center justify-center rounded-lg text-sm font-bold',
                                    'bg-[#22C55E]/20 text-[#22C55E] border border-[#22C55E]/40' => $bit === '1',
                                    'bg-red-500/20 text-red-400 border border-red-500/40' => $bit === '0',
                                ])>
                                    {{ $bit === '1' ? 'W' : 'L' }}
                                </span>
                            @endforeach
                            <span class="text-xs text-zinc-500 ml-1">({{ strlen($followerPattern) }} trade{{ strlen($followerPattern) !== 1 ? 's' : '' }} needed)</span>
                        </div>
                    @endif
                </div>

                <div class="rounded-lg border border-amber-800/40 bg-amber-900/20 p-4">
                    <div class="flex gap-3">
                        <flux:icon.information-circle class="size-5 shrink-0 text-amber-400" />
                        <div>
                            <p class="text-sm font-medium text-amber-300">How pattern matching works</p>
                            <p class="mt-0.5 text-xs text-amber-400">
                                The bot reads the master's last <strong>{{ strlen($followerPattern) }}</strong> trade
                                result{{ strlen($followerPattern) !== 1 ? 's' : '' }}. When they match
                                <strong class="font-mono text-[#CDF12B]">{{ $followerPattern }}</strong>
                                exactly, the next trade is copied. A <strong>1</strong> means the master must have won
                                that trade; a <strong>0</strong> means a loss.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex gap-2 pt-1">
                    <flux:button
                        wire:click="save"
                        wire:loading.attr="disabled"
                        wire:target="save"
                        variant="primary"
                    >
                        <span wire:loading.remove wire:target="save">Save Settings</span>
                        <span wire:loading wire:target="save">Saving…</span>
                    </flux:button>
                    <flux:button wire:click="cancelForm" variant="ghost">Cancel</flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Master Accounts --}}
    <div>
        <div class="mb-4">
            <flux:heading size="lg">Available Masters</flux:heading>
            <flux:text class="text-sm text-zinc-500">Select a master account to copy their trades.</flux:text>
        </div>

        @if($this->masters->isEmpty())
            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] px-6 py-12 text-center">
                <div class="mx-auto mb-3 w-fit rounded-full bg-zinc-800 p-4">
                    <flux:icon.user-group class="size-6 text-zinc-400" />
                </div>
                <flux:heading size="sm">No master accounts yet</flux:heading>
                <flux:text class="mt-1 text-zinc-500">The admin hasn't designated any master traders yet.</flux:text>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($this->masters as $master)
                    @php $isFollowing = $this->currentSetting?->master_connection_id === $master->id; @endphp
                    <div wire:key="{{ $master->id }}"
                         class="rounded-xl border p-5 transition-colors
                             {{ $isFollowing
                                 ? 'border-[#1E45FC]/40 bg-[#1E45FC]/5'
                                 : 'border-[#1F2937] bg-[#0B1220] hover:border-[#1E45FC]/30' }}">

                        <div class="mb-3 flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <flux:avatar :name="$master->user->name" :initials="$master->user->initials()" />
                                <div>
                                    <p class="font-semibold text-white">{{ $master->user->name }}</p>
                                    <p class="text-xs text-zinc-500">{{ $master->user->email }}</p>
                                </div>
                            </div>
                            @if($isFollowing)
                                <span class="inline-flex items-center gap-1 rounded-full bg-[#CDF12B]/10 px-2 py-0.5 text-xs font-medium text-[#CDF12B]">
                                    <span class="h-1.5 w-1.5 rounded-full bg-[#CDF12B]"></span>
                                    Following
                                </span>
                            @endif
                        </div>

                        <p class="mb-4 text-sm text-zinc-500">
                            <span class="font-medium text-zinc-300">{{ $master->followers_count }}</span>
                            {{ Str::plural('follower', $master->followers_count) }}
                        </p>

                        <flux:button
                            wire:click="selectMaster({{ $master->id }})"
                            size="sm"
                            variant="{{ $isFollowing ? 'ghost' : 'primary' }}"
                            class="w-full"
                        >
                            {{ $isFollowing ? 'Edit Settings' : 'Follow This Master' }}
                        </flux:button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>
