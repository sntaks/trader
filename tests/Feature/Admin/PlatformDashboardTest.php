<?php

use App\Livewire\Admin\PlatformDashboard;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use Livewire\Livewire;

test('non-admin cannot access admin dashboard', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.dashboard'))
        ->assertRedirect(route('dashboard'));
});

test('admin can access admin dashboard page', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.dashboard'))
        ->assertSuccessful();
});

test('platform dashboard shows total user count', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->count(4)->create();

    Livewire::actingAs($admin)
        ->test(PlatformDashboard::class)
        ->assertSee('5'); // 4 + admin = 5
});

test('platform dashboard user stats include connection rate', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $connected = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $connected->id]);

    $component = Livewire::actingAs($admin)->test(PlatformDashboard::class);

    expect($component->instance()->userStats['connected'])->toBe(1)
        ->and($component->instance()->userStats['total'])->toBe(2);
});

test('platform dashboard shows master trader count', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $masterUser = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $masterUser->id, 'type' => 'master']);

    $component = Livewire::actingAs($admin)->test(PlatformDashboard::class);

    expect($component->instance()->derivStats['masters'])->toBe(1);
});

test('platform dashboard shows active copy settings', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $masterUser = User::factory()->create();
    $masterConn = DerivConnection::factory()->create(['user_id' => $masterUser->id, 'type' => 'master']);
    $followerUser = User::factory()->create();
    CopySetting::factory()->create([
        'user_id' => $followerUser->id,
        'master_connection_id' => $masterConn->id,
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($admin)->test(PlatformDashboard::class);

    expect($component->instance()->copyTradingStats['active'])->toBe(1);
});

test('platform dashboard shows recent signups', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $newUser = User::factory()->create(['name' => 'Newest Member']);

    Livewire::actingAs($admin)
        ->test(PlatformDashboard::class)
        ->assertSee('Newest Member');
});

test('platform dashboard top masters list is empty when no masters', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $component = Livewire::actingAs($admin)->test(PlatformDashboard::class);

    expect($component->instance()->copyTradingStats['top_masters']->isEmpty())->toBeTrue();
});

test('signup trend returns 14 data points', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $component = Livewire::actingAs($admin)->test(PlatformDashboard::class);

    expect($component->instance()->signupTrend)->toHaveCount(14);
});

test('non-admin cannot mount platform dashboard', function () {
    Livewire::actingAs(User::factory()->create(['is_admin' => false]))
        ->test(PlatformDashboard::class)
        ->assertForbidden();
});
