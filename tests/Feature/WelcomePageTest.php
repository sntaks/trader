<?php

test('the pricing faq says markup lowers the payout', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertDontSee('does not add any visible fee')
        ->assertSee('the payout on a winning trade is slightly lower');
});
