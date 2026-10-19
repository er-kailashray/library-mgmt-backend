<?php 

namespace App\Notifications\Factory;

use App\Notifications\Providers\SMS\TwilioSmsProvider;
use App\Notifications\Providers\WhatsApp\TwilioWhatsappProvider;
use Exception;

class ProviderFactory
{
    public static function make(string $channel)
    {
        return match($channel) {

            'sms' => app(TwilioSmsProvider::class),

            'whatsapp' => app(TwilioWhatsappProvider::class),

            default => throw new Exception('Invalid channel')
        };
    }
}
