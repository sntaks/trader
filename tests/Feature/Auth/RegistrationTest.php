<?php

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
