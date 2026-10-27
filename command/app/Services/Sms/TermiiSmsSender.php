<?php

namespace App\Services\Sms;

use App\Support\Phone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Termii (termii.com), a Nigerian SMS gateway. Use the "dnd" channel for one-time codes so
 * they reach numbers on the Do-Not-Disturb list; it needs a sender ID approved by Termii.
 */
final class TermiiSmsSender implements SmsSender
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $senderId,
        private readonly string $channel = 'dnd',
        private readonly string $baseUrl = 'https://api.ng.termii.com',
    ) {}

    public function send(string $phone, string $message): void
    {
        try {
            $response = Http::baseUrl($this->baseUrl)->acceptJson()->timeout(15)->retry(2, 500, throw: false)
                ->post('/api/sms/send', [
                    'api_key' => $this->apiKey,
                    'to' => Phone::international($phone),
                    'from' => $this->senderId,
                    'sms' => $message,
                    'type' => 'plain',
                    'channel' => $this->channel,
                ]);
        } catch (ConnectionException $e) {
            throw new SmsFailed('Could not reach Termii: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed() || ($response->json('code') !== null && $response->json('code') !== 'ok')) {
            throw new SmsFailed('Termii refused the message: '.$response->status().' '.($response->json('message') ?? $response->body()));
        }
    }
}
