<?php

namespace App\Livewire\Admin;

use App\Models\CopySetting;
use App\Models\DerivConnection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PlatformDashboard extends Component
{
    /** Runs on mount and on every update, so a demoted admin loses access mid-session. */
    public function boot(): void
    {
        abort_unless((bool) auth()->user()?->is_admin, 403);
    }

    #[Computed]
    public function userStats(): array
    {
        $total = User::query()->count();
        $admins = User::query()->where('is_admin', true)->count();
        $connected = User::query()->whereHas('derivConnection')->count();
        $todaySignups = User::query()->whereDate('created_at', today())->count();
        $weekSignups = User::query()->where('created_at', '>=', now()->subWeek())->count();
        $monthSignups = User::query()->where('created_at', '>=', now()->subMonth())->count();

        return [
            'total' => $total,
            'admins' => $admins,
            'regular' => $total - $admins,
            'connected' => $connected,
            'not_connected' => $total - $connected,
            'connection_rate' => $total > 0 ? round(($connected / $total) * 100, 1) : 0,
            'today_signups' => $todaySignups,
            'week_signups' => $weekSignups,
            'month_signups' => $monthSignups,
        ];
    }

    #[Computed]
    public function derivStats(): array
    {
        $total = DerivConnection::query()->count();
        $masters = DerivConnection::query()->where('type', 'master')->count();
        $followers = DerivConnection::query()->where('type', 'follower')->count();
        $expired = DerivConnection::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->count();

        return [
            'total_connections' => $total,
            'masters' => $masters,
            'followers' => $followers,
            'regular' => $total - $masters - $followers,
            'expired' => $expired,
            'active' => $total - $expired,
        ];
    }

    #[Computed]
    public function copyTradingStats(): array
    {
        $total = CopySetting::query()->count();
        $active = CopySetting::query()->where('is_active', true)->count();
        $paused = $total - $active;

        // Master popularity: masters sorted by follower count
        $topMasters = DerivConnection::query()
            ->where('type', 'master')
            ->with('user')
            ->withCount('followers')
            ->orderByDesc('followers_count')
            ->limit(5)
            ->get();

        return [
            'total_setups' => $total,
            'active' => $active,
            'paused' => $paused,
            'top_masters' => $topMasters,
        ];
    }

    #[Computed]
    public function recentUsers(): Collection
    {
        return User::query()
            ->with('derivConnection')
            ->latest()
            ->limit(10)
            ->get();
    }

    /** Signups grouped by day for the last 14 days. */
    #[Computed]
    public function signupTrend(): array
    {
        $days = collect(range(13, 0))->map(fn ($daysAgo) => now()->subDays($daysAgo)->format('Y-m-d'));

        $counts = User::query()
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        return $days->map(fn ($date) => [
            'date' => Carbon::parse($date)->format('d M'),
            'count' => $counts->get($date, 0),
        ])->values()->all();
    }

    public function render(): View
    {
        return view('livewire.admin.platform-dashboard');
    }
}
