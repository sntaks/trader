<div class="space-y-8">

    {{-- KPI Row --}}
    <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">

        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-5">
            <div class="flex items-center justify-between">
                <flux:text class="text-sm font-medium text-zinc-500">Total Users</flux:text>
                <div class="rounded-lg bg-zinc-100 p-2 dark:bg-zinc-800">
                    <flux:icon.users class="size-4 text-zinc-500" />
                </div>
            </div>
            <p class="mt-2 text-3xl font-bold text-zinc-900 dark:text-white">{{ number_format($this->userStats['total']) }}</p>
            <p class="mt-1 text-xs text-zinc-400">
                +{{ $this->userStats['week_signups'] }} this week
            </p>
        </div>

        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-5">
            <div class="flex items-center justify-between">
                <flux:text class="text-sm font-medium text-zinc-500">Deriv Connected</flux:text>
                <div class="rounded-lg bg-[#22C55E]/10 p-2">
                    <flux:icon.link class="size-4 text-[#22C55E]" />
                </div>
            </div>
            <p class="mt-2 text-3xl font-bold text-zinc-900 dark:text-white">{{ $this->userStats['connected'] }}</p>
            <p class="mt-1 text-xs text-zinc-400">{{ $this->userStats['connection_rate'] }}% connection rate</p>
        </div>

        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-5">
            <div class="flex items-center justify-between">
                <flux:text class="text-sm font-medium text-zinc-500">Master Traders</flux:text>
                <div class="rounded-lg bg-amber-500/10 p-2">
                    <flux:icon.star class="size-4 text-amber-500" />
                </div>
            </div>
            <p class="mt-2 text-3xl font-bold text-zinc-900 dark:text-white">{{ $this->derivStats['masters'] }}</p>
            <p class="mt-1 text-xs text-zinc-400">{{ $this->derivStats['followers'] }} followers</p>
        </div>

        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-5">
            <div class="flex items-center justify-between">
                <flux:text class="text-sm font-medium text-zinc-500">Active Copy Setups</flux:text>
                <div class="rounded-lg bg-violet-500/10 p-2">
                    <flux:icon.arrows-right-left class="size-4 text-violet-500" />
                </div>
            </div>
            <p class="mt-2 text-3xl font-bold text-zinc-900 dark:text-white">{{ $this->copyTradingStats['active'] }}</p>
            <p class="mt-1 text-xs text-zinc-400">{{ $this->copyTradingStats['paused'] }} paused · {{ $this->copyTradingStats['no_master'] }} without a master</p>
        </div>

    </div>

    {{-- Two-column layout --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Signup Trend --}}
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="border-b border-[#1F2937] px-6 py-4">
                <flux:heading size="lg">Signups — Last 14 Days</flux:heading>
            </div>
            <div class="px-6 py-5">
                <div class="flex h-32 items-end gap-1">
                    @php
                        $maxSignups = max(array_column($this->signupTrend, 'count') ?: [1]);
                    @endphp
                    @foreach($this->signupTrend as $day)
                        @php $height = $maxSignups > 0 ? max(4, round(($day['count'] / $maxSignups) * 100)) : 4; @endphp
                        <div class="group relative flex flex-1 flex-col items-center justify-end gap-1">
                            <div
                                class="w-full rounded-t-sm bg-violet-500/80 transition-all"
                                style="height: {{ $height }}%"
                            ></div>
                            <span class="text-[10px] text-zinc-400 rotate-45 origin-left mt-1 hidden sm:block">{{ $day['date'] }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 flex justify-between text-xs text-zinc-400">
                    <span>14 days ago</span>
                    <span>Today (+{{ $this->userStats['today_signups'] }})</span>
                </div>
            </div>
        </div>

        {{-- Platform Health --}}
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="border-b border-[#1F2937] px-6 py-4">
                <flux:heading size="lg">Platform Health</flux:heading>
            </div>
            <div class="space-y-4 px-6 py-5">

                {{-- Connection Rate --}}
                <div>
                    <div class="mb-1 flex justify-between text-sm">
                        <span class="text-zinc-600 dark:text-zinc-400">Deriv Connection Rate</span>
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $this->userStats['connection_rate'] }}%</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-[#111827]">
                        <div class="h-full rounded-full bg-[#22C55E]" style="width: {{ $this->userStats['connection_rate'] }}%"></div>
                    </div>
                </div>

                {{-- Copy Trading Activation --}}
                @php
                    $copyRate = $this->userStats['connected'] > 0
                        ? round(($this->copyTradingStats['total_setups'] / $this->userStats['connected']) * 100, 1)
                        : 0;
                @endphp
                <div>
                    <div class="mb-1 flex justify-between text-sm">
                        <span class="text-zinc-600 dark:text-zinc-400">Copy Trading Activation</span>
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $copyRate }}%</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-[#111827]">
                        <div class="h-full rounded-full bg-violet-500" style="width: {{ min(100, $copyRate) }}%"></div>
                    </div>
                </div>

                {{-- Token Health --}}
                @php
                    $healthRate = $this->derivStats['total_connections'] > 0
                        ? round(($this->derivStats['active'] / $this->derivStats['total_connections']) * 100, 1)
                        : 100;
                @endphp
                <div>
                    <div class="mb-1 flex justify-between text-sm">
                        <span class="text-zinc-600 dark:text-zinc-400">Active Tokens</span>
                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $healthRate }}%</span>
                    </div>
                    <div class="h-2 w-full overflow-hidden rounded-full bg-[#111827]">
                        <div class="h-full rounded-full {{ $healthRate >= 80 ? 'bg-[#22C55E]' : 'bg-amber-500' }}" style="width: {{ $healthRate }}%"></div>
                    </div>
                </div>

                {{-- Counts grid --}}
                <div class="grid grid-cols-3 gap-3 pt-2">
                    <div class="rounded-lg bg-[#111827] px-3 py-2 text-center">
                        <p class="text-lg font-bold text-zinc-900 dark:text-white">{{ $this->userStats['today_signups'] }}</p>
                        <p class="text-xs text-zinc-400">Today</p>
                    </div>
                    <div class="rounded-lg bg-[#111827] px-3 py-2 text-center">
                        <p class="text-lg font-bold text-zinc-900 dark:text-white">{{ $this->userStats['week_signups'] }}</p>
                        <p class="text-xs text-zinc-400">This Week</p>
                    </div>
                    <div class="rounded-lg bg-[#111827] px-3 py-2 text-center">
                        <p class="text-lg font-bold text-zinc-900 dark:text-white">{{ $this->userStats['month_signups'] }}</p>
                        <p class="text-xs text-zinc-400">This Month</p>
                    </div>
                </div>

            </div>
        </div>

    </div>

    {{-- Top Masters + Recent Users --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- Top Masters --}}
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="border-b border-[#1F2937] px-6 py-4">
                <flux:heading size="lg">Top Master Traders</flux:heading>
                <flux:text class="mt-0.5 text-sm text-zinc-500">Ranked by number of followers</flux:text>
            </div>
            @if($this->copyTradingStats['top_masters']->isEmpty())
                <div class="px-6 py-10 text-center">
                    <flux:icon.star class="mx-auto mb-2 size-6 text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="text-zinc-500">No master traders yet.</flux:text>
                </div>
            @else
                <div class="divide-y divide-[#1F2937]">
                    @foreach($this->copyTradingStats['top_masters'] as $index => $master)
                        <div class="flex items-center justify-between px-6 py-3.5">
                            <div class="flex items-center gap-3">
                                <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $index === 0 ? 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' }} text-xs font-bold">
                                    {{ $index + 1 }}
                                </span>
                                <flux:avatar :name="$master->user->name" :initials="$master->user->initials()" size="sm" />
                                <div>
                                    <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ $master->user->name }}</p>
                                    <p class="text-xs text-zinc-500">{{ $master->user->email }}</p>
                                </div>
                            </div>
                            <span class="text-sm font-semibold text-zinc-700 dark:text-zinc-300">
                                {{ $master->followers_count }} {{ Str::plural('follower', $master->followers_count) }}
                            </span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Recent Signups --}}
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="border-b border-[#1F2937] px-6 py-4">
                <flux:heading size="lg">Recent Signups</flux:heading>
                <flux:text class="mt-0.5 text-sm text-zinc-500">Last 10 registered users</flux:text>
            </div>
            <div class="divide-y divide-[#1F2937]">
                @foreach($this->recentUsers as $user)
                    <div class="flex items-center justify-between px-6 py-3">
                        <div class="flex items-center gap-3">
                            <flux:avatar :name="$user->name" :initials="$user->initials()" size="sm" />
                            <div>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ $user->name }}
                                    @if($user->is_admin)
                                        <span class="ml-1 rounded-full bg-violet-500/10 px-1.5 py-0.5 text-xs font-medium text-violet-600 dark:text-violet-400">Admin</span>
                                    @endif
                                </p>
                                <p class="text-xs text-zinc-500">{{ $user->created_at->diffForHumans() }}</p>
                            </div>
                        </div>
                        @if($user->derivConnection)
                            <span class="inline-flex items-center gap-1 rounded-full bg-[#22C55E]/10 px-2 py-0.5 text-xs text-[#22C55E]">
                                <span class="h-1.5 w-1.5 rounded-full bg-[#22C55E]"></span>
                                Connected
                            </span>
                        @else
                            <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-400 dark:bg-zinc-800">
                                Not connected
                            </span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

    </div>

</div>
