<?php

use App\Mail\WelcomeMail;
use App\Models\User;

test('the welcome mail escapes the name once', function () {
    $mail = new WelcomeMail(User::factory()->make(['name' => "O'Brien"]));

    $html = $mail->render();
    $text = view('mail.welcome-plain', ['user' => $mail->user])->render();

    expect($html)->toContain('O&#039;Brien')
        ->not->toContain('&amp;#039;')
        ->and($text)->toContain("Hi O'Brien,");
});
