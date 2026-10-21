<?php

use App\Exceptions\DerivApiException;
use App\Services\DerivApiService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Markup statistics `data` in the shape Deriv documents, with one row for app 555 unless a breakdown is given.
 *
 * @param  list<array<string, mixed>>|null  $breakdown
 * @return array<string, mixed>
 */
function markupData(?array $breakdown = null): array
{
    return [
        'total_app_markup_usd' => 12.5,
        'total_volume_usd' => 600.0,
        'total_payout_usd' => 1080.0,
        'total_contract_count' => 48,
        'total_client_count' => 4,
        'breakdown' => $breakdown ?? [
            ['app_id' => '555', 'app_markup_usd' => 12.5, 'volume_usd' => 600.0, 'payout_usd' => 1080.0, 'contract_count' => 48, 'client_count' => 4],
        ],
    ];
}

test('markup statistics are read for the UTC date range with the partner token', function () {
    config(['deriv.partner_token' => 'pat_partner', 'deriv.app_id_pat' => '555']);
    Http::fake(['api.derivws.com/applications/v1/markup-statistics*' => Http::response(['data' => markupData(), 'meta' => []])]);

    $data = app(DerivApiService::class)->getMarkupStatistics(Carbon::parse('2026-10-01', 'UTC'), Carbon::parse('2026-10-21', 'UTC'));

    expect($data['total_app_markup_usd'])->toBe(12.5);
    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'date_from=2026-10-01')
        && str_contains($request->url(), 'date_to=2026-10-21')
        && $request->hasHeader('Authorization', 'Bearer pat_partner')
        && $request->hasHeader('Deriv-App-ID', '555'));
});

test('markup statistics without a partner token throw without calling deriv', function () {
    config(['deriv.partner_token' => null]);
    Http::fake();

    expect(fn () => app(DerivApiService::class)->getMarkupStatistics(now()->subDays(29), now()))
        ->toThrow(DerivApiException::class, DerivApiService::MARKUP_TOKEN_MISSING);
    Http::assertNothingSent();
});

test('markup statistics explain a token without the scope', function (int $status) {
    config(['deriv.partner_token' => 'pat_partner']);
    Http::fake(['*' => Http::response(['errors' => [['status' => $status, 'code' => 'AccessDenied', 'message' => 'x']]], $status)]);

    expect(fn () => app(DerivApiService::class)->getMarkupStatistics(now()->subDays(29), now()))
        ->toThrow(DerivApiException::class, DerivApiService::MARKUP_SCOPE_MISSING);
})->with([401, 403]);

test('markup statistics pass on deriv\'s message for other failures', function () {
    config(['deriv.partner_token' => 'pat_partner']);
    Http::fake(['*' => Http::response(['errors' => [['status' => 504, 'code' => 'Timeout', 'message' => 'Upstream service timeout']]], 504)]);

    expect(fn () => app(DerivApiService::class)->getMarkupStatistics(now()->subDays(29), now()))
        ->toThrow(DerivApiException::class, 'Upstream service timeout');
});

test('markup statistics are cached for ten minutes per range', function () {
    config(['deriv.partner_token' => 'pat_partner']);
    Http::fake(['*' => Http::response(['data' => markupData()])]);
    $service = app(DerivApiService::class);

    $service->getMarkupStatistics(Carbon::parse('2026-10-01', 'UTC'), Carbon::parse('2026-10-21', 'UTC'));
    $service->getMarkupStatistics(Carbon::parse('2026-10-01', 'UTC'), Carbon::parse('2026-10-21', 'UTC'));

    Http::assertSentCount(1);

    $this->travel(11)->minutes();
    $service->getMarkupStatistics(Carbon::parse('2026-10-01', 'UTC'), Carbon::parse('2026-10-21', 'UTC'));

    Http::assertSentCount(2);
});
