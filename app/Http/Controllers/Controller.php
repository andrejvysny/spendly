<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Auth\User;

abstract class Controller
{

    protected function getAuthUser(): Authenticatable
    {
        return auth()->user();
    }

    protected function getAuthUserId(): int
    {
        return auth()->id();
    }

    protected function logError(\Throwable $e, string $message = 'An error occurred'): void
    {
        Log::error($message, [
            'exception' => $e,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }

}
