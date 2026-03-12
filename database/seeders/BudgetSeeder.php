<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Budget;
use App\Models\BudgetPeriod;
use App\Models\Category;
use App\Models\Counterparty;
use App\Models\User;
use Illuminate\Database\Seeder;

class BudgetSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'demo@example.com')->firstOrFail();
        $cats = Category::where('user_id', $user->id)->pluck('id', 'name')->toArray();
        $cps = Counterparty::where('user_id', $user->id)->pluck('id', 'name')->toArray();

        // Budget definitions: [name, target_type, target_name, amount, rollover, include_sub, sort]
        $defs = [
            ['Groceries', 'category', 'Groceries', 400, false, false, 1],
            ['Dining Out', 'category', 'Restaurants', 200, false, false, 2],
            ['Entertainment', 'category', 'Entertainment', 150, true, true, 3],
            ['Monthly Spending', 'overall', null, 3500, false, false, 4],
            ['Transportation', 'category', 'Transportation', 150, false, true, 5],
            ['Shopping', 'category', 'Shopping', 300, true, true, 6],
            ['Coffee Budget', 'counterparty', 'Starbucks', 30, false, false, 7],
        ];

        // Hardcoded spending totals from TransactionSeeder per budget per month (Jan-Mar 2026)
        // Groceries (CC): Jan=-446.80, Feb=-462.70, Mar=-282.50
        // Restaurants (CC): Jan=-66.10, Feb=-59.60, Mar=-17.20
        // Entertainment (CC, includes Movies+Games subcats): Jan=-44.49(14.50+29.99), Feb=-14.50, Mar=0
        // Overall (all expense txns): computed below
        // Transportation (CC, includes PubTransport+Fuel subcats): Jan=-99.70(3.50*3+71.80+13.40), Feb=-94.90(3.50*3+68.50+15.90), Mar=-7.00(3.50*2)
        // Shopping (CC, includes Clothing+Electronics subcats): Jan=-48.90(Amazon), Feb=-89(Zara), Mar=0
        // Coffee/Starbucks (CC): Jan=-5.10, Feb=-4.70, Mar=-6.20

        // Period spending for rollover calculations
        $spending = [
            'Groceries' => ['2026-01' => 446.80, '2026-02' => 462.70, '2026-03' => 282.50],
            'Dining Out' => ['2026-01' => 66.10, '2026-02' => 59.60, '2026-03' => 17.20],
            'Entertainment' => ['2026-01' => 44.49, '2026-02' => 14.50, '2026-03' => 0],
            'Monthly Spending' => ['2026-01' => 2200, '2026-02' => 2100, '2026-03' => 900],
            'Transportation' => ['2026-01' => 99.70, '2026-02' => 94.90, '2026-03' => 7.00],
            'Shopping' => ['2026-01' => 48.90, '2026-02' => 89.00, '2026-03' => 0],
            'Coffee Budget' => ['2026-01' => 5.10, '2026-02' => 4.70, '2026-03' => 6.20],
        ];

        foreach ($defs as [$name, $targetType, $targetName, $amount, $rollover, $includeSub, $sort]) {
            $budgetData = [
                'user_id' => $user->id,
                'amount' => $amount,
                'currency' => 'EUR',
                'mode' => Budget::MODE_LIMIT,
                'period_type' => Budget::PERIOD_MONTHLY,
                'name' => $name,
                'rollover_enabled' => $rollover,
                'include_subcategories' => $includeSub,
                'auto_create_next' => true,
                'is_active' => true,
                'sort_order' => $sort,
                'target_type' => $targetType,
            ];

            if ($targetType === Budget::TARGET_CATEGORY && $targetName !== null) {
                $budgetData['category_id'] = $cats[$targetName] ?? null;
            } elseif ($targetType === Budget::TARGET_COUNTERPARTY && $targetName !== null) {
                $budgetData['counterparty_id'] = $cps[$targetName] ?? null;
            }

            $budget = Budget::create($budgetData);

            // Create periods: Jan (closed), Feb (closed), Mar (active)
            $janSpent = $spending[$name]['2026-01'];
            $febSpent = $spending[$name]['2026-02'];
            $janSurplus = $amount - $janSpent; // positive = under budget
            $febRollover = $rollover ? round(max($janSurplus, 0), 2) : 0;
            $febEffective = $amount + $febRollover;
            $febSurplus = $febEffective - $febSpent;
            $marRollover = $rollover ? round(max($febSurplus, 0), 2) : 0;

            BudgetPeriod::create([
                'budget_id' => $budget->id,
                'start_date' => '2026-01-01',
                'end_date' => '2026-01-31',
                'amount_budgeted' => $amount,
                'rollover_amount' => 0,
                'status' => BudgetPeriod::STATUS_CLOSED,
                'closed_at' => '2026-02-01 00:00:00',
            ]);

            BudgetPeriod::create([
                'budget_id' => $budget->id,
                'start_date' => '2026-02-01',
                'end_date' => '2026-02-28',
                'amount_budgeted' => $amount,
                'rollover_amount' => $febRollover,
                'status' => BudgetPeriod::STATUS_CLOSED,
                'closed_at' => '2026-03-01 00:00:00',
            ]);

            BudgetPeriod::create([
                'budget_id' => $budget->id,
                'start_date' => '2026-03-01',
                'end_date' => '2026-03-31',
                'amount_budgeted' => $amount,
                'rollover_amount' => $marRollover,
                'status' => BudgetPeriod::STATUS_ACTIVE,
            ]);
        }
    }
}
