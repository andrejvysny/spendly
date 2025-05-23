import { CategoryScale, Chart as ChartJS, ChartOptions, Legend, LinearScale, LineElement, PointElement, Title, Tooltip } from 'chart.js';
import { Line } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend);

interface MonthlyData {
    date: string;
    balance: number;
}

interface Props {
    currentMonthData: MonthlyData[];
    previousMonthData: MonthlyData[];
}

function AccountDetailMonthlyComparisonChart({ currentMonthData, previousMonthData }: Props) {
    const options: ChartOptions<'line'> = {
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
                        return `${Number(value).toFixed(2)} €`;
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
    };

    // Get all unique days present in either dataset
    const allDays = [
        ...new Set([
            ...previousMonthData.map((item) => new Date(item.date).getDate()),
            ...currentMonthData.map((item) => new Date(item.date).getDate()),
        ]),
    ].sort((a, b) => a - b);

    const data = {
        labels: allDays,
        datasets: [
            {
                label: 'Current Month',
                data: allDays.map((day) => {
                    const item = currentMonthData.find((d) => new Date(d.date).getDate() === day);
                    return item ? item.balance : null;
                }),
                borderColor: 'rgb(34, 197, 94)', // green-400
                backgroundColor: 'rgba(34, 197, 94, 0.1)',
                tension: 0,
                fill: false,
                spanGaps: true,
            },
            {
                label: 'Previous Month',
                data: allDays.map((day) => {
                    const item = previousMonthData.find((d) => new Date(d.date).getDate() === day);
                    return item ? item.balance : null;
                }),
                borderColor: 'rgb(156, 163, 175)', // gray-400
                backgroundColor: 'rgba(156, 163, 175, 0.1)',
                tension: 0,
                fill: false,
                spanGaps: true,
            },
        ],
    };

    return (
        <div className="h-64">
            <Line options={options} data={data} />
        </div>
    );
}

export default AccountDetailMonthlyComparisonChart;
