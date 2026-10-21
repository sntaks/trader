<?php

use App\Exceptions\DerivApiException;
use App\Exceptions\DerivRateLimitedException;
use App\Livewire\Admin\MarkupEarnings;
use App\Models\User;
use App\Services\DerivApiService;
use Livewire\Livewire;

use function Pest\Laravel\mock;

/** Make getMarkupStatistics return the default statistics merged with $data. */
function mockMarkupStatistics(array $data): void
{
    mock(DerivApiService::class)->allows('getMarkupStatistics')->andReturn([
        'total_app_markup_usd' => 0,
        'total_volume_usd' => 0,
        'total_payout_usd' => 0,
        'total_contract_count' => 0,
        'total_client_count' => 0,
        'breakdown' => [],
        ...$data,
    ]);
}

function mockMarkupStatisticsFailure(Throwable $exception): void
{
    mock(DerivApiService::class)->allows('getMarkupStatistics')->andThrow($exception);
}

function markupAdmin(): User
{
    return User::factory()->create(['is_admin' => true]);
}

test('admins see markup earnings and our apps labelled', function () {
    config(['deriv.app_id' => '111', 'deriv.app_id_pat' => '555']);
    mockMarkupStatistics(['total_app_markup_usd' => 12.5, 'breakdown' => [
        ['app_id' => '111', 'app_markup_usd' => 10.0, 'volume_usd' => 500, 'payout_usd' => 900, 'contract_count' => 40, 'client_count' => 3],
        ['app_id' => '555', 'app_markup_usd' => 2.5, 'volume_usd' => 100, 'payout_usd' => 180, 'contract_count' => 8, 'client_count' => 1],
    ]]);

    Livewire::withoutLazyLoading()->actingAs(markupAdmin())->test(MarkupEarnings::class)
        ->assertSee('$12.50')
        ->assertSee('OAuth app')
        ->assertSee('PAT app')
        ->assertDontSee('earned no markup');
});

test('the markup range is the last 30 days in UTC', function () {
    $this->travelTo(now('UTC')->setDate(2026, 10, 21)->setTime(22, 30));
    $service = mock(DerivApiService::class);
    $service->expects('getMarkupStatistics')
        ->withArgs(fn ($from, $to) => $from->toDateString() === '2026-09-22' && $to->toDateString() === '2026-10-21' && $to->tzName === 'UTC')
        ->andReturn(['total_app_markup_usd' => 0, 'total_volume_usd' => 0, 'total_payout_usd' => 0, 'total_contract_count' => 0, 'total_client_count' => 0, 'breakdown' => []]);

    Livewire::withoutLazyLoading()->actingAs(markupAdmin())->test(MarkupEarnings::class)->assertSee('Last 30 Days');
});

test('an app that traded without earning markup is flagged', function () {
    mockMarkupStatistics(['breakdown' => [
        ['app_id' => '111', 'app_markup_usd' => 0, 'volume_usd' => 50, 'payout_usd' => 90, 'contract_count' => 7, 'client_count' => 1],
    ]]);

    Livewire::withoutLazyLoading()->actingAs(markupAdmin())->test(MarkupEarnings::class)
        ->assertSee('App 111 placed 7 contracts but earned no markup');
});

test('the card explains a markup read failure', function (string $message) {
    mockMarkupStatisticsFailure(new DerivApiException($message));

    Livewire::withoutLazyLoading()->actingAs(markupAdmin())->test(MarkupEarnings::class)
        ->assertSee($message);
})->with([DerivApiService::MARKUP_TOKEN_MISSING, DerivApiService::MARKUP_SCOPE_MISSING, 'Upstream service timeout']);

test('the card reports a busy deriv', function () {
    mockMarkupStatisticsFailure(new DerivRateLimitedException('Deriv rate limit reached — try again shortly.'));

    Livewire::withoutLazyLoading()->actingAs(markupAdmin())->test(MarkupEarnings::class)
        ->assertSee('Deriv rate limit reached');
});

test('non-admins cannot load the markup card', function () {
    Livewire::withoutLazyLoading()->actingAs(User::factory()->create())->test(MarkupEarnings::class)->assertForbidden();
});

test('the admin dashboard renders when the markup token is missing', function () {
    config(['deriv.partner_token' => null]);

    $this->actingAs(markupAdmin())->get(route('admin.dashboard'))->assertSuccessful();
});

test('the admin dashboard shows the markup card', function () {
    config(['deriv.partner_token' => null]);
    Livewire::withoutLazyLoading();

    $this->actingAs(markupAdmin())->get(route('admin.dashboard'))
        ->assertSee(DerivApiService::MARKUP_TOKEN_MISSING);
});
