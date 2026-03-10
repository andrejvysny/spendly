import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { BudgetWithProgress } from '@/types';
import { BudgetProgressBar } from './BudgetProgressBar';

interface BudgetTableProps {
    budgets: BudgetWithProgress[];
    onEdit: (budget: BudgetWithProgress) => void;
    onDelete: (budget: BudgetWithProgress) => void;
    formatAmount: (value: number, currency: string) => string;
    periodLabel: (b: BudgetWithProgress) => string;
    effectiveAmount: (b: BudgetWithProgress) => number;
}

export function BudgetTable({ budgets, onEdit, onDelete, formatAmount, periodLabel, effectiveAmount }: BudgetTableProps) {
    return (
        <Table>
            <TableHeader>
                <TableRow>
                    <TableHead>Category</TableHead>
                    <TableHead>Period</TableHead>
                    <TableHead className="text-right">Spent</TableHead>
                    <TableHead className="text-right">Budget</TableHead>
                    <TableHead className="text-right">Remaining</TableHead>
                    <TableHead>Progress</TableHead>
                    <TableHead className="text-right">Actions</TableHead>
                </TableRow>
            </TableHeader>
            <TableBody>
                {budgets.map((b) => {
                    const total = effectiveAmount(b);
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
                                {b.rollover_enabled && b.period && b.period.rollover_amount > 0 && (
                                    <span className="ml-1 text-xs text-green-600">+{formatAmount(b.period.rollover_amount, b.currency)}</span>
                                )}
                            </TableCell>
                            <TableCell className="text-right">{formatAmount(b.spent, b.currency)}</TableCell>
                            <TableCell className="text-right">{formatAmount(total, b.currency)}</TableCell>
                            <TableCell className={`text-right ${b.is_exceeded ? 'text-destructive font-medium' : ''}`}>
                                {b.is_exceeded ? `-${formatAmount(b.spent - total, b.currency)}` : formatAmount(b.remaining, b.currency)}
                            </TableCell>
                            <TableCell>
                                <BudgetProgressBar percentageUsed={b.percentage_used} isExceeded={b.is_exceeded} />
                            </TableCell>
                            <TableCell className="text-right">
                                <div className="flex justify-end gap-2">
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
