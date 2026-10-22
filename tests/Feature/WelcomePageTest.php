<?php

test('the pricing faq says markup lowers the payout', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertDontSee('does not add any visible fee')
        ->assertSee('the payout on a winning trade is slightly lower');
});

test('the welcome page calls the pattern the follower pattern', function () {
    $this->get(route('home'))
        ->assertSuccessful()
        ->assertSee('Follower Pattern')
        ->assertDontSee('Slave')
        ->assertDontSee('slave');
});
