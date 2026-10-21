<x-layouts::app :title="__('Dashboard')">
    <div class="max-w-5xl mx-auto space-y-8 py-8 px-4">

        {{-- Alerts --}}
        @if(session('success'))
            <flux:callout variant="success" icon="check-circle" class="animate-in fade-in slide-in-from-top-2">
                <flux:callout.heading>{{ session('success') }}</flux:callout.heading>
            </flux:callout>
        @endif

        @if(session('error'))
            <flux:callout variant="danger" icon="x-circle" class="animate-in fade-in slide-in-from-top-2">
                <flux:callout.heading>{{ session('error') }}</flux:callout.heading>
            </flux:callout>
        @endif

        {{-- Header --}}
        <div>
            <flux:heading size="xl" class="font-semibold">
                Welcome back, {{ auth()->user()->name }} 👋
            </flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                Here's what's happening with your trading account.
            </flux:text>
        </div>

        {{-- Static Platform Stats --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Status</flux:text>
                    <div class="rounded-lg bg-zinc-100 p-2 dark:bg-zinc-800">
                        <flux:icon.signal class="size-4 text-zinc-500 dark:text-zinc-400" />
                    </div>
                </div>
                <div class="mt-2">
                    @if(auth()->user()->hasDerivConnected())
                        <span class="text-lg font-semibold text-emerald-600 dark:text-emerald-400">Active</span>
                    @else
                        <span class="text-lg font-semibold text-zinc-400">Inactive</span>
                    @endif
                </div>
                <flux:text class="mt-1 text-xs text-zinc-400">Deriv connection status</flux:text>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Account</flux:text>
                    <div class="rounded-lg bg-zinc-100 p-2 dark:bg-zinc-800">
                        <flux:icon.identification class="size-4 text-zinc-500 dark:text-zinc-400" />
                    </div>
                </div>
                <div class="mt-2">
                    <span class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ auth()->user()->account_no }}
                    </span>
                </div>
                <flux:text class="mt-1 text-xs text-zinc-400">Your platform account</flux:text>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Copy Trading</flux:text>
                    <div class="rounded-lg bg-zinc-100 p-2 dark:bg-zinc-800">
                        <flux:icon.arrows-right-left class="size-4 text-zinc-500 dark:text-zinc-400" />
                    </div>
                </div>
                <div class="mt-2">
                    @php $copySetting = auth()->user()->copySetting; @endphp
                    @if($copySetting?->is_active)
                        <span class="text-lg font-semibold text-emerald-600 dark:text-emerald-400">Active</span>
                    @elseif($copySetting)
                        <span class="text-lg font-semibold text-amber-500">Paused</span>
                    @else
                        <span class="text-lg font-semibold text-zinc-400">Not set up</span>
                    @endif
                </div>
                <flux:text class="mt-1 text-xs text-zinc-400">
                    @if($copySetting)
                        Following {{ $copySetting->masterConnection?->user?->name ?? 'no master' }}
                    @else
                        <a href="{{ route('copy-trading') }}" class="text-zinc-500 underline underline-offset-2 hover:text-zinc-700">Set up copy trading</a>
                    @endif
                </flux:text>
            </div>
        </div>

        {{-- Live Deriv Analytics (only if connected) --}}
        @if(auth()->user()->hasDerivConnected())
            <livewire:dashboard.summary />
        @endif

        {{-- Admin Quick Stats --}}
        @if(auth()->user()->isAdmin())
            @php
                $totalUsers = \App\Models\User::count();
                $connectedUsers = \App\Models\User::whereHas('derivConnection')->count();
                $masterCount = \App\Models\DerivConnection::where('type', 'master')->count();
                $activeCopies = \App\Models\CopySetting::where('is_active', true)->count();
            @endphp
            <div class="rounded-xl border border-violet-200 bg-violet-50/50 dark:border-violet-800/40 dark:bg-violet-900/10">
                <div class="border-b border-violet-100 px-6 py-4 dark:border-violet-800/30">
                    <div class="flex items-center gap-2">
                        <flux:icon.shield-check class="size-4 text-violet-600 dark:text-violet-400" />
                        <flux:heading size="md" class="text-violet-700 dark:text-violet-300">Admin Overview</flux:heading>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-4 px-6 py-5 sm:grid-cols-4">
                    <div class="text-center">
                        <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $totalUsers }}</p>
                        <p class="text-xs text-zinc-500">Total Users</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $connectedUsers }}</p>
                        <p class="text-xs text-zinc-500">Deriv Connected</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $masterCount }}</p>
                        <p class="text-xs text-zinc-500">Master Traders</p>
                    </div>
                    <div class="text-center">
                        <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $activeCopies }}</p>
                        <p class="text-xs text-zinc-500">Active Copies</p>
                    </div>
                </div>
                <div class="border-t border-violet-100 px-6 py-3 dark:border-violet-800/30">
                    <flux:button href="{{ route('admin.dashboard') }}" size="sm" variant="ghost" icon="arrow-right">
                        Full Admin Dashboard
                    </flux:button>
                </div>
            </div>
        @endif

        {{-- Deriv Connection Card --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 px-6 py-4 dark:border-zinc-800">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:heading size="lg">Deriv Account</flux:heading>
                        <flux:text class="mt-0.5 text-sm text-zinc-500">
                            Connect your Deriv account to enable copy trading.
                        </flux:text>
                    </div>
                    @if(auth()->user()->hasDerivConnected())
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-500/10 px-3 py-1 text-sm font-medium text-emerald-600 dark:text-emerald-400">
                            <span class="h-2 w-2 animate-pulse rounded-full bg-emerald-500"></span>
                            Connected
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-zinc-100 px-3 py-1 text-sm font-medium text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                            <span class="h-2 w-2 rounded-full bg-zinc-300 dark:bg-zinc-600"></span>
                            Not Connected
                        </span>
                    @endif
                </div>
            </div>

            <div class="px-6 py-5">
                @if(auth()->user()->hasDerivConnected())
                    @php $conn = auth()->user()->derivConnection; @endphp

                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div class="rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800/50">
                                <flux:text class="text-xs font-medium text-zinc-400 uppercase tracking-wide">Token Type</flux:text>
                                <p class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $conn->token_type }}</p>
                            </div>
                            <div class="rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800/50">
                                <flux:text class="text-xs font-medium text-zinc-400 uppercase tracking-wide">Scopes</flux:text>
                                <p class="mt-1 font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ $conn->scope ?? 'trade account_manage' }}</p>
                            </div>
                            <div class="rounded-lg bg-zinc-50 px-4 py-3 dark:bg-zinc-800/50">
                                <flux:text class="text-xs font-medium text-zinc-400 uppercase tracking-wide">Expires</flux:text>
                                <p class="mt-1 text-sm font-medium {{ $conn->isExpired() ? 'text-red-500' : 'text-zinc-900 dark:text-white' }}">
                                    @if($conn->expires_at)
                                        @if($conn->isExpired()) Expired @else {{ $conn->expires_at->diffForHumans() }} @endif
                                    @else
                                        No expiry
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if($conn->isExpired())
                            <flux:callout variant="warning" icon="exclamation-triangle">
                                <flux:callout.heading>Token expired — reconnect to continue trading.</flux:callout.heading>
                            </flux:callout>
                        @endif

                        <div class="flex flex-wrap gap-2">
                            <flux:button href="{{ route('account') }}" size="sm" variant="primary" icon="user">
                                View Account Details
                            </flux:button>
                            <flux:button href="{{ route('trades') }}" size="sm" variant="ghost" icon="chart-bar">
                                Trade History
                            </flux:button>
                            <flux:button href="{{ route('deriv.connect') }}" size="sm" variant="ghost" icon="arrow-path">
                                Reconnect
                            </flux:button>
                            <form method="POST" action="{{ route('deriv.disconnect') }}">
                                @csrf
                                @method('DELETE')
                                <flux:button type="submit" size="sm" variant="danger">Disconnect</flux:button>
                            </form>
                        </div>
                    </div>

                @else
                    <div class="grid grid-cols-1 gap-px sm:grid-cols-2">
                        {{-- Option 1: OAuth2 --}}
                        <div class="flex flex-col gap-3 py-5 sm:pr-6">
                            <div class="flex items-center gap-2">
                                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-indigo-500/10">
                                    <flux:icon.arrow-top-right-on-square class="size-4 text-indigo-500" />
                                </div>
                                <flux:heading size="sm">OAuth2 <span class="text-xs font-normal text-zinc-400">(Recommended)</span></flux:heading>
                            </div>
                            <flux:text class="text-sm text-zinc-500">
                                Sign in securely via Deriv's website. No token copying needed.
                            </flux:text>
                            <flux:button href="{{ route('deriv.connect') }}" variant="primary" icon="link" class="mt-auto">
                                Connect via Deriv
                            </flux:button>
                            <x-deriv-signup-link />
                        </div>

                        {{-- Option 2: Personal Access Token --}}
                        <div class="flex flex-col gap-3 border-t border-zinc-100 py-5 dark:border-zinc-800 sm:border-l sm:border-t-0 sm:pl-6">
                            <div class="flex items-center gap-2">
                                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10">
                                    <flux:icon.key class="size-4 text-amber-500" />
                                </div>
                                <flux:heading size="sm">Personal Access Token</flux:heading>
                            </div>
                            <flux:text class="text-sm text-zinc-500">
                                Generate a token in your <a href="https://app.deriv.com/account/api-token" target="_blank" rel="noopener noreferrer" class="underline underline-offset-2">Deriv settings</a> and paste it below.
                            </flux:text>
                            @error('pat_token')
                                <p class="text-sm text-red-500">{{ $message }}</p>
                            @enderror
                            <form method="POST" action="{{ route('deriv.connect.pat') }}" class="mt-auto flex flex-col gap-2">
                                @csrf
                                <flux:input type="password" name="pat_token" placeholder="Paste your API token" required />
                                <flux:button type="submit" variant="ghost" icon="key">Connect with Token</flux:button>
                            </form>
                        </div>
                    </div>
                @endif
            </div>
        </div>

    </div>
</x-layouts::app>
