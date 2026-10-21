<?php

use App\Http\Controllers\Auth\GoogleAuthController;
use App\Models\User;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

/** Make the Google provider used by the link flow return $googleUser, or run $user() to throw. */
function mockGoogleLink(SocialiteUser|Closure $googleUser): void
{
    $provider = Mockery::mock(AbstractProvider::class);
    $provider->allows('redirectUrl')->andReturnSelf();
    $provider->allows('user')->andReturnUsing(fn () => $googleUser instanceof Closure ? $googleUser() : $googleUser);

    $socialite = Mockery::mock(SocialiteFactory::class);
    $socialite->allows('driver')->with('google')->andReturn($provider);

    app()->instance(SocialiteFactory::class, $socialite);
}

function googleLinkUser(string $email, bool $verified = true): SocialiteUser
{
    return (new SocialiteUser)
        ->setRaw(['email_verified' => $verified])
        ->map(['id' => 'g-123', 'name' => 'Jane', 'email' => $email, 'avatar' => 'https://example.com/a.jpg']);
}

test('linking a google account with a different email does not verify the user\'s email', function () {
    $user = User::factory()->unverified()->create(['email' => 'jane@example.com']);
    mockGoogleLink(googleLinkUser('someone-else@gmail.com'));

    $this->actingAs($user)->get(route('auth.google.link.callback'))->assertRedirect(route('profile.edit'));

    expect($user->fresh()->google_id)->toBe('g-123')
        ->and($user->fresh()->email_verified_at)->toBeNull();
});

test('linking with the same verified email verifies it', function () {
    $user = User::factory()->unverified()->create(['email' => 'jane@example.com']);
    mockGoogleLink(googleLinkUser('Jane@Example.com'));

    $this->actingAs($user)->get(route('auth.google.link.callback'));

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

test('linking with the same but unverified google email does not verify it', function () {
    $user = User::factory()->unverified()->create(['email' => 'jane@example.com']);
    mockGoogleLink(googleLinkUser('jane@example.com', verified: false));

    $this->actingAs($user)->get(route('auth.google.link.callback'));

    expect($user->fresh()->email_verified_at)->toBeNull();
});

test('a failed google link returns to the profile page with a message', function () {
    $user = User::factory()->create();
    mockGoogleLink(fn () => throw new InvalidStateException);

    $this->actingAs($user)
        ->get(route('auth.google.link.callback'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['google' => GoogleAuthController::FAILED_MESSAGE]);
});

test('a cancelled google link returns to the profile page with a message', function () {
    $user = User::factory()->create();
    mockGoogleLink(fn () => throw new RuntimeException('user() must not be called after a cancel'));

    $this->actingAs($user)
        ->get(route('auth.google.link.callback', ['error' => 'access_denied']))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors(['google' => GoogleAuthController::CANCELLED_MESSAGE]);
});
