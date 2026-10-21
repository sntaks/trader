<?php

namespace Database\Factories;

use App\Models\DerivConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DerivConnection>
 */
class DerivConnectionFactory extends Factory
{
    protected $model = DerivConnection::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'access_token' => 'ory_at_'.fake()->regexify('[a-zA-Z0-9]{64}'),
            'refresh_token' => 'ory_rt_'.fake()->regexify('[a-zA-Z0-9]{64}'),
            'token_type' => 'Bearer',
            'expires_at' => now()->addHour(),
            'scope' => 'trade',
            'type' => 'follower',
        ];
    }

    public function master(): static
    {
        return $this->state(['type' => 'master']);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subHour(), 'refresh_token' => null]);
    }
}
