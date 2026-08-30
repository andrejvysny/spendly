<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where the GoCardless secret pair backing a user's bank sync came from.
 *
 * INSTANCE credentials are shared by every user on the installation, so they must never be
 * echoed back to a user — not even masked. USER credentials belong to one account and may be
 * surfaced (masked) to their owner.
 */
enum GoCardlessCredentialSource: string
{
    case USER = 'user';
    case INSTANCE = 'instance';
}
