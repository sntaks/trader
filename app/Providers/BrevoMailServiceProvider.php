<?php

namespace App\Providers;

use App\Mail\Transport\BrevoTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

class BrevoMailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Mail::extend('brevo', function (): BrevoTransport {
            return new BrevoTransport(
                apiKey: (string) config('services.brevo.key'),
                defaultSenderEmail: (string) config('services.brevo.sender_email'),
                defaultSenderName: (string) config('services.brevo.sender_name'),
                endpoint: (string) config('services.brevo.endpoint'),
            );
        });
    }
}
