<div class="rounded-xl border border-[#1F2937] bg-[#0B1220]">
    <div class="border-b border-[#1F2937] px-6 py-4">
        <flux:heading size="lg">Markup Earnings — Last {{ \App\Livewire\Admin\MarkupEarnings::PERIOD_DAYS }} Days</flux:heading>
    </div>

    @php $data = $this->earnings['data']; @endphp

    @if($this->earnings['error'])
        <div class="px-6 py-5">
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.text>{{ $this->earnings['error'] }}</flux:callout.text>
            </flux:callout>
        </div>
    @else
        <div class="grid grid-cols-2 gap-4 px-6 py-5 sm:grid-cols-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Markup</p>
                <p class="mt-1 text-2xl font-bold text-[#22C55E]">${{ number_format((float) $data['total_app_markup_usd'], 2) }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Contracts</p>
                <p class="mt-1 text-2xl font-bold text-white">{{ number_format((int) $data['total_contract_count']) }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Clients</p>
                <p class="mt-1 text-2xl font-bold text-white">{{ number_format((int) $data['total_client_count']) }}</p>
            </div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500">Volume</p>
                <p class="mt-1 text-2xl font-bold text-white">${{ number_format((float) $data['total_volume_usd'], 2) }}</p>
            </div>
        </div>

        @foreach($this->zeroMarkupApps as $app)
            <div class="px-6 pb-3">
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>
                        App {{ $app['app_id'] }} placed {{ $app['contract_count'] }} contracts but earned no markup. Check its markup setting in the Deriv developer dashboard.
                    </flux:callout.text>
                </flux:callout>
            </div>
        @endforeach

        @if(count($data['breakdown']) > 0)
            <div class="divide-y divide-[#1F2937] border-t border-[#1F2937]">
                @foreach($data['breakdown'] as $app)
                    <div class="flex items-center justify-between px-6 py-3 text-sm">
                        <div>
                            <span class="font-mono text-white">{{ $app['app_id'] }}</span>
                            @if($label = $this->appLabel((string) $app['app_id']))
                                <span class="ml-2 rounded-full bg-zinc-800 px-2 py-0.5 text-xs text-zinc-400">{{ $label }}</span>
                            @endif
                        </div>
                        <div class="flex gap-6 text-zinc-400">
                            <span>{{ number_format((int) $app['contract_count']) }} contracts</span>
                            <span class="font-semibold text-white">${{ number_format((float) $app['app_markup_usd'], 2) }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
