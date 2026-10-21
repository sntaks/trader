<?php

namespace Database\Factories;

use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CopyTrade>
 */
class CopyTradeFactory extends Factory
{
    /**
     * Define the model's default state: a placed copy that settled as a loss.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'master_connection_id' => DerivConnection::factory()->master(),
            'master_trx_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'master_contract_id' => (string) fake()->unique()->numberBetween(100000, 999999),
            'follower_trx_id' => (string) fake()->numberBetween(100000, 999999),
            'follower_contract_id' => (string) fake()->numberBetween(100000, 999999),
            'follower_account_id' => 'DOT100',
            'symbol' => 'R_50',
            'contract_type' => 'DIGITEVEN',
            'duration' => '1t',
            'stake' => 1.00,
            'payout' => 0.00,
            'profit' => -1.00,
            'is_win' => false,
            'status' => CopyTrade::STATUS_PLACED,
            'traded_at' => now(),
        ];
    }

    /** Placed but not yet settled. */
    public function open(): static
    {
        return $this->state(['is_win' => null, 'payout' => null, 'profit' => null]);
    }

    /** A copy attempt Deriv rejected. */
    public function failed(string $reason = 'Insufficient balance.'): static
    {
        return $this->state([
            'status' => CopyTrade::STATUS_FAILED,
            'failure_reason' => $reason,
            'follower_trx_id' => null,
            'follower_contract_id' => null,
            'is_win' => null,
            'payout' => null,
            'profit' => null,
        ]);
    }
}
