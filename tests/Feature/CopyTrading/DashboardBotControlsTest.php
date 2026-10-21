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
        ->with(Mockery::type(DerivConnection::class), 'DOT200')
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
