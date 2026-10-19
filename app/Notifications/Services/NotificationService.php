<?php

namespace App\Notifications\Services;

use App\Notifications\DTO\MessageDTO;
use App\Notifications\Factory\ProviderFactory;

class NotificationService
{
    public function send(string $channel, MessageDTO $dto) 
    {
        $provider = ProviderFactory::make($channel);
        return $provider->send($dto);
    }
}
