<?php

namespace App\Models;

use Database\Factories\CopyTradeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CopyTrade extends Model
{
    /** @use HasFactory<CopyTradeFactory> */
    use HasFactory;

    /** Claimed before the buy; the unique (user_id, master_contract_id) index makes this the dispatch key. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PLACED = 'placed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'master_connection_id',
        'follower_trx_id',
        'follower_contract_id',
        'follower_account_id',
        'master_trx_id',
        'master_contract_id',
        'symbol',
        'contract_type',
        'duration',
        'barrier',
        'stake',
        'payout',
        'profit',
        'sell_at_market',
        'is_win',
        'status',
        'failure_reason',
        'traded_at',
    ];

    /** Defaults that match the column defaults, so freshly created models agree with the database. */
    protected $attributes = [
        'status' => self::STATUS_PLACED,
    ];

    protected function casts(): array
    {
        return [
            'stake' => 'decimal:2',
            'payout' => 'decimal:2',
            'profit' => 'decimal:2',
            'sell_at_market' => 'boolean',
            'is_win' => 'boolean',
            'traded_at' => 'datetime',
        ];
    }

    /**
     * Record a sold contract's result: Deriv's `profit` when present, otherwise `sell_price - buy_price`.
     * Returns false, leaving the trade open, when the result carries neither.
     *
     * @param  array<string, mixed>  $result  A sold `proposal_open_contract` payload or a `profit_table` transaction.
     */
    public function settleFrom(array $result): bool
    {
        $sellPrice = is_numeric($result['sell_price'] ?? null) ? (float) $result['sell_price'] : null;
        $buyPrice = is_numeric($result['buy_price'] ?? null) ? (float) $result['buy_price'] : null;

        $profit = match (true) {
            is_numeric($result['profit'] ?? null) => (float) $result['profit'],
            $sellPrice !== null && $buyPrice !== null => $sellPrice - $buyPrice,
            default => null,
        };

        if ($profit === null) {
            return false;
        }

        $profit = round($profit, 2);

        $this->update([
            'is_win' => $profit > 0,
            'profit' => $profit,
            'payout' => $sellPrice,
        ]);

        return true;
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
