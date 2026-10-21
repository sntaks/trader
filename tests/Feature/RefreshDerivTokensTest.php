<?php

use App\Models\DerivConnection;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

test('refresh-tokens refreshes only connections near expiry', function () {
    Http::fake(['auth.deriv.com/oauth2/token' => Http::response(['access_token' => 'at_new'])]);
    $soon = DerivConnection::factory()->create(['access_token' => 'at_old', 'refresh_token' => 'rt_soon', 'expires_at' => now()->addMinutes(10)]);
    DerivConnection::factory()->create(['refresh_token' => 'rt_later', 'expires_at' => now()->addHours(2)]);
    DerivConnection::factory()->create(['refresh_token' => null, 'expires_at' => now()->addMinutes(10)]);
    DerivConnection::factory()->create(['token_type' => 'pat', 'refresh_token' => null, 'expires_at' => null]);

    $this->artisan('deriv:refresh-tokens')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request['refresh_token'] === 'rt_soon');
    expect($soon->fresh()->access_token)->toBe('at_new');
});

test('refresh-tokens renews a token that lapsed while the scheduler was down', function () {
    Http::fake(['auth.deriv.com/oauth2/token' => Http::response(['access_token' => 'at_new'])]);
    $lapsed = DerivConnection::factory()->create(['refresh_token' => 'rt_lapsed', 'expires_at' => now()->subMinutes(30)]);

    $this->artisan('deriv:refresh-tokens')->assertSuccessful();

    expect($lapsed->fresh()->access_token)->toBe('at_new')
        ->and($lapsed->fresh()->isExpired())->toBeFalse();
});

test('refresh-tokens carries on after one failure', function () {
    Http::fake(['auth.deriv.com/oauth2/token' => Http::sequence()->push('', 503)->push(['access_token' => 'at_new'])]);
    $first = DerivConnection::factory()->create(['refresh_token' => 'rt_1', 'expires_at' => now()->addMinutes(5)]);
    $second = DerivConnection::factory()->create(['refresh_token' => 'rt_2', 'expires_at' => now()->addMinutes(6)]);

    $this->artisan('deriv:refresh-tokens')->assertSuccessful();

    expect($first->fresh()->refresh_token)->toBe('rt_1')
        ->and($second->fresh()->access_token)->toBe('at_new');
});

test('the schedule runs refresh-tokens every five minutes', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'deriv:refresh-tokens'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/5 * * * *');
});
