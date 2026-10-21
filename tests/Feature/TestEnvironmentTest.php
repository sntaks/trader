<?php

test('the test environment never carries real deriv revenue tokens', function (string $key) {
    expect(config($key))->toBeEmpty();
})->with(['deriv.partner_token', 'deriv.affiliate_token', 'deriv.affiliate_id']);

test('no front-end file starts an echo client nothing uses', function () {
    expect(file_exists(resource_path('js/echo.js')))->toBeFalse();
});

test('composer dev does not start reverb, which nothing uses', function () {
    $devScript = implode(' ', json_decode(file_get_contents(base_path('composer.json')), true)['scripts']['dev']);

    expect($devScript)->not->toContain('reverb');
});
