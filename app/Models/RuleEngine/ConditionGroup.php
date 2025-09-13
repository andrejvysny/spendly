<?php

namespace App\Models\RuleEngine;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConditionGroup extends Model
{
    use HasFactory;

    /**
     * Logic operator constants.
     */
    const LOGIC_AND = 'AND';

    const LOGIC_OR = 'OR';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'rule_id',
        'logic_operator',
        'order',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'order' => 'integer',
    ];

    /**
     * Get the rule that owns the condition group.
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * Get the conditions for the condition group.
     */
    public function conditions(): HasMany
    {
        return $this->hasMany(RuleCondition::class);
    }

    /**
     * Get the conditions ordered by their order field.
     */
    public function orderedConditions(): HasMany
    {
        return $this->conditions()->orderBy('order');
    }

    /**
     * Check if the group uses AND logic.
     */
    public function isAndLogic(): bool
    {
        return $this->logic_operator === self::LOGIC_AND;
    }

    /**
     * Check if the group uses OR logic.
     */
    public function isOrLogic(): bool
    {
        return $this->logic_operator === self::LOGIC_OR;
    }

    /**
     * Get available logic operators.
     */
    public static function getLogicOperators(): array
    {
        return [
            self::LOGIC_AND,
            self::LOGIC_OR,
        ];
    }
}
