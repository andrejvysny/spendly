<?php

namespace App\Models\RuleEngine;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rule extends Model
{
    use HasFactory;

    /**
     * Trigger type constants.
     */
    const TRIGGER_TRANSACTION_CREATED = 'transaction_created';

    const TRIGGER_TRANSACTION_UPDATED = 'transaction_updated';

    const TRIGGER_MANUAL = 'manual';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'user_id',
        'rule_group_id',
        'name',
        'description',
        'trigger_type',
        'stop_processing',
        'order',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'stop_processing' => 'boolean',
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Get the user that owns the rule.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the rule group that owns the rule.
     */
    public function ruleGroup(): BelongsTo
    {
        return $this->belongsTo(RuleGroup::class);
    }

    /**
     * Get the condition groups for the rule.
     */
    public function conditionGroups(): HasMany
    {
        return $this->hasMany(ConditionGroup::class);
    }

    /**
     * Get the actions for the rule.
     */
    public function actions(): HasMany
    {
        return $this->hasMany(RuleAction::class);
    }

    /**
     * Get the execution logs for the rule.
     */
    public function executionLogs(): HasMany
    {
        return $this->hasMany(RuleExecutionLog::class);
    }

    /**
     * Scope a query to only include active rules.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to filter by trigger type.
     */
    public function scopeByTrigger($query, string $triggerType)
    {
        return $query->where('trigger_type', $triggerType);
    }

    /**
     * Check if the rule has stop processing enabled.
     */
    public function shouldStopProcessing(): bool
    {
        return $this->stop_processing;
    }

    /**
     * Get available trigger types.
     */
    public static function getTriggerTypes(): array
    {
        return [
            self::TRIGGER_TRANSACTION_CREATED,
            self::TRIGGER_TRANSACTION_UPDATED,
            self::TRIGGER_MANUAL,
        ];
    }
}
