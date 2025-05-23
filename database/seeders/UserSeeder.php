<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Create admin user
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'remember_token' => null,
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
            'gocardless_access_token' => null,
            'gocardless_refresh_token' => null,
            'gocardless_refresh_token_expires_at' => null,
            'gocardless_access_token_expires_at' => null,
            'gocardless_country' => 'DE',
        ]);

        // Create test user
        User::create([
            'name' => 'Andrej Vysny',
            'email' => 'vysnyandrej@gmail.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'remember_token' => null,
            'gocardless_secret_id' => null,
            'gocardless_secret_key' => null,
            'gocardless_access_token' => null,
            'gocardless_refresh_token' => null,
            'gocardless_refresh_token_expires_at' => null,
            'gocardless_access_token_expires_at' => null,
            'gocardless_country' => 'DE',
        ]);

        User::create([
            'name' => 'Demo User',
            'email' => 'demo@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);
    }
}
