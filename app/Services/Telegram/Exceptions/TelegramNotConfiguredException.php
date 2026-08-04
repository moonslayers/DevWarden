<?php

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

/**
 * Thrown when the Telegram bot token is missing from the database settings.
 */
class TelegramNotConfiguredException extends RuntimeException
{
    public function __construct(string $message = 'The Telegram bot token has not been configured.')
    {
        parent::__construct($message);
    }
}
