<?php

use App\Models\User;

test('every page forces the dark theme', function () {
    $this->get(route('login'))->assertSee("window.Flux.applyAppearance('dark')", false);

    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee("window.Flux.applyAppearance('dark')", false);
});

test('the appearance settings page is gone', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/settings/appearance')->assertNotFound();
    $this->get(route('profile.edit'))->assertDontSee('/settings/appearance');
});
