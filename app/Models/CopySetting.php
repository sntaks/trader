<?php

namespace App\Models;

use Database\Factories\CopySettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CopySetting extends Model
{
    /** @use HasFactory<CopySettingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'master_connection_id',
        'master_account_id',
        'follower_account_id',
        'follower_pattern',
        'pattern_enabled',
        'stake',
        'follow_master_stake',
        'safe_mode',
        'stake_multiplier',
        'take_profit',
        'stop_loss',
        'max_compound',
        'do_martingale_at',
        'max_martingale',
        'if_hit_max_martingale',
        'wait_for_loss',
        'only_use_1x_wait_for_loss',
        'filter_markets',
        'synthetic_indices',
        'forex_pairs',
        'is_active',
        'is_running',
        'start_balance',
        'session_started_at',
        'stop_reason',
        'stopped_at_profit',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_running' => 'boolean',
            'pattern_enabled' => 'boolean',
            'follow_master_stake' => 'boolean',
            'safe_mode' => 'boolean',
            'only_use_1x_wait_for_loss' => 'boolean',
            'stake' => 'decimal:2',
            'stake_multiplier' => 'decimal:2',
            'take_profit' => 'decimal:2',
            'stop_loss' => 'decimal:2',
            'start_balance' => 'decimal:2',
            'stopped_at_profit' => 'decimal:2',
            'session_started_at' => 'datetime',
            'filter_markets' => 'array',
            'synthetic_indices' => 'array',
            'forex_pairs' => 'array',
        ];
    }

    /** @var list<string> Deriv symbols offered in the market filter. */
    public const MARKET_SYMBOLS = ['R_10', 'R_25', 'R_50', 'R_75', 'R_100', '1HZ10V', '1HZ25V', '1HZ50V', '1HZ75V', '1HZ100V'];

    /** @var array<string, string> Forex filter label (as stored in forex_pairs) => Deriv symbol. */
    public const FOREX_SYMBOLS = [
        'AUD/CAD' => 'frxAUDCAD',
        'AUD/CHF' => 'frxAUDCHF',
        'AUD/JPY' => 'frxAUDJPY',
        'EUR/USD' => 'frxEURUSD',
        'GBP/USD' => 'frxGBPUSD',
        'USD/JPY' => 'frxUSDJPY',
    ];

    /** @var array<string, list<string>> Trade-type filter code (as stored in synthetic_indices) => contract types. */
    public const TRADE_TYPES = [
        'DM' => ['DIGITMATCH'],
        'DD' => ['DIGITDIFF'],
        'OE' => ['DIGITODD', 'DIGITEVEN'],
        'UO' => ['DIGITUNDER', 'DIGITOVER'],
        'TNT' => ['ONETOUCH', 'NOTOUCH'],
        'RF' => ['CALL', 'PUT'],
    ];

    /**
     * Whether the market and trade-type filters let this trade through.
     * Each filter allows everything while empty. Codes no longer offered are ignored,
     * but a filter holding only such codes allows nothing rather than everything.
     */
    public function allowsTrade(string $symbol, string $contractType): bool
    {
        $savedTradeTypes = $this->synthetic_indices ?? [];

        if ($savedTradeTypes !== [] && self::knownTradeTypeCodes($savedTradeTypes) === []) {
            return false;
        }

        $allowedSymbols = array_merge(
            $this->filter_markets ?? [],
            array_values(array_intersect_key(self::FOREX_SYMBOLS, array_flip($this->forex_pairs ?? []))),
        );

        if ($allowedSymbols !== [] && ! in_array($symbol, $allowedSymbols, true)) {
            return false;
        }

        $allowedTypes = array_merge(...array_values(array_intersect_key(self::TRADE_TYPES, array_flip($this->synthetic_indices ?? []))));

        return $allowedTypes === [] || in_array($contractType, $allowedTypes, true);
    }

    /**
     * @param  array<int, string>  $codes
     * @return list<string> The codes still offered in TRADE_TYPES, in their saved order.
     */
    public static function knownTradeTypeCodes(array $codes): array
    {
        return array_values(array_filter($codes, fn (string $code) => array_key_exists($code, self::TRADE_TYPES)));
    }

    /** Check if the given outcome history matches the follower pattern. */
    public function matchesPattern(array $recentOutcomes): bool
    {
        if (! $this->pattern_enabled || empty($this->follower_pattern)) {
            return true;
        }

        $pattern = $this->follower_pattern;
        $length = strlen($pattern);

        if (count($recentOutcomes) < $length) {
            return false;
        }

        $slice = array_slice($recentOutcomes, -$length);

        foreach ($slice as $index => $outcome) {
            if ((string) $outcome !== $pattern[$index]) {
                return false;
            }
        }

        return true;
    }

    /**
     * Money left before the stop loss: the limit plus settled session profit minus every open stake.
     * Null when no stop loss is set. Pass the caller's own pending row to leave it out of the open stake.
     */
    public function stopLossBudget(?int $exceptTradeId = null): ?float
    {
        if ($this->stop_loss === null) {
            return null;
        }

        $session = CopyTrade::query()
            ->where('user_id', $this->user_id)
            ->when($this->session_started_at, fn ($query) => $query->where('traded_at', '>=', $this->session_started_at));

        $settledProfit = (float) (clone $session)->whereNotNull('is_win')->sum('profit');

        $openStake = (float) (clone $session)
            ->whereNull('is_win')
            ->whereIn('status', [CopyTrade::STATUS_PENDING, CopyTrade::STATUS_PLACED])
            ->when($exceptTradeId, fn ($query) => $query->whereKeyNot($exceptTradeId))
            ->sum('stake');

        return round((float) $this->stop_loss + $settledProfit - $openStake, 2);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function masterConnection(): BelongsTo
    {
        return $this->belongsTo(DerivConnection::class, 'master_connection_id');
    }
}
