<?php

namespace App\Models\RuleEngine;

use App\Models\User;
use Database\Factories\RuleGroupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RuleGroup extends Model
{
    use HasFactory;

    /**
     * These models live in App\Models\RuleEngine, so Laravel looks for a factory in
     * Database\Factories\RuleEngine — which does not exist. Point it at the real one.
     */
    protected static function newFactory(): RuleGroupFactory
    {
        return RuleGroupFactory::new();
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'order',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'order' => 'integer',
    ];

    /**
     * Get the user that owns the rule group.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the rules for the rule group.
     */
    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class);
    }

    /**
     * Get active rules for the rule group.
     */
    public function activeRules(): HasMany
    {
        return $this->rules()->where('is_active', true)->orderBy('order');
    }

    /**
     * Scope a query to only include active rule groups.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to order by the order column.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }
}
