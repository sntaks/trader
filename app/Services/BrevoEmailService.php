<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Thin facade over Laravel's Mail Manager for direct/ad-hoc Brevo sends.
 *
 * Existing Mailable + Notification classes (WelcomeMail, VerifyEmailNotification)
 * route through the Brevo transport automatically — this service is for code paths
 * that need to fire an email without authoring a dedicated Mailable.
 */
class BrevoEmailService
{
    public function sendVerificationEmail(string $toEmail, string $toName, string $verificationLink): bool
    {
        return $this->dispatch(
            toEmail: $toEmail,
            toName: $toName,
            subject: 'Verify your email address — '.config('app.name'),
            htmlView: 'mail.verify-email',
            textView: 'mail.verify-email-plain',
            data: [
                'user' => (object) ['name' => $this->safeName($toName)],
                'verificationUrl' => $verificationLink,
                'appName' => (string) config('app.name'),
            ],
            type: 'verification',
        );
    }

    public function sendWelcomeEmail(string $toEmail, string $toName): bool
    {
        return $this->dispatch(
            toEmail: $toEmail,
            toName: $toName,
            subject: 'Welcome to '.config('app.name').'!',
            htmlView: 'mail.welcome',
            textView: 'mail.welcome-plain',
            data: [
                'user' => (object) ['name' => $this->safeName($toName)],
            ],
            type: 'welcome',
        );
    }

    /**
     * Generic ad-hoc send. Caller supplies pre-rendered HTML content.
     */
    public function sendEmail(string $toEmail, string $toName, string $subject, string $htmlContent, ?string $textContent = null): bool
    {
        try {
            Mail::send([], [], function ($message) use ($toEmail, $toName, $subject, $htmlContent, $textContent) {
                $message->to($toEmail, $toName)->subject($subject)->html($htmlContent);

                if ($textContent !== null && $textContent !== '') {
                    $message->text($textContent);
                }
            });

            return true;
        } catch (Throwable $e) {
            Log::error('Brevo email send failed', [
                'timestamp' => now()->toIso8601String(),
                'recipient' => $toEmail,
                'type' => 'generic',
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function dispatch(string $toEmail, string $toName, string $subject, string $htmlView, string $textView, array $data, string $type): bool
    {
        try {
            Mail::send(
                ['html' => $htmlView, 'text' => $textView],
                $data,
                function ($message) use ($toEmail, $toName, $subject) {
                    $message->to($toEmail, $toName)->subject($subject);
                }
            );

            return true;
        } catch (Throwable $e) {
            Log::error('Brevo email send failed', [
                'timestamp' => now()->toIso8601String(),
                'recipient' => $toEmail,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The name as stored, trimmed. Not escaped here: the HTML templates escape it with {{ }}, and
     * escaping twice would show entities such as &#039; in the email.
     */
    private function safeName(string $name): string
    {
        return trim($name);
    }
}
