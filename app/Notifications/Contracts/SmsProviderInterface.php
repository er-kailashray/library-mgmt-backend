<?php

namespace App\Notifications\Contracts;

use App\Notifications\DTO\MessageDTO;

interface SmsProviderInterface
{
    public function send(MessageDTO $message): array;
}
