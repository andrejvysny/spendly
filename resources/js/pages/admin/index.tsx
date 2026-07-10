import { AccountFilterPills } from '@/components/admin/transaction-labeling/AccountFilterPills';
import { BulkActionBar } from '@/components/admin/transaction-labeling/BulkActionBar';
import { LabelingStatsBar } from '@/components/admin/transaction-labeling/LabelingStatsBar';
import { StatusFilterPills } from '@/components/admin/transaction-labeling/StatusFilterPills';
import { TransactionLabelingTable } from '@/components/admin/transaction-labeling/TransactionLabelingTable';
import { TypeFilterPills } from '@/components/admin/transaction-labeling/TypeFilterPills';
import { useTransactionLabelingShortcuts } from '@/components/admin/transaction-labeling/hooks/useTransactionLabelingShortcuts';
import { initialState, labelingReducer } from '@/components/admin/transaction-labeling/lib/adminTransactionLabelingReducer';
import type { TransactionPatch } from '@/components/admin/transaction-labeling/lib/adminTransactionLabelingTypes';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem, Category, Counterparty, Tag, User } from '@/types';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useCallback, useEffect, useReducer, useState } from 'react';
import { toast } from 'react-toastify';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Admin', href: '/admin' },
    { title: 'Transaction Labeling', href: '/admin' },
];

axios.defaults.withCredentials = true;
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

export default function AdminTransactionLabeling() {
    const [state, dispatch] = useReducer(labelingReducer, initialState);
    const [users, setUsers] = useState<User[]>([]);
    const [categories, setCategories] = useState<Category[]>([]);
    const [counterparties, setCounterparties] = useState<Counterparty[]>([]);
    const [tags, setTags] = useState<Tag[]>([]);
    const [isDataLoaded, setIsDataLoaded] = useState(false);

    // Fetch initial data (users, categories, counterparties, tags)
    useEffect(() => {
        const loadInitialData = async () => {
            try {
                const [usersRes, categoriesRes, counterpartiesRes, tagsRes] = await Promise.all([
                    axios.get('/admin/users'),
                    axios.get('/admin/categories'),
                    axios.get('/admin/counterparties'),
                    axios.get('/admin/tags'), // Assuming there's a tags endpoint
                ]);
                setUsers(usersRes.data.users || []);
                setCategories(categoriesRes.data.categories || []);
                setCounterparties(counterpartiesRes.data.counterparties || []);
                setTags(tagsRes.data.tags || []);
                setIsDataLoaded(true);
            } catch (error) {
                console.error('Failed to load initial data:', error);
                toast.error('Failed to load initial data');
            }
        };
        loadInitialData();
    }, []);

    // Fetch transactions when filters change
    useEffect(() => {
        if (!isDataLoaded) return;

        const fetchTransactions = async () => {
            dispatch({ type: 'SET_LOADING', payload: true });
            try {
                const params: Record<string, unknown> = {
                    status: state.filters.status,
                    type: state.filters.type,
                    page: state.filters.page,
                    per_page: state.filters.per_page,
                };

                if (state.filters.user_id) params.user_id = state.filters.user_id;
                if (state.filters.search) params.search = state.filters.search;
                if (state.filters.account_ids.length > 0) params.account_ids = state.filters.account_ids;
                if (state.filters.category_id) params.category_id = state.filters.category_id;
                if (state.filters.merchant_id) params.merchant_id = state.filters.merchant_id;
                if (state.filters.date_from) params.date_from = state.filters.date_from;
                if (state.filters.date_to) params.date_to = state.filters.date_to;

                const response = await axios.get('/admin/transactions', { params });

                dispatch({
                    type: 'LOAD_TRANSACTIONS_SUCCESS',
                    payload: {
                        rows: response.data.transactions.data,
                        stats: response.data.stats,
                        filterOptions: response.data.filter_options,
                        page: response.data.transactions.current_page,
                        lastPage: response.data.transactions.last_page,
                    },
                });
            } catch (error) {
                console.error('Failed to load transactions:', error);
                toast.error('Failed to load transactions');
                dispatch({ type: 'SET_LOADING', payload: false });
            }
        };

        fetchTransactions();
    }, [
        isDataLoaded,
        state.filters.status,
        state.filters.type,
        state.filters.user_id,
        state.filters.search,
        state.filters.account_ids,
        state.filters.category_id,
        state.filters.merchant_id,
        state.filters.date_from,
        state.filters.date_to,
        state.filters.page,
        state.filters.per_page,
    ]);

    // Create a new category from the inline labeling UI.
    // Server scopes the new category to the currently filtered user when one is selected.
    const createCategory = useCallback(
        async (name: string): Promise<number | null> => {
            try {
                const payload: Record<string, unknown> = { name };
                if (state.filters.user_id) {
                    payload.target_user_id = state.filters.user_id;
                }
                const response = await axios.post('/admin/categories', payload);
                const created = response.data?.category;
                if (!created?.id) return null;
                setCategories((prev) => [...prev, { id: created.id, name: created.name, color: created.color ?? null } as Category]);
                toast.success(`Category "${created.name}" created`);
                return created.id as number;
            } catch (error) {
                console.error('Failed to create category:', error);
                toast.error('Failed to create category');
                return null;
            }
        },
        [state.filters.user_id],
    );

    // Debounced patch update
    const patchTransaction = useCallback(async (id: number, patch: TransactionPatch) => {
        dispatch({ type: 'PATCH_TRANSACTION', payload: { id, patch } });

        try {
            const response = await axios.patch(`/admin/transactions/${id}/label`, patch);
            dispatch({ type: 'PATCH_SUCCESS', payload: { id, row: response.data.transaction } });
        } catch (error) {
            console.error('Failed to update transaction:', error);
            dispatch({ type: 'PATCH_FAILURE', payload: { id, error: 'Update failed' } });
            toast.error('Failed to update transaction');
        }
    }, []);

    // Bulk actions
    const handleBulkAction = useCallback(
        async (patch: TransactionPatch) => {
            const ids = Array.from(state.selectedIds);
            if (ids.length === 0) return;

            try {
                await axios.post('/admin/bulk-label', {
                    transaction_ids: ids,
                    labels: patch,
                });

                dispatch({ type: 'BULK_APPLY', payload: { ids, patch } });
                toast.success(`Updated ${ids.length} transactions`);

                // Refresh to get updated stats
                dispatch({ type: 'SET_FILTER', payload: { ...state.filters } });
            } catch (error) {
                console.error('Failed to bulk update:', error);
                toast.error('Failed to update transactions');
            }
        },
        [state.selectedIds, state.filters],
    );

    // Keyboard shortcuts
    useTransactionLabelingShortcuts({
        rows: state.rowOrder.map((id) => state.rows[id]),
        activeRowId: state.activeRowId,
        expandedIds: state.expandedIds,
        onSetActive: (id) => dispatch({ type: 'SET_ACTIVE_ROW', payload: id }),
        onToggleSelect: (id) => dispatch({ type: 'TOGGLE_SELECT', payload: id }),
        onToggleExpand: (id) => dispatch({ type: 'TOGGLE_EXPAND', payload: id }),
        onAcceptML: (id) => {
            const row = state.rows[id];
            if (row?.ml.category?.id != null) {
                patchTransaction(id, { accept_ml_category: true });
            }
        },
        onOpenCategory: () => {}, // Handled by focusing the select
        onOpenCounterparty: () => {}, // Handled by focusing the select
        onToggleFlag: (id, flag) => {
            const row = state.rows[id];
            if (!row) return;
            patchTransaction(id, { [flag]: !row.flags[flag as keyof typeof row.flags] });
        },
        onNextUnlabeled: () => {
            const rows = state.rowOrder.map((id) => state.rows[id]);
            const nextIndex = rows.findIndex(
                (r, i) => i > (state.activeRowId ? rows.findIndex((row) => row.id === state.activeRowId) : -1) && !r.label_state.is_labeled,
            );
            if (nextIndex !== -1) {
                dispatch({ type: 'SET_ACTIVE_ROW', payload: rows[nextIndex].id });
            }
        },
        onSelectSimilar: () => {
            const activeRow = state.activeRowId ? state.rows[state.activeRowId] : null;
            if (activeRow?.similar_group?.key) {
                dispatch({ type: 'SELECT_SIMILAR_GROUP', payload: activeRow.similar_group.key });
            }
        },
        onClearSelection: () => dispatch({ type: 'CLEAR_SELECTION' }),
    });

    const rows = state.rowOrder.map((id) => state.rows[id]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Transaction Labeling" />

            <div className="space-y-6 p-6">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-bold">Transaction labeling</h1>
                    <span className="text-muted-foreground text-sm">{state.stats?.total || 0} transactions loaded</span>
                </div>

                {/* Stats Bar */}
                <LabelingStatsBar stats={state.stats} />

                {/* Filters */}
                <div className="flex flex-wrap items-center gap-4">
                    <StatusFilterPills
                        value={state.filters.status}
                        onChange={(value) => dispatch({ type: 'SET_FILTER', payload: { status: value as typeof state.filters.status } })}
                    />

                    <div className="bg-border h-6 w-px" />

                    <TypeFilterPills
                        value={state.filters.type}
                        onChange={(value) => dispatch({ type: 'SET_FILTER', payload: { type: value as typeof state.filters.type } })}
                    />

                    <div className="bg-border h-6 w-px" />

                    <AccountFilterPills
                        selectedIds={state.filters.account_ids}
                        accounts={state.filterOptions.accounts}
                        onChange={(ids) => dispatch({ type: 'SET_FILTER', payload: { account_ids: ids } })}
                    />

                    <div className="bg-border h-6 w-px" />

                    <Input
                        placeholder="Search description or partner..."
                        value={state.filters.search}
                        onChange={(e) => dispatch({ type: 'SET_SEARCH', payload: e.target.value })}
                        className="w-64"
                    />
                </div>

                {/* User Filter */}
                <div className="flex items-center gap-2">
                    <Label className="text-sm">User:</Label>
                    <Select
                        value={state.filters.user_id?.toString() || 'all'}
                        onValueChange={(value) =>
                            dispatch({ type: 'SET_FILTER', payload: { user_id: value === 'all' ? undefined : parseInt(value) } })
                        }
                    >
                        <SelectTrigger className="w-64">
                            <SelectValue placeholder="All users" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All users</SelectItem>
                            {users.map((user) => (
                                <SelectItem key={user.id} value={user.id.toString()}>
                                    {user.name} ({user.email})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                {/* Bulk Action Bar */}
                <BulkActionBar
                    selectedCount={state.selectedIds.size}
                    categories={categories}
                    counterparties={counterparties}
                    onSetCategory={(id) => handleBulkAction({ category_id: id })}
                    onSetCounterparty={(id) => handleBulkAction({ counterparty_id: id })}
                    onMarkRecurring={() => handleBulkAction({ is_recurring: true })}
                    onMarkTransfer={() => handleBulkAction({ is_transfer: true })}
                    onMarkDuplicate={() => handleBulkAction({ is_duplicate: true })}
                    onAcceptML={() => handleBulkAction({ accept_ml_category: true, accept_ml_counterparty: true })}
                    onFlagUncertain={() => handleBulkAction({ is_uncertain: true })}
                    onClear={() => dispatch({ type: 'CLEAR_SELECTION' })}
                />

                {/* Table */}
                <TransactionLabelingTable
                    rows={rows}
                    selectedIds={state.selectedIds}
                    expandedIds={state.expandedIds}
                    activeRowId={state.activeRowId}
                    categories={categories}
                    counterparties={counterparties}
                    allTags={tags}
                    onToggleSelect={(id) => dispatch({ type: 'TOGGLE_SELECT', payload: id })}
                    onToggleExpand={(id) => dispatch({ type: 'TOGGLE_EXPAND', payload: id })}
                    onSetActive={(id) => dispatch({ type: 'SET_ACTIVE_ROW', payload: id })}
                    onPatch={patchTransaction}
                    onAcceptML={(id, field) => {
                        const row = state.rows[id];
                        if (field === 'category' && row?.ml.category?.id != null) {
                            patchTransaction(id, { accept_ml_category: true });
                        } else if (field === 'counterparty' && row?.ml.counterparty?.id != null) {
                            patchTransaction(id, { accept_ml_counterparty: true });
                        }
                    }}
                    onSelectAll={() => dispatch({ type: 'SELECT_ALL' })}
                    onCreateCategory={createCategory}
                />

                {/* Load More */}
                {state.hasMore && (
                    <div className="flex justify-center">
                        <Button
                            variant="outline"
                            onClick={() => dispatch({ type: 'SET_FILTER', payload: { page: state.filters.page + 1 } })}
                            disabled={state.isLoading}
                        >
                            {state.isLoading ? 'Loading...' : 'Load More'}
                        </Button>
                    </div>
                )}

                {/* Keyboard Shortcuts Help */}
                <div className="text-muted-foreground flex flex-wrap gap-4 text-xs">
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">↑↓</kbd> Navigate
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">Space</kbd> Select
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">Enter</kbd> Accept ML
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">C</kbd> Category
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">M</kbd> Merchant
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">R</kbd> Recurring
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">F</kbd> Flag
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">D</kbd> Duplicate
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">Tab</kbd> Next unlabeled
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">Shift+A</kbd> Select similar
                    </span>
                    <span>
                        <kbd className="bg-muted rounded border px-1.5 py-0.5">Esc</kbd> Clear
                    </span>
                </div>
            </div>
        </AppLayout>
    );
}
