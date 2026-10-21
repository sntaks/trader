@props(['accounts' => []])

@if (count($accounts) > 1)
    <flux:field class="w-full sm:w-64">
        <flux:label>Account</flux:label>
        <flux:select wire:model.live="accountId" size="sm">
            @foreach ($accounts as $account)
                <flux:select.option wire:key="account-{{ $account['account_id'] }}" value="{{ $account['account_id'] }}">
                    {{ ($account['account_type'] ?? '') === 'demo' ? 'Demo' : 'Real' }} — {{ $account['account_id'] }}
                </flux:select.option>
            @endforeach
        </flux:select>
        <flux:error name="accountId" />
    </flux:field>
@endif
