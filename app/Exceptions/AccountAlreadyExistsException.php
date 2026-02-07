<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;

class AccountAlreadyExistsException extends Exception
{
    public function __construct(string $message = 'Account already exists', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
