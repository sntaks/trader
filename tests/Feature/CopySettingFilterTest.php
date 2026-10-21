<?php

use App\Models\CopySetting;

function filteredSetting(array $filters): CopySetting
{
    return new CopySetting(array_merge([
        'filter_markets' => [],
        'synthetic_indices' => [],
        'forex_pairs' => [],
    ], $filters));
}

test('allows everything when no filters are set', function () {
    expect(filteredSetting([])->allowsTrade('R_50', 'DIGITMATCH'))->toBeTrue()
        ->and((new CopySetting)->allowsTrade('frxEURUSD', 'CALL'))->toBeTrue();
});

test('matches saved forex display names against deriv symbols', function () {
    $setting = filteredSetting(['forex_pairs' => ['EUR/USD']]);

    expect($setting->allowsTrade('frxEURUSD', 'CALL'))->toBeTrue()
        ->and($setting->allowsTrade('R_50', 'CALL'))->toBeFalse();
});

test('combines market and forex selections into one symbol list', function () {
    $setting = filteredSetting(['filter_markets' => ['R_10'], 'forex_pairs' => ['GBP/USD']]);

    expect($setting->allowsTrade('R_10', 'CALL'))->toBeTrue()
        ->and($setting->allowsTrade('frxGBPUSD', 'CALL'))->toBeTrue()
        ->and($setting->allowsTrade('R_50', 'CALL'))->toBeFalse();
});

test('filters by trade type independently of symbol', function () {
    $setting = filteredSetting(['synthetic_indices' => ['DM']]);

    expect($setting->allowsTrade('R_50', 'DIGITMATCH'))->toBeTrue()
        ->and($setting->allowsTrade('R_50', 'DIGITDIFF'))->toBeFalse();
});

test('trade type codes cover both sides of a pair', function () {
    $setting = filteredSetting(['synthetic_indices' => ['OE']]);

    expect($setting->allowsTrade('R_50', 'DIGITODD'))->toBeTrue()
        ->and($setting->allowsTrade('R_50', 'DIGITEVEN'))->toBeTrue()
        ->and($setting->allowsTrade('R_50', 'DIGITOVER'))->toBeFalse();
});

test('requires both symbol and type to match', function () {
    $setting = filteredSetting(['filter_markets' => ['R_10'], 'synthetic_indices' => ['DM']]);

    expect($setting->allowsTrade('R_50', 'DIGITMATCH'))->toBeFalse()
        ->and($setting->allowsTrade('R_10', 'DIGITDIFF'))->toBeFalse()
        ->and($setting->allowsTrade('R_10', 'DIGITMATCH'))->toBeTrue();
});

test('blocks every trade when only dropped trade type codes are saved', function () {
    $setting = filteredSetting(['synthetic_indices' => ['HILO', 'RCP']]);

    expect($setting->allowsTrade('R_50', 'DIGITMATCH'))->toBeFalse()
        ->and($setting->allowsTrade('R_50', 'CALL'))->toBeFalse();
});

test('ignores dropped codes saved alongside known ones', function () {
    $setting = filteredSetting(['synthetic_indices' => ['HILO', 'DM']]);

    expect($setting->allowsTrade('R_50', 'DIGITMATCH'))->toBeTrue()
        ->and($setting->allowsTrade('R_50', 'DIGITDIFF'))->toBeFalse();
});
