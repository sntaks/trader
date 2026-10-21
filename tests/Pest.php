<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A copy-trade payload exactly as the pre-Phase-1 listener produced it: the raw
 * transaction merged with defaulted contract fields (see MasterListenerJob at af147d8).
 *
 * @return array<string, mixed>
 */
function legacyListenerTrade(): array
{
    $transaction = [
        'action' => 'buy',
        'amount' => -2.5,
        'balance' => 997.5,
        'contract_id' => 222222,
        'symbol' => 'R_50',
        'transaction_id' => 111111,
    ];

    return array_merge($transaction, [
        'duration' => 1,
        'duration_unit' => 't',
        'contract_type' => 'CALL',
        'symbol' => 'R_50',
        'barrier' => '1234.56',
    ]);
}
