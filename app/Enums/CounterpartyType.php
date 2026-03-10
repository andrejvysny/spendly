<?php

declare(strict_types=1);

namespace App\Enums;

enum CounterpartyType: string
{
    case MERCHANT = 'merchant';
    case PERSON = 'person';
    case INSTITUTION = 'institution';
    case EMPLOYER = 'employer';
    case OTHER = 'other';
}
