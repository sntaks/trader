<?php

use App\Models\User;
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
