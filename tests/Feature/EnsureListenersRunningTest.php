<?php

use App\Jobs\MasterListenerJob;
use App\Models\CopySetting;
use App\Models\DerivConnection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

test('ensure-listeners uses dispatchOnce', function () {
    Bus::fake([MasterListenerJob::class]);
    $master = DerivConnection::factory()->master()->create();
    CopySetting::factory()->create(['master_connection_id' => $master->id, 'is_running' => true]);

    $this->artisan('deriv:ensure-listeners')->assertSuccessful();
    $this->artisan('deriv:ensure-listeners')->assertSuccessful();

    Bus::assertDispatchedTimes(MasterListenerJob::class, 1);
});

test('ensure-listeners reports when demand exceeds workers', function () {
    Bus::fake([MasterListenerJob::class]);
    Log::spy();
    config(['deriv.listener_workers' => 1]);

    foreach (range(1, 2) as $i) {
        $master = DerivConnection::factory()->master()->create();
        CopySetting::factory()->create(['master_connection_id' => $master->id, 'is_running' => true]);
    }

    $this->artisan('deriv:ensure-listeners')->assertSuccessful();

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message) => str_contains($message, '2 connections') && str_contains($message, '1 listener worker'));
});

test('ensure-listeners stays quiet within capacity', function () {
    Bus::fake([MasterListenerJob::class]);
    Log::spy();
    config(['deriv.listener_workers' => 2]);

    foreach (range(1, 2) as $i) {
        $master = DerivConnection::factory()->master()->create();
        CopySetting::factory()->create(['master_connection_id' => $master->id, 'is_running' => true]);
    }

    $this->artisan('deriv:ensure-listeners')->assertSuccessful();

    Log::shouldNotHaveReceived('error');
});
