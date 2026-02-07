import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Transaction } from '@/types/index';
import { Head, router } from '@inertiajs/react';
import { Check, ChevronLeft } from 'lucide-react';
import React, { useState } from 'react';
import '../../bootstrap';

interface Props {
    transactions: {
        data: Transaction[];
        current_page: number;
        last_page: number;
        per_page: number;
        total: number;
        links: { url: string | null; label: string; active: boolean }[];
    };
    filters?: { review_reason?: string };
}

export default function ReviewQueuePage({ transactions, filters }: Props) {
    const [selectedIds, setSelectedIds] = useState<number[]>([]);

    const handleApprove = (id: number) => {
        router.put(route('transactions.update', id), { needs_manual_review: false }, { preserveScroll: true });
    };

    const handleBulkApprove = () => {
        selectedIds.forEach((id) => {
            router.put(route('transactions.update', id), { needs_manual_review: false }, { preserveScroll: true });
        });
        setSelectedIds([]);
    };

    const toggleSelect = (id: number) => {
        setSelectedIds((prev) => (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]));
    };

    return (
        <AppLayout>
            <Head title="Review queue" />
            <PageHeader
                title="Review queue"
                breadcrumbs={[
                    { label: 'Transactions', href: route('transactions.index') },
                    { label: 'Review queue' },
                ]}
            />
            <div className="mx-auto max-w-6xl space-y-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <div>
                            <CardTitle>Transactions needing review</CardTitle>
                            <CardDescription>
                                {transactions.total} transaction(s) flagged during import. Review and approve to clear the flag.
                            </CardDescription>
                        </div>
                        {selectedIds.length > 0 && (
                            <Button onClick={handleBulkApprove} size="sm">
                                <Check className="mr-1 size-4" />
                                Approve selected ({selectedIds.length})
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto rounded-md border">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b bg-muted/50">
                                        <th className="w-10 px-2 py-2 text-left" />
                                        <th className="px-3 py-2 text-left font-medium">Date</th>
                                        <th className="px-3 py-2 text-left font-medium">Amount</th>
                                        <th className="px-3 py-2 text-left font-medium">Description</th>
                                        <th className="px-3 py-2 text-left font-medium">Account</th>
                                        <th className="px-3 py-2 text-left font-medium">Reason</th>
                                        <th className="w-24 px-3 py-2 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {transactions.data.length === 0 ? (
                                        <tr>
                                            <td colSpan={7} className="px-3 py-8 text-center text-muted-foreground">
                                                No transactions need review.
                                            </td>
                                        </tr>
                                    ) : (
                                        transactions.data.map((tx) => (
                                            <tr key={tx.id} className="border-b last:border-0">
                                                <td className="px-2 py-2">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedIds.includes(tx.id)}
                                                        onChange={() => toggleSelect(tx.id)}
                                                        className="rounded"
                                                    />
                                                </td>
                                                <td className="px-3 py-2">
                                                    {tx.booked_date
                                                        ? new Date(tx.booked_date).toLocaleDateString()
                                                        : '—'}
                                                </td>
                                                <td className="px-3 py-2">{tx.amount != null ? Number(tx.amount).toFixed(2) : '—'}</td>
                                                <td className="max-w-[200px] truncate px-3 py-2" title={tx.description ?? ''}>
                                                    {tx.description ?? '—'}
                                                </td>
                                                <td className="px-3 py-2">{(tx as any).account?.name ?? '—'}</td>
                                                <td className="px-3 py-2">
                                                    {tx.review_reason ? (
                                                        <Badge variant="secondary" className="text-xs">
                                                            {tx.review_reason}
                                                        </Badge>
                                                    ) : (
                                                        '—'
                                                    )}
                                                </td>
                                                <td className="px-3 py-2 text-right">
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        onClick={() => handleApprove(tx.id)}
                                                        title="Approve (clear review flag)"
                                                    >
                                                        <Check className="size-4" />
                                                    </Button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {transactions.last_page > 1 && (
                            <div className="mt-4 flex items-center justify-between">
                                <p className="text-sm text-muted-foreground">
                                    Page {transactions.current_page} of {transactions.last_page}
                                </p>
                                <div className="flex gap-2">
                                    {transactions.links.map((link, i) => (
                                        <Button
                                            key={i}
                                            variant={link.active ? 'default' : 'outline'}
                                            size="sm"
                                            disabled={!link.url}
                                            onClick={() => link.url && router.get(link.url)}
                                        >
                                            {link.label === '&laquo; Previous' ? (
                                                <ChevronLeft className="size-4" />
                                            ) : link.label === 'Next &raquo;' ? (
                                                <ChevronLeft className="size-4 rotate-180" />
                                            ) : (
                                                link.label
                                            )}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
