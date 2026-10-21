<?php

use App\Livewire\CopyTrading\MasterOutcomesTicker;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

test('ticker refuses a connection the user does not follow', function () {
    $setting = CopySetting::factory()->create();
    $otherMaster = DerivConnection::factory()->master()->create();

    Livewire::actingAs($setting->user)
        ->test(MasterOutcomesTicker::class, ['connectionId' => $otherMaster->id])
        ->assertForbidden();
});

test('ticker refuses a user without a copy setting', function () {
    $master = DerivConnection::factory()->master()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(MasterOutcomesTicker::class, ['connectionId' => $master->id])
        ->assertForbidden();
});

test('ticker renders for the followed master', function () {
    $setting = CopySetting::factory()->create();

    Livewire::actingAs($setting->user)
        ->test(MasterOutcomesTicker::class, ['connectionId' => $setting->master_connection_id])
        ->assertSuccessful()
        ->assertSet('connectionId', $setting->master_connection_id);
});

test('ticker renders in own-account mode', function () {
    $user = User::factory()->create();
    $own = DerivConnection::factory()->create(['user_id' => $user->id]);
    CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $own->id, 'master_account_id' => 'DOT100']);

    Livewire::actingAs($user)
        ->test(MasterOutcomesTicker::class, ['connectionId' => $own->id])
        ->assertSuccessful();
});

test('ticker connection id cannot be changed by the client', function () {
    $setting = CopySetting::factory()->create();
    $otherMaster = DerivConnection::factory()->master()->create();

    expect(fn () => Livewire::actingAs($setting->user)
        ->test(MasterOutcomesTicker::class, ['connectionId' => $setting->master_connection_id])
        ->set('connectionId', $otherMaster->id))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});

test('the ticker name resolves to the class component', function () {
    expect(app('livewire.factory')->create('copy-trading.master-outcomes-ticker'))->toBeInstanceOf(MasterOutcomesTicker::class);
});

test('the empty reconnect-banner scaffold is gone', function () {
    expect(fn () => app('livewire.factory')->create('deriv-reconnect-banner'))->toThrow(ComponentNotFoundException::class);
});

test('the unmounted setup component is gone', function () {
    expect(class_exists('App\Livewire\CopyTrading\Setup'))->toBeFalse();
});
