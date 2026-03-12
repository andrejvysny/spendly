import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import type { BudgetTargetType } from '@/types';
import { Head, router } from '@inertiajs/react';
import { CalendarClock, Check, FolderOpen, Landmark, Lightbulb, Loader2, RefreshCw, Tag, Users, Wallet } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

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

interface ExistingBudget {
    id: number;
    category_id: number | null;
    tag_id: number | null;
    counterparty_id: number | null;
    recurring_group_id: number | null;
    account_id: number | null;
    target_type: BudgetTargetType;
    target_key: string | null;
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
    tags: TagOption[];
    counterparties: CounterpartyOption[];
    recurringGroups: RecurringGroupOption[];
    accounts: AccountOption[];
    existingBudgets: ExistingBudget[];
    defaultCurrency: string;
}

interface BudgetRow {
    target_type: BudgetTargetType;
    target_id: number | null;
    target_name: string;
    target_color: string | null;
    amount: string;
    currency: string;
    existing_budget_id: number | null;
    suggested_amount: number | null;
    has_changes: boolean;
    fk_field: string;
}

function formatCurrency(value: number, currency: string): string {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(value);
}

export default function Builder({ categories, tags, counterparties, recurringGroups, accounts, existingBudgets, defaultCurrency }: Props) {
    const [categoryRows, setCategoryRows] = useState<BudgetRow[]>([]);
    const [tagRows, setTagRows] = useState<BudgetRow[]>([]);
    const [counterpartyRows, setCounterpartyRows] = useState<BudgetRow[]>([]);
    const [subscriptionRows, setSubscriptionRows] = useState<BudgetRow[]>([]);
    const [accountRows, setAccountRows] = useState<BudgetRow[]>([]);
    const [overallRow, setOverallRow] = useState<BudgetRow | null>(null);
    const [allSubsRow, setAllSubsRow] = useState<BudgetRow | null>(null);

    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [loadingSuggestions, setLoadingSuggestions] = useState(false);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        const findExisting = (type: BudgetTargetType, id: number | null, fk: keyof ExistingBudget) =>
            existingBudgets.find((b) => b.target_type === type && b[fk] === id);

        // Categories
        const catRows: BudgetRow[] = categories.map((cat) => {
            const existing = findExisting('category', cat.id, 'category_id');
            return {
                target_type: 'category',
                target_id: cat.id,
                target_name: cat.name,
                target_color: cat.color,
                amount: existing ? String(existing.amount) : '',
                currency: existing?.currency ?? defaultCurrency,
                existing_budget_id: existing?.id ?? null,
                suggested_amount: null,
                has_changes: false,
                fk_field: 'category_id',
            };
        });
        setCategoryRows(catRows);

        // Tags
        setTagRows(
            tags.map((t) => {
                const existing = findExisting('tag', t.id, 'tag_id');
                return {
                    target_type: 'tag',
                    target_id: t.id,
                    target_name: t.name,
                    target_color: t.color,
                    amount: existing ? String(existing.amount) : '',
                    currency: existing?.currency ?? defaultCurrency,
                    existing_budget_id: existing?.id ?? null,
                    suggested_amount: null,
                    has_changes: false,
                    fk_field: 'tag_id',
                };
            }),
        );

        // Counterparties
        setCounterpartyRows(
            counterparties.map((cp) => {
                const existing = findExisting('counterparty', cp.id, 'counterparty_id');
                return {
                    target_type: 'counterparty',
                    target_id: cp.id,
                    target_name: cp.name,
                    target_color: '#f59e0b',
                    amount: existing ? String(existing.amount) : '',
                    currency: existing?.currency ?? defaultCurrency,
                    existing_budget_id: existing?.id ?? null,
                    suggested_amount: null,
                    has_changes: false,
                    fk_field: 'counterparty_id',
                };
            }),
        );

        // Subscriptions
        setSubscriptionRows(
            recurringGroups.map((rg) => {
                const existing = findExisting('subscription', rg.id, 'recurring_group_id');
                return {
                    target_type: 'subscription',
                    target_id: rg.id,
                    target_name: rg.name,
                    target_color: '#10b981',
                    amount: existing ? String(existing.amount) : '',
                    currency: existing?.currency ?? defaultCurrency,
                    existing_budget_id: existing?.id ?? null,
                    suggested_amount: null,
                    has_changes: false,
                    fk_field: 'recurring_group_id',
                };
            }),
        );

        // Accounts
        setAccountRows(
            accounts.map((a) => {
                const existing = findExisting('account', a.id, 'account_id');
                return {
                    target_type: 'account',
                    target_id: a.id,
                    target_name: `${a.name} (${a.currency})`,
                    target_color: '#3b82f6',
                    amount: existing ? String(existing.amount) : '',
                    currency: existing?.currency ?? a.currency,
                    existing_budget_id: existing?.id ?? null,
                    suggested_amount: null,
                    has_changes: false,
                    fk_field: 'account_id',
                };
            }),
        );

        // Overall
        const overallExisting = existingBudgets.find((b) => b.target_type === 'overall');
        setOverallRow({
            target_type: 'overall',
            target_id: null,
            target_name: 'Overall',
            target_color: '#64748b',
            amount: overallExisting ? String(overallExisting.amount) : '',
            currency: overallExisting?.currency ?? defaultCurrency,
            existing_budget_id: overallExisting?.id ?? null,
            suggested_amount: null,
            has_changes: false,
            fk_field: '',
        });

        // All subscriptions
        const allSubsExisting = existingBudgets.find((b) => b.target_type === 'all_subscriptions');
        setAllSubsRow({
            target_type: 'all_subscriptions',
            target_id: null,
            target_name: 'All Subscriptions',
            target_color: '#a855f7',
            amount: allSubsExisting ? String(allSubsExisting.amount) : '',
            currency: allSubsExisting?.currency ?? defaultCurrency,
            existing_budget_id: allSubsExisting?.id ?? null,
            suggested_amount: null,
            has_changes: false,
            fk_field: '',
        });
    }, [categories, tags, counterparties, recurringGroups, accounts, existingBudgets, defaultCurrency]);

    const fetchSuggestions = useCallback(async () => {
        setLoadingSuggestions(true);
        try {
            const res = await fetch('/budgets/suggestions');
            const data = (await res.json()) as { suggestions: Suggestion[] };
            setSuggestions(data.suggestions);

            setCategoryRows((prev) =>
                prev.map((row) => {
                    const suggestion = data.suggestions.find((s) => s.category_id === row.target_id);
                    return suggestion ? { ...row, suggested_amount: suggestion.suggested_amount } : row;
                }),
            );
        } catch {
            // Suggestions are optional
        } finally {
            setLoadingSuggestions(false);
        }
    }, []);

    useEffect(() => {
        fetchSuggestions();
    }, [fetchSuggestions]);

    const updateRow = (setter: React.Dispatch<React.SetStateAction<BudgetRow[]>>, index: number, value: string) => {
        setter((prev) => prev.map((r, i) => (i === index ? { ...r, amount: value, has_changes: true } : r)));
    };

    const applySuggestion = (setter: React.Dispatch<React.SetStateAction<BudgetRow[]>>, index: number) => {
        setter((prev) =>
            prev.map((r, i) => (i === index && r.suggested_amount !== null ? { ...r, amount: String(r.suggested_amount), has_changes: true } : r)),
        );
    };

    const updateSingleRow = (setter: React.Dispatch<React.SetStateAction<BudgetRow | null>>, value: string) => {
        setter((prev) => (prev ? { ...prev, amount: value, has_changes: true } : prev));
    };

    // Collect all changed rows
    const allRows = [
        ...categoryRows,
        ...tagRows,
        ...counterpartyRows,
        ...subscriptionRows,
        ...accountRows,
        ...(overallRow ? [overallRow] : []),
        ...(allSubsRow ? [allSubsRow] : []),
    ];
    const changedRows = allRows.filter((r) => r.has_changes && r.amount !== '');
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
            const payload: Record<string, string | number | boolean | null> = {
                target_type: row.target_type,
                amount: Number(row.amount),
                currency: row.currency,
                period_type: 'monthly',
                name: null,
                rollover_enabled: false,
                include_subcategories: true,
                include_transfers: false,
                category_id: null,
                tag_id: null,
                counterparty_id: null,
                recurring_group_id: null,
                account_id: null,
            };
            if (row.fk_field && row.target_id !== null) {
                payload[row.fk_field] = row.target_id;
            }
            router.post('/budgets', payload, { preserveState: true, preserveScroll: true, onFinish: onDone });
        }

        for (const row of updatedRows) {
            router.put(
                `/budgets/${row.existing_budget_id}`,
                { amount: Number(row.amount), currency: row.currency } as Record<string, string | number | boolean | null>,
                { preserveState: true, preserveScroll: true, onFinish: onDone },
            );
        }
    };

    const totalBudgeted = categoryRows.reduce((sum, r) => {
        const amt = Number(r.amount);
        return sum + (Number.isNaN(amt) ? 0 : amt);
    }, 0);

    const renderRow = (row: BudgetRow, index: number, setter: React.Dispatch<React.SetStateAction<BudgetRow[]>>, icon?: React.ReactNode) => (
        <div
            key={`${row.target_type}-${row.target_id ?? 'special'}`}
            className={`flex items-center gap-3 rounded-md border p-3 ${row.has_changes ? 'border-primary/50 bg-primary/5' : ''}`}
        >
            {icon ?? <div className="h-4 w-4 shrink-0 rounded-full" style={{ backgroundColor: row.target_color ?? '#94a3b8' }} />}
            <div className="min-w-[140px]">
                <span className="text-sm font-medium">{row.target_name}</span>
                {row.existing_budget_id !== null && <span className="text-muted-foreground ml-2 text-xs">(existing)</span>}
            </div>
            <div className="flex flex-1 items-center gap-2">
                <Input
                    type="number"
                    placeholder="0.00"
                    className="w-32"
                    value={row.amount}
                    onChange={(e) => updateRow(setter, index, e.target.value)}
                    min={0}
                    step="0.01"
                />
                <span className="text-muted-foreground text-xs">{row.currency}</span>
                {row.suggested_amount !== null && row.amount !== String(row.suggested_amount) && (
                    <Button variant="ghost" size="sm" className="text-xs" onClick={() => applySuggestion(setter, index)}>
                        <Lightbulb className="mr-1 h-3 w-3 text-yellow-500" />
                        {formatCurrency(row.suggested_amount, row.currency)}
                    </Button>
                )}
                {row.has_changes && <Check className="h-4 w-4 text-green-500" />}
            </div>
        </div>
    );

    const renderSingleRow = (row: BudgetRow | null, setter: React.Dispatch<React.SetStateAction<BudgetRow | null>>, icon?: React.ReactNode) => {
        if (!row) return null;
        return (
            <div className={`flex items-center gap-3 rounded-md border p-3 ${row.has_changes ? 'border-primary/50 bg-primary/5' : ''}`}>
                {icon ?? <div className="h-4 w-4 shrink-0 rounded-full" style={{ backgroundColor: row.target_color ?? '#94a3b8' }} />}
                <div className="min-w-[140px]">
                    <span className="text-sm font-semibold">{row.target_name}</span>
                    {row.existing_budget_id !== null && <span className="text-muted-foreground ml-2 text-xs">(existing)</span>}
                </div>
                <div className="flex flex-1 items-center gap-2">
                    <Input
                        type="number"
                        placeholder="0.00"
                        className="w-32"
                        value={row.amount}
                        onChange={(e) => updateSingleRow(setter, e.target.value)}
                        min={0}
                        step="0.01"
                    />
                    <span className="text-muted-foreground text-xs">{row.currency}</span>
                    {row.has_changes && <Check className="h-4 w-4 text-green-500" />}
                </div>
            </div>
        );
    };

    return (
        <AppLayout>
            <Head title="Budget Builder" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <PageHeader
                    title="Budget Builder"
                    subtitle="Quickly set up budgets for all your targets."
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
                                        <span className="font-medium">{s.category_name}</span>: {formatCurrency(s.suggested_amount, s.currency)}
                                        <span className="text-muted-foreground ml-1">({s.recurring_count} recurring)</span>
                                    </div>
                                ))}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Overall + All Subs */}
                <Card className="mb-6">
                    <CardHeader className="pb-2">
                        <CardTitle className="text-base">Special Budgets</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-2">
                        {renderSingleRow(overallRow, setOverallRow, <Wallet className="h-4 w-4 shrink-0 text-slate-500" />)}
                        {renderSingleRow(allSubsRow, setAllSubsRow, <CalendarClock className="h-4 w-4 shrink-0 text-purple-500" />)}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="pb-2">
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">Monthly Budgets</CardTitle>
                            <div className="text-sm">
                                Category total: <span className="font-medium">{formatCurrency(totalBudgeted, defaultCurrency)}</span>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {loadingSuggestions && (
                            <div className="text-muted-foreground mb-4 flex items-center gap-2 text-sm">
                                <Loader2 className="h-4 w-4 animate-spin" /> Loading suggestions...
                            </div>
                        )}
                        <Tabs defaultValue="categories">
                            <TabsList className="mb-4">
                                <TabsTrigger value="categories" className="gap-1.5">
                                    <FolderOpen className="h-3.5 w-3.5" /> Categories
                                </TabsTrigger>
                                <TabsTrigger value="tags" className="gap-1.5">
                                    <Tag className="h-3.5 w-3.5" /> Tags
                                </TabsTrigger>
                                <TabsTrigger value="counterparties" className="gap-1.5">
                                    <Users className="h-3.5 w-3.5" /> Counterparties
                                </TabsTrigger>
                                <TabsTrigger value="subscriptions" className="gap-1.5">
                                    <RefreshCw className="h-3.5 w-3.5" /> Subscriptions
                                </TabsTrigger>
                                <TabsTrigger value="accounts" className="gap-1.5">
                                    <Landmark className="h-3.5 w-3.5" /> Accounts
                                </TabsTrigger>
                            </TabsList>

                            <TabsContent value="categories">
                                <div className="space-y-2">
                                    {categoryRows.map((row, index) => renderRow(row, index, setCategoryRows))}
                                    {categoryRows.length === 0 && (
                                        <p className="text-muted-foreground py-4 text-center text-sm">No categories found.</p>
                                    )}
                                </div>
                            </TabsContent>

                            <TabsContent value="tags">
                                <div className="space-y-2">
                                    {tagRows.map((row, index) => renderRow(row, index, setTagRows))}
                                    {tagRows.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">No tags found.</p>}
                                </div>
                            </TabsContent>

                            <TabsContent value="counterparties">
                                <div className="space-y-2">
                                    {counterpartyRows.map((row, index) => renderRow(row, index, setCounterpartyRows))}
                                    {counterpartyRows.length === 0 && (
                                        <p className="text-muted-foreground py-4 text-center text-sm">No counterparties found.</p>
                                    )}
                                </div>
                            </TabsContent>

                            <TabsContent value="subscriptions">
                                <div className="space-y-2">
                                    {subscriptionRows.map((row, index) => renderRow(row, index, setSubscriptionRows))}
                                    {subscriptionRows.length === 0 && (
                                        <p className="text-muted-foreground py-4 text-center text-sm">No confirmed recurring groups found.</p>
                                    )}
                                </div>
                            </TabsContent>

                            <TabsContent value="accounts">
                                <div className="space-y-2">
                                    {accountRows.map((row, index) => renderRow(row, index, setAccountRows))}
                                    {accountRows.length === 0 && <p className="text-muted-foreground py-4 text-center text-sm">No accounts found.</p>}
                                </div>
                            </TabsContent>
                        </Tabs>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
