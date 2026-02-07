<?php

namespace App\Models\RuleEngine;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleCondition extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
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
        'operator' => ConditionOperator::class,
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
     * Get all available condition fields.
     *
     * @return array<string>
     */
    public static function getFields(): array
    {
        return array_map(fn (ConditionField $field) => $field->value, ConditionField::cases());
    }

    /**
     * Get all available condition operators.
     *
     * @return array<string>
     */
    public static function getOperators(): array
    {
        return array_map(fn (ConditionOperator $operator) => $operator->value, ConditionOperator::cases());
    }
}
