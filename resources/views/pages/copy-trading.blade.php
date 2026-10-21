<x-layouts::app :title="__('Copy Trading')">
    <div class="mx-auto max-w-6xl space-y-6 px-4 py-8">

        @if(session('success'))
            <flux:callout variant="success" icon="check-circle">
                <flux:callout.heading>{{ session('success') }}</flux:callout.heading>
            </flux:callout>
        @endif

        @if(session('settings_saved'))
            <flux:callout variant="success" icon="check-circle">
                <flux:callout.heading>{{ session('settings_saved') }}</flux:callout.heading>
            </flux:callout>
        @endif

        <div>
            <flux:heading size="xl">Copy Trading</flux:heading>
            <flux:text class="mt-1 text-zinc-500">
                Copy trades from Demo to Real Account.
            </flux:text>
        </div>

        @if(! auth()->user()->hasDerivConnected())
            <div>
                <flux:heading size="sm" class="mb-1">Connect your Deriv account first</flux:heading>
                <flux:text class="mb-5 text-zinc-500">You need a connected Deriv account to use copy trading. Choose a connection method below.</flux:text>

                @if(session('error'))
                    <div class="mb-4 rounded-lg border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
                        {{ session('error') }}
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    {{-- OAuth2 --}}
                    <div class="flex flex-col rounded-xl border border-[#1F2937] bg-[#0B1220] p-6">
                        <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-[#1E45FC]/15">
                            <flux:icon.arrow-top-right-on-square class="size-5 text-[#1E45FC]" />
                        </div>
                        <h3 class="font-semibold text-white">Connect with Deriv OAuth</h3>
                        <p class="mt-1 grow text-xs text-zinc-500">Recommended. Securely authorise via Deriv's login page — no passwords stored.</p>
                        <flux:button href="{{ route('deriv.connect') }}" variant="primary" icon="link" class="mt-5 w-full justify-center">
                            Connect with Deriv
                        </flux:button>
                        <x-deriv-signup-link />
                    </div>

                    {{-- PAT --}}
                    <div class="flex flex-col rounded-xl border border-[#1F2937] bg-[#0B1220] p-6">
                        <div class="mb-4 flex h-10 w-10 items-center justify-center rounded-full bg-amber-500/15">
                            <flux:icon.key class="size-5 text-amber-400" />
                        </div>
                        <h3 class="font-semibold text-white">Personal Access Token (PAT)</h3>
                        <p class="mt-1 text-xs text-zinc-500">Paste a PAT from your Deriv account settings. Useful if OAuth is unavailable.</p>
                        <form method="POST" action="{{ route('deriv.connect.pat') }}" class="mt-5 space-y-3">
                            @csrf
                            <input type="hidden" name="redirect_to" value="copy-trading" />
                            <flux:input
                                name="pat_token"
                                type="password"
                                placeholder="Paste your Personal Access Token"
                                class="w-full"
                                required
                            />
                            <flux:button type="submit" variant="filled" class="w-full justify-center bg-amber-600 hover:bg-amber-500 text-white">
                                Connect with PAT
                            </flux:button>
                        </form>
                    </div>
                </div>
            </div>
        @else
            <livewire:copy-trading.dashboard />
        @endif

    </div>
</x-layouts::app>
