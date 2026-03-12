import { BudgetCard } from '@/components/budgets/BudgetCard';
import { BudgetTable } from '@/components/budgets/BudgetTable';
import { BudgetTrendChart } from '@/components/budgets/BudgetTrendChart';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { CheckboxInput, SelectInput, TextInput } from '@/components/ui/form-inputs';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import type { BudgetTargetType, BudgetWithProgress } from '@/types';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, CalendarClock, FolderOpen, Landmark, LayoutGrid, List, RefreshCw, Tag, Users, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import { UseFormReturn } from 'react-hook-form';
import { z } from 'zod';

interface CategoryOption {
    id: number;
    name: string;
    color: string | null;
    icon: string | null;
}

interface TagOption {
    id: number;
    name: string;
    color: string | null;
}

interface CounterpartyOption {
    id: number;
    name: string;
    type: string;
}

interface RecurringGroupOption {
    id: number;
    name: string;
    interval: string;
}

interface AccountOption {
    id: number;
    name: string;
    currency: string;
}

interface Props {
    budgets: BudgetWithProgress[];
    categories: CategoryOption[];
    tags: TagOption[];
    counterparties: CounterpartyOption[];
    recurringGroups: RecurringGroupOption[];
    accounts: AccountOption[];
    periodType: string;
    year: number;
    month: number;
    defaultCurrency: string;
    uncategorizedCount: number;
}

const MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

const TARGET_TYPE_OPTIONS: { value: BudgetTargetType; label: string; icon: typeof FolderOpen }[] = [
    { value: 'category', label: 'Category', icon: FolderOpen },
    { value: 'tag', label: 'Tag', icon: Tag },
    { value: 'counterparty', label: 'Counterparty', icon: Users },
    { value: 'subscription', label: 'Subscription', icon: RefreshCw },
    { value: 'account', label: 'Account', icon: Landmark },
    { value: 'overall', label: 'Overall', icon: Wallet },
    { value: 'all_subscriptions', label: 'All Subs', icon: CalendarClock },
];

const formSchema = z.object({
    target_type: z.enum(['category', 'tag', 'counterparty', 'subscription', 'account', 'overall', 'all_subscriptions']),
    category_id: z.string().optional(),
    tag_id: z.string().optional(),
    counterparty_id: z.string().optional(),
    recurring_group_id: z.string().optional(),
    account_id: z.string().optional(),
    amount: z
        .string()
        .min(1, 'Amount is required')
        .refine((v) => !Number.isNaN(Number(v)) && Number(v) > 0, 'Amount must be positive'),
    currency: z.string().length(3, 'Currency must be 3 characters'),
    period_type: z.enum(['monthly', 'yearly']),
    name: z.string().optional(),
    rollover_enabled: z.boolean().optional(),
    rollover_cap: z.string().optional(),
    include_subcategories: z.boolean().optional(),
    include_transfers: z.boolean().optional(),
});

type FormValues = InferFormValues<typeof formSchema>;

interface BudgetFormFieldsProps {
    form: UseFormReturn<FormValues>;
    editingBudget: BudgetWithProgress | null;
    accounts: AccountOption[];
    categoryOptions: { value: string; label: string }[];
    tagOptions: { value: string; label: string }[];
    counterpartyOptions: { value: string; label: string }[];
    recurringGroupOptions: { value: string; label: string }[];
    accountOptions: { value: string; label: string }[];
    closeDialog: () => void;
}

function BudgetFormFields({
    form,
    editingBudget,
    accounts,
    categoryOptions,
    tagOptions,
    counterpartyOptions,
    recurringGroupOptions,
    accountOptions,
    closeDialog,
}: BudgetFormFieldsProps) {
    const targetType = form.watch('target_type');
    const selectedAccountId = form.watch('account_id');

    useEffect(() => {
        if (targetType === 'account' && selectedAccountId) {
            const acc = accounts.find((a) => String(a.id) === selectedAccountId);
            if (acc) {
                form.setValue('currency', acc.currency);
            }
        }
    }, [targetType, selectedAccountId, accounts, form]);

    return (
        <>
            {!editingBudget && (
                <div className="space-y-1.5">
                    <label className="text-muted-foreground text-sm font-semibold">Budget Type</label>
                    <div className="flex flex-wrap gap-1.5">
                        {TARGET_TYPE_OPTIONS.map((opt) => {
                            const Icon = opt.icon;
                            const isSelected = targetType === opt.value;
                            return (
                                <button
                                    key={opt.value}
                                    type="button"
                                    onClick={() => form.setValue('target_type', opt.value)}
                                    className={`flex items-center gap-1.5 rounded-md border px-3 py-1.5 text-sm transition-colors ${
                                        isSelected ? 'border-primary bg-primary/10 text-primary' : 'border-input hover:bg-muted'
                                    }`}
                                >
                                    <Icon className="h-3.5 w-3.5" />
                                    {opt.label}
                                </button>
                            );
                        })}
                    </div>
                </div>
            )}

            {editingBudget && (
                <div className="text-muted-foreground text-sm">
                    Type: <span className="font-medium capitalize">{editingBudget.target_type.replace('_', ' ')}</span>
                </div>
            )}

            {targetType === 'category' && (
                <>
                    <SelectInput<FormValues> name="category_id" label="Category" options={categoryOptions} disabled={!!editingBudget} />
                    <CheckboxInput<FormValues> name="include_subcategories" label="Include subcategories" />
                </>
            )}

            {targetType === 'tag' && <SelectInput<FormValues> name="tag_id" label="Tag" options={tagOptions} disabled={!!editingBudget} />}

            {targetType === 'counterparty' && (
                <SelectInput<FormValues> name="counterparty_id" label="Counterparty" options={counterpartyOptions} disabled={!!editingBudget} />
            )}

            {targetType === 'subscription' && (
                <SelectInput<FormValues> name="recurring_group_id" label="Subscription" options={recurringGroupOptions} disabled={!!editingBudget} />
            )}

            {targetType === 'account' && (
                <>
                    <SelectInput<FormValues> name="account_id" label="Account" options={accountOptions} disabled={!!editingBudget} />
                    <CheckboxInput<FormValues> name="include_transfers" label="Include transfers" />
                </>
            )}

            <TextInput<FormValues> name="amount" label="Amount" type="number" required />
            <TextInput<FormValues> name="currency" label="Currency (e.g. EUR)" required disabled={targetType === 'account' && !!selectedAccountId} />
            <SelectInput<FormValues>
                name="period_type"
                label="Period type"
                options={[
                    { value: 'monthly', label: 'Monthly' },
                    { value: 'yearly', label: 'Yearly' },
                ]}
            />
            <TextInput<FormValues> name="name" label="Name (optional)" />
            {form.watch('rollover_enabled') && (
                <TextInput<FormValues> name="rollover_cap" label="Rollover cap (optional, leave empty for unlimited)" type="number" />
            )}
            <DialogFooter>
                <Button type="button" variant="outline" onClick={closeDialog}>
                    Cancel
                </Button>
                <Button type="submit">{editingBudget ? 'Update' : 'Create'}</Button>
            </DialogFooter>
        </>
    );
}

function formatAmount(value: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(value);
}

function periodLabel(b: BudgetWithProgress, year?: number, month?: number): string {
    if (b.period) {
        const start = new Date(b.period.start_date);
        if (b.period_type === 'yearly') {
            return `${start.getFullYear()}`;
        }
        return `${MONTH_NAMES[start.getMonth()]} ${start.getFullYear()}`;
    }
    if (b.period_type === 'yearly' && year) {
        return `${year}`;
    }
    if (year && month && month >= 1 && month <= 12) {
        return `${MONTH_NAMES[month - 1]} ${year}`;
    }
    return '';
}

function effectiveAmount(b: BudgetWithProgress): number {
    if (b.period) {
        return b.period.amount_budgeted + b.period.rollover_amount;
    }
    return b.amount;
}

export default function BudgetsIndex({
    budgets,
    categories,
    tags,
    counterparties,
    recurringGroups,
    accounts,
    periodType,
    year,
    month,
    defaultCurrency,
    uncategorizedCount,
}: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [editingBudget, setEditingBudget] = useState<BudgetWithProgress | null>(null);
    const [viewMode, setViewMode] = useState<string>(() => localStorage.getItem('budgets_view_mode') ?? 'cards');
    const [trendBudgetId, setTrendBudgetId] = useState<number | null>(null);

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

    const categoryOptions = categories.map((c) => ({ value: String(c.id), label: c.name }));
    const tagOptions = tags.map((t) => ({ value: String(t.id), label: t.name }));
    const counterpartyOptions = counterparties.map((cp) => ({ value: String(cp.id), label: cp.name }));
    const recurringGroupOptions = recurringGroups.map((rg) => ({ value: String(rg.id), label: rg.name }));
    const accountOptions = accounts.map((a) => ({ value: String(a.id), label: `${a.name} (${a.currency})` }));

    const defaultFormValues: FormValues = {
        target_type: 'category',
        category_id: undefined,
        tag_id: undefined,
        counterparty_id: undefined,
        recurring_group_id: undefined,
        account_id: undefined,
        amount: '',
        currency: defaultCurrency,
        period_type: 'monthly',
        name: '',
        rollover_enabled: false,
        rollover_cap: '',
        include_subcategories: true,
        include_transfers: false,
    };

    const onSubmit = (values: FormValues) => {
        const payload: Record<string, string | number | boolean | null> = {
            target_type: values.target_type,
            amount: Number(values.amount),
            currency: values.currency,
            period_type: values.period_type,
            name: values.name || null,
            rollover_enabled: values.rollover_enabled ?? false,
            rollover_cap: values.rollover_cap ? Number(values.rollover_cap) : null,
            include_subcategories: values.include_subcategories ?? true,
            include_transfers: values.include_transfers ?? false,
            category_id: null,
            tag_id: null,
            counterparty_id: null,
            recurring_group_id: null,
            account_id: null,
        };

        switch (values.target_type) {
            case 'category':
                payload.category_id = values.category_id ? Number(values.category_id) : null;
                break;
            case 'tag':
                payload.tag_id = values.tag_id ? Number(values.tag_id) : null;
                break;
            case 'counterparty':
                payload.counterparty_id = values.counterparty_id ? Number(values.counterparty_id) : null;
                break;
            case 'subscription':
                payload.recurring_group_id = values.recurring_group_id ? Number(values.recurring_group_id) : null;
                break;
            case 'account':
                payload.account_id = values.account_id ? Number(values.account_id) : null;
                break;
        }

        if (editingBudget) {
            // Don't send target_type on update (immutable)
            delete payload.target_type;
            delete payload.category_id;
            delete payload.tag_id;
            delete payload.counterparty_id;
            delete payload.recurring_group_id;
            delete payload.account_id;
            router.put(`/budgets/${editingBudget.id}`, payload, { onSuccess: closeDialog });
        } else {
            router.post('/budgets', payload, { onSuccess: closeDialog });
        }
    };

    const confirmDelete = (b: BudgetWithProgress) => {
        const targetName = b.category?.name ?? b.tag?.name ?? b.counterparty?.name ?? b.recurring_group?.name ?? b.account?.name ?? 'Overall';
        if (window.confirm(`Delete budget for ${targetName} (${periodLabel(b, year, month)})?`)) {
            router.delete(`/budgets/${b.id}`);
        }
    };

    const periodLabelFn = (b: BudgetWithProgress) => periodLabel(b, year, month);

    // Summary calculations
    const totalBudgeted = budgets.reduce((sum, b) => sum + effectiveAmount(b), 0);
    const totalSpent = budgets.reduce((sum, b) => sum + b.spent, 0);
    const totalRemaining = Math.max(0, totalBudgeted - totalSpent);
    const totalPercentage = totalBudgeted > 0 ? (totalSpent / totalBudgeted) * 100 : 0;

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

                {uncategorizedCount > 0 && (
                    <Card className="mb-4 border-yellow-500/50 bg-yellow-50 dark:bg-yellow-950/20">
                        <CardContent className="flex items-center gap-3 py-3">
                            <AlertTriangle className="h-5 w-5 shrink-0 text-yellow-600" />
                            <p className="text-sm text-yellow-800 dark:text-yellow-200">
                                <strong>{uncategorizedCount}</strong> uncategorized expense{uncategorizedCount !== 1 ? 's' : ''} in this period.
                                Budget tracking may be inaccurate.{' '}
                                <button className="underline hover:no-underline" onClick={() => router.visit('/transactions?uncategorized=1')}>
                                    Review transactions
                                </button>
                            </p>
                        </CardContent>
                    </Card>
                )}

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

                {budgets.length > 0 && (
                    <Card className="mb-4">
                        <CardContent className="flex flex-wrap items-center justify-between gap-4 py-4">
                            <div className="flex items-center gap-6">
                                <div className="text-sm">
                                    <span className="text-muted-foreground">Total budgeted:</span>{' '}
                                    <span className="font-medium">{formatAmount(totalBudgeted, defaultCurrency)}</span>
                                </div>
                                <div className="text-sm">
                                    <span className="text-muted-foreground">Total spent:</span>{' '}
                                    <span className="font-medium">{formatAmount(totalSpent, defaultCurrency)}</span>
                                </div>
                                <div className="text-sm">
                                    <span className="text-muted-foreground">Remaining:</span>{' '}
                                    <span className={`font-medium ${totalSpent > totalBudgeted ? 'text-destructive' : ''}`}>
                                        {formatAmount(totalRemaining, defaultCurrency)}
                                    </span>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                <div className="bg-muted h-2 w-32 overflow-hidden rounded-full">
                                    <div
                                        className={`h-full rounded-full ${totalSpent > totalBudgeted ? 'bg-destructive' : totalPercentage >= 80 ? 'bg-yellow-500' : 'bg-primary'}`}
                                        style={{ width: `${Math.min(100, totalPercentage)}%` }}
                                    />
                                </div>
                                <span className="text-muted-foreground text-xs">{totalPercentage.toFixed(1)}%</span>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {budgets.length === 0 ? (
                    <Card>
                        <CardContent className="flex flex-col items-center gap-3 py-12">
                            <Wallet className="text-muted-foreground h-12 w-12" />
                            <p className="text-muted-foreground text-center">No budgets for this period.</p>
                            <div className="flex gap-2">
                                <Button variant="outline" onClick={openCreate}>
                                    Create Budget
                                </Button>
                                <Button variant="outline" onClick={() => router.visit('/budgets/builder')}>
                                    Open Builder
                                </Button>
                            </div>
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
                                periodLabel={periodLabelFn}
                                effectiveAmount={effectiveAmount}
                                onShowTrend={(id) => setTrendBudgetId(id)}
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
                                periodLabel={periodLabelFn}
                                effectiveAmount={effectiveAmount}
                                onShowTrend={(id) => setTrendBudgetId(id)}
                            />
                        ))}
                    </div>
                )}

                {trendBudgetId !== null && <BudgetTrendChart budgetId={trendBudgetId} onClose={() => setTrendBudgetId(null)} />}

                <Dialog open={isOpen} onOpenChange={(open) => !open && closeDialog()}>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>{editingBudget ? 'Edit Budget' : 'New Budget'}</DialogTitle>
                            <DialogDescription>
                                {editingBudget ? 'Update the budget amount or settings.' : 'Set a spending limit for a target.'}
                            </DialogDescription>
                        </DialogHeader>
                        <SmartForm
                            key={editingBudget ? `edit-${editingBudget.id}` : 'create'}
                            schema={formSchema}
                            defaultValues={
                                editingBudget
                                    ? {
                                          target_type: editingBudget.target_type,
                                          category_id: editingBudget.category_id ? String(editingBudget.category_id) : undefined,
                                          tag_id: editingBudget.tag_id ? String(editingBudget.tag_id) : undefined,
                                          counterparty_id: editingBudget.counterparty_id ? String(editingBudget.counterparty_id) : undefined,
                                          recurring_group_id: editingBudget.recurring_group_id ? String(editingBudget.recurring_group_id) : undefined,
                                          account_id: editingBudget.account_id ? String(editingBudget.account_id) : undefined,
                                          amount: String(editingBudget.amount),
                                          currency: editingBudget.currency,
                                          period_type: editingBudget.period_type,
                                          name: editingBudget.name ?? '',
                                          rollover_enabled: editingBudget.rollover_enabled,
                                          rollover_cap: editingBudget.rollover_cap != null ? String(editingBudget.rollover_cap) : '',
                                          include_subcategories: editingBudget.include_subcategories,
                                          include_transfers: editingBudget.include_transfers,
                                      }
                                    : defaultFormValues
                            }
                            onSubmit={onSubmit}
                            formProps={{ className: 'space-y-4' }}
                        >
                            {(form) => (
                                <BudgetFormFields
                                    form={form}
                                    editingBudget={editingBudget}
                                    accounts={accounts}
                                    categoryOptions={categoryOptions}
                                    tagOptions={tagOptions}
                                    counterpartyOptions={counterpartyOptions}
                                    recurringGroupOptions={recurringGroupOptions}
                                    accountOptions={accountOptions}
                                    closeDialog={closeDialog}
                                />
                            )}
                        </SmartForm>
                    </DialogContent>
                </Dialog>
            </div>
        </AppLayout>
    );
}
