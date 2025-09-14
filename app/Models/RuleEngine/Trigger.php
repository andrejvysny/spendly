<?php

namespace App\Models\RuleEngine;

enum Trigger: string
{
    case TRANSACTION_CREATED = 'transaction_created';
    case TRANSACTION_UPDATED = 'transaction_updated';
    case TRANSACTION_DELETED = 'transaction_deleted';
    case MANUAL = 'manual';

}
