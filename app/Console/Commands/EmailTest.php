<?php

namespace App\Console\Commands;

use App\Services\BrevoEmailService;
use Illuminate\Console\Command;

class EmailTest extends Command
{
    protected $signature = 'email:test
        {recipient : The destination email address}
        {--type=generic : verify | welcome | generic}
        {--name=Test User : Display name for the recipient}';

    protected $description = 'Send a test email through the configured mailer (Brevo) to verify integration end-to-end.';

    public function handle(BrevoEmailService $service): int
    {
        $recipient = (string) $this->argument('recipient');
        $type = (string) $this->option('type');
        $name = (string) $this->option('name');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid recipient email: {$recipient}");

            return self::FAILURE;
        }

        $this->info('Mailer: '.config('mail.default'));
        $this->info('Sender: '.config('services.brevo.sender_email').' ('.config('services.brevo.sender_name').')');
        $this->info("Sending [{$type}] email to {$recipient}...");

        $result = match ($type) {
            'verify' => $service->sendVerificationEmail(
                $recipient,
                $name,
                url('/email/verify/test-token-'.bin2hex(random_bytes(8))),
            ),
            'welcome' => $service->sendWelcomeEmail($recipient, $name),
            'generic' => $service->sendEmail(
                $recipient,
                $name,
                'Brevo Test — '.config('app.name'),
                '<p>This is a test email from <strong>'.e(config('app.name')).'</strong>.</p>'
                .'<p>If you can read this, the Brevo HTTPS API integration is working.</p>',
                'This is a test email from '.config('app.name').".\n\nIf you can read this, the Brevo HTTPS API integration is working.",
            ),
            default => null,
        };

        if ($result === null) {
            $this->error("Unknown type [{$type}]. Use verify, welcome, or generic.");

            return self::FAILURE;
        }

        if ($result) {
            $this->info('Email dispatched successfully.');

            return self::SUCCESS;
        }

        $this->error('Email failed — see storage/logs/laravel.log for details.');

        return self::FAILURE;
    }
}
