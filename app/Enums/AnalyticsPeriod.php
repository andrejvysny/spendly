<?php

namespace App\Enums;

enum AnalyticsPeriod: string
{

    case CUSTOM = 'custom';
    case SPECIFIC_MONTH = 'specific_month';
    case CURRENT_MONTH = 'current_month';
    case LAST_3_MONTHS = 'last_3_months';
    case LAST_6_MONTHS = 'last_6_months';
    case CURRENT_YEAR = 'current_year';
    case LAST_YEAR = 'last_year';
}
