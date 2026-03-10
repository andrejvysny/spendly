import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { BudgetWithProgress } from '@/types';
import { BudgetProgressBar } from './BudgetProgressBar';

interface BudgetCardProps {
    budget: BudgetWithProgress;
    onEdit: (budget: BudgetWithProgress) => void;
    onDelete: (budget: BudgetWithProgress) => void;
    formatAmount: (value: number, currency: string) => string;
    periodLabel: (b: BudgetWithProgress) => string;
    effectiveAmount: (b: BudgetWithProgress) => number;
}

export function BudgetCard({ budget: b, onEdit, onDelete, formatAmount, periodLabel, effectiveAmount }: BudgetCardProps) {
    const total = effectiveAmount(b);

    return (
        <Card>
            <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
                <div className="flex items-center gap-3">
                    <div className="h-10 w-10 shrink-0 rounded-full" style={{ backgroundColor: b.category?.color ?? '#94a3b8' }} />
                    <div>
                        <p className="font-medium">{b.name ?? b.category?.name ?? 'Overall'}</p>
                        <p className="text-muted-foreground text-sm">
                            {periodLabel(b)}
                            {b.rollover_enabled && b.period && b.period.rollover_amount > 0 && (
                                <span className="ml-2 text-xs text-green-600">+{formatAmount(b.period.rollover_amount, b.currency)} rollover</span>
                            )}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-6">
                    <div className="text-right text-sm">
                        <p>
                            {formatAmount(b.spent, b.currency)} / {formatAmount(total, b.currency)}
                        </p>
                        <p className="text-muted-foreground">
                            {b.is_exceeded
                                ? `Over by ${formatAmount(b.spent - total, b.currency)}`
                                : `${formatAmount(b.remaining, b.currency)} remaining`}
                        </p>
                    </div>
                    <BudgetProgressBar percentageUsed={b.percentage_used} isExceeded={b.is_exceeded} />
                    <div className="flex gap-2">
                        <Button variant="outline" size="sm" onClick={() => onEdit(b)}>
                            Edit
                        </Button>
                        <Button variant="destructive" size="sm" onClick={() => onDelete(b)}>
                            Delete
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
