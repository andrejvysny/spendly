import { formatCurrency } from '@/utils/currency';

interface Props {
    spendingPace: {
        daily_average: number;
        projected_total: number;
        days_elapsed: number;
        days_in_month: number;
    };
    currency: string;
}

export default function SpendingPaceCard({ spendingPace, currency }: Props) {
    const { daily_average, projected_total, days_elapsed, days_in_month } = spendingPace;
    const progressPct = days_in_month > 0 ? Math.round((days_elapsed / days_in_month) * 100) : 0;

    return (
        <div className="bg-card rounded-xl p-6 shadow-xs">
            <h3 className="mb-4 text-lg font-semibold">Spending Pace</h3>
            <div className="space-y-3">
                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Daily average</span>
                    <span className="font-medium">{formatCurrency(daily_average, currency)}</span>
                </div>
                <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Projected total</span>
                    <span className="font-medium">{formatCurrency(projected_total, currency)}</span>
                </div>
                <div>
                    <div className="text-muted-foreground mb-1 flex justify-between text-xs">
                        <span>Day {days_elapsed}</span>
                        <span>{days_in_month} days</span>
                    </div>
                    <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                        <div className="h-full rounded-full bg-blue-500 transition-all" style={{ width: `${progressPct}%` }} />
                    </div>
                </div>
            </div>
        </div>
    );
}
