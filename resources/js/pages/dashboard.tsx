import BudgetProgressCard from '@/components/dashboard/BudgetProgressCard';
import SpendingPaceCard from '@/components/dashboard/SpendingPaceCard';
import SyncStatusCard from '@/components/dashboard/SyncStatusCard';
import TopCounterpartiesCard from '@/components/dashboard/TopCounterpartiesCard';
import UpcomingRecurringCard from '@/components/dashboard/UpcomingRecurringCard';
import TransactionCard from '@/components/transactions/Transaction';
import AppLayout from '@/layouts/app-layout';
import { Account, Transaction } from '@/types';
import { formatCurrency } from '@/utils/currency';
import { Head, usePage } from '@inertiajs/react';
import { ArcElement, CategoryScale, Chart as ChartJS, ChartOptions, Legend, LinearScale, LineElement, PointElement, Title, Tooltip } from 'chart.js';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { Doughnut, Line } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, ArcElement);

const breadcrumbs = [{ title: 'Dashboard', href: '/dashboard' }];

// Deterministic color palette for chart lines
const CHART_COLORS = [
    'hsl(142, 70%, 50%)',
    'hsl(217, 70%, 50%)',
    'hsl(350, 70%, 50%)',
    'hsl(45, 70%, 50%)',
    'hsl(280, 70%, 50%)',
    'hsl(180, 70%, 50%)',
    'hsl(30, 70%, 50%)',
    'hsl(320, 70%, 50%)',
];

interface DashboardProps {
    accounts: Account[];
    recentTransactions: Transaction[];
    monthlyBalances: Record<number, { date: string; balance: number }[]>;
    currentMonthStats: { income: number; expenses: number };
    previousMonthStats: { income: number; expenses: number };
    previousNetWorth: number;
    expensesByCategory: {
        id: number;
        name: string;
        color: string;
        amount: number;
        percentage: number;
    }[];
    budgetProgress: {
        name: string;
        budgeted: number;
        spent: number;
        percentage: number;
        is_exceeded: boolean;
        category_color: string | null;
        currency: string;
    }[];
    topCounterparties: { name: string; amount: number; transaction_count: number }[];
    upcomingRecurring: { name: string; amount: number; next_date: string; interval: string; counterparty_name: string | null }[];
    spendingPace: { daily_average: number; projected_total: number; days_elapsed: number; days_in_month: number };
    is_converted?: boolean;
}

function momChange(current: number, previous: number): number | null {
    if (previous === 0) return null;
    return Math.round(((current - previous) / Math.abs(previous)) * 100);
}

function MomBadge({ change, suffix = '%' }: { change: number | null; suffix?: string }) {
    if (change === null) return null;
    const isPositive = change > 0;
    const Icon = isPositive ? TrendingUp : TrendingDown;
    const color = isPositive ? 'text-green-400' : 'text-red-400';

    return (
        <span className={`mt-1 flex items-center gap-1 text-xs ${color}`}>
            <Icon className="h-3 w-3" />
            {change > 0 ? '+' : ''}
            {change}
            {suffix}
        </span>
    );
}

function MomBadgeInverse({ change, suffix = '%' }: { change: number | null; suffix?: string }) {
    if (change === null) return null;
    const isPositive = change > 0;
    const Icon = isPositive ? TrendingUp : TrendingDown;
    // For expenses: increase is bad (red), decrease is good (green)
    const color = isPositive ? 'text-red-400' : 'text-green-400';

    return (
        <span className={`mt-1 flex items-center gap-1 text-xs ${color}`}>
            <Icon className="h-3 w-3" />
            {change > 0 ? '+' : ''}
            {change}
            {suffix}
        </span>
    );
}

export default function Dashboard({
    accounts,
    recentTransactions,
    monthlyBalances,
    currentMonthStats,
    previousMonthStats,
    previousNetWorth,
    expensesByCategory,
    budgetProgress,
    topCounterparties,
    upcomingRecurring,
    spendingPace,
    is_converted: isConverted,
}: DashboardProps) {
    const { auth } = usePage<{ auth: { user: { base_currency?: string } } }>().props;
    const baseCurrency = auth.user.base_currency || 'EUR';

    const totalBalance = accounts.reduce((sum, acc) => sum + Number(acc.balance), 0);
    const savingsRate =
        currentMonthStats.income > 0
            ? Math.round(((currentMonthStats.income - Math.abs(currentMonthStats.expenses)) / currentMonthStats.income) * 100)
            : 0;
    const prevSavingsRate =
        previousMonthStats.income > 0
            ? Math.round(((previousMonthStats.income - Math.abs(previousMonthStats.expenses)) / previousMonthStats.income) * 100)
            : 0;

    const netWorthChange = momChange(totalBalance, previousNetWorth);
    const incomeChange = momChange(currentMonthStats.income, previousMonthStats.income);
    const expenseChange = momChange(Math.abs(currentMonthStats.expenses), Math.abs(previousMonthStats.expenses));
    const savingsChange = savingsRate - prevSavingsRate;

    // Balance chart data with deterministic colors
    const firstAccountId = accounts[0]?.id;
    const months = monthlyBalances[firstAccountId]?.map((item) => item.date) || [];

    const chartData = {
        labels: months,
        datasets: accounts.map((account, index) => {
            const history = monthlyBalances[account.id] || [];
            return {
                label: account.name,
                data: history.map((item) => item.balance),
                borderColor: CHART_COLORS[index % CHART_COLORS.length],
                backgroundColor: 'transparent',
                tension: 0.3,
                fill: false,
                pointRadius: 2,
            };
        }),
    };

    const chartOptions: ChartOptions<'line'> = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: true, position: 'top', labels: { color: '#9CA3AF', usePointStyle: true, pointStyle: 'circle' } },
            tooltip: { mode: 'index', intersect: false },
        },
        scales: {
            y: {
                type: 'linear',
                grid: { color: 'rgba(75, 85, 99, 0.2)' },
                ticks: { color: '#9CA3AF', callback: (value) => formatCurrency(Number(value), baseCurrency) },
            },
            x: {
                grid: { color: 'rgba(75, 85, 99, 0.2)' },
                ticks: { color: '#9CA3AF' },
            },
        },
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
    };

    // Category doughnut
    const doughnutData = {
        labels: expensesByCategory.map((c) => c.name),
        datasets: [
            { data: expensesByCategory.map((c) => c.amount), backgroundColor: expensesByCategory.map((c) => c.color || '#9CA3AF'), borderWidth: 0 },
        ],
    };

    const doughnutOptions: ChartOptions<'doughnut'> = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'right', labels: { color: '#9CA3AF' } } },
        cutout: '70%',
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4">
                {/* Stats Row */}
                <div className="grid grid-cols-1 gap-6 sm:grid-cols-2 md:grid-cols-4">
                    <div className="bg-card flex flex-col justify-center rounded-xl border-l-4 border-green-500 p-6 shadow-xs">
                        <span className="text-muted-foreground text-sm font-medium">Net Worth</span>
                        <span className="mt-2 text-3xl font-bold">{formatCurrency(totalBalance, baseCurrency)}</span>
                        <MomBadge change={netWorthChange} />
                    </div>

                    <div className="bg-card flex flex-col justify-center rounded-xl border-l-4 border-blue-500 p-6 shadow-xs">
                        <span className="text-muted-foreground text-sm font-medium">Monthly Income</span>
                        <span className="mt-2 text-2xl font-bold text-blue-400">+{formatCurrency(currentMonthStats.income, baseCurrency)}</span>
                        <MomBadge change={incomeChange} />
                    </div>

                    <div className="bg-card flex flex-col justify-center rounded-xl border-l-4 border-red-500 p-6 shadow-xs">
                        <span className="text-muted-foreground text-sm font-medium">Monthly Expenses</span>
                        <span className="mt-2 text-2xl font-bold text-red-400">
                            -{formatCurrency(Math.abs(currentMonthStats.expenses), baseCurrency)}
                        </span>
                        <MomBadgeInverse change={expenseChange} />
                    </div>

                    <div className="bg-card flex flex-col justify-center rounded-xl border-l-4 border-purple-500 p-6 shadow-xs">
                        <span className="text-muted-foreground text-sm font-medium">Savings Rate</span>
                        <span className="mt-2 text-xl font-bold text-purple-400">{savingsRate}%</span>
                        <MomBadge change={savingsChange !== 0 ? savingsChange : null} suffix=" pp" />
                    </div>
                </div>

                {isConverted && (
                    <div className="text-muted-foreground rounded-lg border border-dashed px-4 py-2 text-xs">
                        Amounts converted to {baseCurrency} at historical ECB rates
                    </div>
                )}

                {/* Row 2: Balance History + Category Doughnut */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <div className="bg-card rounded-xl p-6 shadow-xs lg:col-span-2">
                        <h3 className="mb-6 text-lg font-semibold">Balance History</h3>
                        <div className="h-80">
                            <Line data={chartData} options={chartOptions} />
                        </div>
                    </div>

                    <div className="bg-card rounded-xl p-6 shadow-xs">
                        <h3 className="mb-4 text-lg font-semibold">Top Expenses</h3>
                        <div className="relative mb-4 h-48">
                            {expensesByCategory.length > 0 ? (
                                <Doughnut data={doughnutData} options={doughnutOptions} />
                            ) : (
                                <div className="text-muted-foreground flex h-full items-center justify-center">No expenses this month</div>
                            )}
                        </div>
                        <ul className="space-y-3">
                            {expensesByCategory.map((cat) => (
                                <li key={cat.id} className="flex items-center justify-between text-sm">
                                    <div className="flex items-center gap-2">
                                        <div className="h-3 w-3 rounded-full" style={{ backgroundColor: cat.color }} />
                                        <span>{cat.name}</span>
                                    </div>
                                    <div className="flex gap-4">
                                        <span className="font-medium">{formatCurrency(cat.amount, baseCurrency)}</span>
                                        <span className="text-muted-foreground w-12 text-right">{cat.percentage}%</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>

                {/* Row 3: Budgets + Spending Pace / Top Counterparties */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <BudgetProgressCard budgets={budgetProgress} />
                    <div className="flex flex-col gap-6">
                        <SpendingPaceCard spendingPace={spendingPace} currency={baseCurrency} />
                        <TopCounterpartiesCard counterparties={topCounterparties} currency={baseCurrency} />
                    </div>
                </div>

                {/* Row 4: Upcoming Recurring + Sync Status */}
                <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <UpcomingRecurringCard items={upcomingRecurring} currency={baseCurrency} />
                    <SyncStatusCard accounts={accounts} />
                </div>

                {/* Row 5: Recent Transactions (full width) */}
                <div>
                    <div className="mb-4 flex items-center justify-between px-1">
                        <h3 className="text-lg font-semibold">Recent Transactions</h3>
                        <a href="/transactions" className="text-sm text-blue-400 hover:text-blue-300">
                            View All
                        </a>
                    </div>
                    <div className="flex flex-col gap-3">
                        {recentTransactions.map((tx) => (
                            <TransactionCard key={tx.id} compact={false} {...tx} />
                        ))}
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
