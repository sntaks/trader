<?php

use App\Exceptions\DerivApiException;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::fake([
        'api.derivws.com/trading/v1/options/accounts' => Http::response(['data' => [
            ['account_id' => 'DOT100', 'currency' => 'USD', 'account_type' => 'demo'],
            ['account_id' => 'DOT200', 'currency' => 'eur', 'account_type' => 'real'],
        ]]),
    ]);
});

test('accountCurrency returns the matching account currency', function () {
    $connection = DerivConnection::factory()->create();

    expect(app(DerivApiService::class)->accountCurrency($connection, 'DOT200'))->toBe('EUR');
});

test('accountCurrency uses the first account when none is selected', function () {
    $connection = DerivConnection::factory()->create();

    expect(app(DerivApiService::class)->accountCurrency($connection, null))->toBe('USD');
});

test('accountCurrency throws for an unknown account', function () {
    $connection = DerivConnection::factory()->create();

    app(DerivApiService::class)->accountCurrency($connection, 'DOT999');
})->throws(DerivApiException::class);

test('proposal request carries currency and both barriers', function () {
    $proposal = DerivApiService::proposalRequest([
        'contract_type' => 'EXPIRYRANGE',
        'symbol' => 'R_50',
        'duration' => 2,
        'duration_unit' => 'm',
        'stake' => 1.5,
        'currency' => 'EUR',
        'barrier' => '+1.2',
        'barrier2' => '-1.2',
    ]);

    expect($proposal)->toMatchArray([
        'proposal' => 1,
        'amount' => 1.5,
        'basis' => 'stake',
        'contract_type' => 'EXPIRYRANGE',
        'currency' => 'EUR',
        'duration' => 2,
        'duration_unit' => 'm',
        'underlying_symbol' => 'R_50',
        'barrier' => '+1.2',
        'barrier2' => '-1.2',
    ]);
});

test('proposal request omits barriers that are not set', function () {
    $proposal = DerivApiService::proposalRequest([
        'contract_type' => 'CALL',
        'symbol' => 'R_50',
        'duration' => 5,
        'duration_unit' => 't',
        'stake' => 1,
        'currency' => 'USD',
        'barrier' => null,
        'barrier2' => null,
    ]);

    expect($proposal)->not->toHaveKey('barrier')
        ->and($proposal)->not->toHaveKey('barrier2');
});
