import { formatCurrency } from '@/utils/currency';
import { Link } from '@inertiajs/react';

interface RecurringItem {
    name: string;
    amount: number;
    next_date: string;
    interval: string;
    counterparty_name: string | null;
}

interface Props {
    items: RecurringItem[];
    currency: string;
}

function daysUntil(dateStr: string): number {
    const now = new Date();
    now.setHours(0, 0, 0, 0);
    const target = new Date(dateStr);
    return Math.ceil((target.getTime() - now.getTime()) / (1000 * 60 * 60 * 24));
}

function formatDaysUntil(days: number): string {
    if (days === 0) return 'Today';
    if (days === 1) return 'Tomorrow';
    return `In ${days} days`;
}

const intervalLabels: Record<string, string> = {
    weekly: 'Weekly',
    monthly: 'Monthly',
    quarterly: 'Quarterly',
    yearly: 'Yearly',
};

export default function UpcomingRecurringCard({ items, currency }: Props) {
    if (items.length === 0) {
        return (
            <div className="bg-card rounded-xl p-6 shadow-xs">
                <div className="mb-4 flex items-center justify-between">
                    <h3 className="text-lg font-semibold">Upcoming Payments</h3>
                    <Link href="/recurring" className="text-sm text-blue-400 hover:text-blue-300">
                        View recurring
                    </Link>
                </div>
                <p className="text-muted-foreground text-sm">No upcoming recurring payments in the next 30 days.</p>
            </div>
        );
    }

    return (
        <div className="bg-card rounded-xl p-6 shadow-xs">
            <div className="mb-4 flex items-center justify-between">
                <h3 className="text-lg font-semibold">Upcoming Payments</h3>
                <Link href="/recurring" className="text-sm text-blue-400 hover:text-blue-300">
                    View all
                </Link>
            </div>
            <div className="space-y-3">
                {items.map((item, i) => {
                    const days = daysUntil(item.next_date);
                    return (
                        <div key={i} className="flex items-center justify-between">
                            <div className="flex flex-col">
                                <span className="text-sm font-medium">{item.counterparty_name || item.name}</span>
                                <div className="flex items-center gap-2">
                                    <span className="text-muted-foreground text-xs">{formatDaysUntil(days)}</span>
                                    <span className="bg-muted rounded px-1.5 py-0.5 text-xs">{intervalLabels[item.interval] || item.interval}</span>
                                </div>
                            </div>
                            <span className="text-sm font-semibold text-red-400">{formatCurrency(Math.abs(item.amount), currency)}</span>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
