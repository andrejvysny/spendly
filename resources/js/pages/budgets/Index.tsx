import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { SelectInput, TextInput } from '@/components/ui/form-inputs';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import type { BudgetWithProgress } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { z } from 'zod';

interface CategoryOption {
    id: number;
    name: string;
    color: string | null;
    icon: string | null;
}

interface Props {
    budgets: BudgetWithProgress[];
    categories: CategoryOption[];
    periodType: string;
    year: number;
    month: number;
    defaultCurrency: string;
}

const MONTH_NAMES = [
    'January', 'February', 'March', 'April', 'May', 'June',
    'July', 'August', 'September', 'October', 'November', 'December',
];

const formSchema = z.object({
    category_id: z.string().min(1, 'Category is required'),
    amount: z.string().min(1, 'Amount is required').refine((v) => !Number.isNaN(Number(v)) && Number(v) > 0, 'Amount must be positive'),
    currency: z.string().length(3, 'Currency must be 3 characters'),
    period_type: z.enum(['monthly', 'yearly']),
    year: z.coerce.number().min(2000).max(2100),
    month: z.coerce.number().min(0).max(12).optional(),
});

type FormValues = InferFormValues<typeof formSchema>;

function formatAmount(value: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(value);
}

function periodLabel(b: BudgetWithProgress): string {
    if (b.period_type === 'yearly') {
        return `${b.year}`;
    }
    return `${MONTH_NAMES[(b.month || 1) - 1]} ${b.year}`;
}

export default function BudgetsIndex({ budgets, categories, periodType, year, month, defaultCurrency }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [editingBudget, setEditingBudget] = useState<BudgetWithProgress | null>(null);

    const openCreate = () => {
        setEditingBudget(null);
        setIsOpen(true);
    };

    const openEdit = (budget: BudgetWithProgress) => {
        setEditingBudget(budget);
        setIsOpen(true);
    };

    const closeDialog = () => {
        setIsOpen(false);
        setEditingBudget(null);
    };

    const changePeriod = (newPeriodType?: string, newYear?: number, newMonth?: number) => {
        const params = new URLSearchParams();
        params.set('period_type', newPeriodType ?? periodType);
        params.set('year', String(newYear ?? year));
        params.set('month', String(newMonth ?? month));
        router.visit(`/budgets?${params.toString()}`);
    };

    const categoryOptions = categories.map((c) => ({ value: String(c.id), label: c.name }));

    const defaultFormValues: FormValues = {
        category_id: '',
        amount: '',
        currency: defaultCurrency,
        period_type: 'monthly',
        year: new Date().getFullYear(),
        month: new Date().getMonth() + 1,
    };

    const onSubmit = (values: FormValues) => {
        const payload = {
            category_id: Number(values.category_id),
            amount: Number(values.amount),
            currency: values.currency,
            period_type: values.period_type,
            year: values.year,
            month: values.period_type === 'monthly' ? Number(values.month ?? 1) : 0,
        };
        if (editingBudget) {
            router.put(`/budgets/${editingBudget.id}`, payload, { onSuccess: closeDialog });
        } else {
            router.post('/budgets', payload, { onSuccess: closeDialog });
        }
    };

    const confirmDelete = (b: BudgetWithProgress) => {
        if (window.confirm(`Delete budget for ${b.category?.name ?? 'category'} (${periodLabel(b)})?`)) {
            router.delete(`/budgets/${b.id}`);
        }
    };

    return (
        <AppLayout>
            <Head title="Budgets" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <PageHeader
                    title="Budgets"
                    subtitle="Set spending limits per category and track progress."
                    buttons={[{ onClick: openCreate, label: 'New Budget' }]}
                />

                <Card className="mb-6">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Period</CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2">
                            <label className="text-sm font-medium">Type</label>
                            <select
                                className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                                value={periodType}
                                onChange={(e) => changePeriod(e.target.value, year, month)}
                            >
                                <option value="monthly">Monthly</option>
                                <option value="yearly">Yearly</option>
                            </select>
                        </div>
                        <div className="flex items-center gap-2">
                            <label className="text-sm font-medium">Year</label>
                            <input
                                type="number"
                                className="w-24 rounded-md border border-input bg-background px-3 py-2 text-sm"
                                value={year}
                                min={2000}
                                max={2100}
                                onChange={(e) => changePeriod(periodType, parseInt(e.target.value, 10), month)}
                            />
                        </div>
                        {periodType === 'monthly' && (
                            <div className="flex items-center gap-2">
                                <label className="text-sm font-medium">Month</label>
                                <select
                                    className="rounded-md border border-input bg-background px-3 py-2 text-sm"
                                    value={month}
                                    onChange={(e) => changePeriod(periodType, year, parseInt(e.target.value, 10))}
                                >
                                    {MONTH_NAMES.map((name, i) => (
                                        <option key={name} value={i + 1}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                            </div>
                        )}
                    </CardContent>
                </Card>

                {budgets.length === 0 ? (
                    <Card>
                        <CardContent className="py-8 text-center text-muted-foreground">
                            No budgets for this period. Create one to start tracking.
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-4">
                        {budgets.map((b) => (
                            <Card key={b.id}>
                                <CardContent className="flex flex-wrap items-center justify-between gap-4 pt-6">
                                    <div className="flex items-center gap-3">
                                        <div
                                            className="h-10 w-10 shrink-0 rounded-full"
                                            style={{ backgroundColor: b.category?.color ?? '#94a3b8' }}
                                        />
                                        <div>
                                            <p className="font-medium">{b.category?.name ?? 'Unknown'}</p>
                                            <p className="text-sm text-muted-foreground">{periodLabel(b)}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-center gap-6">
                                        <div className="text-right text-sm">
                                            <p>
                                                {formatAmount(b.spent, b.currency)} / {formatAmount(b.amount, b.currency)}
                                            </p>
                                            <p className="text-muted-foreground">
                                                {b.is_exceeded
                                                    ? `Over by ${formatAmount(b.spent - b.amount, b.currency)}`
                                                    : `${formatAmount(b.remaining, b.currency)} remaining`}
                                            </p>
                                        </div>
                                        <div className="w-32">
                                            <div className="h-2 w-full overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className={`h-full rounded-full ${
                                                        b.is_exceeded ? 'bg-destructive' : 'bg-primary'
                                                    }`}
                                                    style={{
                                                        width: `${Math.min(100, b.percentage_used)}%`,
                                                    }}
                                                />
                                            </div>
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {b.percentage_used.toFixed(1)}% used
                                            </p>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button variant="outline" size="sm" onClick={() => openEdit(b)}>
                                                Edit
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                size="sm"
                                                onClick={() => confirmDelete(b)}
                                            >
                                                Delete
                                            </Button>
                                        </div>
                                    </div>
                                </CardContent>
                            </Card>
                        ))}
                    </div>
                )}

                <Dialog open={isOpen} onOpenChange={(open) => !open && closeDialog()}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{editingBudget ? 'Edit Budget' : 'New Budget'}</DialogTitle>
                            <DialogDescription>
                                {editingBudget
                                    ? 'Update the budget amount or period.'
                                    : 'Set a spending limit for a category for a specific period.'}
                            </DialogDescription>
                        </DialogHeader>
                        <SmartForm
                            schema={formSchema}
                            defaultValues={
                                editingBudget
                                    ? {
                                          category_id: String(editingBudget.category_id),
                                          amount: String(editingBudget.amount),
                                          currency: editingBudget.currency,
                                          period_type: editingBudget.period_type,
                                          year: editingBudget.year,
                                          month: editingBudget.period_type === 'monthly' ? editingBudget.month : 0,
                                      }
                                    : defaultFormValues
                            }
                            onSubmit={onSubmit}
                            formProps={{ className: 'space-y-4' }}
                        >
                            {({ watch }) => {
                                const pt = watch('period_type');
                                return (
                                    <>
                                        <SelectInput<FormValues>
                                            name="category_id"
                                            label="Category"
                                            options={categoryOptions}
                                            required
                                            disabled={!!editingBudget}
                                        />
                                        <TextInput<FormValues> name="amount" label="Amount" type="number" required />
                                        <TextInput<FormValues> name="currency" label="Currency (e.g. EUR)" required />
                                        <SelectInput<FormValues>
                                            name="period_type"
                                            label="Period type"
                                            options={[
                                                { value: 'monthly', label: 'Monthly' },
                                                { value: 'yearly', label: 'Yearly' },
                                            ]}
                                        />
                                        <TextInput<FormValues> name="year" label="Year" type="number" required />
                                        {pt === 'monthly' && (
                                            <SelectInput<FormValues>
                                                name="month"
                                                label="Month"
                                                options={MONTH_NAMES.map((name, i) => ({
                                                    value: String(i + 1),
                                                    label: name,
                                                }))}
                                            />
                                        )}
                                        <DialogFooter>
                                            <Button type="button" variant="outline" onClick={closeDialog}>
                                                Cancel
                                            </Button>
                                            <Button type="submit">{editingBudget ? 'Update' : 'Create'}</Button>
                                        </DialogFooter>
                                    </>
                                );
                            }}
                        </SmartForm>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
