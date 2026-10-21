<?php

namespace App\Console\Commands;

use App\Jobs\MasterListenerJob;
use App\Models\CopySetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EnsureListenersRunning extends Command
{
    protected $signature = 'deriv:ensure-listeners';

    protected $description = 'Start a MasterListenerJob for any active copy session that has no live listener';

    public function handle(): int
    {
        $activeConnectionIds = CopySetting::query()
            ->where('is_running', true)
            ->distinct()
            ->pluck('master_connection_id');

        foreach ($activeConnectionIds as $connectionId) {
            $heartbeat = Cache::get(MasterListenerJob::heartbeatKey($connectionId));

            if ($heartbeat !== null) {
                $this->line("Listener for connection #{$connectionId} is alive (last heartbeat: {$heartbeat}).");

                continue;
            }

            if (MasterListenerJob::dispatchOnce($connectionId)) {
                $this->warn("No live listener for connection #{$connectionId} — dispatching MasterListenerJob.");
            } else {
                $this->line("Listener for connection #{$connectionId} is already queued.");
            }
        }

        $workers = (int) config('deriv.listener_workers');

        if ($activeConnectionIds->count() > $workers) {
            $message = "MasterListenerJob capacity: {$activeConnectionIds->count()} connections need a listener but only {$workers} listener workers are configured (DERIV_LISTENER_WORKERS / supervisor numprocs).";
            Log::error($message);
            $this->error($message);
        }

        if ($activeConnectionIds->isEmpty()) {
            $this->line('No active copy sessions found.');
        }

        return self::SUCCESS;
    }
}
