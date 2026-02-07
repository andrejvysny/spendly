import { Button } from '@/components/ui/button';
import { DateRangePicker } from '@/components/ui/date-range-picker';
import { Input } from '@/components/ui/input';
import { MultiSelect } from '@/components/ui/multi-select';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { Account, Category, PageProps } from '@/types';
import { formatAmount } from '@/utils/currency';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import {
    ArcElement,
    BarElement,
    CategoryScale,
    ChartData,
    Chart as ChartJS,
    ChartOptions,
    Legend,
    LinearScale,
    LineElement,
    PointElement,
    Title,
    Tooltip,
    Filler,
} from 'chart.js';
import ChartDataLabels from 'chartjs-plugin-datalabels';
import { format } from 'date-fns';
import React, { useCallback, useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import { Bar, Chart, Doughnut, Line } from 'react-chartjs-2';

// Register ChartJS components
ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, BarElement, ArcElement, Title, Tooltip, Legend, ChartDataLabels, Filler);

interface CashflowData {
    year: number;
    month: number;
    transaction_count: number;
    month_balance: number;
    total_income: number;
    total_expenses: number;
    day?: number;
    day_balance?: number;
}

interface CategorySpendingData {
    categorized: CategorySpending[];
    uncategorized: { total: number; count: number };
}

interface MerchantSpendingData {
    withMerchant: MerchantSpending[];
    noMerchant: { total: number; count: number };
}

interface CategorySpending {
    category: string;
    total: number;
    count: number;
    color?: string;
}

interface MerchantSpending {
    merchant: string;
    total: number;
    count: number;
}

interface BalanceHistoryData {
    balanceHistory: Record<number, Record<string, number>>; // accountId -> period -> balance
    netWorthHistory: Record<string, number>; // period -> totalBalance
}

interface RecurringGroupStats {
    first_payment_date: string | null;
    last_payment_date: string | null;
    transactions_count: number;
    total_paid: number;
    average_amount: number | null;
    projected_yearly_cost: number;
    next_expected_payment: string | null;
}

interface RecurringGroupItem {
    id: number;
    name: string;
    interval: string;
    stats?: RecurringGroupStats | null;
}

interface AnalyticsProps extends PageProps {
    accounts: Account[];
    categories: Category[];
    selectedAccountIds: number[];
    cashflow: CashflowData[];
    categorySpending: CategorySpendingData;
    merchantSpending: MerchantSpendingData;
    dateRange: {
        start: string;
        end: string;
    };
    period: string;
}

// Define breadcrumbs for consistent navigation
const breadcrumbs = [{ title: 'Analytics', href: '/analytics' }];

/**
 * Renders the analytics dashboard for financial data, providing interactive charts and tables for cashflow, category spending, and merchant spending.
 *
 * Displays summary statistics, period and account filters, and visualizations for selected accounts and date ranges. Users can filter data by period, custom date range, specific month, and accounts, with the dashboard updating dynamically based on selections.
 *
 * @remark If no accounts are selected, all charts and tables display placeholder or empty data.
 */
export default function Index({
    accounts,
    categories,
    selectedAccountIds,
    cashflow,
    categorySpending,
    merchantSpending,
    dateRange,
    period: initialPeriod,
}: AnalyticsProps & {
    categorySpending: CategorySpendingData;
    merchantSpending: MerchantSpendingData;
}) {
    const [dateSelection, setDateSelection] = useState({ from: dateRange.start, to: dateRange.end });
    const [periodType, setPeriodType] = useState<string>(initialPeriod);
    const [selectedAccounts, setSelectedAccounts] = useState<number[]>(selectedAccountIds);
    const [specificMonth, setSpecificMonth] = useState<string>('');
    const noAccountsSelected = selectedAccounts.length === 0;

    // Balance history state
    const [balanceHistory, setBalanceHistory] = useState<BalanceHistoryData | null>(null);
    const [loadingBalanceHistory, setLoadingBalanceHistory] = useState(false);

    // Recurring overview state
    const [recurringGroups, setRecurringGroups] = useState<RecurringGroupItem[]>([]);
    const [loadingRecurring, setLoadingRecurring] = useState(true);

    // Fetch recurring confirmed groups for overview card
    useEffect(() => {
        setLoadingRecurring(true);
        axios
            .get<{ data: { confirmed: RecurringGroupItem[] } }>('/api/recurring?status=confirmed')
            .then((res) => setRecurringGroups(res.data.data.confirmed ?? []))
            .catch(() => setRecurringGroups([]))
            .finally(() => setLoadingRecurring(false));
    }, []);

    // Fetch balance history when accounts or date range changes
    const fetchBalanceHistory = useCallback(async () => {
        if (selectedAccounts.length === 0) {
            setBalanceHistory(null);
            return;
        }

        setLoadingBalanceHistory(true);
        try {
            const params = new URLSearchParams();
            selectedAccounts.forEach((id) => params.append('account_ids[]', id.toString()));
            params.append('start_date', dateRange.start);
            params.append('end_date', dateRange.end);
            params.append('granularity', periodType === 'specific_month' ? 'day' : 'month');

            const response = await axios.get(`/api/analytics/balance-history?${params.toString()}`);
            setBalanceHistory(response.data);
        } catch (error) {
            console.error('Failed to fetch balance history:', error);
            setBalanceHistory(null);
        } finally {
            setLoadingBalanceHistory(false);
        }
    }, [selectedAccounts, dateRange.start, dateRange.end, periodType]);

    useEffect(() => {
        fetchBalanceHistory();
    }, [fetchBalanceHistory]);

    useEffect(() => {
        // When period changes to specific_month, set initial value
        if (periodType === 'specific_month' && !specificMonth) {
            const today = new Date();
            setSpecificMonth(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`);
        }
    }, [periodType, specificMonth]);

    // Handle date range selection
    const handleDateRangeChange = (value: { from: string; to: string }) => {
        setDateSelection(value);
        if (value.from && value.to) {
            router.visit('/analytics', {
                data: {
                    period: 'custom',
                    start_date: value.from,
                    end_date: value.to,
                    account_ids: selectedAccounts,
                },
                only: ['cashflow', 'categorySpending', 'merchantSpending', 'dateRange'],
                preserveState: true,
            });
        }
    };

    // Handle period selection
    const handlePeriodChange = (value: string) => {
        setPeriodType(value);

        // Create data object for the request
        const data: Record<string, string | number | boolean | File | null | number[]> = {
            period: value,
            account_ids: selectedAccounts,
        };

        // Add specific_month if that's the selected period
        if (value === 'specific_month' && specificMonth) {
            data.specific_month = specificMonth;
        }

        router.visit('/analytics', {
            data,
            only: ['cashflow', 'categorySpending', 'merchantSpending', 'dateRange'],
            preserveState: true,
        });
    };

    // Handle specific month selection
    const handleSpecificMonthChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        const newValue = event.target.value;
        setSpecificMonth(newValue);

        if (newValue && periodType === 'specific_month') {
            router.visit('/analytics', {
                data: {
                    period: 'specific_month',
                    specific_month: newValue,
                    account_ids: selectedAccounts,
                } as Record<string, string | number | boolean | File | null | number[]>,
                only: ['cashflow', 'categorySpending', 'merchantSpending', 'dateRange'],
                preserveState: true,
            });
        }
    };

    // Handle account selection
    const handleAccountFilterChange = (selectedIds: number[]) => {
        // Convert to numbers if they're not already
        const numericIds = selectedIds.map((id) => (typeof id === 'string' ? parseInt(id) : id));
        setSelectedAccounts(numericIds);

        // Create data object for the request
        const data: Record<string, string | number | boolean | File | null | number[]> = {
            period: periodType,
            account_ids: numericIds,
        };

        // Add period-specific parameters
        if (periodType === 'custom' && dateSelection.from && dateSelection.to) {
            data.start_date = dateSelection.from;
            data.end_date = dateSelection.to;
        } else if (periodType === 'specific_month' && specificMonth) {
            data.specific_month = specificMonth;
        }

        // Use router.visit instead of reload to avoid parameter accumulation
        router.visit('/analytics', {
            data,
            only: ['cashflow', 'categorySpending', 'merchantSpending', 'dateRange', 'selectedAccountIds'],
            preserveState: true,
        });
    };

    // Format the CashflowData for the chart
    const formatCashflowForChart = (data: CashflowData[]): ChartData<'bar' | 'line', (number | undefined)[], string> => {
        const datasets = []; // If no data, return empty datasets

        if (data.length === 0) {
            return {
                labels: [],
                datasets: [
                    {
                        label: 'Income',
                        data: [],
                        backgroundColor: 'rgba(34, 197, 94, 0.2)',
                        borderColor: 'rgb(34, 197, 94)',
                        borderWidth: 1,
                        type: 'bar' as const,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Expenses',
                        data: [],
                        backgroundColor: 'rgba(239, 68, 68, 0.2)',
                        borderColor: 'rgb(239, 68, 68)',
                        borderWidth: 1,
                        type: 'bar' as const,
                        yAxisID: 'y',
                    },
                    {
                        label: 'Resulting Balance',
                        data: [],
                        borderColor: 'rgb(59, 130, 246)',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        borderWidth: 2,
                        type: 'line' as const,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        yAxisID: 'y',
                    },
                ],
            };
        }

        const hasDailyData = Object.prototype.hasOwnProperty.call(data[0], 'day');
        const labels = data.map((item) => {
            if (hasDailyData) {
                return `${item.year}-${String(item.month).padStart(2, '0')}-${String(item.day).padStart(2, '0')}`;
            }
            return `${item.year}-${String(item.month).padStart(2, '0')}`;
        });

        datasets.push(
            {
                label: 'Income',
                data: data.map((item) => item.total_income),
                backgroundColor: 'rgba(34, 197, 94, 0.2)',
                borderColor: 'rgb(34, 197, 94)',
                borderWidth: 1,
                type: 'bar' as const,
                yAxisID: 'y',
            },
            {
                label: 'Expenses',
                data: data.map((item) => -item.total_expenses),
                backgroundColor: 'rgba(239, 68, 68, 0.2)',
                borderColor: 'rgb(239, 68, 68)',
                borderWidth: 1,
                type: 'bar' as const,
                yAxisID: 'y',
            },
            {
                label: 'Resulting Balance',
                data: data.map((item) => (hasDailyData ? item.day_balance : item.month_balance)),
                borderColor: 'rgb(59, 130, 246)',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 2,
                type: 'line' as const,
                fill: true,
                tension: 0.4,
                pointRadius: 4,
                pointHoverRadius: 6,
                yAxisID: 'y',
            },
        );

        return {
            labels,
            datasets,
        };
    };

    // Format category spending for chart
    const formatCategoryForChart = (): ChartData<'doughnut'> => {
        return {
            labels: categorySpending.categorized.map((item) => item.category),
            datasets: [
                {
                    label: 'Spending by Category',
                    data: categorySpending.categorized.map((item) => item.total),
                    backgroundColor: categorySpending.categorized.map((item) => {
                        const matchingCategory = categories.find((cat) => cat.name === item.category);
                        return matchingCategory?.color ? `${matchingCategory.color}99` : 'rgba(75, 85, 99, 0.6)';
                    }),
                    borderColor: categorySpending.categorized.map((item) => {
                        const matchingCategory = categories.find((cat) => cat.name === item.category);
                        return matchingCategory?.color || 'rgba(75, 85, 99, 1)';
                    }),
                    borderWidth: 1,
                },
            ],
        };
    };

    // Format merchant spending for chart
    const formatMerchantForChart = (): ChartData<'bar'> => {
        return {
            labels: merchantSpending.withMerchant.map((item) => item.merchant),
            datasets: [
                {
                    label: 'Spending by Merchant',
                    data: merchantSpending.withMerchant.map((item) => item.total),
                    backgroundColor: 'rgba(153, 102, 255, 0.6)',
                    borderColor: 'rgba(153, 102, 255, 1)',
                    borderWidth: 1,
                },
            ],
        };
    };

    // Mixed chart options
    const cashflowChartOptions = React.useMemo(() => {
        // Check if we have daily data (specific month selected)
        const hasDailyData = cashflow.length > 0 && 'day' in cashflow[0];

        // Format chart title based on period
        let chartTitle = 'Income vs Expenses Analysis';
        if (periodType === 'specific_month' && specificMonth) {
            // Format the month name for display (e.g., "January 2023")
            const monthDate = new Date(specificMonth);
            chartTitle = `Daily Cashflow: ${monthDate.toLocaleString('default', { month: 'long', year: 'numeric' })}`;
        } else if (periodType === 'current_month') {
            chartTitle = 'Current Month Analysis';
        } else if (periodType === 'last_month') {
            chartTitle = 'Last Month Analysis';
        } else if (periodType === 'last_3_months') {
            chartTitle = 'Last 3 Months Analysis';
        } else if (periodType === 'last_6_months') {
            chartTitle = 'Last 6 Months Analysis';
        } else if (periodType === 'current_year') {
            chartTitle = 'Current Year Analysis (Monthly)';
        } else if (periodType === 'last_year') {
            chartTitle = 'Last Year Analysis (Monthly)';
        }

        return {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: true,
                    position: 'top' as const,
                    labels: {
                        color: '#9CA3AF',
                        usePointStyle: true,
                        pointStyle: 'circle' as const,
                    },
                },
                tooltip: {
                    mode: 'index' as const,
                    intersect: false,
                    callbacks: {
                        label: function (context: import('chart.js').TooltipItem<'bar' | 'line'>) {
                            const value = Number(context.raw);
                            if (context.dataset.label === 'Expenses') {
                                return `Expenses: ${formatAmount(Math.abs(value))}`;
                            }
                            if (context.dataset.label === 'Resulting Balance') {
                                return `Resulting Balance: ${formatAmount(value)}`;
                            }
                            return `${context.dataset.label}: ${formatAmount(value)}`;
                        },
                    },
                },
                title: {
                    display: true,
                    text: chartTitle,
                    color: '#9CA3AF',
                    font: {
                        size: 16,
                    },
                },
                // Disable datalabels for cashflow chart
                datalabels: {
                    display: false,
                },
            },
            scales: {
                y: {
                    type: 'linear' as const,
                    grid: {
                        color: 'rgba(75, 85, 99, 0.2)',
                    },
                    ticks: {
                        color: '#9CA3AF',
                        callback: function (value: string | number) {
                            return `$${Number(value).toFixed(2)}`;
                        },
                    },
                    title: {
                        display: true,
                        text: 'Amount ($)',
                        color: '#9CA3AF',
                    },
                    stacked: false,
                },
                x: {
                    grid: {
                        color: 'rgba(75, 85, 99, 0.2)',
                    },
                    ticks: {
                        color: '#9CA3AF',
                    },
                    title: {
                        display: true,
                        text: hasDailyData ? 'Day of Month' : 'Month',
                        color: '#9CA3AF',
                    },
                    stacked: true,
                },
            },
            interaction: {
                mode: 'nearest' as const,
                axis: 'x' as const,
                intersect: false,
            },
        };
    }, [cashflow, periodType, specificMonth]);

    // Chart options for bar charts
    const barChartOptions: ChartOptions<'bar'> = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    color: '#9CA3AF',
                    usePointStyle: true,
                    pointStyle: 'circle',
                },
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                callbacks: {
                    label: function (context) {
                        const value = Number(context.raw);
                        return `${context.dataset.label}: $${value.toFixed(2)}`;
                    },
                },
            },
            // Disable datalabels for bar charts
            datalabels: {
                display: false,
            },
        },
        scales: {
            y: {
                type: 'linear',
                grid: {
                    color: 'rgba(75, 85, 99, 0.2)',
                },
                ticks: {
                    color: '#9CA3AF',
                    callback: function (value) {
                        return `$${Number(value).toFixed(2)}`;
                    },
                },
            },
            x: {
                grid: {
                    color: 'rgba(75, 85, 99, 0.2)',
                },
                ticks: {
                    color: '#9CA3AF',
                },
            },
        },
    };

    // Doughnut chart options (without scales)
    const doughnutChartOptions: ChartOptions<'doughnut'> = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    color: '#9CA3AF',
                    usePointStyle: true,
                    pointStyle: 'circle',
                },
            },
            tooltip: {
                callbacks: {
                    label: function (context) {
                        const value = context.raw as number;
                        const total = context.chart.data.datasets[0].data.reduce((sum: number, val) => {
                            return sum + (typeof val === 'number' ? val : 0);
                        }, 0);
                        const percentage = ((value / total) * 100).toFixed(1);
                        return `${context.label}: ${formatAmount(value)} (${percentage}%)`;
                    },
                },
            },
            datalabels: {
                formatter: (value: number, ctx: import('chartjs-plugin-datalabels/types/context').Context) => {
                    const dataset = ctx.chart.data.datasets[0];
                    const total = (dataset.data as number[]).reduce((acc: number, data: number) => {
                        return acc + data;
                    }, 0);
                    const percentage = ((value / total) * 100).toFixed(1) + '%';
                    return percentage;
                },
                color: '#fff',
                font: {
                    weight: 'bold',
                    size: 11,
                },
                display: function (context: import('chartjs-plugin-datalabels/types/context').Context) {
                    // Only display label if the percentage is greater than 5%
                    const value = context.dataset.data[context.dataIndex] as number;
                    const total = (context.dataset.data as number[]).reduce((acc: number, data: number) => {
                        return acc + data;
                    }, 0);
                    return (value / total) * 100 > 5;
                },
            },
        },
    };

    // Calculate totals for summary
    const totalIncome = cashflow.reduce((sum, item) => sum + item.total_income, 0);
    const totalExpenses = cashflow.reduce((sum, item) => sum + item.total_expenses, 0);
    const netBalance = totalIncome - totalExpenses;
    const totalTransactions = cashflow.reduce((sum, item) => sum + item.transaction_count, 0);

    // Format balance history for chart
    const formatBalanceHistoryForChart = (): ChartData<'line'> => {
        if (!balanceHistory || !balanceHistory.netWorthHistory) {
            return {
                labels: [],
                datasets: [
                    {
                        label: 'Net Worth',
                        data: [],
                        borderColor: 'rgb(99, 102, 241)',
                        backgroundColor: 'rgba(99, 102, 241, 0.1)',
                        borderWidth: 2,
                        fill: true,
                        tension: 0.4,
                        pointRadius: 4,
                        pointHoverRadius: 6,
                    },
                ],
            };
        }

        // Sort periods chronologically
        const periods = Object.keys(balanceHistory.netWorthHistory).sort();
        const datasets: ChartData<'line'>['datasets'] = [];

        // Add net worth line (primary)
        datasets.push({
            label: 'Total Net Worth',
            data: periods.map((period) => balanceHistory.netWorthHistory[period]),
            borderColor: 'rgb(99, 102, 241)',
            backgroundColor: 'rgba(99, 102, 241, 0.1)',
            borderWidth: 3,
            fill: true,
            tension: 0.4,
            pointRadius: 4,
            pointHoverRadius: 6,
        });

        // Add individual account lines (secondary, if more than one account)
        if (selectedAccounts.length > 1 && balanceHistory.balanceHistory) {
            const colors = [
                { border: 'rgb(34, 197, 94)', bg: 'rgba(34, 197, 94, 0.1)' },
                { border: 'rgb(239, 68, 68)', bg: 'rgba(239, 68, 68, 0.1)' },
                { border: 'rgb(59, 130, 246)', bg: 'rgba(59, 130, 246, 0.1)' },
                { border: 'rgb(168, 85, 247)', bg: 'rgba(168, 85, 247, 0.1)' },
                { border: 'rgb(245, 158, 11)', bg: 'rgba(245, 158, 11, 0.1)' },
            ];

            Object.entries(balanceHistory.balanceHistory).forEach(([accountId, history], index) => {
                const account = accounts.find((a) => a.id === parseInt(accountId));
                const color = colors[index % colors.length];
                datasets.push({
                    label: account?.name || `Account ${accountId}`,
                    data: periods.map((period) => history[period] || 0),
                    borderColor: color.border,
                    backgroundColor: 'transparent',
                    borderWidth: 1,
                    borderDash: [5, 5],
                    fill: false,
                    tension: 0.4,
                    pointRadius: 2,
                    pointHoverRadius: 4,
                });
            });
        }

        return {
            labels: periods,
            datasets,
        };
    };

    // Balance history chart options
    const balanceHistoryChartOptions: ChartOptions<'line'> = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: true,
                position: 'top',
                labels: {
                    color: '#9CA3AF',
                    usePointStyle: true,
                    pointStyle: 'circle',
                },
            },
            tooltip: {
                mode: 'index',
                intersect: false,
                callbacks: {
                    label: function (context) {
                        const value = Number(context.raw);
                        return `${context.dataset.label}: ${formatAmount(value)}`;
                    },
                },
            },
            title: {
                display: true,
                text: 'Balance Over Time',
                color: '#9CA3AF',
                font: {
                    size: 16,
                },
            },
            datalabels: {
                display: false,
            },
        },
        scales: {
            y: {
                type: 'linear',
                grid: {
                    color: 'rgba(75, 85, 99, 0.2)',
                },
                ticks: {
                    color: '#9CA3AF',
                    callback: function (value) {
                        return `$${Number(value).toFixed(0)}`;
                    },
                },
                title: {
                    display: true,
                    text: 'Balance ($)',
                    color: '#9CA3AF',
                },
            },
            x: {
                grid: {
                    color: 'rgba(75, 85, 99, 0.2)',
                },
                ticks: {
                    color: '#9CA3AF',
                },
                title: {
                    display: true,
                    text: periodType === 'specific_month' ? 'Day' : 'Month',
                    color: '#9CA3AF',
                },
            },
        },
        interaction: {
            mode: 'nearest',
            axis: 'x',
            intersect: false,
        },
    };

    // Format the cashflow chart title based on period
    const getChartTitle = () => {
        if (periodType === 'specific_month' && specificMonth) {
            // Format the month name for display (e.g., "January 2023")
            const monthDate = new Date(specificMonth);
            return `Daily Cashflow: ${monthDate.toLocaleString('default', { month: 'long', year: 'numeric' })}`;
        } else if (periodType === 'custom' && dateSelection.from && dateSelection.to) {
            // Format the custom date range for display
            const start = new Date(dateSelection.from);
            const end = new Date(dateSelection.to);

            // If dates are in the same month and year, show daily view title
            if (start.getMonth() === end.getMonth() && start.getFullYear() === end.getFullYear() && cashflow.length > 0 && 'day' in cashflow[0]) {
                return `Daily Cashflow: ${start.toLocaleString('default', { month: 'long', year: 'numeric' })}`;
            }

            return `Custom Period: ${format(start, 'MMM d, yyyy')} - ${format(end, 'MMM d, yyyy')}`;
        } else if (periodType === 'current_month') {
            return 'Current Month Analysis';
        } else if (periodType === 'last_month') {
            return 'Last Month Analysis';
        } else if (periodType === 'last_3_months') {
            return 'Last 3 Months Analysis';
        } else if (periodType === 'last_6_months') {
            return 'Last 6 Months Analysis';
        } else if (periodType === 'current_year') {
            return 'Current Year Analysis (Monthly)';
        } else if (periodType === 'last_year') {
            return 'Last Year Analysis (Monthly)';
        }

        return 'Income vs Expenses Analysis';
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Analytics" />

            <div className="mx-auto flex w-full max-w-7xl flex-col gap-4 overflow-hidden p-4">
                <div className="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
                    <h1 className="text-2xl font-semibold">Analytics</h1>
                    <div className="flex w-full flex-wrap items-center gap-3 md:w-auto">
                        <Select value={periodType} onValueChange={handlePeriodChange}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="Select period" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="last_month">Last Month</SelectItem>
                                <SelectItem value="current_month">Current Month</SelectItem>
                                <SelectItem value="last_3_months">Last 3 Months</SelectItem>
                                <SelectItem value="last_6_months">Last 6 Months</SelectItem>
                                <SelectItem value="current_year">Current Year</SelectItem>
                                <SelectItem value="last_year">Last Year</SelectItem>
                                <SelectItem value="specific_month">Specific Month</SelectItem>
                                <SelectItem value="custom">Custom Range</SelectItem>
                            </SelectContent>
                        </Select>

                        {periodType === 'custom' && <DateRangePicker name="dateRange" value={dateSelection} onChange={handleDateRangeChange} />}

                        {periodType === 'specific_month' && (
                            <Input id="month-picker" type="month" value={specificMonth} onChange={handleSpecificMonthChange} className="w-[180px]" />
                        )}

                        <MultiSelect
                            options={accounts.map((account) => ({
                                value: account.id,
                                label: account.name,
                                description: `${formatAmount(account.balance, account.currency)}`,
                            }))}
                            selected={selectedAccounts}
                            onChange={handleAccountFilterChange}
                            placeholder="Select accounts"
                            className="w-[220px]"
                        />
                    </div>
                    <div className="self-start">
                        <Button variant="outline">Export</Button>
                    </div>
                </div>

                {/* Controls and Summary Section */}
                <div className="grid grid-cols-1 gap-6 md:grid-cols-3">
                    {/* Summary Widget */}
                    {noAccountsSelected ? (
                        <div className="bg-card flex flex-col justify-center rounded-xl border-1 p-6 shadow-xs">
                            <h3 className="mb-4 text-lg font-semibold">Summary</h3>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Income</span>
                                    <span className="text-base font-medium text-green-500">$0.00</span>
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Expenses</span>
                                    <span className="text-base font-medium text-red-500">$0.00</span>
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Resulting Balance</span>
                                    <span className="text-muted-foreground text-base font-medium">$0.00</span>
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Transactions</span>
                                    <span className="text-base font-medium">0</span>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="bg-card flex flex-col justify-center rounded-xl border-1 p-6 shadow-xs">
                            <h3 className="mb-4 text-lg font-semibold">Summary</h3>
                            <div className="grid grid-cols-2 gap-4">
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Income</span>
                                    <span className="text-base font-medium text-green-500">{formatAmount(totalIncome)}</span>
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Expenses</span>
                                    <span className="text-base font-medium text-red-500">{formatAmount(totalExpenses)}</span>
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Resulting Balance</span>
                                    <span className={`text-base font-medium ${netBalance >= 0 ? 'text-green-500' : 'text-red-500'}`}>
                                        {formatAmount(netBalance)}
                                    </span>
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Transactions</span>
                                    <span className="text-base font-medium">{totalTransactions}</span>
                                </div>
                            </div>
                        </div>
                    )}

                    {/* Date Range Info */}
                    {noAccountsSelected ? (
                        <div className="bg-card flex flex-col justify-center rounded-xl border-1 p-6 shadow-xs">
                            <h3 className="mb-4 text-lg font-semibold">Current Analysis</h3>
                            <div className="flex flex-col gap-2">
                                <div className="flex justify-between">
                                    <span className="text-sm text-gray-400">Period:</span>
                                    <span className="text-sm font-medium">No data</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-sm text-gray-400">Date Range:</span>
                                    <span className="text-sm font-medium">-</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-sm text-gray-400">Accounts:</span>
                                    <span className="text-sm font-medium">0 of {accounts.length} selected</span>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <div className="bg-card flex flex-col justify-center rounded-xl border-1 p-6 shadow-xs">
                            <h3 className="mb-4 text-lg font-semibold">Current Analysis</h3>
                            <div className="flex flex-col gap-2">
                                <div className="flex justify-between">
                                    <span className="text-sm text-gray-400">Period:</span>
                                    <span className="text-sm font-medium">{getChartTitle()}</span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-sm text-gray-400">Date Range:</span>
                                    <span className="text-sm font-medium">
                                        {format(new Date(dateRange.start), 'MMM d, yyyy')} - {format(new Date(dateRange.end), 'MMM d, yyyy')}
                                    </span>
                                </div>
                                <div className="flex justify-between">
                                    <span className="text-sm text-gray-400">Accounts:</span>
                                    <span className="text-sm font-medium">
                                        {selectedAccounts.length} of {accounts.length} selected
                                    </span>
                                </div>
                            </div>
                        </div>
                    )}
                </div>

                {/* Recurring overview */}
                <div className="bg-card rounded-xl border-1 p-6 shadow-xs">
                    <h3 className="mb-4 text-lg font-semibold">Recurring overview</h3>
                    {loadingRecurring ? (
                        <div className="text-muted-foreground text-sm">Loading…</div>
                    ) : recurringGroups.length === 0 ? (
                        <div className="text-muted-foreground flex flex-col gap-2 text-sm">
                            <p>No recurring subscriptions.</p>
                            <Link href="/recurring" className="text-primary hover:underline">
                                Go to Recurring payments →
                            </Link>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-4">
                            <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Projected yearly</span>
                                    <span className="text-base font-medium">
                                        {formatAmount(
                                            recurringGroups.reduce((sum, g) => sum + (g.stats?.projected_yearly_cost ?? 0), 0)
                                        )}
                                    </span>
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Total paid (all time)</span>
                                    <span className="text-base font-medium">
                                        {formatAmount(
                                            recurringGroups.reduce((sum, g) => sum + (g.stats?.total_paid ?? 0), 0)
                                        )}
                                    </span>
                                </div>
                                <div className="flex flex-col">
                                    <span className="text-xs text-gray-400">Subscriptions</span>
                                    <span className="text-base font-medium">{recurringGroups.length}</span>
                                </div>
                            </div>
                            {recurringGroups.length > 0 && (
                                <div>
                                    <p className="text-muted-foreground mb-2 text-xs">Top by projected yearly</p>
                                    <ul className="space-y-1 text-sm">
                                        {[...recurringGroups]
                                            .sort((a, b) => (b.stats?.projected_yearly_cost ?? 0) - (a.stats?.projected_yearly_cost ?? 0))
                                            .slice(0, 5)
                                            .map((g) => (
                                                <li key={g.id} className="flex justify-between gap-2">
                                                    <Link href="/recurring" className="min-w-0 truncate hover:underline">
                                                        {g.name}
                                                    </Link>
                                                    <span className="shrink-0 tabular-nums">
                                                        {formatAmount(g.stats?.projected_yearly_cost ?? 0)}
                                                    </span>
                                                </li>
                                            ))}
                                    </ul>
                                    <Link href="/recurring" className="text-muted-foreground mt-2 inline-block text-xs hover:underline">
                                        View all →
                                    </Link>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Cashflow Chart */}
                <div className="bg-card rounded-xl border-1 p-6 shadow-xs">
                    <h3 className="mb-4 text-lg font-semibold">Income & Expenses Over Time</h3>
                    <div className="h-80 w-full">
                        {noAccountsSelected ? (
                            <Chart type="bar" data={formatCashflowForChart([])} options={cashflowChartOptions} />
                        ) : (
                            <Chart type="bar" data={formatCashflowForChart(cashflow)} options={cashflowChartOptions} />
                        )}
                    </div>
                    <div className="text-muted-foreground mt-2 text-center text-xs">
                        <span className="mx-2 inline-block">
                            <span className="mr-1 inline-block h-3 w-3 rounded-sm bg-green-500"></span>
                            Income (above axis)
                        </span>
                        <span className="mx-2 inline-block">
                            <span className="mr-1 inline-block h-3 w-3 rounded-sm bg-red-500"></span>
                            Expenses (below axis)
                        </span>
                        <span className="mx-2 inline-block">
                            <span className="mr-1 inline-block h-3 w-3 rounded-sm bg-blue-500"></span>
                            Resulting Balance (line)
                        </span>
                    </div>
                </div>

                {/* Balance History / Net Worth Chart */}
                <div className="bg-card rounded-xl border-1 p-6 shadow-xs">
                    <h3 className="mb-4 text-lg font-semibold">Net Worth Over Time</h3>
                    <div className="h-80 w-full">
                        {loadingBalanceHistory ? (
                            <div className="flex h-full items-center justify-center">
                                <span className="text-muted-foreground">Loading balance history...</span>
                            </div>
                        ) : noAccountsSelected ? (
                            <div className="flex h-full items-center justify-center">
                                <span className="text-muted-foreground">Select accounts to view balance history</span>
                            </div>
                        ) : (
                            <Line data={formatBalanceHistoryForChart()} options={balanceHistoryChartOptions} />
                        )}
                    </div>
                    <div className="text-muted-foreground mt-2 text-center text-xs">
                        <span className="mx-2 inline-block">
                            <span className="mr-1 inline-block h-3 w-3 rounded-sm bg-indigo-500"></span>
                            Total Net Worth
                        </span>
                        {selectedAccounts.length > 1 && (
                            <span className="text-muted-foreground text-xs italic"> (Individual account balances shown with dashed lines)</span>
                        )}
                    </div>
                </div>

                {/* Category and Merchant Charts */}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {/* Category Spending */}
                    {noAccountsSelected ? (
                        <div className="bg-card rounded-xl border-1 p-6 shadow-xs">
                            <h3 className="mb-4 text-lg font-semibold">Top Categories</h3>
                            <div className="h-80 w-full">
                                <Doughnut data={{ labels: [], datasets: [{ data: [] }] }} options={doughnutChartOptions} />
                            </div>
                            <div className="mt-4 overflow-hidden overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Category</TableHead>
                                            <TableHead className="text-right">Amount</TableHead>
                                            <TableHead className="text-right">%</TableHead>
                                            <TableHead className="text-right">Transactions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-muted-foreground text-center">
                                                No category data available
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    ) : (
                        <div className="bg-card rounded-xl border-1 p-6 shadow-xs">
                            <h3 className="mb-4 text-lg font-semibold">Top Categories</h3>
                            <div className="h-80 w-full">
                                <Doughnut data={formatCategoryForChart()} options={doughnutChartOptions} />
                            </div>

                            {/* Categories List */}
                            <div className="mt-4 overflow-hidden overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Category</TableHead>
                                            <TableHead className="text-right">Amount</TableHead>
                                            <TableHead className="text-right">%</TableHead>
                                            <TableHead className="text-right">Transactions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {categorySpending.categorized.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="text-muted-foreground text-center">
                                                    No category data available
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            <>
                                                {categorySpending.categorized.map((category) => {
                                                    const totalSpending = categorySpending.categorized.reduce((sum, cat) => sum + cat.total, 0);
                                                    const percentage = ((category.total / totalSpending) * 100).toFixed(1);
                                                    return (
                                                        <TableRow key={category.category}>
                                                            <TableCell className="font-medium">{category.category}</TableCell>
                                                            <TableCell className="text-right text-red-500">{formatAmount(category.total)}</TableCell>
                                                            <TableCell className="text-right">{percentage}%</TableCell>
                                                            <TableCell className="text-right">{category.count}</TableCell>
                                                        </TableRow>
                                                    );
                                                })}
                                                {/* Uncategorized row */}
                                                {categorySpending.uncategorized && categorySpending.uncategorized.count > 0 && (
                                                    <TableRow key="uncategorized">
                                                        <TableCell className="text-muted-foreground font-medium">Uncategorized</TableCell>
                                                        <TableCell className="text-right text-red-500">
                                                            {formatAmount(categorySpending.uncategorized.total)}
                                                        </TableCell>
                                                        <TableCell className="text-right">-</TableCell>
                                                        <TableCell className="text-right">{categorySpending.uncategorized.count}</TableCell>
                                                    </TableRow>
                                                )}
                                            </>
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    )}

                    {/* Merchant Spending */}
                    {noAccountsSelected ? (
                        <div className="bg-card rounded-xl border-1 p-6 shadow-xs">
                            <h3 className="mb-4 text-lg font-semibold">Top Merchants</h3>
                            <div className="h-80 w-full">
                                <Bar data={{ labels: [], datasets: [{ data: [] }] }} options={barChartOptions} />
                            </div>
                            <div className="mt-4 overflow-hidden overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Merchant</TableHead>
                                            <TableHead className="text-right">Amount</TableHead>
                                            <TableHead className="text-right">%</TableHead>
                                            <TableHead className="text-right">Transactions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-muted-foreground text-center">
                                                No merchant data available
                                            </TableCell>
                                        </TableRow>
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    ) : (
                        <div className="bg-card rounded-xl border-1 p-6 shadow-xs">
                            <h3 className="mb-4 text-lg font-semibold">Top Merchants</h3>
                            <div className="h-80 w-full">
                                <Bar data={formatMerchantForChart()} options={barChartOptions} />
                            </div>

                            {/* Merchants List */}
                            <div className="mt-4 overflow-hidden overflow-x-auto">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Merchant</TableHead>
                                            <TableHead className="text-right">Amount</TableHead>
                                            <TableHead className="text-right">%</TableHead>
                                            <TableHead className="text-right">Transactions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {merchantSpending.withMerchant.length === 0 ? (
                                            <TableRow>
                                                <TableCell colSpan={4} className="text-muted-foreground text-center">
                                                    No merchant data available
                                                </TableCell>
                                            </TableRow>
                                        ) : (
                                            <>
                                                {merchantSpending.withMerchant.map((merchant) => {
                                                    const totalSpending = merchantSpending.withMerchant.reduce((sum, merch) => sum + merch.total, 0);
                                                    const percentage = ((merchant.total / totalSpending) * 100).toFixed(1);
                                                    return (
                                                        <TableRow key={merchant.merchant}>
                                                            <TableCell className="font-medium">{merchant.merchant}</TableCell>
                                                            <TableCell className="text-right text-red-500">{formatAmount(merchant.total)}</TableCell>
                                                            <TableCell className="text-right">{percentage}%</TableCell>
                                                            <TableCell className="text-right">{merchant.count}</TableCell>
                                                        </TableRow>
                                                    );
                                                })}
                                                {/* No merchant row */}
                                                {merchantSpending.noMerchant && merchantSpending.noMerchant.count > 0 && (
                                                    <TableRow key="no-merchant">
                                                        <TableCell className="text-muted-foreground font-medium">No Merchant</TableCell>
                                                        <TableCell className="text-right text-red-500">
                                                            {formatAmount(merchantSpending.noMerchant.total)}
                                                        </TableCell>
                                                        <TableCell className="text-right">-</TableCell>
                                                        <TableCell className="text-right">{merchantSpending.noMerchant.count}</TableCell>
                                                    </TableRow>
                                                )}
                                            </>
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
