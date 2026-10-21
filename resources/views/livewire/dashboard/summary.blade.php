<div class="space-y-4">
    <x-deriv-account-select :accounts="$this->selectableAccounts()" />

    @if(! empty($this->balance))
        {{-- Balance + Performance Row --}}
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">

            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-5">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500">Balance</flux:text>
                    <div class="rounded-lg bg-[#22C55E]/10 p-2">
                        <flux:icon.banknotes class="size-4 text-[#22C55E]" />
                    </div>
                </div>
                <p class="mt-2 text-2xl font-bold text-white">
                    {{ number_format((float)($this->balance['balance'] ?? 0), 2) }}
                </p>
                <p class="mt-0.5 text-xs text-zinc-400">{{ $this->balance['currency'] ?? '' }} · {{ $this->balance['loginid'] ?? '' }}</p>
            </div>

            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-5">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500">Win Rate</flux:text>
                    <div class="rounded-lg p-2 {{ $this->performance['win_rate'] >= 50 ? 'bg-[#22C55E]/10' : 'bg-[#FF5A5F]/10' }}">
                        <flux:icon.trophy class="size-4 {{ $this->performance['win_rate'] >= 50 ? 'text-[#22C55E]' : 'text-[#FF5A5F]' }}" />
                    </div>
                </div>
                <p class="mt-2 text-2xl font-bold {{ $this->performance['win_rate'] >= 50 ? 'text-[#22C55E]' : 'text-[#FF5A5F]' }}">
                    {{ $this->performance['win_rate'] }}%
                </p>
                <p class="mt-0.5 text-xs text-zinc-400">{{ $this->performance['wins'] }}W / {{ $this->performance['losses'] }}L (last 20)</p>
            </div>

            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-5">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500">P&L</flux:text>
                    <div class="rounded-lg p-2 {{ $this->performance['pnl_positive'] ? 'bg-[#22C55E]/10' : 'bg-[#FF5A5F]/10' }}">
                        <flux:icon.arrow-trending-up class="size-4 {{ $this->performance['pnl_positive'] ? 'text-[#22C55E]' : 'text-[#FF5A5F]' }}" />
                    </div>
                </div>
                <p class="mt-2 text-2xl font-bold {{ $this->performance['pnl_positive'] ? 'text-[#22C55E]' : 'text-[#FF5A5F]' }}">
                    {{ $this->performance['pnl_positive'] ? '+' : '' }}{{ number_format($this->performance['pnl'], 2) }}
                </p>
                <p class="mt-0.5 text-xs text-zinc-400">Last 20 trades</p>
            </div>

            <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-5">
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm font-medium text-zinc-500">Trades</flux:text>
                    <div class="rounded-lg bg-[#111827] p-2">
                        <flux:icon.chart-bar class="size-4 text-zinc-500" />
                    </div>
                </div>
                <p class="mt-2 text-2xl font-bold text-white">{{ $this->performance['trades'] }}</p>
                <p class="mt-0.5 text-xs text-zinc-400">Last 20 fetched</p>
            </div>

        </div>
    @endif
</div>
