<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'gocardless_secret_id',
        'gocardless_secret_key',
        'gocardless_access_token',
        'gocardless_refresh_token',
        'gocardless_refresh_token_expires_at',
        'gocardless_access_token_expires_at',
        'gocardless_country',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'gocardless_secret_id',
        'gocardless_secret_key',
        'gocardless_access_token',
        'gocardless_refresh_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'gocardless_refresh_token_expires_at' => 'datetime',
            'gocardless_access_token_expires_at' => 'datetime',
        ];
    }

    /**
     * Get the accounts for the user.
     */
    public function accounts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Account::class);
    }

    /**
     * Get the categories for the user.
     */
    public function categories(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Category::class);
    }

    /**
     * Get the budgets for the user.
     */
    public function budgets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Budget::class);
    }

    /**
     * Get the merchants for the user.
     */
    public function merchants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Merchant::class);
    }

    /**
     * Get the tags for the user.
     */
    public function tags(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\Tag::class);
    }

    /**
     * Get the rule groups for the user.
     */
    public function ruleGroups(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RuleEngine\RuleGroup::class);
    }

    /**
     * Get the rules for the user.
     */
    public function rules(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(RuleEngine\Rule::class);
    }

    /**
     * Get all transactions for the user through their accounts.
     */
    public function transactions(): \Illuminate\Database\Eloquent\Relations\HasManyThrough
    {
        return $this->hasManyThrough(
            \App\Models\Transaction::class,
            \App\Models\Account::class
        );
    }

    /**
     * Get the recurring groups for the user.
     */
    public function recurringGroups(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\RecurringGroup::class);
    }

    /**
     * Get the recurring detection settings for the user.
     */
    public function recurringDetectionSetting(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\RecurringDetectionSetting::class);
    }

    public function getId(): mixed
    {
        return $this->id;
    }
}
