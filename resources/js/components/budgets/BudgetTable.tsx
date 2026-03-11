import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { BudgetWithProgress } from '@/types';
import { TrendingUp } from 'lucide-react';
import { BudgetProgressBar } from './BudgetProgressBar';

interface BudgetTableProps {
    budgets: BudgetWithProgress[];
    onEdit: (budget: BudgetWithProgress) => void;
    onDelete: (budget: BudgetWithProgress) => void;
    formatAmount: (value: number, currency: string) => string;
    periodLabel: (b: BudgetWithProgress) => string;
    effectiveAmount: (b: BudgetWithProgress) => number;
    onShowTrend?: (budgetId: number) => void;
}

function paceLabel(pace: number): { text: string; color: string } {
    if (pace <= 0) return { text: '-', color: 'text-muted-foreground' };
    if (pace <= 90) return { text: 'Ahead', color: 'text-green-600' };
    if (pace <= 110) return { text: 'On track', color: 'text-blue-600' };
    return { text: 'Behind', color: 'text-red-600' };
}

export function BudgetTable({ budgets, onEdit, onDelete, formatAmount, periodLabel, effectiveAmount, onShowTrend }: BudgetTableProps) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Category</TableHead>
                    <TableHead>Period</TableHead>
                    <TableHead className="text-right">Spent</TableHead>
                    <TableHead className="text-right">Budget</TableHead>
                    <TableHead className="text-right">Remaining</TableHead>
                    <TableHead>Pace</TableHead>
                    <TableHead>Progress</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {budgets.map((b) => {
                    const total = effectiveAmount(b);
                    const pace = paceLabel(b.pace_percentage);
                    return (
                        <TableRow key={b.id}>
                            <TableCell>
                                <div className="flex items-center gap-2">
                                    <div className="h-3 w-3 shrink-0 rounded-full" style={{ backgroundColor: b.category?.color ?? '#94a3b8' }} />
                                    <span className="font-medium">{b.name ?? b.category?.name ?? 'Overall'}</span>
                                </div>
                            </TableCell>
                            <TableCell className="text-muted-foreground text-sm">
                                {periodLabel(b)}
                                {b.rollover_enabled && b.period && b.period.rollover_amount !== 0 && (
                                    <span className={`ml-1 text-xs ${b.period.rollover_amount > 0 ? 'text-green-600' : 'text-red-600'}`}>
                                        {b.period.rollover_amount > 0 ? '+' : ''}
                                        {formatAmount(b.period.rollover_amount, b.currency)}
                                    </span>
                                )}
                            </TableCell>
                            <TableCell className="text-right">{formatAmount(b.spent, b.currency)}</TableCell>
                            <TableCell className="text-right">{formatAmount(total, b.currency)}</TableCell>
                            <TableCell className={`text-right ${b.is_exceeded ? 'text-destructive font-medium' : ''}`}>
                                {b.is_exceeded ? `-${formatAmount(b.spent - total, b.currency)}` : formatAmount(b.remaining, b.currency)}
                            </TableCell>
                            <TableCell>
                                <span className={`text-xs font-medium ${pace.color}`}>{pace.text}</span>
                            </TableCell>
                            <TableCell>
                                <BudgetProgressBar
                                    percentageUsed={b.percentage_used}
                                    isExceeded={b.is_exceeded}
                                    pacePosition={b.days_in_period > 0 ? (b.days_elapsed / b.days_in_period) * 100 : undefined}
                                />
                            </TableCell>
                            <TableCell className="text-right">
                                <div className="flex justify-end gap-2">
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
                            </TableCell>
                        </TableRow>
                    );
                })}
            </TableBody>
        </Table>
    );
}
