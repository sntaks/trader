<?php

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivRateLimitedException;
use App\Services\DerivApiService;
use App\Services\DerivRateLimiter;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
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

test('markup statistics explain a token without the scope', function () {
    config(['deriv.partner_token' => 'pat_partner']);
    Http::fake(['*' => Http::response(['errors' => [['status' => 403, 'code' => 'AccessDenied', 'message' => 'x']]], 403)]);

    expect(fn () => app(DerivApiService::class)->getMarkupStatistics(now()->subDays(29), now()))
        ->toThrow(DerivApiException::class, DerivApiService::MARKUP_SCOPE_MISSING);
});

test('markup statistics explain a rejected token', function () {
    config(['deriv.partner_token' => 'pat_partner']);
    Http::fake(['*' => Http::response(['errors' => [['status' => 401, 'code' => 'Unauthorized', 'message' => 'x']]], 401)]);

    expect(fn () => app(DerivApiService::class)->getMarkupStatistics(now()->subDays(29), now()))
        ->toThrow(DerivApiException::class, DerivApiService::MARKUP_TOKEN_REJECTED);
});

test('a success without statistics is an error and is not cached', function (mixed $body) {
    config(['deriv.partner_token' => 'pat_partner']);
    Http::fakeSequence('*')->push($body)->push(['data' => markupData()]);
    $service = app(DerivApiService::class);

    expect(fn () => $service->getMarkupStatistics(now()->subDays(29), now()))
        ->toThrow(DerivApiException::class, DerivApiService::MARKUP_EMPTY);

    $this->travel(61)->seconds();

    expect($service->getMarkupStatistics(now()->subDays(29), now())['total_app_markup_usd'])->toBe(12.5);
})->with([
    'no data' => [['meta' => []]],
    'html' => ['<html>maintenance</html>'],
]);

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

test('a markup failure is remembered for a minute', function () {
    config(['deriv.partner_token' => 'pat_partner']);
    Http::fake(['*' => Http::response(['errors' => [['status' => 401, 'code' => 'Unauthorized', 'message' => 'x']]], 401)]);
    $service = app(DerivApiService::class);

    expect(fn () => $service->getMarkupStatistics(now()->subDays(29), now()))->toThrow(DerivApiException::class, DerivApiService::MARKUP_TOKEN_REJECTED)
        ->and(fn () => $service->getMarkupStatistics(now()->subDays(29), now()))->toThrow(DerivApiException::class, DerivApiService::MARKUP_TOKEN_REJECTED);
    Http::assertSentCount(1);

    $this->travel(61)->seconds();

    expect(fn () => $service->getMarkupStatistics(now()->subDays(29), now()))->toThrow(DerivApiException::class);
    Http::assertSentCount(2);
});

test('a markup 429 is a rate-limit error that pauses no trades and is remembered for a minute', function () {
    config(['deriv.partner_token' => 'pat_partner']);
    Http::fake(['*' => Http::response(['errors' => [['status' => 429, 'code' => 'RateLimit', 'message' => 'Too many requests']]], 429, ['Retry-After' => '40'])]);
    $service = app(DerivApiService::class);

    expect(fn () => $service->getMarkupStatistics(now()->subDays(29), now()))->toThrow(DerivRateLimitedException::class)
        ->and(Cache::get(DerivRateLimiter::COOLDOWN_KEY))->toBeNull()
        ->and(fn () => $service->getMarkupStatistics(now()->subDays(29), now()))->toThrow(DerivRateLimitedException::class);
    Http::assertSentCount(1);
});
