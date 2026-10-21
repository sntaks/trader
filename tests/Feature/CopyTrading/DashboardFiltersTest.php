<?php

use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\User;
use Livewire\Livewire;

test('settings offer trade types and forex labels from the copy setting filters', function () {
    $user = User::factory()->create();
    CopySetting::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('settingsOpen', true)
        ->assertSee('Trade Types')
        ->assertDontSee('Synthetic Indices')
        ->assertSee('EUR/USD')
        ->assertSee("toggleSynthetic('DM')", false)
        ->assertDontSee("toggleSynthetic('HILO')", false);
});
