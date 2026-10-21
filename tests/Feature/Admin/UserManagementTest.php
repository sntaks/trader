<?php

use App\Livewire\Admin\UserManagement;
use App\Models\DerivConnection;
use App\Models\User;
use Livewire\Livewire;

test('non-admin cannot access user management page', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)
        ->get(route('admin.users'))
        ->assertRedirect(route('dashboard'));
});

test('admin can access user management page', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    $this->actingAs($admin)
        ->get(route('admin.users'))
        ->assertSuccessful();
});

test('user management renders all users', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    User::factory()->count(3)->create();

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->assertSee($admin->name);
});

test('search filters users by name', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create(['name' => 'Unique Person']);
    $other = User::factory()->create(['name' => 'Someone Else']);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->set('search', 'Unique Person')
        ->assertSee('Unique Person')
        ->assertDontSee('Someone Else');
});

test('search filters users by email', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $target = User::factory()->create(['email' => 'find@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->set('search', 'find@example.com')
        ->assertSee('find@example.com')
        ->assertDontSee('other@example.com');
});

test('admin can grant admin role to another user', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $user = User::factory()->create(['is_admin' => false]);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('toggleAdmin', $user->id);

    expect($user->fresh()->is_admin)->toBeTrue();
});

test('admin can revoke admin role from another user', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('toggleAdmin', $other->id);

    expect($other->fresh()->is_admin)->toBeFalse();
});

test('admin cannot revoke their own admin role', function () {
    $admin = User::factory()->create(['is_admin' => true]);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->call('toggleAdmin', $admin->id);

    expect($admin->fresh()->is_admin)->toBeTrue();
});

test('deriv connected badge shows for users with connection', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $connected = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $connected->id]);

    Livewire::actingAs($admin)
        ->test(UserManagement::class)
        ->assertSee('Connected');
});

test('non-admin cannot mount user management', function () {
    Livewire::actingAs(User::factory()->create(['is_admin' => false]))
        ->test(UserManagement::class)
        ->assertForbidden();
});

test('a demoted admin cannot act on an open user management page', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create(['is_admin' => false]);

    $component = Livewire::actingAs($admin)->test(UserManagement::class);

    $admin->forceFill(['is_admin' => false])->save();

    $component->call('toggleAdmin', $other->id)->assertForbidden();

    expect($other->fresh()->is_admin)->toBeFalse();
});
