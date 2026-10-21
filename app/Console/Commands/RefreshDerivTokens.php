<?php

namespace App\Console\Commands;

use App\Models\DerivConnection;
use App\Services\DerivTokenRefresher;
use Illuminate\Console\Command;

class RefreshDerivTokens extends Command
{
    /** Refresh tokens expiring this soon; three times the schedule interval, so a slow tick still lands in time. */
    public const WINDOW_SECONDS = 900;

    protected $signature = 'deriv:refresh-tokens';

    protected $description = 'Refresh Deriv OAuth tokens that are about to expire, or lapsed while this was not running';

    public function handle(DerivTokenRefresher $refresher): int
    {
        $refreshed = 0;
        $failed = 0;

        DerivConnection::query()
            ->whereNotNull('refresh_token')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now()->addSeconds(self::WINDOW_SECONDS))
            ->each(function (DerivConnection $connection) use ($refresher, &$refreshed, &$failed): void {
                $refresher->refresh($connection, self::WINDOW_SECONDS) ? $refreshed++ : $failed++;
            });

        $this->line("Refreshed {$refreshed} Deriv token(s); {$failed} could not be refreshed.");

        return self::SUCCESS;
    }
}
