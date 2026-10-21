<?php

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyFeature(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
    $response = $this->post(route('register.store'), [
        'name' => 'John Doe',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
});
test('names with apostrophes can register', function () {
    $this->post(route('register.store'), [
        'name' => "Seán O'Brien-Smith",
        'email' => 'sean@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasNoErrors();

    $this->assertDatabaseHas('users', ['email' => 'sean@example.com', 'name' => "Seán O'Brien-Smith"]);
});

test('names with markup are still rejected', function () {
    $this->post(route('register.store'), [
        'name' => '<b>Bob</b>',
        'email' => 'bob@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertSessionHasErrors('name');
});

test('a filled honeypot redirects home without creating or logging in a user', function () {
    Mail::fake();

    $this->post(route('register.store'), [
        'name' => 'Spam Bot',
        'email' => 'bot@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'website' => 'spam.example',
    ])->assertRedirect(route('home'));

    $this->assertGuest();
    expect(User::count())->toBe(0);
    Mail::assertNothingQueued();
    Mail::assertNothingSent();
});
