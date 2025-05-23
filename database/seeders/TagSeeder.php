<?php

namespace Database\Seeders;

use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'demo@example.com')->first();

        $tags = [
            [
                'name' => 'Recurring',
                'color' => '#3b82f6',
            ],
            [
                'name' => 'Business',
                'color' => '#8b5cf6',
            ],
            [
                'name' => 'Personal',
                'color' => '#ec4899',
            ],
            [
                'name' => 'Travel',
                'color' => '#f59e0b',
            ],
            [
                'name' => 'Health',
                'color' => '#ef4444',
            ],
            [
                'name' => 'Education',
                'color' => '#10b981',
            ],
            [
                'name' => 'Investment',
                'color' => '#6366f1',
            ],
            [
                'name' => 'Gift',
                'color' => '#f43f5e',
            ],
        ];

        foreach ($tags as $tag) {
            Tag::create([
                'name' => $tag['name'],
                'color' => $tag['color'],
                'user_id' => $user->id,
            ]);
        }
    }
}
