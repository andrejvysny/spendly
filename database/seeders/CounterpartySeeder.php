<?php

namespace Database\Seeders;

use App\Models\Counterparty;
use App\Models\User;
use Illuminate\Database\Seeder;

class CounterpartySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'demo@example.com')->first();

        $counterparties = [
            ['name' => 'Netflix', 'description' => 'Streaming service', 'logo' => 'Film', 'type' => 'merchant'],
            ['name' => 'Spotify', 'description' => 'Music streaming service', 'logo' => 'Music', 'type' => 'merchant'],
            ['name' => 'Amazon', 'description' => 'Online shopping', 'logo' => 'ShoppingBag', 'type' => 'merchant'],
            ['name' => 'Uber', 'description' => 'Ride sharing service', 'logo' => 'Car', 'type' => 'merchant'],
            ['name' => 'Starbucks', 'description' => 'Coffee shop', 'logo' => 'Coffee', 'type' => 'merchant'],
            ['name' => 'McDonald\'s', 'description' => 'Fast food restaurant', 'logo' => 'Utensils', 'type' => 'merchant'],
            ['name' => 'Walmart', 'description' => 'Retail store', 'logo' => 'Store', 'type' => 'merchant'],
            ['name' => 'Shell', 'description' => 'Gas station', 'logo' => 'Droplet', 'type' => 'merchant'],
            ['name' => 'John Doe', 'description' => 'Friend', 'logo' => 'User', 'type' => 'person'],
            ['name' => 'Jane Smith', 'description' => 'Family', 'logo' => 'User', 'type' => 'person'],
            ['name' => 'Tax Office', 'description' => 'Government tax authority', 'logo' => 'Building', 'type' => 'institution'],
            ['name' => 'Health Insurance Co.', 'description' => 'Health insurance provider', 'logo' => 'Shield', 'type' => 'institution'],
            ['name' => 'Acme Corp', 'description' => 'Employer', 'logo' => 'Briefcase', 'type' => 'employer'],
        ];

        foreach ($counterparties as $counterparty) {
            Counterparty::create([
                'name' => $counterparty['name'],
                'description' => $counterparty['description'],
                'logo' => $counterparty['logo'],
                'type' => $counterparty['type'],
                'user_id' => $user->id,
            ]);
        }
    }
}
