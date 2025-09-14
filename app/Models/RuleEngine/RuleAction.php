<?php

namespace App\Models\RuleEngine;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RuleAction extends Model
{
    use HasFactory;


    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'rule_id',
        'action_type',
        'action_value',
        'order',
        'stop_processing',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'stop_processing' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Get the rule that owns the action.
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(Rule::class);
    }

    /**
     * Get decoded action value.
     */
    public function getDecodedValue()
    {
        if ($this->action_value === null || $this->action_value === '') {
            return $this->action_value;
        }

        $decoded = json_decode($this->action_value, true);

        // Check for JSON errors
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Return the raw value if JSON decoding fails
            return $this->action_value;
        }

        return $decoded;
    }

    /**
     * Set encoded action value.
     */
    public function setEncodedValue($value): void
    {
        $this->action_value = is_array($value) || is_object($value)
            ? json_encode($value)
            : $value;
    }



}
