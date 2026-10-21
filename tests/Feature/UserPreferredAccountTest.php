<?php

use App\Models\CopySetting;
use App\Models\User;

$accounts = [
    ['account_id' => 'DOT100', 'status' => 'active'],
    ['account_id' => 'DOT200', 'status' => 'active'],
];

test('preferred account is the follower account when listed, else the first', function () use ($accounts) {
    $follower = User::factory()->create();
    CopySetting::factory()->create(['user_id' => $follower->id, 'follower_account_id' => 'DOT200']);

    $unlisted = User::factory()->create();
    CopySetting::factory()->create(['user_id' => $unlisted->id, 'follower_account_id' => 'DOT999']);

    expect($follower->preferredDerivAccountId($accounts))->toBe('DOT200')
        ->and($unlisted->preferredDerivAccountId($accounts))->toBe('DOT100')
        ->and(User::factory()->create()->preferredDerivAccountId($accounts))->toBe('DOT100')
        ->and(User::factory()->create()->preferredDerivAccountId([]))->toBeNull();
});

test('preferred account skips inactive accounts', function () {
    $user = User::factory()->create();
    CopySetting::factory()->create(['user_id' => $user->id, 'follower_account_id' => 'DOT100']);

    expect($user->preferredDerivAccountId([
        ['account_id' => 'DOT100', 'status' => 'inactive'],
        ['account_id' => 'DOT200', 'status' => 'active'],
    ]))->toBe('DOT200');
});
