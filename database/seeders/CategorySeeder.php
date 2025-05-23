<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'demo@example.com')->first();

        // Income categories
        $income = Category::create([
            'name' => 'Income',
            'description' => 'All income related transactions',
            'color' => '#22c55e',
            'icon' => 'DollarSign',
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Salary',
            'description' => 'Monthly salary payments',
            'color' => '#22c55e',
            'icon' => 'Briefcase',
            'parent_category_id' => $income->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Freelance',
            'description' => 'Freelance work income',
            'color' => '#22c55e',
            'icon' => 'Laptop',
            'parent_category_id' => $income->id,
            'user_id' => $user->id,
        ]);

        // Expenses categories
        $expenses = Category::create([
            'name' => 'Expenses',
            'description' => 'All expense related transactions',
            'color' => '#ef4444',
            'icon' => 'ShoppingBag',
            'user_id' => $user->id,
        ]);

        // Housing
        $housing = Category::create([
            'name' => 'Housing',
            'description' => 'Housing related expenses',
            'color' => '#f97316',
            'icon' => 'Home',
            'parent_category_id' => $expenses->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Rent',
            'description' => 'Monthly rent payments',
            'color' => '#f97316',
            'icon' => 'Building',
            'parent_category_id' => $housing->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Utilities',
            'description' => 'Utility bills',
            'color' => '#f97316',
            'icon' => 'Wifi',
            'parent_category_id' => $housing->id,
            'user_id' => $user->id,
        ]);

        // Food
        $food = Category::create([
            'name' => 'Food',
            'description' => 'Food and dining expenses',
            'color' => '#eab308',
            'icon' => 'Utensils',
            'parent_category_id' => $expenses->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Groceries',
            'description' => 'Grocery shopping',
            'color' => '#eab308',
            'icon' => 'ShoppingBag',
            'parent_category_id' => $food->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Restaurants',
            'description' => 'Dining out',
            'color' => '#eab308',
            'icon' => 'Utensils',
            'parent_category_id' => $food->id,
            'user_id' => $user->id,
        ]);

        // Transportation
        $transport = Category::create([
            'name' => 'Transportation',
            'description' => 'Transportation expenses',
            'color' => '#3b82f6',
            'icon' => 'Car',
            'parent_category_id' => $expenses->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Public Transport',
            'description' => 'Public transportation costs',
            'color' => '#3b82f6',
            'icon' => 'Bus',
            'parent_category_id' => $transport->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Fuel',
            'description' => 'Vehicle fuel expenses',
            'color' => '#3b82f6',
            'icon' => 'Droplet',
            'parent_category_id' => $transport->id,
            'user_id' => $user->id,
        ]);

        // Entertainment
        $entertainment = Category::create([
            'name' => 'Entertainment',
            'description' => 'Entertainment expenses',
            'color' => '#8b5cf6',
            'icon' => 'Film',
            'parent_category_id' => $expenses->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Movies',
            'description' => 'Movie tickets and streaming',
            'color' => '#8b5cf6',
            'icon' => 'Film',
            'parent_category_id' => $entertainment->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Games',
            'description' => 'Gaming expenses',
            'color' => '#8b5cf6',
            'icon' => 'Gamepad2',
            'parent_category_id' => $entertainment->id,
            'user_id' => $user->id,
        ]);
    }
}
