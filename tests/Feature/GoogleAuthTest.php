<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeSocialiteUser(array $attributes = []): SocialiteUser
{
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->allows('getId')->andReturn($attributes['id'] ?? '109876543210');
    $socialiteUser->allows('getName')->andReturn($attributes['name'] ?? 'Google User');
    $socialiteUser->allows('getEmail')->andReturn($attributes['email'] ?? 'google@example.com');
    $socialiteUser->allows('getAvatar')->andReturn($attributes['avatar'] ?? 'https://example.com/avatar.jpg');
    $socialiteUser->allows('getRaw')->andReturn(['email_verified' => $attributes['email_verified'] ?? true]);

    return $socialiteUser;
}

function mockSocialite(SocialiteUser $socialiteUser): void
{
    $provider = Mockery::mock(Provider::class);
    $provider->allows('user')->andReturn($socialiteUser);

    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->allows('driver')->with('google')->andReturn($provider);

    app()->instance(SocialiteFactory::class, $socialite);
}

test('callback creates new user and logs them in', function () {
    $socialiteUser = fakeSocialiteUser();
    mockSocialite($socialiteUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirectToRoute('dashboard');

    $user = User::where('google_id', '109876543210')->first();
    expect($user)->not->toBeNull()
        ->and($user->email)->toBe('google@example.com')
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
});

test('callback logs in existing user by google_id', function () {
    $existing = User::factory()->create([
        'google_id' => '109876543210',
        'email' => 'google@example.com',
    ]);

    $socialiteUser = fakeSocialiteUser();
    mockSocialite($socialiteUser);

    $response = $this->get(route('auth.google.callback'));

    $response->assertRedirectToRoute('dashboard');
    $this->assertAuthenticatedAs($existing);
    expect(User::count())->toBe(1);
});

test('callback links google_id to existing user matched by email', function () {
    $existing = User::factory()->create([
        'email' => 'google@example.com',
        'google_id' => null,
    ]);

    $socialiteUser = fakeSocialiteUser();
    mockSocialite($socialiteUser);

    $this->get(route('auth.google.callback'));

    $existing->refresh();
    expect($existing->google_id)->toBe('109876543210');
    $this->assertAuthenticatedAs($existing);
    expect(User::count())->toBe(1);
});

test('google names are stored raw and capped', function (string $googleName, string $expected) {
    mockSocialite(fakeSocialiteUser(['name' => $googleName, 'email' => 'jane.doe@example.com']));

    $this->get(route('auth.google.callback'));

    expect(User::query()->where('email', 'jane.doe@example.com')->value('name'))->toBe($expected);
})->with([
    'apostrophe' => ["O'Brien", "O'Brien"],
    'markup' => ['<b>Bo</b>', '<b>Bo</b>'],
    'too long' => [str_repeat('a', 150), str_repeat('a', 100)],
    'blank' => ['   ', 'jane.doe'],
]);

test('an unverified google email cannot create an account', function () {
    mockSocialite(fakeSocialiteUser(['email_verified' => false]));

    $this->get(route('auth.google.callback'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleAuthController::UNVERIFIED_MESSAGE]);

    expect(User::count())->toBe(0);
    $this->assertGuest();
});

test('an unverified google email cannot link to an existing account', function () {
    $existing = User::factory()->create(['email' => 'google@example.com', 'google_id' => null]);
    mockSocialite(fakeSocialiteUser(['email_verified' => false]));

    $this->get(route('auth.google.callback'))->assertSessionHasErrors('google');

    expect($existing->fresh()->google_id)->toBeNull();
    $this->assertGuest();
});

test('an unverified google email still signs in an already linked account', function () {
    $existing = User::factory()->create(['email' => 'google@example.com', 'google_id' => '109876543210']);
    mockSocialite(fakeSocialiteUser(['email_verified' => false]));

    $this->get(route('auth.google.callback'))->assertRedirectToRoute('dashboard');

    $this->assertAuthenticatedAs($existing);
});

test('linking to an unverified password account wipes its password, two-factor and sessions', function () {
    $existing = User::factory()->unverified()->create([
        'email' => 'google@example.com',
        'google_id' => null,
        'password' => 'attacker-password',
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt('[]'),
        'two_factor_confirmed_at' => now(),
    ]);
    $rememberToken = $existing->remember_token;
    DB::table('sessions')->insert(['id' => 'attacker-session', 'user_id' => $existing->id, 'payload' => '', 'last_activity' => time()]);
    mockSocialite(fakeSocialiteUser());

    $this->get(route('auth.google.callback'))->assertRedirectToRoute('dashboard');

    $existing->refresh();

    expect($existing->password)->not->toBeNull()
        ->and(Hash::check('attacker-password', $existing->password))->toBeFalse()
        ->and($existing->two_factor_secret)->toBeNull()
        ->and($existing->two_factor_recovery_codes)->toBeNull()
        ->and($existing->two_factor_confirmed_at)->toBeNull()
        ->and($existing->remember_token)->not->toBe($rememberToken)
        ->and($existing->email_verified_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('id', 'attacker-session')->exists())->toBeFalse();
    $this->assertAuthenticatedAs($existing);
});

test('linking to a verified account leaves its password and two-factor alone', function () {
    $existing = User::factory()->create([
        'email' => 'google@example.com',
        'google_id' => null,
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);
    $password = $existing->password;
    mockSocialite(fakeSocialiteUser());

    $this->get(route('auth.google.callback'));

    $existing->refresh();

    expect($existing->password)->toBe($password)
        ->and($existing->two_factor_secret)->not->toBeNull()
        ->and($existing->google_id)->toBe('109876543210');
});

/** Make the Google provider throw $exception when the callback reads the user. */
function mockSocialiteFailure(Throwable $exception): void
{
    $provider = Mockery::mock(Provider::class);
    $provider->allows('user')->andThrow($exception);

    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->allows('driver')->with('google')->andReturn($provider);

    app()->instance(SocialiteFactory::class, $socialite);
}

test('cancelled google sign-in returns to login with a message', function () {
    mockSocialiteFailure(new RuntimeException('user() must not be called after a cancel'));

    $this->get(route('auth.google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleAuthController::CANCELLED_MESSAGE]);

    $this->assertGuest();
});

test('a failed google sign-in returns to login with a message', function (Throwable $exception) {
    mockSocialiteFailure($exception);

    $this->get(route('auth.google.callback', ['code' => 'c', 'state' => 's']))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => GoogleAuthController::FAILED_MESSAGE]);

    $this->assertGuest();
})->with([
    'stale state' => fn () => new InvalidStateException,
    'token error' => fn () => new ClientException('bad', new GuzzleRequest('POST', 'https://oauth2.googleapis.com/token'), new GuzzleResponse(400)),
]);

test('a google user with two-factor goes to the challenge', function () {
    $user = User::factory()->withTwoFactor()->create(['google_id' => '109876543210', 'email' => 'google@example.com']);
    mockSocialite(fakeSocialiteUser());

    $this->get(route('auth.google.callback'))->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id)
        ->and(session('login.remember'))->toBeTrue();
});

test('a verified-email match with two-factor goes to the challenge', function () {
    $user = User::factory()->withTwoFactor()->create(['google_id' => null, 'email' => 'google@example.com']);
    mockSocialite(fakeSocialiteUser());

    $this->get(route('auth.google.callback'))->assertRedirect(route('two-factor.login'));

    $this->assertGuest();
    expect(session('login.id'))->toBe($user->id);
});

test('the challenge page accepts a google user sent to it', function () {
    User::factory()->withTwoFactor()->create(['google_id' => '109876543210', 'email' => 'google@example.com']);
    mockSocialite(fakeSocialiteUser());

    $this->get(route('auth.google.callback'));

    $this->get(route('two-factor.login'))->assertOk();
});

test('a cancelled google sign-in by a signed-in user returns to the dashboard', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('auth.google.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error', GoogleAuthController::CANCELLED_MESSAGE);
});

test('a long google name is capped at 100 characters, not display width', function () {
    mockSocialite(fakeSocialiteUser(['name' => str_repeat('名', 150)]));

    $this->get(route('auth.google.callback'));

    expect(mb_strlen(User::where('google_id', '109876543210')->value('name')))->toBe(100);
});

test('a google user who passes the challenge is signed in', function () {
    $user = User::factory()->withTwoFactor()->create(['google_id' => '109876543210', 'email' => 'google@example.com']);
    mockSocialite(fakeSocialiteUser());

    $this->get(route('auth.google.callback'));
    $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

test('a takeover disconnects the pre-registrant\'s deriv account and copy setting', function () {
    $existing = User::factory()->unverified()->create(['email' => 'google@example.com', 'google_id' => null]);
    $connection = DerivConnection::factory()->create(['user_id' => $existing->id]);
    CopySetting::factory()->create(['user_id' => $existing->id, 'master_connection_id' => $connection->id]);
    CopyTrade::factory()->create(['user_id' => $existing->id, 'master_connection_id' => $connection->id]);
    mockSocialite(fakeSocialiteUser());

    $this->get(route('auth.google.callback'));

    expect(DerivConnection::query()->where('user_id', $existing->id)->exists())->toBeFalse()
        ->and(CopySetting::query()->where('user_id', $existing->id)->exists())->toBeFalse()
        ->and(CopyTrade::query()->where('user_id', $existing->id)->count())->toBe(1);
});

test('a takeover stops followers of the pre-registrant\'s master', function () {
    $existing = User::factory()->unverified()->create(['email' => 'google@example.com', 'google_id' => null]);
    $master = DerivConnection::factory()->master()->create(['user_id' => $existing->id]);
    $follower = CopySetting::factory()->create(['master_connection_id' => $master->id, 'is_running' => true]);
    mockSocialite(fakeSocialiteUser());

    $this->get(route('auth.google.callback'));

    expect($follower->fresh()->only('is_running', 'stop_reason'))
        ->toBe(['is_running' => false, 'stop_reason' => CopySetting::STOP_REASON_MASTER_DISCONNECTED]);
});

test('a takeover logs out the pre-registrant\'s other session under any driver', function () {
    config(['session.driver' => 'array']);
    $attacker = User::factory()->unverified()->create(['password' => 'attacker-password']);

    $this->actingAs($attacker)->get(route('profile.edit'))->assertOk();

    $attacker->secureAfterTakeover();

    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});
