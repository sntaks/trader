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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function masterConnection(): BelongsTo
    {
        return $this->belongsTo(DerivConnection::class, 'master_connection_id');
    }
}
