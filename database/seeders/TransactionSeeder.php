<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Category;
use App\Models\Merchant;
use App\Models\Tag;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TransactionSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'demo@example.com')->first();
        $checkingAccount = Account::where('type', 'checking')->where('user_id', $user->id)->first();
        $creditCard = Account::where('type', 'credit')->where('user_id', $user->id)->first();

        // Set initial balances (should match AccountSeeder)
        $checkingBalance = 5000.00;
        $creditBalance = -2500.00;

        // Get categories for this user
        $salaryCategory = Category::where('name', 'Salary')->where('user_id', $user->id)->first();
        $groceriesCategory = Category::where('name', 'Groceries')->where('user_id', $user->id)->first();
        $restaurantsCategory = Category::where('name', 'Restaurants')->where('user_id', $user->id)->first();
        $rentCategory = Category::where('name', 'Rent')->where('user_id', $user->id)->first();
        $utilitiesCategory = Category::where('name', 'Utilities')->where('user_id', $user->id)->first();

        // Get merchants for this user
        $walmart = Merchant::where('name', 'Walmart')->where('user_id', $user->id)->first();
        $starbucks = Merchant::where('name', 'Starbucks')->where('user_id', $user->id)->first();
        $mcdonalds = Merchant::where('name', 'McDonald\'s')->where('user_id', $user->id)->first();

        // Get tags for this user
        $recurringTag = Tag::where('name', 'Recurring')->where('user_id', $user->id)->first();
        $personalTag = Tag::where('name', 'Personal')->where('user_id', $user->id)->first();
        $businessTag = Tag::where('name', 'Business')->where('user_id', $user->id)->first();

        // Create salary transaction
        $checkingBalance += 5000.00;
        $salaryTx = Transaction::create([
            'transaction_id' => 'SAL-'.uniqid(),
            'amount' => 5000.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::now()->startOfMonth(),
            'processed_date' => Carbon::now()->startOfMonth(),
            'description' => 'Monthly Salary',
            'type' => Transaction::TYPE_DEPOSIT,
            'account_id' => $checkingAccount->id,
            'category_id' => $salaryCategory->id,
            'balance_after_transaction' => $checkingBalance,
        ]);
        $salaryTx->tags()->attach($recurringTag->id);

        // Create rent transaction
        $checkingBalance -= 1500.00;
        $rentTx = Transaction::create([
            'transaction_id' => 'RENT-'.uniqid(),
            'amount' => -1500.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::now()->startOfMonth()->addDays(2),
            'processed_date' => Carbon::now()->startOfMonth()->addDays(2),
            'description' => 'Monthly Rent',
            'type' => Transaction::TYPE_TRANSFER,
            'account_id' => $checkingAccount->id,
            'category_id' => $rentCategory->id,
            'balance_after_transaction' => $checkingBalance,
        ]);
        $rentTx->tags()->attach($recurringTag->id);

        // Create utilities transaction
        $checkingBalance -= 200.00;
        $utilTx = Transaction::create([
            'transaction_id' => 'UTIL-'.uniqid(),
            'amount' => -200.00,
            'currency' => 'EUR',
            'booked_date' => Carbon::now()->startOfMonth()->addDays(5),
            'processed_date' => Carbon::now()->startOfMonth()->addDays(5),
            'description' => 'Monthly Utilities',
            'type' => Transaction::TYPE_TRANSFER,
            'account_id' => $checkingAccount->id,
            'category_id' => $utilitiesCategory->id,
            'balance_after_transaction' => $checkingBalance,
        ]);
        $utilTx->tags()->attach($recurringTag->id);

        // Create grocery transactions
        for ($i = 0; $i < 4; $i++) {
            $creditBalance -= 150.00;
            $grocTx = Transaction::create([
                'transaction_id' => 'GROC-'.uniqid(),
                'amount' => -150.00,
                'currency' => 'EUR',
                'booked_date' => Carbon::now()->startOfMonth()->addDays(rand(7, 25)),
                'processed_date' => Carbon::now()->startOfMonth()->addDays(rand(7, 25)),
                'description' => 'Grocery Shopping',
                'type' => Transaction::TYPE_CARD_PAYMENT,
                'account_id' => $creditCard->id,
                'category_id' => $groceriesCategory->id,
                'merchant_id' => $walmart->id,
                'balance_after_transaction' => $creditBalance,
            ]);
            $grocTx->tags()->attach($personalTag->id);
        }

        // Create restaurant transactions
        for ($i = 0; $i < 8; $i++) {
            $amount = rand(-50, -15);
            $creditBalance += $amount; // amount is negative, so this subtracts
            $merchant = rand(0, 1) ? $starbucks : $mcdonalds;
            $restTx = Transaction::create([
                'transaction_id' => 'FOOD-'.uniqid(),
                'amount' => $amount,
                'currency' => 'EUR',
                'booked_date' => Carbon::now()->startOfMonth()->addDays(rand(1, 28)),
                'processed_date' => Carbon::now()->startOfMonth()->addDays(rand(1, 28)),
                'description' => 'Restaurant Visit',
                'type' => Transaction::TYPE_CARD_PAYMENT,
                'account_id' => $creditCard->id,
                'category_id' => $restaurantsCategory->id,
                'merchant_id' => $merchant->id,
                'balance_after_transaction' => $creditBalance,
            ]);
            $restTx->tags()->attach($personalTag->id);
        }

        // Get additional categories
        $transportCategory = Category::where('name', 'Transportation')->where('user_id', $user->id)->first();
        $publicTransportCategory = Category::where('name', 'Public Transport')->where('user_id', $user->id)->first();
        $fuelCategory = Category::where('name', 'Fuel')->where('user_id', $user->id)->first();
        $entertainmentCategory = Category::where('name', 'Entertainment')->where('user_id', $user->id)->first();
        $moviesCategory = Category::where('name', 'Movies')->where('user_id', $user->id)->first();
        $gamesCategory = Category::where('name', 'Games')->where('user_id', $user->id)->first();

        // Create transportation transactions for 3 months
        for ($month = 0; $month < 3; $month++) {
            // Public transport transactions
            for ($i = 0; $i < 8; $i++) {
                $creditBalance -= 5.50;
                $transportTx = Transaction::create([
                    'transaction_id' => 'PT-'.uniqid(),
                    'amount' => -5.50,
                    'currency' => 'EUR',
                    'booked_date' => Carbon::now()->subMonths($month)->startOfMonth()->addDays(rand(1, 28)),
                    'processed_date' => Carbon::now()->subMonths($month)->startOfMonth()->addDays(rand(1, 28)),
                    'description' => 'Bus Fare',
                    'type' => Transaction::TYPE_CARD_PAYMENT,
                    'account_id' => $creditCard->id,
                    'category_id' => $publicTransportCategory->id,
                    'balance_after_transaction' => $creditBalance,
                ]);
                $transportTx->tags()->attach($personalTag->id);
            }

            // Fuel transactions
            $creditBalance -= 65.00;
            $fuelTx = Transaction::create([
                'transaction_id' => 'FUEL-'.uniqid(),
                'amount' => -65.00,
                'currency' => 'EUR',
                'booked_date' => Carbon::now()->subMonths($month)->startOfMonth()->addDays(rand(1, 28)),
                'processed_date' => Carbon::now()->subMonths($month)->startOfMonth()->addDays(rand(1, 28)),
                'description' => 'Gas Station',
                'type' => Transaction::TYPE_CARD_PAYMENT,
                'account_id' => $creditCard->id,
                'category_id' => $fuelCategory->id,
                'balance_after_transaction' => $creditBalance,
            ]);
            $fuelTx->tags()->attach($personalTag->id);
        }

        // Create entertainment transactions for 3 months
        for ($month = 0; $month < 3; $month++) {
            // Movie tickets
            for ($i = 0; $i < 2; $i++) {
                $creditBalance -= 15.00;
                $movieTx = Transaction::create([
                    'transaction_id' => 'MOV-'.uniqid(),
                    'amount' => -15.00,
                    'currency' => 'EUR',
                    'booked_date' => Carbon::now()->subMonths($month)->startOfMonth()->addDays(rand(1, 28)),
                    'processed_date' => Carbon::now()->subMonths($month)->startOfMonth()->addDays(rand(1, 28)),
                    'description' => 'Movie Theater',
                    'type' => Transaction::TYPE_CARD_PAYMENT,
                    'account_id' => $creditCard->id,
                    'category_id' => $moviesCategory->id,
                    'balance_after_transaction' => $creditBalance,
                ]);
                $movieTx->tags()->attach($personalTag->id);
            }

            // Gaming expenses
            $creditBalance -= 29.99;
            $gameTx = Transaction::create([
                'transaction_id' => 'GAME-'.uniqid(),
                'amount' => -29.99,
                'currency' => 'EUR',
                'booked_date' => Carbon::now()->subMonths($month)->startOfMonth()->addDays(rand(1, 28)),
                'processed_date' => Carbon::now()->subMonths($month)->startOfMonth()->addDays(rand(1, 28)),
                'description' => 'Gaming Subscription',
                'type' => Transaction::TYPE_CARD_PAYMENT,
                'account_id' => $creditCard->id,
                'category_id' => $gamesCategory->id,
                'balance_after_transaction' => $creditBalance,
            ]);
            $gameTx->tags()->attach($personalTag->id);
        }

        // Add recurring salary for previous months
        for ($month = 1; $month <= 3; $month++) {
            $checkingBalance += 5000.00;
            $salaryTx = Transaction::create([
                'transaction_id' => 'SAL-'.uniqid(),
                'amount' => 5000.00,
                'currency' => 'EUR',
                'booked_date' => Carbon::now()->subMonths($month)->startOfMonth(),
                'processed_date' => Carbon::now()->subMonths($month)->startOfMonth(),
                'description' => 'Monthly Salary',
                'type' => Transaction::TYPE_DEPOSIT,
                'account_id' => $checkingAccount->id,
                'category_id' => $salaryCategory->id,
                'balance_after_transaction' => $checkingBalance,
            ]);
            $salaryTx->tags()->attach($recurringTag->id);
        }
    }
}
