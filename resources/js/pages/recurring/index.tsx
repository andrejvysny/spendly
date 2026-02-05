import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { LoadingDots } from '@/components/ui/loading-dots';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { formatAmount } from '@/utils/currency';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { Check, ChevronDown, ChevronRight, Repeat, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';

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

    const toggleExpanded = (id: number) => {
        setExpandedId((prev) => (prev === id ? null : id));
    };

    const breadcrumbs = [{ href: '/recurring', title: 'Recurring' }];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recurring payments" />
            <PageHeader
                title="Recurring payments"
                subtitle="Detected subscriptions and recurring transactions"
            />

            {analyticsLoading ? (
                <div className="flex justify-center p-8">
                    <LoadingDots />
                </div>
            ) : analytics && (
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <Repeat className="h-5 w-5" />
                            This month&apos;s recurring total
                        </CardTitle>
                        <CardDescription>
                            {analytics.period.from} – {analytics.period.to}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <p className="text-2xl font-semibold">
                            {formatAmount(analytics.total_recurring, 'EUR')}
                        </p>
                        {analytics.by_group.length > 0 && (
                            <ul className="mt-2 list-inside list-disc text-sm text-muted-foreground">
                                {analytics.by_group.map((g) => (
                                    <li key={g.id}>
                                        {g.name}: {formatAmount(g.period_total, 'EUR')} ({g.interval})
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            )}

            {loading ? (
                <div className="flex justify-center p-8">
                    <LoadingDots />
                </div>
            ) : (
                <div className="space-y-6">
                    {suggested.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>Suggested recurring</CardTitle>
                                <CardDescription>
                                    Possible subscriptions – confirm or dismiss
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="space-y-2">
                                {suggested.map((group) => (
                                    <div
                                        key={group.id}
                                        className="flex flex-col gap-2 rounded-lg border p-3"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleExpanded(group.id)}
                                                    className="p-0.5"
                                                >
                                                    {expandedId === group.id ? (
                                                        <ChevronDown className="h-4 w-4" />
                                                    ) : (
                                                        <ChevronRight className="h-4 w-4" />
                                                    )}
                                                </button>
                                                <span className="font-medium">{group.name}</span>
                                                <Badge variant="secondary">{group.interval}</Badge>
                                                <span className="text-sm text-muted-foreground">
                                                    {formatAmount(Number(group.amount_min), 'EUR')} –{' '}
                                                    {formatAmount(Number(group.amount_max), 'EUR')}
                                                </span>
                                            </div>
                                            <div className="flex gap-2">
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
                                            <div className="ml-6 text-sm text-muted-foreground">
                                                {group.detection_config_snapshot?.transaction_ids && (
                                                    <p>
                                                        {group.detection_config_snapshot.transaction_ids.length} transaction(s)
                                                        in this series
                                                    </p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}

                    <Card>
                        <CardHeader>
                            <CardTitle>Confirmed recurring</CardTitle>
                            <CardDescription>
                                Recurring groups you’ve confirmed
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {confirmed.length === 0 ? (
                                <p className="text-muted-foreground text-sm">
                                    No confirmed recurring groups yet. Confirm suggestions above or run detection after
                                    importing transactions.
                                </p>
                            ) : (
                                confirmed.map((group) => (
                                    <div
                                        key={group.id}
                                        className="flex flex-col gap-2 rounded-lg border p-3"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <button
                                                    type="button"
                                                    onClick={() => toggleExpanded(group.id)}
                                                    className="p-0.5"
                                                >
                                                    {expandedId === group.id ? (
                                                        <ChevronDown className="h-4 w-4" />
                                                    ) : (
                                                        <ChevronRight className="h-4 w-4" />
                                                    )}
                                                </button>
                                                <span className="font-medium">{group.name}</span>
                                                <Badge variant="secondary">{group.interval}</Badge>
                                                <span className="text-sm text-muted-foreground">
                                                    {formatAmount(Number(group.amount_min), 'EUR')} –{' '}
                                                    {formatAmount(Number(group.amount_max), 'EUR')}
                                                </span>
                                            </div>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => handleUnlink(group)}
                                                disabled={actioningId === group.id}
                                            >
                                                Unlink
                                            </Button>
                                        </div>
                                        {expandedId === group.id && group.transactions && (
                                            <ul className="ml-6 list-inside text-sm text-muted-foreground">
                                                {group.transactions.slice(0, 10).map((tx) => (
                                                    <li key={tx.id}>
                                                        {tx.booked_date}: {formatAmount(Number(tx.amount), 'EUR')}{' '}
                                                        – {tx.description}
                                                    </li>
                                                ))}
                                                {group.transactions.length > 10 && (
                                                    <li>… and {group.transactions.length - 10} more</li>
                                                )}
                                            </ul>
                                        )}
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            )}
        </AppLayout>
    );
}
