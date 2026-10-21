<?php

use App\Livewire\CopyTrading\Dashboard;
use App\Models\CopySetting;
use App\Models\CopyTrade;
use App\Models\DerivConnection;
use App\Models\User;
use App\Services\DerivApiService;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

use function Pest\Laravel\mock;

beforeEach(function () {
    mock(DerivApiService::class)->shouldIgnoreMissing();
});

/** A follower whose session started an hour ago. */
function exportSetting(): CopySetting
{
    $user = User::factory()->create();
    DerivConnection::factory()->create(['user_id' => $user->id]);

    return CopySetting::factory()->create(['user_id' => $user->id, 'session_started_at' => now()->subHour()]);
}

/** @param  array<string, mixed>  $attributes */
function exportTrade(CopySetting $setting, array $attributes = [], ?Closure $state = null): CopyTrade
{
    $factory = CopyTrade::factory();

    return ($state ? $state($factory) : $factory)->create([
        'user_id' => $setting->user_id,
        'master_connection_id' => $setting->master_connection_id,
        ...$attributes,
    ]);
}

/** @return list<list<string>> The downloaded CSV, one array per line. */
function downloadedCsv(Testable $component): array
{
    $content = base64_decode((string) data_get($component->effects, 'download.content'));

    return array_map('str_getcsv', array_values(array_filter(explode("\n", $content), fn (string $line) => $line !== '')));
}

test('export downloads every session trade, newest first, as csv', function () {
    $setting = exportSetting();
    exportTrade($setting, ['follower_trx_id' => 'TRX-BEFORE', 'traded_at' => now()->subHours(2)]);
    foreach (range(1, 27) as $position) {
        exportTrade($setting, ['follower_trx_id' => "TRX-{$position}", 'traded_at' => now()->subMinutes(30 - $position)]);
    }

    $component = Livewire::actingAs($setting->user)->test(Dashboard::class)->call('exportTransactions')
        ->assertFileDownloaded('copy-trades-'.now()->format('Y-m-d').'.csv');
    $rows = downloadedCsv($component);

    expect($rows[0])->toBe(Dashboard::EXPORT_HEADERS)
        ->and($rows)->toHaveCount(28)
        ->and(collect($rows)->flatten())->not->toContain('TRX-BEFORE')
        ->and($rows[1][0])->toBe(now()->subMinutes(3)->format('Y-m-d H:i:s'));
});

test('export of a session without trades has only the header', function () {
    $setting = exportSetting();

    $component = Livewire::actingAs($setting->user)->test(Dashboard::class)->call('exportTransactions');

    expect(downloadedCsv($component))->toBe([Dashboard::EXPORT_HEADERS]);
});

test('export writes won, lost, open, pending and failed trades plainly', function () {
    $setting = exportSetting();
    exportTrade($setting, ['traded_at' => now()->subMinutes(5), 'is_win' => true, 'stake' => 1, 'payout' => 1.95, 'profit' => 0.95]);
    exportTrade($setting, ['traded_at' => now()->subMinutes(4)]);
    exportTrade($setting, ['traded_at' => now()->subMinutes(3)], fn ($factory) => $factory->open());
    exportTrade($setting, ['traded_at' => now()->subMinutes(2), 'status' => CopyTrade::STATUS_PENDING], fn ($factory) => $factory->open());
    exportTrade($setting, ['traded_at' => now()->subMinute()], fn ($factory) => $factory->failed('Insufficient balance.'));

    $rows = downloadedCsv(Livewire::actingAs($setting->user)->test(Dashboard::class)->call('exportTransactions'));
    $column = fn (string $header) => array_search($header, Dashboard::EXPORT_HEADERS, true);

    expect(array_column(array_slice($rows, 1), $column('Result')))->toBe(['Failed', 'Pending', 'Open', 'Lost', 'Won'])
        ->and($rows[1][$column('Failure reason')])->toBe('Insufficient balance.')
        ->and($rows[3][$column('Profit')])->toBe('')
        ->and($rows[3][$column('Payout')])->toBe('')
        ->and($rows[4][$column('Profit')])->toBe('-1.00')
        ->and($rows[5][$column('Payout')])->toBe('1.95');
});

test('export neutralises spreadsheet formulas in text but keeps negative money', function () {
    $setting = exportSetting();
    exportTrade($setting, ['traded_at' => now()->subMinutes(2), 'symbol' => '+cmd'], fn ($factory) => $factory->failed('=HYPERLINK("x")'));
    exportTrade($setting, ['traded_at' => now()->subMinute(), 'symbol' => '@SUM(A1)', 'profit' => -1.00]);

    $rows = downloadedCsv(Livewire::actingAs($setting->user)->test(Dashboard::class)->call('exportTransactions'));
    $column = fn (string $header) => array_search($header, Dashboard::EXPORT_HEADERS, true);

    expect($rows[1][$column('Symbol')])->toBe("'@SUM(A1)")
        ->and($rows[1][$column('Profit')])->toBe('-1.00')
        ->and($rows[2][$column('Symbol')])->toBe("'+cmd")
        ->and($rows[2][$column('Failure reason')])->toBe("'=HYPERLINK(\"x\")");
});

test('export only includes the acting user\'s trades', function () {
    $setting = exportSetting();
    $other = exportSetting();
    exportTrade($other, ['follower_trx_id' => 'TRX-OTHER', 'traded_at' => now()->subMinute()]);

    $rows = downloadedCsv(Livewire::actingAs($setting->user)->test(Dashboard::class)->call('exportTransactions'));

    expect($rows)->toHaveCount(1);
});

test('the trade table has an export button', function () {
    $setting = exportSetting();

    Livewire::actingAs($setting->user)->test(Dashboard::class)
        ->assertSeeHtml('wire:click="exportTransactions"')
        ->assertSee('Export CSV');
});
