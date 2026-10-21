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

    /** Version stamped on every parsed trade; jobs reject payloads without it (e.g. queued by the old listener). */
    public const SHAPE_VERSION = 2;

    /** @var list<string> The barrier is the predicted last digit. */
    private const DIGIT_BARRIER_TYPES = ['DIGITMATCH', 'DIGITDIFF', 'DIGITOVER', 'DIGITUNDER'];

    /** @var list<string> */
    private const TOUCH_TYPES = ['ONETOUCH', 'NOTOUCH'];

    /** @var list<string> */
    private const DOUBLE_BARRIER_TYPES = ['EXPIRYRANGE', 'EXPIRYMISS', 'RANGE', 'UPORDOWN'];

    /** Shortcode barrier token for a zero relative barrier, i.e. Rise/Fall rather than Higher/Lower. */
    private const RISE_FALL_SHORTCODE_BARRIER = 'S0P';

    /** @var array<string, int> Largest unit first. */
    private const SECONDS_PER_UNIT = ['d' => 86400, 'h' => 3600, 'm' => 60];

    /**
     * @param  array<string, mixed>  $transaction  The master's `transaction` stream message (action = buy).
     * @param  array<string, mixed>  $contract  The `proposal_open_contract` payload for the same contract.
     * @return array{contract_id: string, transaction_id: string|null, symbol: string, contract_type: string, duration: int, duration_unit: string, barrier: string|null, barrier2: string|null, buy_price: float|null, shape_version: int}
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
            'shape_version' => self::SHAPE_VERSION,
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
        $barrier = self::firstFilled($contract['barrier'] ?? null, $transaction['barrier'] ?? null);
        $entrySpot = self::firstFilled($contract['entry_spot'] ?? null, $contract['entry_tick'] ?? null);

        if (in_array($contractType, self::DIGIT_BARRIER_TYPES, true)) {
            if ($barrier === null) {
                throw new UncopyableTradeException("{$contractType} contract #{$contractId} has no barrier.");
            }

            return [$barrier, null];
        }

        if (in_array($contractType, self::TOUCH_TYPES, true)) {
            if ($barrier === null) {
                throw new UncopyableTradeException("{$contractType} contract #{$contractId} has no barrier.");
            }

            return [self::relativeBarrier($barrier, $entrySpot, $contractId), null];
        }

        if (in_array($contractType, self::DOUBLE_BARRIER_TYPES, true)) {
            $high = self::firstFilled($contract['high_barrier'] ?? null, $transaction['high_barrier'] ?? null);
            $low = self::firstFilled($contract['low_barrier'] ?? null, $transaction['low_barrier'] ?? null);

            if ($high === null || $low === null) {
                throw new UncopyableTradeException("{$contractType} contract #{$contractId} is missing a barrier.");
            }

            return [
                self::relativeBarrier($high, $entrySpot, $contractId),
                self::relativeBarrier($low, $entrySpot, $contractId),
            ];
        }

        if (in_array($contractType, self::RISE_FALL_TYPES, true) && ! self::isRiseFall($contract, $barrier, $entrySpot)) {
            throw new UncopyableTradeException("Contract #{$contractId} is not provably Rise/Fall (Higher/Lower is not supported).");
        }

        return [null, null];
    }

    /**
     * Rise/Fall and Higher/Lower share contract types. Prove Rise/Fall from the shortcode's
     * zero relative barrier, or else from a barrier equal to the entry spot; anything else is rejected.
     *
     * @param  array<string, mixed>  $contract
     */
    private static function isRiseFall(array $contract, ?string $barrier, ?string $entrySpot): bool
    {
        $shortcode = self::firstFilled($contract['shortcode'] ?? null);

        if ($shortcode !== null) {
            return self::shortcodeBarrier($shortcode) === self::RISE_FALL_SHORTCODE_BARRIER;
        }

        return is_numeric($barrier) && is_numeric($entrySpot) && (float) $barrier === (float) $entrySpot;
    }

    /**
     * Contract details may report barriers as absolute prices. A follower buys later at a
     * different spot, so convert to the master's offset from entry (e.g. "+0.50").
     */
    private static function relativeBarrier(string $barrier, ?string $entrySpot, string $contractId): string
    {
        if (str_starts_with($barrier, '+') || str_starts_with($barrier, '-')) {
            return $barrier;
        }

        if (! is_numeric($barrier) || ! is_numeric($entrySpot)) {
            throw new UncopyableTradeException("Cannot derive a relative barrier for contract #{$contractId} without an entry spot.");
        }

        $decimals = max(self::decimalPlaces($barrier), self::decimalPlaces($entrySpot));

        return sprintf("%+.{$decimals}f", round((float) $barrier - (float) $entrySpot, $decimals));
    }

    private static function decimalPlaces(string $number): int
    {
        $dot = strpos($number, '.');

        return $dot === false ? 0 : strlen($number) - $dot - 1;
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
