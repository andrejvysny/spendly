import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import type { BudgetWithProgress } from '@/types';
import { TrendingUp } from 'lucide-react';
import { BudgetProgressBar } from './BudgetProgressBar';
import { budgetTargetDisplay } from './budgetTargetDisplay';

interface BudgetCardProps {
    budget: BudgetWithProgress;
    onEdit: (budget: BudgetWithProgress) => void;
    onDelete: (budget: BudgetWithProgress) => void;
    formatAmount: (value: number, currency: string) => string;
    periodLabel: (b: BudgetWithProgress) => string;
    effectiveAmount: (b: BudgetWithProgress) => number;
    onShowTrend?: (budgetId: number) => void;
}

function paceLabel(pace: number): { text: string; color: string } {
    if (pace <= 0) return { text: '', color: '' };
    if (pace <= 90) return { text: 'Ahead', color: 'text-green-600' };
    if (pace <= 110) return { text: 'On track', color: 'text-blue-600' };
    return { text: 'Behind', color: 'text-red-600' };
}

export function BudgetCard({ budget: b, onEdit, onDelete, formatAmount, periodLabel, effectiveAmount, onShowTrend }: BudgetCardProps) {
    const total = effectiveAmount(b);
    const pace = paceLabel(b.pace_percentage);
    const target = budgetTargetDisplay(b);
    const Icon = target.icon;

    return (
        <Card>
            <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
                <div className="flex items-center gap-3">
                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" style={{ backgroundColor: target.color }}>
                        <Icon className="h-5 w-5 text-white" />
                    </div>
                    <div>
                        <div className="flex items-center gap-2">
                            <p className="font-medium">{b.name ?? target.label}</p>
                            {b.target_type !== 'overall' && b.target_type !== 'category' && (
                                <span className="text-muted-foreground rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-slate-800">
                                    {target.typeBadge}
                                </span>
                            )}
                        </div>
                        <p className="text-muted-foreground text-sm">
                            {periodLabel(b)}
                            {b.rollover_enabled && b.period && b.period.rollover_amount !== 0 && (
                                <span className={`ml-2 text-xs ${b.period.rollover_amount > 0 ? 'text-green-600' : 'text-red-600'}`}>
                                    {b.period.rollover_amount > 0 ? '+' : ''}
                                    {formatAmount(b.period.rollover_amount, b.currency)} rollover
                                </span>
                            )}
                        </p>
                        {pace.text && b.days_elapsed > 0 && <p className={`text-xs ${pace.color}`}>{pace.text}</p>}
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
                    <BudgetProgressBar
                        percentageUsed={b.percentage_used}
                        isExceeded={b.is_exceeded}
                        pacePosition={b.days_in_period > 0 ? (b.days_elapsed / b.days_in_period) * 100 : undefined}
                    />
                    <div className="flex gap-2">
                        {onShowTrend && (
                            <Button variant="ghost" size="sm" onClick={() => onShowTrend(b.id)} title="Trends">
                                <TrendingUp className="h-4 w-4" />
                            </Button>
                        )}
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
