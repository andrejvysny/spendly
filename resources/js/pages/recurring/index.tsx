import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { LoadingDots } from '@/components/ui/loading-dots';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { formatAmount } from '@/utils/currency';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { format, parseISO } from 'date-fns';
import { Check, ChevronDown, ChevronRight, Repeat, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';

function formatBookedDate(dateStr: string): string {
    try {
        const d = dateStr.includes('T') ? parseISO(dateStr) : new Date(dateStr);
        return format(d, 'dd MMM yyyy');
    } catch {
        return dateStr;
    }
}

interface RecurringGroup {
    id: number;
    name: string;
    interval: string;
    interval_days: number | null;
    amount_min: string;
    amount_max: string;
    scope: string;
    status: string;
    first_date: string | null;
    last_date: string | null;
    merchant?: { id: number; name: string } | null;
    account?: { id: number; name: string } | null;
    transactions?: Array<{
        id: number;
        amount: string;
        booked_date: string;
        description: string;
    }>;
    detection_config_snapshot?: { transaction_ids?: number[] };
}

interface AnalyticsData {
    period: { from: string; to: string };
    total_recurring: number;
    by_group: Array<{ id: number; name: string; interval: string; period_total: number }>;
}

export default function RecurringIndex() {
    const [suggested, setSuggested] = useState<RecurringGroup[]>([]);
    const [confirmed, setConfirmed] = useState<RecurringGroup[]>([]);
    const [analytics, setAnalytics] = useState<AnalyticsData | null>(null);
    const [loading, setLoading] = useState(true);
    const [analyticsLoading, setAnalyticsLoading] = useState(true);
    const [detecting, setDetecting] = useState(false);
    const [actioningId, setActioningId] = useState<number | null>(null);
    const [expandedId, setExpandedId] = useState<number | null>(null);

    const fetchGroups = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get<{ data: { suggested: RecurringGroup[]; confirmed: RecurringGroup[] } }>(
                '/api/recurring'
            );
            setSuggested(data.data.suggested);
            setConfirmed(data.data.confirmed);
        } catch (e: unknown) {
            toast.error('Failed to load recurring groups');
            console.error(e);
        } finally {
            setLoading(false);
        }
    }, []);

    const fetchAnalytics = useCallback(async () => {
        setAnalyticsLoading(true);
        try {
            const { data } = await axios.get<{ data: AnalyticsData }>('/api/recurring/analytics');
            setAnalytics(data.data);
        } catch (e: unknown) {
            console.error(e);
        } finally {
            setAnalyticsLoading(false);
        }
    }, []);

    useEffect(() => {
        fetchGroups();
        fetchAnalytics();
    }, [fetchGroups, fetchAnalytics]);

    const handleConfirm = async (group: RecurringGroup) => {
        setActioningId(group.id);
        try {
            await axios.post(`/api/recurring/groups/${group.id}/confirm`, { add_recurring_tag: true });
            toast.success(`"${group.name}" confirmed as recurring`);
            await fetchGroups();
            await fetchAnalytics();
        } catch (e: unknown) {
            toast.error('Failed to confirm');
            console.error(e);
        } finally {
            setActioningId(null);
        }
    };

    const handleDismiss = async (group: RecurringGroup) => {
        setActioningId(group.id);
        try {
            await axios.post(`/api/recurring/groups/${group.id}/dismiss`);
            toast.success('Suggestion dismissed');
            await fetchGroups();
        } catch (e: unknown) {
            toast.error('Failed to dismiss');
            console.error(e);
        } finally {
            setActioningId(null);
        }
    };

    const handleUnlink = async (group: RecurringGroup) => {
        setActioningId(group.id);
        try {
            await axios.post(`/api/recurring/groups/${group.id}/unlink`, { remove_recurring_tag: true });
            toast.success('Recurring group unlinked');
            await fetchGroups();
            await fetchAnalytics();
        } catch (e: unknown) {
            toast.error('Failed to unlink');
            console.error(e);
        } finally {
            setActioningId(null);
        }
    };

    const handleRunDetection = async () => {
        if (detecting) {
            return;
        }
        setDetecting(true);
        try {
            const { data } = await axios.post<{ message?: string; count?: number }>('/api/recurring/detect');
            toast.success(data.message || 'Detection complete');
            await fetchGroups();
            await fetchAnalytics();
        } catch (e: unknown) {
            toast.error('Failed to run detection');
            console.error(e);
        } finally {
            setDetecting(false);
        }
    };

    const toggleExpanded = (id: number) => {
        setExpandedId((prev) => (prev === id ? null : id));
    };

    const breadcrumbs = [{ href: '/recurring', title: 'Recurring' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recurring payments" />
            <div className="mx-auto flex w-full max-w-7xl flex-col gap-6 overflow-hidden p-4 md:p-6">
                <PageHeader
                    title="Recurring payments"
                    subtitle="Subscriptions and recurring transactions"
                    buttons={[
                        {
                            label: detecting ? 'Running…' : 'Run detection',
                            icon: Sparkles,
                            onClick: handleRunDetection,
                            disabled: detecting,
                        },
                    ]}
                />

                {analyticsLoading ? (
                    <div className="flex justify-center py-6">
                        <LoadingDots />
                    </div>
                ) : analytics && (
                    <div className="flex flex-col gap-3 rounded-xl border bg-card p-4 sm:flex-row sm:items-center sm:justify-between sm:p-6">
                        <div className="flex items-center gap-3">
                            <Repeat className="h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />
                            <div className="min-w-0">
                                <p className="text-sm font-medium text-muted-foreground">
                                    This month ({analytics.period.from} – {analytics.period.to})
                                </p>
                                <p className="text-xl font-semibold tabular-nums">
                                    {formatAmount(analytics.total_recurring, 'EUR')}
                                </p>
                            </div>
                        </div>
                        {analytics.by_group.length > 0 && (
                            <ul className="flex min-w-0 flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground sm:border-l sm:pl-4">
                                {analytics.by_group.map((g) => (
                                    <li key={g.id} className="whitespace-nowrap">
                                        {g.name}: {formatAmount(g.period_total, 'EUR')} ({g.interval})
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}

                {loading ? (
                    <div className="flex justify-center py-8">
                        <LoadingDots />
                    </div>
                ) : (
                    <div className="flex flex-col gap-6">
                        <Card className="rounded-xl border shadow-sm">
                            <CardHeader className="pb-3">
                                <CardTitle className="text-lg">Suggested recurring</CardTitle>
                                <CardDescription>
                                    {suggested.length === 0
                                        ? 'Detected subscriptions you can confirm or dismiss'
                                        : 'Confirm to add to recurring, or dismiss'}
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="p-6 pt-0">
                                {suggested.length === 0 ? (
                                    <div className="rounded-lg border border-dashed bg-muted/30 px-4 py-6 text-center">
                                    <p className="text-sm text-muted-foreground">
                                        No suggestions yet. Run detection to find subscriptions from your transactions.
                                    </p>
                                    <Button
                                        className="mt-3"
                                        variant="default"
                                        onClick={handleRunDetection}
                                        disabled={detecting}
                                    >
                                        <Sparkles className="mr-2 h-4 w-4" />
                                        {detecting ? 'Running…' : 'Run detection'}
                                    </Button>
                                </div>
                            ) : (
                                <ul className="space-y-2">
                                    {suggested.map((group) => (
                                        <li
                                            key={group.id}
                                            className="rounded-lg border bg-background transition-colors hover:bg-muted/30"
                                        >
                                            <div className="flex flex-wrap items-center justify-between gap-2 p-3">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleExpanded(group.id)}
                                                    className="flex min-w-0 flex-1 items-center gap-2 rounded p-1 text-left hover:bg-muted/50"
                                                    aria-expanded={expandedId === group.id}
                                                    aria-label={expandedId === group.id ? 'Collapse' : 'Expand'}
                                                >
                                                    {expandedId === group.id ? (
                                                        <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                    ) : (
                                                        <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                    )}
                                                    <span className="truncate font-medium">{group.name}</span>
                                                    <Badge variant="secondary" className="shrink-0 text-xs">
                                                        {group.interval}
                                                    </Badge>
                                                    <span className="shrink-0 text-sm text-muted-foreground">
                                                        {formatAmount(Number(group.amount_min), 'EUR')} –{' '}
                                                        {formatAmount(Number(group.amount_max), 'EUR')}
                                                    </span>
                                                </button>
                                                <div className="flex shrink-0 gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="default"
                                                        onClick={() => handleConfirm(group)}
                                                        disabled={actioningId === group.id}
                                                    >
                                                        <Check className="mr-1 h-4 w-4" />
                                                        Confirm
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => handleDismiss(group)}
                                                        disabled={actioningId === group.id}
                                                    >
                                                        <X className="mr-1 h-4 w-4" />
                                                        Dismiss
                                                    </Button>
                                                </div>
                                            </div>
                                            {expandedId === group.id && (
                                                <div className="border-t px-4 py-2 pb-3 pt-2 pl-9 text-sm text-muted-foreground">
                                                    {group.detection_config_snapshot?.transaction_ids && (
                                                        <p>
                                                            {group.detection_config_snapshot.transaction_ids.length} transaction(s)
                                                            in this series
                                                        </p>
                                                    )}
                                                </div>
                                            )}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="rounded-xl border shadow-sm">
                        <CardHeader className="pb-3">
                            <CardTitle className="text-lg">Confirmed recurring</CardTitle>
                            <CardDescription>
                                Recurring groups you’ve confirmed; unlink to remove the link.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="p-6 pt-0">
                            {confirmed.length === 0 ? (
                                <div className="rounded-lg border border-dashed bg-muted/30 px-4 py-6 text-center">
                                    <p className="text-sm text-muted-foreground">
                                        No confirmed recurring groups yet.
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        Run detection above or import transactions to get started.
                                    </p>
                                </div>
                            ) : (
                                <ul className="space-y-2">
                                    {confirmed.map((group) => {
                                        const amountMin = Number(group.amount_min);
                                        const amountMax = Number(group.amount_max);
                                        const sameAmount = Math.abs(amountMin - amountMax) < 0.02;
                                        return (
                                            <li
                                                key={group.id}
                                                className="rounded-lg border bg-background transition-colors hover:bg-muted/30"
                                            >
                                                <div className="flex flex-wrap items-center justify-between gap-2 p-3">
                                                    <button
                                                        type="button"
                                                        onClick={() => toggleExpanded(group.id)}
                                                        className="flex min-w-0 flex-1 items-center gap-2 rounded p-1 text-left hover:bg-muted/50"
                                                        aria-expanded={expandedId === group.id}
                                                        aria-label={expandedId === group.id ? 'Collapse' : 'Expand'}
                                                    >
                                                        {expandedId === group.id ? (
                                                            <ChevronDown className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                        ) : (
                                                            <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground" />
                                                        )}
                                                        <span className="truncate font-medium">{group.name}</span>
                                                        <Badge variant="secondary" className="shrink-0 text-xs">
                                                            {group.interval}
                                                        </Badge>
                                                        <span className="shrink-0 text-sm text-muted-foreground">
                                                            {sameAmount
                                                                ? formatAmount(amountMin, 'EUR')
                                                                : `${formatAmount(amountMin, 'EUR')} – ${formatAmount(amountMax, 'EUR')}`}
                                                        </span>
                                                    </button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        className="shrink-0 text-muted-foreground hover:text-destructive"
                                                        onClick={() => handleUnlink(group)}
                                                        disabled={actioningId === group.id}
                                                    >
                                                        Unlink
                                                    </Button>
                                                </div>
                                                {expandedId === group.id && group.transactions && (
                                                    <div className="border-t px-4 py-2 pb-3 pt-2 pl-9">
                                                        <ul className="space-y-2 text-sm text-muted-foreground">
                                                            {group.transactions.slice(0, 10).map((tx) => (
                                                                <li key={tx.id} className="flex justify-between gap-2">
                                                                    <span className="truncate">{tx.description}</span>
                                                                    <span className="shrink-0 tabular-nums">
                                                                        {formatBookedDate(tx.booked_date)} · {formatAmount(Number(tx.amount), 'EUR')}
                                                                    </span>
                                                                </li>
                                                            ))}
                                                            {group.transactions.length > 10 && (
                                                                <li className="text-muted-foreground">
                                                                    … and {group.transactions.length - 10} more
                                                                </li>
                                                            )}
                                                        </ul>
                                                    </div>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </CardContent>
                    </Card>
                </div>
                )}
            </div>
        </AppLayout>
    );
}
