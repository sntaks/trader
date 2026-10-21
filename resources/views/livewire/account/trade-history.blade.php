<div class="space-y-6">

    @if(! auth()->user()->hasDerivConnected())
        <div class="rounded-xl border border-zinc-200 bg-white px-6 py-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
            <flux:heading size="sm">Connect your Deriv account first</flux:heading>
            <flux:text class="mb-4 mt-1 text-zinc-500">You need a connected Deriv account to view trade history.</flux:text>
            <flux:button href="{{ route('deriv.connect') }}" variant="primary" icon="link">Connect Deriv Account</flux:button>
            <x-deriv-signup-link />
        </div>
    @else

        <x-deriv-account-select :accounts="$this->selectableAccounts()" />

        {{-- Analytics Summary --}}
        @if($this->analytics['total_trades'] > 0)
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Win Rate</p>
                    <p class="mt-1 text-2xl font-bold {{ $this->analytics['win_rate'] >= 50 ? 'text-[#22C55E]' : 'text-[#FF5A5F]' }}">
                        {{ $this->analytics['win_rate'] }}%
                    </p>
                    <p class="mt-0.5 text-xs text-zinc-500">{{ $this->analytics['wins'] }}W / {{ $this->analytics['losses'] }}L</p>
                </div>

                <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Total P&L</p>
                    <p class="mt-1 text-2xl font-bold {{ $this->analytics['total_profit'] >= 0 ? 'text-[#22C55E]' : 'text-[#FF5A5F]' }}">
                        {{ $this->analytics['total_profit'] >= 0 ? '+' : '' }}{{ number_format($this->analytics['total_profit'], 2) }}
                    </p>
                    <p class="mt-0.5 text-xs text-zinc-500">This page</p>
                </div>

                <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Best Trade</p>
                    <p class="mt-1 text-2xl font-bold text-[#22C55E]">
                        +{{ number_format($this->analytics['best_trade'], 2) }}
                    </p>
                    <p class="mt-0.5 text-xs text-zinc-500">Largest gain</p>
                </div>

                <div class="rounded-xl border border-[#1F2937] bg-[#0B1220] p-4">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-400">Avg Stake</p>
                    <p class="mt-1 text-2xl font-bold text-white">
                        {{ number_format($this->analytics['avg_stake'], 2) }}
                    </p>
                    <p class="mt-0.5 text-xs text-zinc-500">Per trade</p>
                </div>
            </div>
        @endif

        {{-- Tabs --}}
        <div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
            <div class="flex border-b border-[#1F2937]">
                <button
                    wire:click="switchTab('profit_table')"
                    class="px-5 py-3.5 text-sm font-medium transition-colors {{ $activeTab === 'profit_table' ? 'border-b-2 border-[#1E45FC] text-[#1E45FC]' : 'text-zinc-500 hover:text-zinc-300' }}"
                >
                    Trades
                </button>
                <button
                    wire:click="switchTab('statement')"
                    class="px-5 py-3.5 text-sm font-medium transition-colors {{ $activeTab === 'statement' ? 'border-b-2 border-[#1E45FC] text-[#1E45FC]' : 'text-zinc-500 hover:text-zinc-300' }}"
                >
                    Transactions
                </button>
            </div>

            {{-- Profit Table --}}
            @if($activeTab === 'profit_table')
                @php $transactions = $this->trades['transactions'] ?? []; @endphp

                @if(empty($transactions))
                    <div class="px-6 py-12 text-center">
                        <flux:icon.chart-bar class="mx-auto mb-3 size-8 text-zinc-300 dark:text-zinc-600" />
                        <flux:heading size="sm">No trades yet</flux:heading>
                        <flux:text class="mt-1 text-zinc-500">Your closed trades will appear here.</flux:text>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-[#111827]">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Symbol</th>
                                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Type</th>
                                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Stake</th>
                                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Payout</th>
                                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">P&L</th>
                                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#1F2937]">
                                @foreach($transactions as $trade)
                                    @php
                                        $pnl = (float)($trade['sell_price'] ?? 0) - (float)($trade['buy_price'] ?? 0);
                                        $isWin = $pnl > 0;
                                    @endphp
                                    <tr class="hover:bg-[#111827]/50">
                                        <td class="px-4 py-3 font-mono text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                            {{ $trade['underlying_symbol'] ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                                {{ $trade['contract_type'] ?? '—' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                            {{ number_format((float)($trade['buy_price'] ?? 0), 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                            {{ number_format((float)($trade['sell_price'] ?? 0), 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold {{ $isWin ? 'text-[#22C55E]' : 'text-[#FF5A5F]' }}">
                                            {{ $isWin ? '+' : '' }}{{ number_format($pnl, 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right text-xs text-zinc-500">
                                            @if(isset($trade['purchase_time']))
                                                {{ \Carbon\Carbon::createFromTimestamp($trade['purchase_time'])->format('d M y, H:i') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            {{-- Statement --}}
            @if($activeTab === 'statement')
                @php $transactions = $this->statement['transactions'] ?? []; @endphp

                @if(empty($transactions))
                    <div class="px-6 py-12 text-center">
                        <flux:icon.document-text class="mx-auto mb-3 size-8 text-zinc-300 dark:text-zinc-600" />
                        <flux:heading size="sm">No transactions yet</flux:heading>
                        <flux:text class="mt-1 text-zinc-500">Your account transactions will appear here.</flux:text>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="bg-[#111827]">
                                <tr>
                                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Txn ID</th>
                                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Type</th>
                                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Amount</th>
                                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Balance After</th>
                                    <th class="px-4 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Date</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[#1F2937]">
                                @foreach($transactions as $txn)
                                    @php
                                        $amount = (float)($txn['amount'] ?? 0);
                                        $actionType = $txn['action_type'] ?? '';
                                    @endphp
                                    <tr class="hover:bg-[#111827]/50">
                                        <td class="px-4 py-3 font-mono text-xs text-zinc-500">
                                            {{ $txn['transaction_id'] ?? '—' }}
                                        </td>
                                        <td class="px-4 py-3">
                                            @php
                                                $typeColor = match($actionType) {
                                                    'buy'       => 'bg-blue-500/10 text-blue-600 dark:text-blue-400',
                                                    'sell'      => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400',
                                                    'deposit'   => 'bg-violet-500/10 text-violet-600 dark:text-violet-400',
                                                    'withdrawal'=> 'bg-orange-500/10 text-orange-600 dark:text-orange-400',
                                                    default     => 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400',
                                                };
                                            @endphp
                                            <span class="rounded-full px-2.5 py-0.5 text-xs font-medium capitalize {{ $typeColor }}">
                                                {{ $actionType ?: '—' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-right font-semibold {{ $amount >= 0 ? 'text-[#22C55E]' : 'text-[#FF5A5F]' }}">
                                            {{ $amount >= 0 ? '+' : '' }}{{ number_format($amount, 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right text-zinc-700 dark:text-zinc-300">
                                            {{ number_format((float)($txn['balance_after'] ?? 0), 2) }}
                                        </td>
                                        <td class="px-4 py-3 text-right text-xs text-zinc-500">
                                            @if(isset($txn['transaction_time']))
                                                {{ \Carbon\Carbon::createFromTimestamp($txn['transaction_time'])->format('d M y, H:i') }}
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif

            {{-- Pagination --}}
            <div class="flex items-center justify-between border-t border-[#1F2937] px-4 py-3">
                <flux:text class="text-xs text-zinc-500">Page {{ $page }}</flux:text>
                <div class="flex gap-2">
                    <flux:button wire:click="prevPage" size="xs" variant="ghost" :disabled="$page <= 1">Previous</flux:button>
                    <flux:button wire:click="nextPage" size="xs" variant="ghost">Next</flux:button>
                </div>
            </div>
        </div>

    @endif

</div>
