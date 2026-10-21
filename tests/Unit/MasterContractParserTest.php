<?php

use App\Exceptions\UncopyableTradeException;
use App\Services\MasterContractParser;

$buy = ['action' => 'buy', 'contract_id' => 123, 'transaction_id' => 456, 'amount' => -2.5];

test('parses a tick digit contract', function () use ($buy) {
    $trade = MasterContractParser::parse($buy, [
        'contract_type' => 'DIGITOVER',
        'underlying' => 'R_50',
        'tick_count' => 5,
        'barrier' => '3',
    ]);

    expect($trade)->toBe([
        'contract_id' => '123',
        'transaction_id' => '456',
        'symbol' => 'R_50',
        'contract_type' => 'DIGITOVER',
        'duration' => 5,
        'duration_unit' => 't',
        'barrier' => '3',
        'barrier2' => null,
        'buy_price' => 2.5,
    ]);
});

test('prefers underlying_symbol over legacy names', function () use ($buy) {
    $trade = MasterContractParser::parse(
        [...$buy, 'symbol' => 'R_50'],
        ['contract_type' => 'DIGITEVEN', 'underlying_symbol' => 'R_100', 'tick_count' => 1],
    );

    expect($trade['symbol'])->toBe('R_100');
});

test('falls back to the transaction symbol', function () use ($buy) {
    $trade = MasterContractParser::parse(
        [...$buy, 'symbol' => 'R_75'],
        ['contract_type' => 'DIGITODD', 'tick_count' => 1],
    );

    expect($trade['symbol'])->toBe('R_75');
});

test('derives minutes from expiry window', function () use ($buy) {
    $trade = MasterContractParser::parse($buy, [
        'contract_type' => 'CALL',
        'underlying' => 'R_50',
        'date_start' => 1700000000,
        'date_expiry' => 1700000300,
    ]);

    expect($trade['duration'])->toBe(5)
        ->and($trade['duration_unit'])->toBe('m');
});

test('derives the largest whole unit from expiry window', function (int $seconds, int $duration, string $unit) use ($buy) {
    $trade = MasterContractParser::parse($buy, [
        'contract_type' => 'PUT',
        'underlying' => 'R_50',
        'date_start' => 1700000000,
        'date_expiry' => 1700000000 + $seconds,
    ]);

    expect([$trade['duration'], $trade['duration_unit']])->toBe([$duration, $unit]);
})->with([
    'seconds' => [45, 45, 's'],
    'hours' => [7200, 2, 'h'],
    'days' => [172800, 2, 'd'],
]);

test('uses explicit duration fields when present', function () use ($buy) {
    $trade = MasterContractParser::parse($buy, [
        'contract_type' => 'CALL',
        'underlying' => 'R_50',
        'duration' => 3,
        'duration_unit' => 's',
        'tick_count' => 9,
    ]);

    expect($trade['duration'])->toBe(3)
        ->and($trade['duration_unit'])->toBe('s');
});

test('drops the entry-spot barrier on rise fall', function () use ($buy) {
    $trade = MasterContractParser::parse($buy, [
        'contract_type' => 'CALL',
        'underlying' => 'R_50',
        'tick_count' => 5,
        'barrier' => '1234.56',
        'shortcode' => 'CALL_R_50_19.54_1700000000_5T_S0P_0',
    ]);

    expect($trade['barrier'])->toBeNull()
        ->and($trade['barrier2'])->toBeNull();
});

test('rejects higher lower', function () use ($buy) {
    MasterContractParser::parse($buy, [
        'contract_type' => 'CALL',
        'underlying' => 'R_50',
        'tick_count' => 5,
        'barrier' => '1234.56',
        'shortcode' => 'CALL_R_50_19.54_1700000000_5T_S10P_0',
    ]);
})->throws(UncopyableTradeException::class);

test('maps range barriers', function () use ($buy) {
    $trade = MasterContractParser::parse($buy, [
        'contract_type' => 'EXPIRYRANGE',
        'underlying' => 'R_50',
        'date_start' => 1700000000,
        'date_expiry' => 1700000120,
        'high_barrier' => '+1.2',
        'low_barrier' => '-1.2',
    ]);

    expect($trade['barrier'])->toBe('+1.2')
        ->and($trade['barrier2'])->toBe('-1.2');
});

test('keeps the barrier for touch contracts', function () use ($buy) {
    $trade = MasterContractParser::parse($buy, [
        'contract_type' => 'ONETOUCH',
        'underlying' => 'R_50',
        'tick_count' => 5,
        'barrier' => '+0.5',
    ]);

    expect($trade['barrier'])->toBe('+0.5');
});

test('takes stake from transaction amount when contract has no buy price', function () use ($buy) {
    $trade = MasterContractParser::parse($buy, ['contract_type' => 'DIGITEVEN', 'underlying' => 'R_50', 'tick_count' => 1]);

    expect($trade['buy_price'])->toBe(2.5);
});

test('prefers the contract buy price', function () use ($buy) {
    $trade = MasterContractParser::parse($buy, ['contract_type' => 'DIGITEVEN', 'underlying' => 'R_50', 'tick_count' => 1, 'buy_price' => '3.10']);

    expect($trade['buy_price'])->toBe(3.1);
});

test('rejects uncopyable input', function (array $transaction, array $contract) {
    MasterContractParser::parse($transaction, $contract);
})->throws(UncopyableTradeException::class)->with([
    'missing symbol' => [['contract_id' => 1], ['contract_type' => 'DIGITEVEN', 'tick_count' => 1]],
    'multiplier' => [['contract_id' => 1], ['contract_type' => 'MULTUP', 'underlying' => 'R_50', 'tick_count' => 1]],
    'accumulator' => [['contract_id' => 1], ['contract_type' => 'ACCU', 'underlying' => 'R_50', 'tick_count' => 1]],
    'missing contract type' => [['contract_id' => 1], ['underlying' => 'R_50', 'tick_count' => 1]],
    'digit match without barrier' => [['contract_id' => 1], ['contract_type' => 'DIGITMATCH', 'underlying' => 'R_50', 'tick_count' => 1]],
    'touch without barrier' => [['contract_id' => 1], ['contract_type' => 'NOTOUCH', 'underlying' => 'R_50', 'tick_count' => 1]],
    'range without low barrier' => [['contract_id' => 1], ['contract_type' => 'RANGE', 'underlying' => 'R_50', 'tick_count' => 1, 'high_barrier' => '+1']],
    'no duration info' => [['contract_id' => 1], ['contract_type' => 'DIGITEVEN', 'underlying' => 'R_50']],
    'expiry before start' => [['contract_id' => 1], ['contract_type' => 'DIGITEVEN', 'underlying' => 'R_50', 'date_start' => 100, 'date_expiry' => 100]],
    'missing contract id' => [[], ['contract_type' => 'DIGITEVEN', 'underlying' => 'R_50', 'tick_count' => 1]],
]);
