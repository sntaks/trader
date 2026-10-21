<div class="space-y-6">
    {{-- Eagerly trigger computed properties so $apiError is set before the alerts block. --}}
    @php $accounts = $this->accounts; $liveBalance = $this->balance; $apiError = $this->apiError; @endphp

    @if(! auth()->user()->hasDerivConnected())
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="px-6 py-8 text-center">
                <div class="mx-auto mb-3 w-fit rounded-full bg-zinc-100 p-4 dark:bg-zinc-800">
                    <flux:icon.link class="size-6 text-zinc-400" />
                </div>
                <flux:heading size="sm">No Deriv account connected</flux:heading>
                <flux:text class="mt-1 text-zinc-500">Choose how you'd like to connect your Deriv account.</flux:text>
            </div>

            <div class="grid grid-cols-1 gap-px border-t border-zinc-200 dark:border-zinc-700 sm:grid-cols-2">
                {{-- Option 1: OAuth2 --}}
                <div class="flex flex-col gap-3 px-6 py-6">
                    <div class="flex items-center gap-2">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-500/10">
                            <flux:icon.arrow-top-right-on-square class="size-4 text-indigo-500" />
                        </div>
                        <flux:heading size="sm">OAuth2 (Recommended)</flux:heading>
                    </div>
                    <flux:text class="text-sm text-zinc-500">
                        Sign in securely via Deriv's website. No token copying required.
                    </flux:text>
                    <flux:button href="{{ route('deriv.connect') }}" variant="primary" icon="link" class="mt-auto w-full">
                        Connect via Deriv
                    </flux:button>
                </div>

                {{-- Option 2: Personal Access Token --}}
                <div class="flex flex-col gap-3 border-t border-zinc-200 px-6 py-6 dark:border-zinc-700 sm:border-l sm:border-t-0">
                    <div class="flex items-center gap-2">
                        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10">
                            <flux:icon.key class="size-4 text-amber-500" />
                        </div>
                        <flux:heading size="sm">Personal Access Token</flux:heading>
                    </div>
                    <flux:text class="text-sm text-zinc-500">
                        Generate a token in your
                        <a href="https://app.deriv.com/account/api-token" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">Deriv account settings</a>
                        and paste it below.
                    </flux:text>

                    @if($errors->has('pat_token'))
                        <p class="text-sm text-red-500">{{ $errors->first('pat_token') }}</p>
                    @endif

                    <form method="POST" action="{{ route('deriv.connect.pat') }}" class="mt-auto flex flex-col gap-2">
                        @csrf
                        <flux:input
                            type="password"
                            name="pat_token"
                            placeholder="Paste your API token here"
                            required
                        />
                        <flux:button type="submit" variant="ghost" icon="key" class="w-full">
                            Connect with Token
                        </flux:button>
                    </form>
                </div>
            </div>
        </div>
    @else

        {{-- Header --}}
        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">Account Overview</flux:heading>
                <flux:text class="mt-1 text-zinc-500">Your Deriv account details and balances.</flux:text>
            </div>
            <flux:button wire:click="refresh" wire:loading.attr="disabled" wire:target="refresh" size="sm" variant="ghost" icon="arrow-path">
                <span wire:loading.remove wire:target="refresh">Refresh</span>
                <span wire:loading wire:target="refresh">Refreshing…</span>
            </flux:button>
        </div>

        {{-- Alerts --}}
        @if($resetSuccess)
            <flux:callout variant="success" icon="check-circle">
                <flux:callout.heading>{{ $resetSuccess }}</flux:callout.heading>
            </flux:callout>
        @endif

        @if($resetError)
            <flux:callout variant="danger" icon="x-circle">
                <flux:callout.heading>{{ $resetError }}</flux:callout.heading>
            </flux:callout>
        @endif

        @if($apiError)
            <div class="rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 dark:border-amber-800/40 dark:bg-amber-900/20">
                <div class="flex gap-3">
                    <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-600 dark:text-amber-400" />
                    <div>
                        <p class="text-sm font-medium text-amber-800 dark:text-amber-300">{{ $apiError }}</p>
                        <p class="mt-0.5 text-xs text-amber-700 dark:text-amber-400">
                            Your token may be expired.
                            <a href="{{ route('deriv.connect') }}" class="underline underline-offset-2">Reconnect</a> to restore access.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        {{-- Profile Summary --}}
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="border-b border-[#1F2937] px-6 py-4">
                <flux:heading size="lg">Profile</flux:heading>
            </div>
            <div class="px-6 py-5">
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Name</p>
                        <p class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ auth()->user()->name }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Email</p>
                        <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-300">{{ auth()->user()->email }}</p>
                    </div>
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Platform Account</p>
                        <p class="mt-1 font-mono text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ auth()->user()->account_no ?? '—' }}
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Live Balance (from WebSocket) --}}
        @if(! empty($liveBalance))
            <div class="rounded-xl border border-[#22C55E]/30 bg-[#22C55E]/5">
                <div class="px-6 py-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Live Balance</p>
                            <div class="mt-1 flex items-end gap-2">
                                <span class="text-3xl font-bold text-zinc-900 dark:text-white">
                                    {{ number_format((float)($liveBalance['balance'] ?? 0), 2) }}
                                </span>
                                <span class="mb-0.5 text-lg font-medium text-zinc-500">
                                    {{ $liveBalance['currency'] ?? '' }}
                                </span>
                            </div>
                            <p class="mt-0.5 text-xs text-zinc-400">
                                Account: {{ $liveBalance['loginid'] ?? '—' }}
                            </p>
                        </div>
                        <div class="rounded-full bg-[#22C55E]/10 p-3">
                            <flux:icon.banknotes class="size-6 text-[#22C55E]" />
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Account List from REST API --}}
        @if(! empty($accounts))
            {{-- Real Accounts --}}
            @if(! empty($this->realAccounts))
                <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
                    <div class="border-b border-[#1F2937] px-6 py-4">
                        <flux:heading size="lg">Real Accounts</flux:heading>
                        <flux:text class="mt-0.5 text-sm text-zinc-500">
                            {{ count($this->realAccounts) }} {{ Str::plural('account', count($this->realAccounts)) }}
                        </flux:text>
                    </div>
                    <div class="divide-y divide-[#1F2937]">
                        @foreach($this->realAccounts as $account)
                            @php
                                $accountId   = $account['account_id'] ?? $account['loginid'] ?? '—';
                                $currency    = strtoupper($account['currency'] ?? '');
                                $balance     = isset($account['balance']) ? number_format((float)$account['balance'], 2) : null;
                            @endphp
                            <div class="flex items-center justify-between px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-[#22C55E]/10">
                                        <flux:icon.building-library class="size-4 text-[#22C55E]" />
                                    </div>
                                    <div>
                                        <p class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">{{ $accountId }}</p>
                                        <p class="text-xs text-zinc-500">Real account</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    @if($balance !== null)
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $balance }}</span>
                                    @endif
                                    <span class="text-sm font-medium text-zinc-500">{{ $currency }}</span>
                                    <span class="rounded-full bg-[#22C55E]/10 px-2.5 py-0.5 text-xs font-medium text-[#22C55E]">Options</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Demo Accounts --}}
            @if(! empty($this->demoAccounts))
                <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
                    <div class="border-b border-[#1F2937] px-6 py-4">
                        <flux:heading size="lg">Demo Accounts</flux:heading>
                        <flux:text class="mt-0.5 text-sm text-zinc-500">Practice with virtual money — no risk.</flux:text>
                    </div>
                    <div class="divide-y divide-[#1F2937]">
                        @foreach($this->demoAccounts as $account)
                            @php
                                $accountId   = $account['account_id'] ?? $account['loginid'] ?? '—';
                                $currency    = strtoupper($account['currency'] ?? '');
                                $balance     = isset($account['balance']) ? number_format((float)$account['balance'], 2) : null;
                            @endphp
                            <div class="flex items-center justify-between px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-9 w-9 items-center justify-center rounded-full bg-blue-500/10">
                                        <flux:icon.beaker class="size-4 text-blue-500" />
                                    </div>
                                    <div>
                                        <p class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">{{ $accountId }}</p>
                                        <p class="text-xs text-zinc-500">Demo account</p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    @if($balance !== null)
                                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $balance }}</span>
                                    @endif
                                    <span class="text-sm font-medium text-zinc-500">{{ $currency }}</span>
                                    <span class="rounded-full bg-blue-500/10 px-2.5 py-0.5 text-xs font-medium text-blue-600 dark:text-blue-400">Options</span>
                                    <flux:button
                                        wire:click="resetDemoBalance('{{ $accountId }}')"
                                        wire:confirm="Reset demo balance to $10,000?"
                                        wire:loading.attr="disabled"
                                        size="xs"
                                        variant="ghost"
                                        icon="arrow-path"
                                    >
                                        Reset
                                    </flux:button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        @elseif(! $apiError)
            {{-- Loading / empty state --}}
            <div class="rounded-xl border border-zinc-200 bg-white px-6 py-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
                <div class="mx-auto mb-3 w-fit rounded-full bg-zinc-100 p-4 dark:bg-zinc-800">
                    <flux:icon.building-library class="size-6 text-zinc-400" />
                </div>
                <flux:heading size="sm">No accounts found</flux:heading>
                <flux:text class="mt-1 text-zinc-500">No Options accounts were returned for your Deriv login.</flux:text>
            </div>
        @endif

        {{-- Connection details --}}
        @php $conn = auth()->user()->derivConnection; @endphp
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="border-b border-[#1F2937] px-6 py-4">
                <flux:heading size="lg">Connection Details</flux:heading>
            </div>
            <div class="grid grid-cols-1 gap-3 px-6 py-5 sm:grid-cols-3">
                <div class="rounded-lg bg-[#111827] px-4 py-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Connection Method</p>
                    <p class="mt-1 font-semibold text-white">
                        @if($conn->token_type === 'pat')
                            Personal Access Token
                        @else
                            OAuth2
                        @endif
                    </p>
                </div>
                <div class="rounded-lg bg-[#111827] px-4 py-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Scopes</p>
                    <p class="mt-1 font-mono text-xs text-zinc-300">
                        @if($conn->token_type === 'pat')
                            As granted by token
                        @else
                            {{ $conn->scope ?? 'trade account_manage' }}
                        @endif
                    </p>
                </div>
                <div class="rounded-lg bg-[#111827] px-4 py-3">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Token Expires</p>
                    <p class="mt-1 text-sm font-medium {{ $conn->isExpired() ? 'text-[#FF5A5F]' : 'text-white' }}">
                        @if($conn->token_type === 'pat')
                            No expiry
                        @elseif($conn->expires_at)
                            @if($conn->isExpired()) Expired @else {{ $conn->expires_at->diffForHumans() }} @endif
                        @else
                            No expiry set
                        @endif
                    </p>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 border-t border-[#1F2937] px-6 py-4">
                <flux:button href="{{ route('deriv.connect') }}" size="sm" variant="ghost" icon="arrow-path">
                    Reconnect via OAuth2
                </flux:button>
                <flux:modal.trigger name="reconnect-pat">
                    <flux:button size="sm" variant="ghost" icon="key">
                        Reconnect via Token
                    </flux:button>
                </flux:modal.trigger>
                <form method="POST" action="{{ route('deriv.disconnect') }}" class="ml-auto">
                    @csrf @method('DELETE')
                    <flux:button type="submit" size="sm" variant="danger">Disconnect</flux:button>
                </form>
            </div>

            <flux:modal name="reconnect-pat" class="w-full max-w-sm">
                <flux:heading size="lg">Reconnect via Token</flux:heading>
                <flux:text class="mt-1 text-zinc-500">
                    Paste a Personal Access Token from your
                    <a href="https://app.deriv.com/account/api-token" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">Deriv account settings</a>.
                </flux:text>
                <form method="POST" action="{{ route('deriv.connect.pat') }}" class="mt-4 flex flex-col gap-3">
                    @csrf
                    <flux:input type="password" name="pat_token" placeholder="Paste your API token here" required autofocus />
                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">Cancel</flux:button>
                        </flux:modal.close>
                        <flux:button type="submit" variant="primary" icon="key">Connect</flux:button>
                    </div>
                </form>
            </flux:modal>
        </div>

    @endif

</div>
