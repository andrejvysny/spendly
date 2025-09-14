<?php

namespace App\Models\RuleEngine;

use League\Uri\UriTemplate\Operator;

enum ConditionOperator: string
{

    case OPERATOR_EQUALS = 'equals';

    case OPERATOR_NOT_EQUALS = 'not_equals';

    case OPERATOR_CONTAINS = 'contains';

    case OPERATOR_NOT_CONTAINS = 'not_contains';

    case OPERATOR_STARTS_WITH = 'starts_with';

    case OPERATOR_ENDS_WITH = 'ends_with';

    case OPERATOR_GREATER_THAN = 'greater_than';

    case OPERATOR_GREATER_THAN_OR_EQUAL = 'greater_than_or_equal';

    case OPERATOR_LESS_THAN = 'less_than';

    case OPERATOR_LESS_THAN_OR_EQUAL = 'less_than_or_equal';

    case OPERATOR_REGEX = 'regex';

    case OPERATOR_WILDCARD = 'wildcard';

    case OPERATOR_IS_EMPTY = 'is_empty';

    case OPERATOR_IS_NOT_EMPTY = 'is_not_empty';

    case OPERATOR_IN = 'in';

    case OPERATOR_NOT_IN = 'not_in';

    case OPERATOR_BETWEEN = 'between';


    public static function string(): array
    {
     return [
         self::OPERATOR_EQUALS,
         self::OPERATOR_NOT_EQUALS,
         self::OPERATOR_CONTAINS,
         self::OPERATOR_NOT_CONTAINS,
         self::OPERATOR_STARTS_WITH,
         self::OPERATOR_ENDS_WITH,
         self::OPERATOR_REGEX,
         self::OPERATOR_WILDCARD,
         self::OPERATOR_IS_EMPTY,
         self::OPERATOR_IS_NOT_EMPTY,
         self::OPERATOR_IN,
         self::OPERATOR_NOT_IN,
     ];
    }

    public static function numeric(): array
    {
        return [
            self::OPERATOR_EQUALS,
            self::OPERATOR_NOT_EQUALS,
            self::OPERATOR_GREATER_THAN,
            self::OPERATOR_GREATER_THAN_OR_EQUAL,
            self::OPERATOR_LESS_THAN,
            self::OPERATOR_LESS_THAN_OR_EQUAL,
            self::OPERATOR_BETWEEN,
        ];
    }

    public static function validateOperator(Operator|string $operator): bool
    {
        return in_array($operator, array_column(self::cases(), 'value'));
    }


}
