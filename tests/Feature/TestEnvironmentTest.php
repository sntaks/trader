<?php

test('the test environment never carries real deriv revenue tokens', function (string $key) {
    expect(config($key))->toBeEmpty();
})->with(['deriv.partner_token', 'deriv.affiliate_token', 'deriv.affiliate_id']);
