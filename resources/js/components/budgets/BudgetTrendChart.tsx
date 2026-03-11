import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Title, Tooltip } from 'chart.js';
import { X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Bar } from 'react-chartjs-2';

ChartJS.register(CategoryScale, LinearScale, BarElement, Title, Tooltip, Legend);

interface HistoryEntry {
    label: string;
    budgeted: number;
    spent: number;
    rollover: number;
}

interface BudgetTrendChartProps {
    budgetId: number;
    onClose: () => void;
}

export function BudgetTrendChart({ budgetId, onClose }: BudgetTrendChartProps) {
    const [history, setHistory] = useState<HistoryEntry[]>([]);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        setLoading(true);
        fetch(`/budgets/${budgetId}/history`, {
            headers: { Accept: 'application/json' },
        })
            .then((res) => res.json())
            .then((data) => {
                setHistory(data.history ?? []);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    }, [budgetId]);

    const data = {
        labels: history.map((h) => h.label),
        datasets: [
            {
                label: 'Budgeted',
                data: history.map((h) => h.budgeted),
                backgroundColor: 'rgba(59, 130, 246, 0.5)',
                borderColor: 'rgb(59, 130, 246)',
                borderWidth: 1,
            },
            {
                label: 'Spent',
                data: history.map((h) => h.spent),
                backgroundColor: 'rgba(239, 68, 68, 0.5)',
                borderColor: 'rgb(239, 68, 68)',
                borderWidth: 1,
            },
        ],
    };

    const options = {
        responsive: true,
        plugins: {
            legend: { position: 'top' as const },
        },
        scales: {
            y: { beginAtZero: true },
        },
    };

    return (
        <Card className="mt-4">
            <CardHeader className="flex flex-row items-center justify-between pb-2">
                <CardTitle className="text-base">Budget vs Actual Trend</CardTitle>
                <button onClick={onClose} className="text-muted-foreground hover:text-foreground">
                    <X className="h-4 w-4" />
                </button>
            </CardHeader>
            <CardContent>
                {loading ? (
                    <p className="text-muted-foreground py-8 text-center text-sm">Loading...</p>
                ) : history.length === 0 ? (
                    <p className="text-muted-foreground py-8 text-center text-sm">No history data available.</p>
                ) : (
                    <Bar data={data} options={options} />
                )}
            </CardContent>
        </Card>
    );
}
