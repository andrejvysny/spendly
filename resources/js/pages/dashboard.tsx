import AppLayout from '@/layouts/app-layout';
import TransactionCard from '@/components/transactions/Transaction';
import { Account, Transaction } from '@/types';
import { Head } from '@inertiajs/react';
import { ArcElement, CategoryScale, Chart as ChartJS, ChartOptions, Legend, LinearScale, LineElement, PointElement, Title, Tooltip } from 'chart.js';
import { Doughnut, Line } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, ArcElement);

const breadcrumbs = [{ title: 'Dashboard', href: '/dashboard' }];

interface DashboardProps {
    accounts: Account[];
    recentTransactions: Transaction[];
    // monthlyBalances is now an array of objects for each account
    monthlyBalances: Record<number, { date: string; balance: number }[]>;
    currentMonthStats: {
        income: number;
        expenses: number;
    };
    expensesByCategory: {
        id: number;
        name: string;
        color: string;
        amount: number;
        percentage: number;
    }[];
}

export default function Dashboard({ accounts, recentTransactions, monthlyBalances, currentMonthStats, expensesByCategory }: DashboardProps) {
    const totalBalance = accounts.reduce((sum, acc) => sum + Number(acc.balance), 0);

    // Prepare data for balance chart
    // Assuming all accounts have the same months aligned
    const firstAccountId = accounts[0]?.id;
    const months = monthlyBalances[firstAccountId]?.map(item => item.date) || [];

    const chartData = {
        labels: months,
        datasets: accounts.map((account) => {
            // Fallback for missing data
            const history = monthlyBalances[account.id] || [];
            return {
                label: account.name,
                data: history.map((item) => item.balance),
                borderColor: `hsl(${Math.random() * 360}, 70%, 50%)`,
                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                tension: 0.3, // Smoother curve
                fill: false,
            };
        }),
    };

    const chartOptions: ChartOptions<'line'> = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: { color: '#9CA3AF', usePointStyle: true, pointStyle: 'circle' },
            },
            tooltip: { mode: 'index', intersect: false },
        },
        scales: {
            y: {
                type: 'linear',
                grid: { color: 'rgba(75, 85, 99, 0.2)' },
                ticks: { color: '#9CA3AF', callback: (value) => `${Number(value).toFixed(0)} €` },
            },
            x: {
                grid: { color: 'rgba(75, 85, 99, 0.2)' },
                ticks: { color: '#9CA3AF' },
            },
        },
        interaction: { mode: 'nearest', axis: 'x', intersect: false },
    };

    // Category Doughnut Chart Data
    const doughnutData = {
        labels: expensesByCategory.map(c => c.name),
        datasets: [{
            data: expensesByCategory.map(c => c.amount),
            backgroundColor: expensesByCategory.map(c => c.color || '#9CA3AF'),
            borderWidth: 0,
        }]
    };

    const doughnutOptions: ChartOptions<'doughnut'> = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'right', labels: { color: '#9CA3AF' } }
        },
        cutout: '70%',
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dashboard" />
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 p-4">

                {/* Stats Row */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-4">
                    {/* Net Worth */}
                    <div className="bg-card flex flex-col justify-center rounded-xl p-6 shadow-xs border-l-4 border-green-500">
                        <span className="text-muted-foreground text-sm font-medium">Net Worth</span>
                        <span className="text-3xl font-bold text-white mt-2">{totalBalance.toFixed(2)} €</span>
                    </div>

                    {/* Monthly Income */}
                    <div className="bg-card flex flex-col justify-center rounded-xl p-6 shadow-xs border-l-4 border-blue-500">
                        <span className="text-muted-foreground text-sm font-medium">Monthly Income</span>
                        <span className="text-2xl font-bold text-blue-400 mt-2">+{currentMonthStats.income.toFixed(2)} €</span>
                    </div>

                    {/* Monthly Expenses */}
                    <div className="bg-card flex flex-col justify-center rounded-xl p-6 shadow-xs border-l-4 border-red-500">
                        <span className="text-muted-foreground text-sm font-medium">Monthly Expenses</span>
                        <span className="text-2xl font-bold text-red-400 mt-2">{currentMonthStats.expenses.toFixed(2)} €</span>
                    </div>

                    {/* Savings Rate (Simplistic) */}
                    <div className="bg-card flex flex-col justify-center rounded-xl p-6 shadow-xs border-l-4 border-purple-500">
                        <span className="text-muted-foreground text-sm font-medium">Savings Rate</span>
                        <span className="text-xl font-bold text-purple-400 mt-2">
                            {currentMonthStats.income > 0
                                ? Math.round(((currentMonthStats.income - Math.abs(currentMonthStats.expenses)) / currentMonthStats.income) * 100)
                                : 0}%
                        </span>
                    </div>
                </div>

                {/* Main Content Grid */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    {/* Balance History Chart (Left 2 cols) */}
                    <div className="lg:col-span-2 bg-card rounded-xl p-6 shadow-xs">
                        <h3 className="mb-6 text-lg font-semibold text-white">Balance History</h3>
                        <div className="h-80">
                            <Line data={chartData} options={chartOptions} />
                        </div>
                    </div>

                    {/* Expenses by Category (Right 1 col) */}
                    <div className="bg-card rounded-xl p-6 shadow-xs">
                        <h3 className="mb-4 text-lg font-semibold text-white">Top Expenses</h3>
                        <div className="h-48 mb-4 relative">
                            {expensesByCategory.length > 0 ? (
                                <Doughnut data={doughnutData} options={doughnutOptions} />
                            ) : (
                                <div className="flex items-center justify-center h-full text-muted-foreground">No expenses this month</div>
                            )}
                        </div>
                        <ul className="space-y-3">
                            {expensesByCategory.map(cat => (
                                <li key={cat.id} className="flex justify-between items-center text-sm">
                                    <div className="flex items-center gap-2">
                                        <div className="w-3 h-3 rounded-full" style={{ backgroundColor: cat.color }}></div>
                                        <span>{cat.name}</span>
                                    </div>
                                    <div className="flex gap-4">
                                        <span className="font-medium">{Math.abs(cat.amount).toFixed(2)} €</span>
                                        <span className="text-muted-foreground w-12 text-right">{cat.percentage}%</span>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>

                {/* Bottom Row: Placeholder & Recent Transactions */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    {/* Placeholder for future component */}
                    <div className="w-full">
                        {/* Whitespace placeholder */}
                    </div>

                    {/* Recent Transactions List without card background */}
                    <div className="flex flex-col">
                        <div className="flex justify-between items-center mb-4 px-1">
                            <h3 className="text-lg font-semibold text-white">Recent Transactions</h3>
                            <a href="/transactions" className="text-sm text-blue-400 hover:text-blue-300">View All</a>
                        </div>
                        <div className="flex flex-col gap-3">
                            {recentTransactions.map((tx) => (
                                <TransactionCard key={tx.id} compact={false} {...tx} />
                            ))}
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}

