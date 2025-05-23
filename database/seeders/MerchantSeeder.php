<?php

namespace Database\Seeders;

use App\Models\Merchant;
use App\Models\User;
use Illuminate\Database\Seeder;

class MerchantSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'demo@example.com')->first();

        $merchants = [
            [
                'name' => 'Netflix',
                'description' => 'Streaming service',
                'logo' => 'Film',
            ],
            [
                'name' => 'Spotify',
                'description' => 'Music streaming service',
                'logo' => 'Music',
            ],
            [
                'name' => 'Amazon',
                'description' => 'Online shopping',
                'logo' => 'ShoppingBag',
            ],
            [
                'name' => 'Uber',
                'description' => 'Ride sharing service',
                'logo' => 'Car',
            ],
            [
                'name' => 'Starbucks',
                'description' => 'Coffee shop',
                'logo' => 'Coffee',
            ],
            [
                'name' => 'McDonald\'s',
                'description' => 'Fast food restaurant',
                'logo' => 'Utensils',
            ],
            [
                'name' => 'Walmart',
                'description' => 'Retail store',
                'logo' => 'Store',
            ],
            [
                'name' => 'Shell',
                'description' => 'Gas station',
                'logo' => 'Droplet',
            ],
            [
                'name' => 'AT&T',
                'description' => 'Mobile carrier',
                'logo' => 'Smartphone',
            ],
            [
                'name' => 'Comcast',
                'description' => 'Internet provider',
                'logo' => 'Wifi',
            ],
        ];

        foreach ($merchants as $merchant) {
            Merchant::create([
                'name' => $merchant['name'],
                'description' => $merchant['description'],
                'logo' => $merchant['logo'],
                'user_id' => $user->id,
            ]);
        }
    }
}
