<?php

namespace App\Services;

use App\Exceptions\UncopyableTradeException;

/**
 * Turns a master's buy transaction plus its contract details into the exact
 * parameters a follower needs to buy the same contract.
 *
 * Never guesses: anything missing, ambiguous or unsupported throws, so a copy
 * is skipped rather than placed with the wrong parameters.
 */
class MasterContractParser
{
    /** @var list<string> */
    public const COPYABLE_CONTRACT_TYPES = [
        'CALL', 'PUT', 'CALLE', 'PUTE',
        'DIGITMATCH', 'DIGITDIFF', 'DIGITOVER', 'DIGITUNDER', 'DIGITEVEN', 'DIGITODD',
        'ONETOUCH', 'NOTOUCH',
        'EXPIRYRANGE', 'EXPIRYMISS', 'RANGE', 'UPORDOWN',
    ];

    /** @var list<string> */
    private const RISE_FALL_TYPES = ['CALL', 'PUT', 'CALLE', 'PUTE'];

    /** @var list<string> */
    private const SINGLE_BARRIER_TYPES = ['DIGITMATCH', 'DIGITDIFF', 'DIGITOVER', 'DIGITUNDER', 'ONETOUCH', 'NOTOUCH'];

    /** @var list<string> */
    private const DOUBLE_BARRIER_TYPES = ['EXPIRYRANGE', 'EXPIRYMISS', 'RANGE', 'UPORDOWN'];

    /** Shortcode barrier token for a zero relative barrier, i.e. Rise/Fall rather than Higher/Lower. */
    private const RISE_FALL_SHORTCODE_BARRIER = 'S0P';

    /** @var array<string, int> Largest unit first. */
    private const SECONDS_PER_UNIT = ['d' => 86400, 'h' => 3600, 'm' => 60];

    /**
     * @param  array<string, mixed>  $transaction  The master's `transaction` stream message (action = buy).
     * @param  array<string, mixed>  $contract  The `proposal_open_contract` payload for the same contract.
     * @return array{contract_id: string, transaction_id: string|null, symbol: string, contract_type: string, duration: int, duration_unit: string, barrier: string|null, barrier2: string|null, buy_price: float|null}
     *
     * @throws UncopyableTradeException
     */
    public static function parse(array $transaction, array $contract): array
    {
        $contractId = self::firstFilled($contract['contract_id'] ?? null, $transaction['contract_id'] ?? null);

        if ($contractId === null) {
            throw new UncopyableTradeException('Missing contract_id.');
        }

        $symbol = self::firstFilled(
            $contract['underlying_symbol'] ?? null,
            $contract['underlying'] ?? null,
            $transaction['underlying_symbol'] ?? null,
            $transaction['symbol'] ?? null,
            $transaction['underlying'] ?? null,
        );

        if ($symbol === null) {
            throw new UncopyableTradeException("Missing symbol for contract #{$contractId}.");
        }

        $contractType = self::firstFilled($contract['contract_type'] ?? null, $transaction['contract_type'] ?? null);

        if ($contractType === null || ! in_array($contractType, self::COPYABLE_CONTRACT_TYPES, true)) {
            throw new UncopyableTradeException('Contract type '.($contractType ?? 'unknown')." is not copyable (contract #{$contractId}).");
        }

        [$duration, $durationUnit] = self::duration($contract, $contractId);
        [$barrier, $barrier2] = self::barriers($contractType, $transaction, $contract, $contractId);

        return [
            'contract_id' => $contractId,
            'transaction_id' => self::firstFilled($transaction['transaction_id'] ?? null),
            'symbol' => $symbol,
            'contract_type' => $contractType,
            'duration' => $duration,
            'duration_unit' => $durationUnit,
            'barrier' => $barrier,
            'barrier2' => $barrier2,
            'buy_price' => self::buyPrice($transaction, $contract),
        ];
    }

    /**
     * @param  array<string, mixed>  $contract
     * @return array{0: int, 1: string}
     */
    private static function duration(array $contract, string $contractId): array
    {
        $explicitUnit = self::firstFilled($contract['duration_unit'] ?? null);

        if ($explicitUnit !== null && (int) ($contract['duration'] ?? 0) > 0) {
            return [(int) $contract['duration'], $explicitUnit];
        }

        if ((int) ($contract['tick_count'] ?? 0) > 0) {
            return [(int) $contract['tick_count'], 't'];
        }

        if (isset($contract['date_start'], $contract['date_expiry'])) {
            $seconds = (int) $contract['date_expiry'] - (int) $contract['date_start'];

            if ($seconds <= 0) {
                throw new UncopyableTradeException("Contract #{$contractId} expires before it starts.");
            }

            foreach (self::SECONDS_PER_UNIT as $unit => $unitSeconds) {
                if ($seconds % $unitSeconds === 0) {
                    return [intdiv($seconds, $unitSeconds), $unit];
                }
            }

            return [$seconds, 's'];
        }

        throw new UncopyableTradeException("Cannot determine duration for contract #{$contractId}.");
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $contract
     * @return array{0: string|null, 1: string|null}
     */
    private static function barriers(string $contractType, array $transaction, array $contract, string $contractId): array
    {
        if (in_array($contractType, self::SINGLE_BARRIER_TYPES, true)) {
            $barrier = self::firstFilled($contract['barrier'] ?? null, $transaction['barrier'] ?? null);

            if ($barrier === null) {
                throw new UncopyableTradeException("{$contractType} contract #{$contractId} has no barrier.");
            }

            return [$barrier, null];
        }

        if (in_array($contractType, self::DOUBLE_BARRIER_TYPES, true)) {
            $high = self::firstFilled($contract['high_barrier'] ?? null, $transaction['high_barrier'] ?? null);
            $low = self::firstFilled($contract['low_barrier'] ?? null, $transaction['low_barrier'] ?? null);

            if ($high === null || $low === null) {
                throw new UncopyableTradeException("{$contractType} contract #{$contractId} is missing a barrier.");
            }

            return [$high, $low];
        }

        if (in_array($contractType, self::RISE_FALL_TYPES, true)) {
            $shortcode = self::firstFilled($contract['shortcode'] ?? null);

            if ($shortcode !== null && self::shortcodeBarrier($shortcode) !== self::RISE_FALL_SHORTCODE_BARRIER) {
                throw new UncopyableTradeException("Higher/Lower contract #{$contractId} is not supported.");
            }
        }

        return [null, null];
    }

    /** Shortcodes end in `_{barrier}_{barrier2}`; symbols may contain underscores, so read from the end. */
    private static function shortcodeBarrier(string $shortcode): ?string
    {
        $parts = explode('_', $shortcode);

        return count($parts) >= 2 ? $parts[count($parts) - 2] : null;
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @param  array<string, mixed>  $contract
     */
    private static function buyPrice(array $transaction, array $contract): ?float
    {
        if (is_numeric($contract['buy_price'] ?? null)) {
            return (float) $contract['buy_price'];
        }

        if (is_numeric($transaction['amount'] ?? null)) {
            return abs((float) $transaction['amount']);
        }

        return null;
    }

    private static function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }
}
