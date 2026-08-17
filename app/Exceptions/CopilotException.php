<?php

namespace App\Exceptions;

use Exception;

class CopilotException extends Exception
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 503,
        public readonly ?string $reference = null,
    ) {
        parent::__construct($message);
    }
}
