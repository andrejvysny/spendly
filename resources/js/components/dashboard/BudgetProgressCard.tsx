import { formatCurrency } from '@/utils/currency';
import { Link } from '@inertiajs/react';

interface BudgetItem {
    name: string;
    budgeted: number;
    spent: number;
    percentage: number;
    is_exceeded: boolean;
    category_color: string | null;
    currency: string;
}

interface Props {
    budgets: BudgetItem[];
}

function getBarColor(percentage: number, isExceeded: boolean): string {
    if (isExceeded) return 'bg-red-500';
    if (percentage >= 90) return 'bg-red-400';
    if (percentage >= 75) return 'bg-yellow-400';
    return 'bg-green-500';
}

export default function BudgetProgressCard({ budgets }: Props) {
    if (budgets.length === 0) {
        return (
            <div className="bg-card rounded-xl p-6 shadow-xs">
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-lg font-semibold">Budget Progress</h3>
                    <Link href="/budgets" className="text-sm text-blue-400 hover:text-blue-300">
                        Set up budgets
                    </Link>
                </div>
                <p className="text-muted-foreground text-sm">No active budgets this month.</p>
            </div>
        );
    }

    return (
        <div className="bg-card rounded-xl p-6 shadow-xs">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="text-lg font-semibold">Budget Progress</h3>
                <Link href="/budgets" className="text-sm text-blue-400 hover:text-blue-300">
                    View all
                </Link>
            </div>
            <div className="space-y-4">
                {budgets.map((b, i) => (
                    <div key={i}>
                        <div className="mb-1 flex items-center justify-between text-sm">
                            <div className="flex items-center gap-2">
                                {b.category_color && <div className="h-2.5 w-2.5 rounded-full" style={{ backgroundColor: b.category_color }} />}
                                <span className="font-medium">{b.name}</span>
                            </div>
                            <span className="text-muted-foreground">
                                {formatCurrency(b.spent, b.currency)} / {formatCurrency(b.budgeted, b.currency)}
                            </span>
                        </div>
                        <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                            <div
                                className={`h-full rounded-full transition-all ${getBarColor(b.percentage, b.is_exceeded)}`}
                                style={{ width: `${Math.min(b.percentage, 100)}%` }}
                            />
                        </div>
                        {b.is_exceeded && (
                            <p className="mt-0.5 text-xs text-red-400">Over budget by {formatCurrency(b.spent - b.budgeted, b.currency)}</p>
                        )}
                    </div>
                ))}
            </div>
        </div>
    );
}
