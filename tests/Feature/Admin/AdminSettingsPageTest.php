<?php

use App\Models\User;

test('admin settings show the configured deriv setup without secrets', function () {
    config(['deriv.oauth_scopes' => 'trade', 'deriv.partner_token' => 'pat_secret', 'deriv.affiliate_token' => null]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.settings'))
        ->assertSuccessful()
        ->assertDontSee('account_manage')
        ->assertDontSee('ws.binaryws.com')
        ->assertDontSee('pat_secret')
        ->assertSeeInOrder(['Partner Token', 'Configured'])
        ->assertSeeInOrder(['Affiliate Token', 'Not set']);
});

test('admin settings never show the affiliate token itself', function () {
    config(['deriv.affiliate_token' => 'aff_secret']);

    $this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('admin.settings'))
        ->assertDontSee('aff_secret')
        ->assertSeeInOrder(['Affiliate Token', 'Configured']);
});

test('the legacy oauth url is gone from config', function () {
    expect(config()->has('deriv.oauth_url'))->toBeFalse();
});
