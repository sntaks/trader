<?php

use App\Models\CopyTrade;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

test('copy trades are indexed for a user\'s history', function () {
    $columnSets = collect(Schema::getIndexes('copy_trades'))->pluck('columns')->all();

    expect($columnSets)->toContain(['user_id', 'traded_at']);
});

test('a follower contract can be recorded only once', function () {
    CopyTrade::factory()->create(['follower_contract_id' => 'FC-1']);

    expect(fn () => CopyTrade::factory()->create(['follower_contract_id' => 'FC-1']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('trades without a follower contract are not limited', function () {
    CopyTrade::factory()->count(2)->create(['follower_contract_id' => null]);

    expect(CopyTrade::query()->whereNull('follower_contract_id')->count())->toBe(2);
});
