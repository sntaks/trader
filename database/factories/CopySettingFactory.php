<?php

namespace Database\Factories;

use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CopySetting>
 */
class CopySettingFactory extends Factory
{
    protected $model = CopySetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'master_connection_id' => DerivConnection::factory()->state(['type' => 'master']),
            'follower_pattern' => fake()->randomElement(['111', '101', '110', '11']),
            'pattern_enabled' => true,
            'stake' => fake()->randomFloat(2, 0.35, 10),
            'follower_account_id' => 'DOT100',
            'is_active' => true,
        ];
    }

    public function paused(): static
    {
        return $this->state(['is_active' => false]);
    }
}
