<?php

namespace App\Notifications\Contracts;

use App\Notifications\DTO\MessageDTO;

interface WhatsappProviderInterface
{
    public function send(MessageDTO $message): array;
}
