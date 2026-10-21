<?php

use App\Jobs\MasterListenerJob;
use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

use function Pest\Laravel\mock;

test('a master\'s disconnect keeps followers\' settings and history', function () {
    $master = DerivConnection::factory()->master()->create();
    $setting = CopySetting::factory()->create(['master_connection_id' => $master->id, 'is_running' => true]);
    $trades = CopyTrade::factory()->count(2)->create(['user_id' => $setting->user_id, 'master_connection_id' => $master->id]);

    $this->actingAs($master->user)->delete(route('deriv.disconnect'));

    expect($setting->fresh()?->only('master_connection_id', 'is_running', 'stop_reason'))
        ->toBe(['master_connection_id' => null, 'is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_MASTER_DISCONNECTED])
        ->and($trades->map(fn (CopyTrade $trade) => $trade->fresh()?->master_connection_id)->all())->toBe([null, null]);
});

test('a user\'s own disconnect keeps their own-account history', function () {
    $user = User::factory()->create();
    $connection = DerivConnection::factory()->create(['user_id' => $user->id]);
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $connection->id, 'is_running' => true]);
    CopyTrade::factory()->create(['user_id' => $user->id, 'master_connection_id' => $connection->id]);

    $this->actingAs($user)->delete(route('deriv.disconnect'));

    expect(CopyTrade::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and($setting->fresh()?->stop_reason)->toBe(CopySetting::STOP_REASON_DISCONNECTED);
});

test('a follower whose master disconnected sees the notice and cannot start', function () {
    Bus::fake([MasterListenerJob::class]);
    mock(DerivApiService::class)->shouldIgnoreMissing();
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    CopySetting::factory()->create([
        'user_id' => $user->id,
        'master_connection_id' => null,
        'is_running' => false,
        'start_balance' => 100,
        'stop_reason' => CopySetting::STOP_REASON_MASTER_DISCONNECTED,
    ]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->assertSee('Master Stopped Sharing')
        ->call('startBot')
        ->assertHasErrors('bot')
        ->assertSee(Dashboard::MASTER_REQUIRED);

    Bus::assertNotDispatched(MasterListenerJob::class);
});

test('the dashboard home renders for a setting without a master', function () {
    mock(DerivApiService::class)->shouldIgnoreMissing();
    $user = User::factory()->create();
    CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => null]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSuccessful()
        ->assertSee('no master');
});

test('ensure-listeners skips settings without a master', function () {
    Bus::fake([MasterListenerJob::class]);
    CopySetting::factory()->create(['master_connection_id' => null, 'is_running' => true]);

    $this->artisan('deriv:ensure-listeners')->assertSuccessful();

    Bus::assertNotDispatched(MasterListenerJob::class);
});
