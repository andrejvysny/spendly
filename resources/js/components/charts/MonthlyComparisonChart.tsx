import { CategoryScale, Chart as ChartJS, ChartOptions, Legend, LinearScale, LineElement, PointElement, Title, Tooltip } from 'chart.js';
import { Line } from 'react-chartjs-2';
import { formatAmount } from '@/utils/currency';
import { useMemo } from 'react';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend);

interface MonthlyData {
    date: string;
    dailySpending: number;
}

interface Props {
    firstMonthData: MonthlyData[];
    secondMonthData: MonthlyData[];
    firstMonthLabel: string;
    secondMonthLabel: string;
    currency: string;
    title?: string;
    className?: string;
    height?: string;
}

function MonthlyComparisonChart({
    firstMonthData,
    secondMonthData,
    firstMonthLabel,
    secondMonthLabel,
    currency,
    title,
    className,
    height = 'h-64',
}: Props) {
    // Compute cumulative spending for each month
    const { firstMonthCumulative, secondMonthCumulative, allDays } = useMemo(() => {
        // Create a map of day -> spending for quick lookup
        const firstMap = new Map<number, number>();
        const secondMap = new Map<number, number>();

        firstMonthData.forEach((item) => {
            const day = new Date(item.date).getDate();
            firstMap.set(day, (firstMap.get(day) || 0) + item.dailySpending);
        });

        secondMonthData.forEach((item) => {
            const day = new Date(item.date).getDate();
            secondMap.set(day, (secondMap.get(day) || 0) + item.dailySpending);
        });

        // Get the last day of each month (maximum day with data)
        const firstMonthDays = firstMonthData.map((item) => new Date(item.date).getDate());
        const secondMonthDays = secondMonthData.map((item) => new Date(item.date).getDate());
        const firstMonthLastDay = firstMonthDays.length > 0 ? Math.max(...firstMonthDays) : 0;
        const secondMonthLastDay = secondMonthDays.length > 0 ? Math.max(...secondMonthDays) : 0;
        const maxDay = Math.max(firstMonthLastDay, secondMonthLastDay, 31);

        // Build cumulative spending arrays for days 1..maxDay
        const firstCumulative: (number | null)[] = [];
        const secondCumulative: (number | null)[] = [];
        let firstRunningTotal = 0;
        let secondRunningTotal = 0;

        for (let day = 1; day <= maxDay; day++) {
            const firstSpending = firstMap.get(day) || 0;
            const secondSpending = secondMap.get(day) || 0;

            firstRunningTotal += firstSpending;
            secondRunningTotal += secondSpending;

            // Store cumulative value, null if beyond the month's last day
            firstCumulative.push(day <= firstMonthLastDay ? firstRunningTotal : null);
            secondCumulative.push(day <= secondMonthLastDay ? secondRunningTotal : null);
        }

        // All days 1..maxDay for x-axis labels
        const allDays = Array.from({ length: maxDay }, (_, i) => i + 1);

        return {
            firstMonthCumulative: firstCumulative,
            secondMonthCumulative: secondCumulative,
            allDays,
        };
    }, [firstMonthData, secondMonthData]);

    const options: ChartOptions<'line'> = useMemo(
        () => ({
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
                            const value = context.parsed.y;
                            if (value === null) return null;
                            return `${context.dataset.label}: ${formatAmount(value, currency)}`;
                        },
                    },
                },
                ...(title && {
                    title: {
                        display: true,
                        text: title,
                        color: '#9CA3AF',
                        font: {
                            size: 16,
                        },
                    },
                }),
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
                            return formatAmount(Number(value), currency);
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
            interaction: {
                mode: 'nearest',
                axis: 'x',
                intersect: false,
            },
        }),
        [currency, title]
    );

    const data = useMemo(
        () => ({
            labels: allDays,
            datasets: [
                {
                    label: firstMonthLabel,
                    data: allDays.map((day) => {
                        const index = day - 1;
                        return index < firstMonthCumulative.length ? firstMonthCumulative[index] : null;
                    }),
                    borderColor: 'rgb(34, 197, 94)', // green-400
                    backgroundColor: 'rgba(34, 197, 94, 0.1)',
                    tension: 0,
                    fill: false,
                    spanGaps: true,
                },
                {
                    label: secondMonthLabel,
                    data: allDays.map((day) => {
                        const index = day - 1;
                        return index < secondMonthCumulative.length ? secondMonthCumulative[index] : null;
                    }),
                    borderColor: 'rgb(156, 163, 175)', // gray-400
                    backgroundColor: 'rgba(156, 163, 175, 0.1)',
                    tension: 0,
                    fill: false,
                    spanGaps: true,
                },
            ],
        }),
        [allDays, firstMonthCumulative, secondMonthCumulative, firstMonthLabel, secondMonthLabel]
    );

    return (
        <div className={className || height}>
            <Line options={options} data={data} />
        </div>
    );
}

export default MonthlyComparisonChart;
