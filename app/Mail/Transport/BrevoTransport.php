<?php

namespace App\Mail\Transport;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class BrevoTransport extends AbstractTransport
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $defaultSenderEmail,
        private readonly string $defaultSenderName,
        private readonly string $endpoint,
    ) {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $payload = $this->buildPayload($email);

        $response = Http::withHeaders([
            'api-key' => $this->apiKey,
            'accept' => 'application/json',
            'content-type' => 'application/json',
        ])->timeout(15)->post($this->endpoint, $payload);

        if ($response->failed()) {
            throw new RuntimeException(
                'Brevo API error ['.$response->status().']: '.$response->body()
            );
        }

        $data = $response->json();

        if (is_array($data) && isset($data['messageId'])) {
            $message->setMessageId((string) $data['messageId']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(Email $email): array
    {
        $fromList = $email->getFrom();
        $from = $fromList[0] ?? new Address($this->defaultSenderEmail, $this->defaultSenderName);

        $payload = [
            'sender' => [
                'email' => $from->getAddress() ?: $this->defaultSenderEmail,
                'name' => $from->getName() !== '' ? $from->getName() : $this->defaultSenderName,
            ],
            'to' => $this->formatAddresses($email->getTo()),
            'subject' => (string) ($email->getSubject() ?? ''),
        ];

        if ($cc = $email->getCc()) {
            $payload['cc'] = $this->formatAddresses($cc);
        }

        if ($bcc = $email->getBcc()) {
            $payload['bcc'] = $this->formatAddresses($bcc);
        }

        if ($replyToList = $email->getReplyTo()) {
            $replyTo = $replyToList[0];
            $payload['replyTo'] = ['email' => $replyTo->getAddress()];

            if ($replyTo->getName() !== '') {
                $payload['replyTo']['name'] = $replyTo->getName();
            }
        }

        if ($html = $email->getHtmlBody()) {
            $payload['htmlContent'] = (string) $html;
        }

        if ($text = $email->getTextBody()) {
            $payload['textContent'] = (string) $text;
        }

        return $payload;
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, array<string, string>>
     */
    private function formatAddresses(array $addresses): array
    {
        return array_map(static function (Address $address): array {
            $entry = ['email' => $address->getAddress()];

            if ($address->getName() !== '') {
                $entry['name'] = $address->getName();
            }

            return $entry;
        }, $addresses);
    }

    public function __toString(): string
    {
        return 'brevo';
    }
}
