<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'demo@example.com')->firstOrFail();

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

        // Subscriptions
        $subscriptions = Category::create([
            'name' => 'Subscriptions',
            'description' => 'Recurring subscription services',
            'color' => '#a855f7',
            'icon' => 'Repeat',
            'parent_category_id' => $expenses->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Streaming',
            'description' => 'Video and music streaming services',
            'color' => '#a855f7',
            'icon' => 'Tv',
            'parent_category_id' => $subscriptions->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Software',
            'description' => 'Software subscriptions',
            'color' => '#a855f7',
            'icon' => 'Monitor',
            'parent_category_id' => $subscriptions->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Cloud Services',
            'description' => 'Cloud hosting and services',
            'color' => '#a855f7',
            'icon' => 'Cloud',
            'parent_category_id' => $subscriptions->id,
            'user_id' => $user->id,
        ]);

        // Health
        $health = Category::create([
            'name' => 'Health',
            'description' => 'Health and wellness expenses',
            'color' => '#ef4444',
            'icon' => 'Heart',
            'parent_category_id' => $expenses->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Gym',
            'description' => 'Gym membership and fitness',
            'color' => '#ef4444',
            'icon' => 'Dumbbell',
            'parent_category_id' => $health->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Pharmacy',
            'description' => 'Pharmacy and medication',
            'color' => '#ef4444',
            'icon' => 'Pill',
            'parent_category_id' => $health->id,
            'user_id' => $user->id,
        ]);

        // Shopping
        $shopping = Category::create([
            'name' => 'Shopping',
            'description' => 'General shopping expenses',
            'color' => '#ec4899',
            'icon' => 'ShoppingCart',
            'parent_category_id' => $expenses->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Clothing',
            'description' => 'Clothing and apparel',
            'color' => '#ec4899',
            'icon' => 'Shirt',
            'parent_category_id' => $shopping->id,
            'user_id' => $user->id,
        ]);

        Category::create([
            'name' => 'Electronics',
            'description' => 'Electronics and gadgets',
            'color' => '#ec4899',
            'icon' => 'Smartphone',
            'parent_category_id' => $shopping->id,
            'user_id' => $user->id,
        ]);

        // Insurance (leaf under Expenses)
        Category::create([
            'name' => 'Insurance',
            'description' => 'Insurance premiums',
            'color' => '#64748b',
            'icon' => 'Shield',
            'parent_category_id' => $expenses->id,
            'user_id' => $user->id,
        ]);
    }
}
