<?php

namespace App\Services\TransactionImport;

enum ImportFailureStatus: string
{
    case PENDING = 'pending';
    case REVIEWED = 'reviewed';
    case RESOLVED = 'resolved';
    case IGNORED = 'ignored';
}
