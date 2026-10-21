<?php

use App\Models\User;

test('gravatar returns correct url for email', function () {
    $user = new User(['email' => 'test@example.com', 'name' => 'Test User']);

    $url = $user->gravatar();

    $hash = md5('test@example.com');
    expect($url)->toBe("https://www.gravatar.com/avatar/{$hash}?s=80&d=robohash");
});

test('gravatar normalises email before hashing', function () {
    $userLower = new User(['email' => 'test@example.com', 'name' => 'Test']);
    $userUpper = new User(['email' => '  TEST@EXAMPLE.COM  ', 'name' => 'Test']);

    expect($userLower->gravatar())->toBe($userUpper->gravatar());
});

test('gravatar accepts custom size', function () {
    $user = new User(['email' => 'test@example.com', 'name' => 'Test']);

    expect($user->gravatar(40))->toContain('s=40');
});
