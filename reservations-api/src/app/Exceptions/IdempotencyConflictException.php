<?php

namespace App\Exceptions;

use RuntimeException;

class IdempotencyConflictException extends RuntimeException
{
    public function __construct(public readonly string $requestId)
    {
        parent::__construct("Request id [{$requestId}] was already used with a different payload.");
    }
}
