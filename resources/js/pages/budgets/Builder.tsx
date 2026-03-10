import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Head, router } from '@inertiajs/react';
import { Check, Lightbulb, Loader2 } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface CategoryOption {
    id: number;
    name: string;
    color: string | null;
    icon: string | null;
}

interface ExistingBudget {
    id: number;
    category_id: number | null;
    amount: number;
    currency: string;
    period_type: string;
}

interface Suggestion {
    category_id: number | null;
    category_name: string;
    suggested_amount: number;
    currency: string;
    recurring_count: number;
    sources: { name: string; amount: number; interval: string }[];
}

interface Props {
    categories: CategoryOption[];
    existingBudgets: ExistingBudget[];
    defaultCurrency: string;
}

interface BudgetRow {
    category_id: number | null;
    category_name: string;
    category_color: string | null;
    amount: string;
    currency: string;
    existing_budget_id: number | null;
    suggested_amount: number | null;
    has_changes: boolean;
}

export default function Builder({ categories, existingBudgets, defaultCurrency }: Props) {
    const [rows, setRows] = useState<BudgetRow[]>([]);
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [loadingSuggestions, setLoadingSuggestions] = useState(false);
    const [saving, setSaving] = useState(false);

    // Initialize rows from categories + existing budgets
    useEffect(() => {
        const existingMap = new Map(existingBudgets.filter((b) => b.category_id !== null).map((b) => [b.category_id, b]));

        const initialRows: BudgetRow[] = categories.map((cat) => {
            const existing = existingMap.get(cat.id);
            return {
                category_id: cat.id,
                category_name: cat.name,
                category_color: cat.color,
                amount: existing ? String(existing.amount) : '',
                currency: existing?.currency ?? defaultCurrency,
                existing_budget_id: existing?.id ?? null,
                suggested_amount: null,
                has_changes: false,
            };
        });

        // Add overall budget row
        const overallBudget = existingBudgets.find((b) => b.category_id === null);
        initialRows.unshift({
            category_id: null,
            category_name: 'Overall',
            category_color: '#94a3b8',
            amount: overallBudget ? String(overallBudget.amount) : '',
            currency: overallBudget?.currency ?? defaultCurrency,
            existing_budget_id: overallBudget?.id ?? null,
            suggested_amount: null,
            has_changes: false,
        });

        setRows(initialRows);
    }, [categories, existingBudgets, defaultCurrency]);

    const fetchSuggestions = useCallback(async () => {
        setLoadingSuggestions(true);
        try {
            const res = await fetch('/budgets/suggestions');
            const data = (await res.json()) as { suggestions: Suggestion[] };
            setSuggestions(data.suggestions);

            // Apply suggestions to rows
            setRows((prev) =>
                prev.map((row) => {
                    const suggestion = data.suggestions.find((s) => s.category_id === row.category_id);
                    if (suggestion) {
                        return { ...row, suggested_amount: suggestion.suggested_amount };
                    }
                    return row;
                }),
            );
        } catch {
            // Silently fail - suggestions are optional
        } finally {
            setLoadingSuggestions(false);
        }
    }, []);

    useEffect(() => {
        fetchSuggestions();
    }, [fetchSuggestions]);

    const updateAmount = (index: number, value: string) => {
        setRows((prev) => prev.map((r, i) => (i === index ? { ...r, amount: value, has_changes: true } : r)));
    };

    const applySuggestion = (index: number) => {
        setRows((prev) =>
            prev.map((r, i) => (i === index && r.suggested_amount !== null ? { ...r, amount: String(r.suggested_amount), has_changes: true } : r)),
        );
    };

    const changedRows = rows.filter((r) => r.has_changes && r.amount !== '');
    const newRows = changedRows.filter((r) => r.existing_budget_id === null);
    const updatedRows = changedRows.filter((r) => r.existing_budget_id !== null);

    const saveAll = () => {
        if (changedRows.length === 0) return;
        setSaving(true);

        let pending = changedRows.length;
        const onDone = () => {
            pending--;
            if (pending <= 0) {
                setSaving(false);
                router.visit('/budgets');
            }
        };

        for (const row of newRows) {
            router.post(
                '/budgets',
                {
                    category_id: row.category_id,
                    amount: Number(row.amount),
                    currency: row.currency,
                    period_type: 'monthly',
                    name: null,
                    rollover_enabled: false,
                    include_subcategories: true,
                } as Record<string, string | number | boolean | null>,
                { preserveState: true, preserveScroll: true, onFinish: onDone },
            );
        }

        for (const row of updatedRows) {
            router.put(
                `/budgets/${row.existing_budget_id}`,
                {
                    amount: Number(row.amount),
                    currency: row.currency,
                } as Record<string, string | number | boolean | null>,
                { preserveState: true, preserveScroll: true, onFinish: onDone },
            );
        }
    };

    const totalBudgeted = rows.reduce((sum, r) => {
        if (r.category_id === null) return sum; // exclude overall from total
        const amt = Number(r.amount);
        return sum + (Number.isNaN(amt) ? 0 : amt);
    }, 0);

    return (
        <AppLayout>
            <Head title="Budget Builder" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <PageHeader
                    title="Budget Builder"
                    subtitle="Quickly set up budgets for all your categories."
                    buttons={[
                        { onClick: () => router.visit('/budgets'), label: 'Back to Budgets' },
                        {
                            onClick: saveAll,
                            label: saving ? 'Saving...' : `Save ${changedRows.length} Changes`,
                            disabled: changedRows.length === 0 || saving,
                        },
                    ]}
                />

                {suggestions.length > 0 && (
                    <Card className="mb-6">
                        <CardHeader className="pb-2">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Lightbulb className="h-4 w-4 text-yellow-500" />
                                Suggestions from Recurring Transactions
                            </CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-muted-foreground mb-3 text-sm">
                                Based on {suggestions.reduce((s, sg) => s + sg.recurring_count, 0)} confirmed recurring transactions. Amounts include
                                a 10% buffer.
                            </p>
                            <div className="flex flex-wrap gap-2">
                                {suggestions.map((s) => (
                                    <div key={s.category_id ?? 'none'} className="bg-muted rounded-md px-3 py-1.5 text-sm">
                                        <span className="font-medium">{s.category_name}</span>:{' '}
                                        {new Intl.NumberFormat(undefined, { style: 'currency', currency: s.currency }).format(s.suggested_amount)}
                                        <span className="text-muted-foreground ml-1">({s.recurring_count} recurring)</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                <Card>
                    <CardHeader className="pb-2">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Monthly Budgets</CardTitle>
                            <div className="text-sm">
                                Total:{' '}
                                <span className="font-medium">
                                    {new Intl.NumberFormat(undefined, { style: 'currency', currency: defaultCurrency }).format(totalBudgeted)}
                                </span>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {loadingSuggestions && (
                            <div className="text-muted-foreground mb-4 flex items-center gap-2 text-sm">
                                <Loader2 className="h-4 w-4 animate-spin" /> Loading suggestions...
                            </div>
                        )}
                        <div className="space-y-2">
                            {rows.map((row, index) => (
                                <div
                                    key={row.category_id ?? 'overall'}
                                    className={`flex items-center gap-3 rounded-md border p-3 ${row.has_changes ? 'border-primary/50 bg-primary/5' : ''} ${row.category_id === null ? 'bg-muted/50' : ''}`}
                                >
                                    <div className="h-4 w-4 shrink-0 rounded-full" style={{ backgroundColor: row.category_color ?? '#94a3b8' }} />
                                    <div className="min-w-[140px]">
                                        <span className={`text-sm ${row.category_id === null ? 'font-semibold' : 'font-medium'}`}>
                                            {row.category_name}
                                        </span>
                                        {row.existing_budget_id !== null && <span className="text-muted-foreground ml-2 text-xs">(existing)</span>}
                                    </div>
                                    <div className="flex flex-1 items-center gap-2">
                                        <Input
                                            type="number"
                                            placeholder="0.00"
                                            className="w-32"
                                            value={row.amount}
                                            onChange={(e) => updateAmount(index, e.target.value)}
                                            min={0}
                                            step="0.01"
                                        />
                                        <span className="text-muted-foreground text-xs">{row.currency}</span>
                                        {row.suggested_amount !== null && row.amount !== String(row.suggested_amount) && (
                                            <Button variant="ghost" size="sm" className="text-xs" onClick={() => applySuggestion(index)}>
                                                <Lightbulb className="mr-1 h-3 w-3 text-yellow-500" />
                                                {new Intl.NumberFormat(undefined, { style: 'currency', currency: row.currency }).format(
                                                    row.suggested_amount,
                                                )}
                                            </Button>
                                        )}
                                        {row.has_changes && <Check className="h-4 w-4 text-green-500" />}
                                    </div>
                                </div>
                            ))}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
