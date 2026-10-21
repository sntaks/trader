<?php

namespace App\Livewire\Admin;

use App\Exceptions\DerivApiException;
use App\Services\DerivApiService;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/** Markup the platform's Deriv apps earned, loaded after the page so a slow or failing Deriv call never blocks it. */
#[Lazy]
class MarkupEarnings extends Component
{
    public const PERIOD_DAYS = 30;

    /** Runs on mount and on every update, so a demoted admin loses access mid-session. */
    public function boot(): void
    {
        abort_unless((bool) auth()->user()?->is_admin, 403);
    }

    /**
     * Statistics for today and the 29 UTC days before it, or the reason they could not be read.
     *
     * @return array{data: ?array<string, mixed>, error: ?string}
     */
    #[Computed]
    public function earnings(): array
    {
        $to = now('UTC');

        try {
            return ['data' => app(DerivApiService::class)->getMarkupStatistics($to->copy()->subDays(self::PERIOD_DAYS - 1), $to), 'error' => null];
        } catch (DerivApiException $e) {
            return ['data' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Apps that placed contracts but earned nothing, which means their markup is not set or not applied.
     *
     * @return list<array{app_id: string, contract_count: int}>
     */
    #[Computed]
    public function zeroMarkupApps(): array
    {
        return collect($this->earnings['data']['breakdown'] ?? [])
            ->filter(fn (array $app) => ($app['contract_count'] ?? 0) > 0 && (float) ($app['app_markup_usd'] ?? 0) === 0.0)
            ->map(fn (array $app) => ['app_id' => (string) $app['app_id'], 'contract_count' => (int) $app['contract_count']])
            ->values()
            ->all();
    }

    /** Which of the platform's apps an app id is, or null for any other app. */
    public function appLabel(string $appId): ?string
    {
        return match ($appId) {
            (string) config('deriv.app_id') => 'OAuth app',
            (string) config('deriv.app_id_pat') => 'PAT app',
            default => null,
        };
    }

    public function placeholder(): string
    {
        return <<<'HTML'
        <div class="h-40 animate-pulse rounded-xl border border-[#1F2937] bg-[#0B1220]"></div>
        HTML;
    }

    public function render(): View
    {
        return view('livewire.admin.markup-earnings');
    }
}
