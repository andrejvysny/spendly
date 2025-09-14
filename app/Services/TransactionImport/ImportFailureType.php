<?php

namespace App\Services\TransactionImport;

enum ImportFailureType: string
{

    case VALIDATION_ERROR = 'validation_error';
    case SQL_ERROR = 'sql_error';
    case DUPLICATE = 'duplicate';
    case PARSING_ERROR = 'parsing_error';
    case UNKNOWN_ERROR = 'unknown_error';

}
