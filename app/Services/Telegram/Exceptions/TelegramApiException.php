<?php

namespace App\Services\Telegram\Exceptions;

use RuntimeException;

/**
 * Thrown when the Telegram Bot API returns an error or the HTTP call fails.
 */
class TelegramApiException extends RuntimeException {}
