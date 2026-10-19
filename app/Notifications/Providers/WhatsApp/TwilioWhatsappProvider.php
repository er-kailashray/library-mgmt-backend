<?php

namespace App\Notifications\Providers\WhatsApp;

use App\Notifications\Contracts\WhatsappProviderInterface;
use App\Notifications\DTO\MessageDTO;
use Twilio\Rest\Client;

class TwilioWhatsappProvider implements WhatsappProviderInterface
{
    protected Client $client;

    public function __construct() 
    {
        $this->client = new Client(config('services.twilio.sid'), config('services.twilio.token'));
    }

    public function send(MessageDTO $message): array
    {
        $response = $this->client->messages->create(
            "whatsapp:{$message->to}",
            [
                'from' => config('services.twilio.whatsapp_from'),
                'body' => $message->message,
            ]
        );

        return [
            'sid' => $response->sid,
            'status' => $response->status,
        ];
    }
}


