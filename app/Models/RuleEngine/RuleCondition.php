<?php

namespace App\Models\RuleEngine;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleCondition extends Model
{
    use HasFactory;

    /**
     * Operator constants.
     */
    const OPERATOR_EQUALS = 'equals';

    const OPERATOR_NOT_EQUALS = 'not_equals';

    const OPERATOR_CONTAINS = 'contains';

    const OPERATOR_NOT_CONTAINS = 'not_contains';

    const OPERATOR_STARTS_WITH = 'starts_with';

    const OPERATOR_ENDS_WITH = 'ends_with';

    const OPERATOR_GREATER_THAN = 'greater_than';

    const OPERATOR_GREATER_THAN_OR_EQUAL = 'greater_than_or_equal';

    const OPERATOR_LESS_THAN = 'less_than';

    const OPERATOR_LESS_THAN_OR_EQUAL = 'less_than_or_equal';

    const OPERATOR_REGEX = 'regex';

    const OPERATOR_WILDCARD = 'wildcard';

    const OPERATOR_IS_EMPTY = 'is_empty';

    const OPERATOR_IS_NOT_EMPTY = 'is_not_empty';

    const OPERATOR_IN = 'in';

    const OPERATOR_NOT_IN = 'not_in';

    const OPERATOR_BETWEEN = 'between';

    /**
     * Field constants.
     */
    const FIELD_AMOUNT = 'amount';

    const FIELD_DESCRIPTION = 'description';

    const FIELD_PARTNER = 'partner';

    const FIELD_CATEGORY = 'category';

    const FIELD_MERCHANT = 'merchant';

    const FIELD_ACCOUNT = 'account';

    const FIELD_TYPE = 'type';

    const FIELD_NOTE = 'note';

    const FIELD_RECIPIENT_NOTE = 'recipient_note';

    const FIELD_PLACE = 'place';

    const FIELD_TARGET_IBAN = 'target_iban';

    const FIELD_SOURCE_IBAN = 'source_iban';

    const FIELD_DATE = 'date';

    const FIELD_TAGS = 'tags';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'condition_group_id',
        'field',
        'operator',
        'value',
        'is_case_sensitive',
        'is_negated',
        'order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_case_sensitive' => 'boolean',
        'is_negated' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Get the condition group that owns the condition.
     */
    public function conditionGroup(): BelongsTo
    {
        return $this->belongsTo(ConditionGroup::class);
    }

    /**
     * Get available operators.
     */
    public static function getOperators(): array
    {
        return [
            self::OPERATOR_EQUALS,
            self::OPERATOR_NOT_EQUALS,
            self::OPERATOR_CONTAINS,
            self::OPERATOR_NOT_CONTAINS,
            self::OPERATOR_STARTS_WITH,
            self::OPERATOR_ENDS_WITH,
            self::OPERATOR_GREATER_THAN,
            self::OPERATOR_GREATER_THAN_OR_EQUAL,
            self::OPERATOR_LESS_THAN,
            self::OPERATOR_LESS_THAN_OR_EQUAL,
            self::OPERATOR_REGEX,
            self::OPERATOR_WILDCARD,
            self::OPERATOR_IS_EMPTY,
            self::OPERATOR_IS_NOT_EMPTY,
            self::OPERATOR_IN,
            self::OPERATOR_NOT_IN,
            self::OPERATOR_BETWEEN,
        ];
    }

    /**
     * Get available fields.
     */
    public static function getFields(): array
    {
        return [
            self::FIELD_AMOUNT,
            self::FIELD_DESCRIPTION,
            self::FIELD_PARTNER,
            self::FIELD_CATEGORY,
            self::FIELD_MERCHANT,
            self::FIELD_ACCOUNT,
            self::FIELD_TYPE,
            self::FIELD_NOTE,
            self::FIELD_RECIPIENT_NOTE,
            self::FIELD_PLACE,
            self::FIELD_TARGET_IBAN,
            self::FIELD_SOURCE_IBAN,
            self::FIELD_DATE,
            self::FIELD_TAGS,
        ];
    }

    /**
     * Get numeric operators.
     */
    public static function getNumericOperators(): array
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

    /**
     * Get string operators.
     */
    public static function getStringOperators(): array
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
}
