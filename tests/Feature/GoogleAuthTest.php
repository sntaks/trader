<?php

use App\Models\User;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Two\User as SocialiteUser;

function fakeSocialiteUser(array $attributes = []): SocialiteUser
{
    $socialiteUser = Mockery::mock(SocialiteUser::class);
    $socialiteUser->allows('getId')->andReturn($attributes['id'] ?? '109876543210');
    $socialiteUser->allows('getName')->andReturn($attributes['name'] ?? 'Google User');
    $socialiteUser->allows('getEmail')->andReturn($attributes['email'] ?? 'google@example.com');
    $socialiteUser->allows('getAvatar')->andReturn($attributes['avatar'] ?? 'https://example.com/avatar.jpg');

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
