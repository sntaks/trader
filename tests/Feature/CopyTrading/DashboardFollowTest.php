<?php

use App\Exceptions\DerivApiException;
use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

use function Pest\Laravel\mock;

beforeEach(function () {
    mock(DerivApiService::class)
        ->shouldReceive('getAccounts')
        ->andReturn([
            ['account_id' => 'DOT100', 'currency' => 'USD'],
            ['account_id' => 'DOT200', 'currency' => 'USD'],
        ])
        ->getMock()
        ->shouldIgnoreMissing();
});

function followTestUser(string $type = 'follower'): User
{
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id, 'type' => $type]);

    return $user;
}

test('a follower can follow a master', function () {
    $user = followTestUser();
    $master = DerivConnection::factory()->master()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('selectMaster', $master->id)
        ->set('followerAccountId', 'DOT100')
        ->call('follow')
        ->assertHasNoErrors();

    expect($user->copySetting()->value('master_connection_id'))->toBe($master->id);
});

test('own-account follow works with two different accounts', function () {
    $user = followTestUser();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('enterOwnAccountMode')
        ->set('masterAccountId', 'DOT100')
        ->set('followerAccountId', 'DOT200')
        ->call('follow')
        ->assertHasNoErrors();

    expect($user->copySetting()->first())
        ->master_connection_id->toBe($user->derivConnection->id)
        ->master_account_id->toBe('DOT100')
        ->follower_account_id->toBe('DOT200');
});

test('follow refuses a connection that is not a master', function () {
    $user = followTestUser();
    $other = DerivConnection::factory()->create(['type' => 'follower']);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('selectMaster', $other->id)
        ->set('followerAccountId', 'DOT100')
        ->call('follow')
        ->assertHasErrors('selectedMasterId');

    expect(CopySetting::count())->toBe(0);
});

test('follow refuses the users own connection outside own-account mode', function () {
    $user = followTestUser('master');

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('selectMaster', $user->derivConnection->id)
        ->set('followerAccountId', 'DOT100')
        ->call('follow')
        ->assertHasErrors('selectedMasterId');

    expect(CopySetting::count())->toBe(0);
});

test('the client cannot flip own-account mode or the selected master', function (string $property, mixed $value) {
    $user = followTestUser();

    expect(fn () => Livewire::actingAs($user)->test(Dashboard::class)->set($property, $value))
        ->toThrow(CannotUpdateLockedPropertyException::class);
})->with([
    'own account mode' => ['ownAccountMode', true],
    'selected master' => ['selectedMasterId', 5],
]);

test('own-account follow requires different master and follower accounts', function () {
    $user = followTestUser();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('enterOwnAccountMode')
        ->set('masterAccountId', 'DOT100')
        ->set('followerAccountId', 'DOT100')
        ->call('follow')
        ->assertHasErrors('masterAccountId');

    expect(CopySetting::count())->toBe(0);
});

test('a master cannot follow another master', function () {
    $user = followTestUser('master');
    $otherMaster = DerivConnection::factory()->master()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('selectMaster', $otherMaster->id)
        ->set('followerAccountId', 'DOT100')
        ->call('follow')
        ->assertHasErrors('selectedMasterId');

    expect(CopySetting::count())->toBe(0);
});

test('a master cannot enter own-account mode', function () {
    $user = followTestUser('master');

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('enterOwnAccountMode')
        ->assertHasErrors('selectedMasterId')
        ->assertSet('ownAccountMode', false)
        ->assertSet('showForm', false);
});

test('switchMaster rejects a non-master connection', function () {
    $user = followTestUser();
    $master = DerivConnection::factory()->master()->create();
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $master->id, 'follower_account_id' => 'DOT100']);
    $other = DerivConnection::factory()->create(['type' => 'follower']);

    expect(fn () => Livewire::actingAs($user)->test(Dashboard::class)->call('switchMaster', $other->id))
        ->toThrow(ModelNotFoundException::class);

    expect($setting->fresh()->master_connection_id)->toBe($master->id);
});

test('switchMaster refuses when the user is a master', function () {
    $user = followTestUser('master');
    $master = DerivConnection::factory()->master()->create();
    $otherMaster = DerivConnection::factory()->master()->create();
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $master->id, 'follower_account_id' => 'DOT100']);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('switchMaster', $otherMaster->id)
        ->assertHasErrors('selectedMasterId');

    expect($setting->fresh()->master_connection_id)->toBe($master->id);
});

test('switchToOwnAccounts rejects the follower account as master account', function () {
    $user = followTestUser();
    $master = DerivConnection::factory()->master()->create();
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $master->id, 'follower_account_id' => 'DOT100']);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('masterAccountId', 'DOT100')
        ->call('switchToOwnAccounts')
        ->assertHasErrors('masterAccountId');

    expect($setting->fresh())
        ->master_connection_id->toBe($master->id)
        ->master_account_id->toBeNull();
});

test('switchToOwnAccounts refuses when the user is a master', function () {
    $user = followTestUser('master');
    $master = DerivConnection::factory()->master()->create();
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $master->id, 'follower_account_id' => 'DOT100']);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('masterAccountId', 'DOT200')
        ->call('switchToOwnAccounts')
        ->assertHasErrors('selectedMasterId');

    expect($setting->fresh()->master_connection_id)->toBe($master->id);
});

test('saveSettings still works for a master who already follows another master', function () {
    $user = followTestUser('master');
    $otherMaster = DerivConnection::factory()->master()->create();
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $otherMaster->id, 'follower_account_id' => 'DOT100']);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('stake', 2.0)
        ->call('saveSettings')
        ->assertHasNoErrors();

    expect((float) $setting->fresh()->stake)->toBe(2.0);
});

function accountsUnavailable(): void
{
    mock(DerivApiService::class)
        ->shouldReceive('getAccounts')
        ->andThrow(new DerivApiException('Deriv API unreachable'))
        ->getMock()
        ->shouldIgnoreMissing();
}

test('follow refuses to save when accounts cannot be loaded', function () {
    accountsUnavailable();
    $user = followTestUser();
    $master = DerivConnection::factory()->master()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('selectMaster', $master->id)
        ->call('follow')
        ->assertHasErrors('followerAccountId');

    expect(CopySetting::count())->toBe(0);
});

test('saveSettings refuses to save when accounts cannot be loaded', function () {
    accountsUnavailable();
    $user = followTestUser();
    $master = DerivConnection::factory()->master()->create();
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $master->id, 'follower_account_id' => 'DOT100', 'stake' => 1]);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('settingsOpen', true)
        ->set('stake', 3.0)
        ->call('saveSettings')
        ->assertHasErrors('followerAccountId')
        ->assertSee('Reconnect or try again');

    expect($setting->fresh())
        ->follower_account_id->toBe('DOT100')
        ->and((float) $setting->fresh()->stake)->toBe(1.0);
});

test('follow requires a follower account', function () {
    $user = followTestUser();
    $master = DerivConnection::factory()->master()->create();

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->call('selectMaster', $master->id)
        ->call('follow')
        ->assertHasErrors(['followerAccountId' => 'required']);

    expect(CopySetting::count())->toBe(0);
});

test('saveSettings requires a follower account', function () {
    $user = followTestUser();
    $master = DerivConnection::factory()->master()->create();
    $setting = CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $master->id, 'follower_account_id' => 'DOT100']);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('followerAccountId', null)
        ->call('saveSettings')
        ->assertHasErrors(['followerAccountId' => 'required']);

    expect($setting->fresh()->follower_account_id)->toBe('DOT100');
});

function namedMaster(): DerivConnection
{
    return DerivConnection::factory()->master()
        ->for(User::factory()->state(['name' => 'Master Mia', 'email' => 'mia.master@example.test']))
        ->create();
}

test('dashboard does not show the masters email', function () {
    $user = followTestUser();
    $master = namedMaster();
    CopySetting::factory()->create(['user_id' => $user->id, 'master_connection_id' => $master->id, 'follower_account_id' => 'DOT100']);

    Livewire::actingAs($user)
        ->test(Dashboard::class)
        ->set('settingsOpen', true)
        ->assertSee('Master Mia')
        ->assertDontSee('mia.master@example.test')
        ->set('showMasterList', true)
        ->assertDontSee('mia.master@example.test');
});
