<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

test('password can be updated', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.password')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('correct password must be provided to update password', function () {
    $user = User::factory()->create([
        'password' => Hash::make('password'),
    ]);

    $this->actingAs($user);

    $response = Livewire::test('pages::settings.password')
        ->set('current_password', 'wrong-password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword');

    $response->assertHasErrors(['current_password']);
});
test('changing your password keeps you signed in here', function () {
    Route::middleware(['web', 'auth'])->post('/_test/change-password', function () {
        auth()->user()->update(['password' => Hash::make('new-password')]);

        return response()->noContent();
    });
    $user = User::factory()->create(['password' => Hash::make('password')]);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk();
    $this->post('/_test/change-password')->assertNoContent();

    $this->get(route('profile.edit'))->assertOk();
});

test('changing your password keeps this device remembered', function () {
    $user = User::factory()->create(['password' => Hash::make('password')]);
    $guard = Auth::guard();
    $recaller = $user->id.'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->password);

    Livewire::withCookies([$guard->getRecallerName() => $recaller])
        ->actingAs($user)
        ->test('pages::settings.password')
        ->set('current_password', 'password')
        ->set('password', 'new-password')
        ->set('password_confirmation', 'new-password')
        ->call('updatePassword')
        ->assertHasNoErrors();

    $queued = Cookie::queued($guard->getRecallerName());

    expect($queued?->getValue())->toBeString()
        ->and(explode('|', (string) $queued?->getValue())[2] ?? null)->toBe($guard->hashPasswordForCookie($user->fresh()->password));
});
