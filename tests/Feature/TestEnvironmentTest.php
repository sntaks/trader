<?php

use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;

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

test('tests log nowhere', function () {
    $handlers = Log::driver()->getLogger()->getHandlers();

    expect($handlers)->not->toBeEmpty()->each->toBeInstanceOf(NullHandler::class);
});

test('composer dev migrates first and runs the scheduler', function () {
    $devScript = json_decode(file_get_contents(base_path('composer.json')), true)['scripts']['dev'];

    $migrateIndex = array_search('@php artisan migrate --no-interaction', $devScript, true);
    $concurrentlyIndex = array_key_first(array_filter($devScript, fn (string $entry) => str_starts_with($entry, 'npx concurrently')));

    expect($migrateIndex)->toBeInt()
        ->and($concurrentlyIndex)->toBeInt()
        ->and($migrateIndex)->toBeLessThan($concurrentlyIndex)
        ->and($devScript[$concurrentlyIndex])->toContain('php artisan schedule:work');
});
