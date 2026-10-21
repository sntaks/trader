<?php

use App\Exceptions\DerivApiException;
use App\Jobs\CopyTradeJob;
use App\Jobs\MasterListenerJob;
use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
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

test('bot controls clear only the acting users pattern lock', function (string $action) {
    Bus::fake([MasterListenerJob::class]);
    mock(DerivApiService::class)
        ->shouldReceive('getBalance')
        ->andReturn(['balance' => ['balance' => 100]])
        ->getMock()
        ->shouldIgnoreMissing();

    $master = DerivConnection::factory()->master()->create();
    $settings = collect([1, 2])->map(function () use ($master) {
        $user = User::factory()->create();
        DerivConnection::factory()->create(['user_id' => $user->id]);

        return CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $master->id, 'is_running' => true]);
    });
    [$actor, $other] = [$settings[0], $settings[1]];

    foreach ($settings as $setting) {
        Cache::put(CopyTradeJob::patternConsumedKey($master->id, $setting->user_id), true, 600);
    }

    Livewire::actingAs($actor->user)->test(Dashboard::class)->call($action);

    expect(Cache::has(CopyTradeJob::patternConsumedKey($master->id, $actor->user_id)))->toBeFalse()
        ->and(Cache::has(CopyTradeJob::patternConsumedKey($master->id, $other->user_id)))->toBeTrue();
})->with(['startBot', 'performReset', 'performFullReset']);

test('startBot records the start balance of the follower account', function () {
    Bus::fake([MasterListenerJob::class]);
    mock(DerivApiService::class)
        ->shouldReceive('getBalance')
        ->with(Mockery::type(DerivConnection::class), 'DOT200', Mockery::any(), Mockery::any())
        ->andReturn(['balance' => ['balance' => 250]])
        ->getMock()
        ->shouldIgnoreMissing();

    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'follower_account_id' => 'DOT200', 'is_running' => false, 'start_balance' => null]);

    Livewire::actingAs($user)->test(Dashboard::class)->call('startBot')->assertHasNoErrors();

    expect($setting->fresh())
        ->start_balance->toEqual(250)
        ->is_running->toBeTrue();
});

test('startBot asks for a follower account when none is set', function () {
    Bus::fake([MasterListenerJob::class]);
    mock(DerivApiService::class)->shouldNotReceive('getBalance')->getMock()->shouldIgnoreMissing();

    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'follower_account_id' => null, 'is_running' => false, 'start_balance' => null]);

    Livewire::actingAs($user)->test(Dashboard::class)->call('startBot')->assertHasErrors('bot');

    expect($setting->fresh()->is_running)->toBeFalse();
    Bus::assertNotDispatched(MasterListenerJob::class);
});

test('the dashboard polls the balance with a two minute max age', function () {
    mock(DerivApiService::class)
        ->shouldReceive('getBalance')
        ->with(Mockery::type(DerivConnection::class), 'DOT100', 120)
        ->atLeast()->once()
        ->andReturn(['balance' => ['balance' => 321.5]])
        ->getMock()
        ->shouldIgnoreMissing();

    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    CopySetting::factory()->create(['user_id' => $user->id, 'follower_account_id' => 'DOT100']);

    Livewire::actingAs($user)->test(Dashboard::class)->assertSee('321.50 USD');
});

test('startBot never records a stale balance as the start balance', function () {
    Bus::fake([MasterListenerJob::class]);
    mock(DerivApiService::class)
        ->shouldReceive('getBalance')
        ->with(Mockery::type(DerivConnection::class), 'DOT100', 30, false)
        ->once()
        ->andReturn(['balance' => ['balance' => 50.0]])
        ->getMock()
        ->shouldIgnoreMissing();

    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'follower_account_id' => 'DOT100', 'is_running' => false, 'start_balance' => null]);

    Livewire::actingAs($user)->test(Dashboard::class)->call('startBot');

    expect((float) $setting->fresh()->start_balance)->toBe(50.0);
});

test('the dashboard explains a reauth stop', function () {
    mock(DerivApiService::class)->shouldIgnoreMissing();
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    CopySetting::factory()->create(['user_id' => $user->id, 'is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_REAUTH]);

    Livewire::actingAs($user)->test(Dashboard::class)
        ->assertSee('Deriv Connection Lost')
        ->assertSee('has expired or was revoked');
});

test('startBot queues the listener once', function () {
    Bus::fake([MasterListenerJob::class]);
    mock(DerivApiService::class)
        ->shouldReceive('getBalance')
        ->andReturn(['balance' => ['balance' => 100]])
        ->getMock()
        ->shouldIgnoreMissing();
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);
    CopySetting::factory()->create(['user_id' => $user->id, 'follower_account_id' => 'DOT100', 'is_running' => false]);

    Livewire::actingAs($user)->test(Dashboard::class)
        ->call('startBot')
        ->call('stopBot')
        ->call('startBot');

    Bus::assertDispatchedTimes(MasterListenerJob::class, 1);
});
