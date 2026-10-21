<?php

use App\Exceptions\DerivApiException;
use App\Jobs\MasterListenerJob;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Services\DerivApiService;
use App\Services\DerivRateLimiter;
use Illuminate\Support\Sleep;

use function Pest\Laravel\mock;

test('the listener\'s otp is a trade call', function () {
    Sleep::fake();
    $master = DerivConnection::factory()->master()->create();
    CopySetting::factory()->create(['master_connection_id' => $master->id, 'master_account_id' => 'DOT500', 'is_running' => true]);
    $calls = new ArrayObject;

    mock(DerivApiService::class)
        ->shouldReceive('getOtpUrl')
        ->andReturnUsing(function (...$arguments) use ($calls) {
            $calls[] = $arguments;
            CopySetting::query()->update(['is_running' => false]);

            throw new DerivApiException('stop after one session attempt');
        });

    (new MasterListenerJob($master->id))->handle();

    expect($calls)->toHaveCount(1)
        ->and($calls[0][1])->toBe('DOT500')
        ->and($calls[0][2] ?? null)->toBe(DerivRateLimiter::PRIORITY_TRADE);

    Sleep::assertSleptTimes(1);
});
