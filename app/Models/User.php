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
            'gocardless_token_expires_at' => 'datetime',
        ];
    }

    /**
     * Get the accounts for the user.
     */
    public function accounts()
    {
        return $this->hasMany(\App\Models\Account::class);
    }

    /**
     * Get the categories for the user.
     */
    public function categories()
    {
        return $this->hasMany(\App\Models\Category::class);
    }

    /**
     * Get the merchants for the user.
     */
    public function merchants()
    {
        return $this->hasMany(\App\Models\Merchant::class);
    }

    /**
     * Get the tags for the user.
     */
    public function tags()
    {
        return $this->hasMany(\App\Models\Tag::class);
    }
}
