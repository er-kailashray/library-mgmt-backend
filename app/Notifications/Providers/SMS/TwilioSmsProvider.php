<?php

namespace App\Notifications\Providers\SMS;

use App\Notifications\Contracts\SmsProviderInterface;
use App\Notifications\DTO\MessageDTO;
use Twilio\Rest\Client;

class TwilioSmsProvider implements SmsProviderInterface
{
    protected Client $client;

    public function __construct() 
    {
        $this->client = new Client(config('services.twilio.sid'), config('services.twilio.token'));
    }

    public function send(MessageDTO $message): array
    {
        $response = $this->client->messages->create(
            $message->to,
            [
                'from' => config('services.twilio.sms_from'),
                'body' => $message->message,
            ]
        );

        return [
            'sid' => $response->sid,
            'status' => $response->status,
        ];
    }
}
