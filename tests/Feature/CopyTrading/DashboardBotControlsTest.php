<?php

use App\Exceptions\DerivApiException;
use App\Jobs\MasterListenerJob;
use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

use function Pest\Laravel\mock;

test('startBot shows the deriv error and does not start when balance fails', function () {
    Bus::fake([MasterListenerJob::class]);
    mock(DerivApiService::class)
        ->shouldReceive('getBalance')
        ->andThrow(new DerivApiException('Token expired or invalid. Please reconnect your Deriv account.'))
        ->getMock()
        ->shouldIgnoreMissing();

    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'is_running' => false, 'start_balance' => null]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('startBot')
        ->assertHasErrors('bot')
        ->assertSee('Please reconnect your Deriv account.');

    expect($setting->fresh()->is_running)->toBeFalse();
    Bus::assertNotDispatched(MasterListenerJob::class);
});
