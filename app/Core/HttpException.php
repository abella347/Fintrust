<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Thrown anywhere during an API request; the front controller turns it into a JSON error response. */
class HttpException extends RuntimeException
{
    /**
     * @param array<string, string> $errors field => message
     */
    public function __construct(
        private readonly int $status,
        string $message,
        private readonly array $errors = [],
        private readonly string $errorCode = ''
    ) {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /** Machine-readable code such as INSUFFICIENT_FUNDS, so the frontend can react. */
    public function errorCode(): string
    {
        return $this->errorCode !== '' ? $this->errorCode : match ($this->status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHENTICATED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            409 => 'CONFLICT',
            422 => 'VALIDATION_FAILED',
            423 => 'LOCKED',
            429 => 'TOO_MANY_REQUESTS',
            default => 'SERVER_ERROR',
        };
    }
}
