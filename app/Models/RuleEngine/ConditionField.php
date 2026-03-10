<?php

namespace App\Models\RuleEngine;

enum ConditionField: string
{
    case FIELD_AMOUNT = 'amount';

    case FIELD_DESCRIPTION = 'description';

    case FIELD_PARTNER = 'partner';

    case FIELD_CATEGORY = 'category';

    case FIELD_COUNTERPARTY = 'counterparty';

    case FIELD_ACCOUNT = 'account';

    case FIELD_TYPE = 'type';

    case FIELD_NOTE = 'note';

    case FIELD_RECIPIENT_NOTE = 'recipient_note';

    case FIELD_PLACE = 'place';

    case FIELD_TARGET_IBAN = 'target_iban';

    case FIELD_SOURCE_IBAN = 'source_iban';

    case FIELD_DATE = 'date';

    case FIELD_TAGS = 'tags';

    case FIELD_CURRENCY = 'currency';

    case FIELD_IS_RECONCILED = 'is_reconciled';

    case FIELD_HAS_CATEGORY = 'has_category';

    case FIELD_HAS_COUNTERPARTY = 'has_counterparty';

    /**
     * Fields that use boolean-style operators (is_empty/is_not_empty only).
     */
    public static function booleanFields(): array
    {
        return [
            self::FIELD_IS_RECONCILED,
            self::FIELD_HAS_CATEGORY,
            self::FIELD_HAS_COUNTERPARTY,
        ];
    }
}
