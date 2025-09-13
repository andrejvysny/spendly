<?php

namespace App\Models\RuleEngine;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleExecutionLog extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'rule_id',
        'transaction_id',
        'matched',
        'actions_executed',
        'execution_context',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'matched' => 'boolean',
        'actions_executed' => 'array',
        'execution_context' => 'array',
    ];

    /**
     * Get the rule that was executed.
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * Scope a query to only include matched executions.
     */
    public function scopeMatched($query)
    {
        return $query->where('matched', true);
    }

    /**
     * Scope a query to only include non-matched executions.
     */
    public function scopeNotMatched($query)
    {
        return $query->where('matched', false);
    }

    /**
     * Get execution logs for a specific transaction.
     */
    public function scopeForTransaction($query, string $transactionId)
    {
        return $query->where('transaction_id', $transactionId);
    }
}
