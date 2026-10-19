<?php

namespace App\Notifications\DTO;

class MessageDTO
{
    public function __construct(public string $to, public string $message, public string $channel = 'sms') 
    {
        
    }
}
