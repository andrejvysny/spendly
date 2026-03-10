import { BudgetCard } from '@/components/budgets/BudgetCard';
import { BudgetTable } from '@/components/budgets/BudgetTable';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SelectInput, TextInput } from '@/components/ui/form-inputs';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import type { BudgetWithProgress } from '@/types';
import { Head, router } from '@inertiajs/react';
import { LayoutGrid, List } from 'lucide-react';
import { useEffect, useState } from 'react';
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

const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const formSchema = z.object({
    category_id: z.string().optional(),
    amount: z
        .string()
        .min(1, 'Amount is required')
        .refine((v) => !Number.isNaN(Number(v)) && Number(v) > 0, 'Amount must be positive'),
    currency: z.string().length(3, 'Currency must be 3 characters'),
    period_type: z.enum(['monthly', 'yearly']),
    name: z.string().optional(),
    rollover_enabled: z.boolean().optional(),
    include_subcategories: z.boolean().optional(),
});

type FormValues = InferFormValues<typeof formSchema>;

function formatAmount(value: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(value);
}

function periodLabel(b: BudgetWithProgress): string {
    if (!b.period) {
        return b.period_type === 'yearly' ? '' : '';
    }
    const start = new Date(b.period.start_date);
    if (b.period_type === 'yearly') {
        return `${start.getFullYear()}`;
    }
    return `${MONTH_NAMES[start.getMonth()]} ${start.getFullYear()}`;
}

function effectiveAmount(b: BudgetWithProgress): number {
    if (b.period) {
        return b.period.amount_budgeted + b.period.rollover_amount;
    }
    return b.amount;
}

export default function BudgetsIndex({ budgets, categories, periodType, year, month, defaultCurrency }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [editingBudget, setEditingBudget] = useState<BudgetWithProgress | null>(null);
    const [viewMode, setViewMode] = useState<string>(() => localStorage.getItem('budgets_view_mode') ?? 'cards');

    useEffect(() => {
        localStorage.setItem('budgets_view_mode', viewMode);
    }, [viewMode]);

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

    const categoryOptions = [{ value: '', label: 'Overall (no category)' }, ...categories.map((c) => ({ value: String(c.id), label: c.name }))];

    const defaultFormValues: FormValues = {
        category_id: '',
        amount: '',
        currency: defaultCurrency,
        period_type: 'monthly',
        name: '',
        rollover_enabled: false,
        include_subcategories: true,
    };

    const onSubmit = (values: FormValues) => {
        const payload = {
            category_id: values.category_id ? Number(values.category_id) : null,
            amount: Number(values.amount),
            currency: values.currency,
            period_type: values.period_type,
            name: values.name || null,
            rollover_enabled: values.rollover_enabled ?? false,
            include_subcategories: values.include_subcategories ?? true,
        };
        if (editingBudget) {
            router.put(`/budgets/${editingBudget.id}`, payload as Record<string, string | number | boolean | null>, { onSuccess: closeDialog });
        } else {
            router.post('/budgets', payload as Record<string, string | number | boolean | null>, { onSuccess: closeDialog });
        }
    };

    const confirmDelete = (b: BudgetWithProgress) => {
        if (window.confirm(`Delete budget for ${b.category?.name ?? 'Overall'} (${periodLabel(b)})?`)) {
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
                    buttons={[
                        { onClick: () => router.visit('/budgets/builder'), label: 'Builder' },
                        { onClick: openCreate, label: 'New Budget' },
                    ]}
                />

                <Card className="mb-6">
                    <CardHeader className="pb-2">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Period</CardTitle>
                            <ToggleGroup type="single" value={viewMode} onValueChange={(v) => v && setViewMode(v)} variant="outline" size="sm">
                                <ToggleGroupItem value="cards" aria-label="Card view">
                                    <LayoutGrid className="h-4 w-4" />
                                </ToggleGroupItem>
                                <ToggleGroupItem value="table" aria-label="Table view">
                                    <List className="h-4 w-4" />
                                </ToggleGroupItem>
                            </ToggleGroup>
                        </div>
                    </CardHeader>
                    <CardContent className="flex flex-wrap items-center gap-4">
                        <div className="flex items-center gap-2">
                            <label className="text-sm font-medium">Type</label>
                            <select
                                className="border-input bg-background rounded-md border px-3 py-2 text-sm"
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
                                className="border-input bg-background w-24 rounded-md border px-3 py-2 text-sm"
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
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
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
                        <CardContent className="text-muted-foreground py-8 text-center">
                            No budgets for this period. Create one to start tracking.
                        </CardContent>
                    </Card>
                ) : viewMode === 'table' ? (
                    <Card>
                        <CardContent className="p-0">
                            <BudgetTable
                                budgets={budgets}
                                onEdit={openEdit}
                                onDelete={confirmDelete}
                                formatAmount={formatAmount}
                                periodLabel={periodLabel}
                                effectiveAmount={effectiveAmount}
                            />
                        </CardContent>
                    </Card>
                ) : (
                    <div className="space-y-4">
                        {budgets.map((b) => (
                            <BudgetCard
                                key={b.id}
                                budget={b}
                                onEdit={openEdit}
                                onDelete={confirmDelete}
                                formatAmount={formatAmount}
                                periodLabel={periodLabel}
                                effectiveAmount={effectiveAmount}
                            />
                        ))}
                    </div>
                )}

                <Dialog open={isOpen} onOpenChange={(open) => !open && closeDialog()}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{editingBudget ? 'Edit Budget' : 'New Budget'}</DialogTitle>
                            <DialogDescription>
                                {editingBudget ? 'Update the budget amount or settings.' : 'Set a spending limit for a category.'}
                            </DialogDescription>
                        </DialogHeader>
                        <SmartForm
                            schema={formSchema}
                            defaultValues={
                                editingBudget
                                    ? {
                                          category_id: editingBudget.category_id ? String(editingBudget.category_id) : '',
                                          amount: String(editingBudget.amount),
                                          currency: editingBudget.currency,
                                          period_type: editingBudget.period_type,
                                          name: editingBudget.name ?? '',
                                          rollover_enabled: editingBudget.rollover_enabled,
                                          include_subcategories: editingBudget.include_subcategories,
                                      }
                                    : defaultFormValues
                            }
                            onSubmit={onSubmit}
                            formProps={{ className: 'space-y-4' }}
                        >
                            {() => (
                                <>
                                    <SelectInput<FormValues>
                                        name="category_id"
                                        label="Category"
                                        options={categoryOptions}
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
                                    <TextInput<FormValues> name="name" label="Name (optional)" />
                                    <DialogFooter>
                                        <Button type="button" variant="outline" onClick={closeDialog}>
                                            Cancel
                                        </Button>
                                        <Button type="submit">{editingBudget ? 'Update' : 'Create'}</Button>
                                    </DialogFooter>
                                </>
                            )}
                        </SmartForm>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
