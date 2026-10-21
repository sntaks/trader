<?php

use App\Jobs\MasterListenerJob;
use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

/** A follower with a stopped bot whose start balance is already known, so startBot calls no Deriv API. */
function followerWithStoppedBot(array $connection, array $masterConnection = []): array
{
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id, ...$connection]);
    $master = DerivConnection::factory()->master()->create($masterConnection);
    $setting = CopySetting::factory()->create([
        'user_id' => $user->id,
        'master_connection_id' => $master->id,
        'is_running' => false,
        'start_balance' => 100,
    ]);

    return [$user, $setting];
}

test('a follower on a sign-in session without a refresh token cannot start the bot', function () {
    Bus::fake([MasterListenerJob::class]);
    [$user, $setting] = followerWithStoppedBot(['expires_at' => now()->addHour(), 'refresh_token' => null]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('startBot')
        ->assertHasErrors('bot')
        ->assertSee(DerivConnection::UNATTENDED_REQUIRED_MESSAGE);

    expect($setting->fresh()->is_running)->toBeFalse();
    Bus::assertNotDispatched(MasterListenerJob::class);
});

test('a master on a sign-in session without a refresh token cannot be copied', function () {
    Bus::fake([MasterListenerJob::class]);
    [$user, $setting] = followerWithStoppedBot(
        ['token_type' => 'pat', 'expires_at' => null, 'refresh_token' => null],
        ['expires_at' => now()->addHour(), 'refresh_token' => null],
    );

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('startBot')
        ->assertHasErrors('bot')
        ->assertSee(DerivConnection::MASTER_UNATTENDED_REQUIRED_MESSAGE);

    expect($setting->fresh()->is_running)->toBeFalse();
});

test('a session that can keep itself signed in can start the bot', function (array $connection) {
    Bus::fake([MasterListenerJob::class]);
    [$user, $setting] = followerWithStoppedBot($connection);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('startBot')
        ->assertHasNoErrors();

    expect($setting->fresh()->is_running)->toBeTrue();
})->with([
    'refreshable sign-in' => fn () => ['expires_at' => now()->addHour(), 'refresh_token' => 'rt_1'],
    'api token' => fn () => ['token_type' => 'pat', 'expires_at' => null, 'refresh_token' => null],
]);

test('canRunUnattended', function (bool $expires, ?string $refreshToken, bool $expected) {
    $connection = new DerivConnection(['expires_at' => $expires ? now()->addHour() : null, 'refresh_token' => $refreshToken]);

    expect($connection->canRunUnattended())->toBe($expected);
})->with([
    'pat' => [false, null, true],
    'refreshable' => [true, 'rt', true],
    'sign-in without refresh' => [true, null, false],
]);
