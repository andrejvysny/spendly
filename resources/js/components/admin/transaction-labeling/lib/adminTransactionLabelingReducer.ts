import type { AdminTransactionRow, LabelingAction, LabelingState } from './adminTransactionLabelingTypes';

export const initialState: LabelingState = {
    filters: {
        status: 'unlabeled',
        type: 'all',
        account_ids: [],
        search: '',
        sort: 'date',
        page: 1,
        per_page: 50,
    },
    rows: {},
    rowOrder: [],
    selectedIds: new Set(),
    expandedIds: new Set(),
    activeRowId: null,
    pendingPatches: {},
    savingIds: new Set(),
    errorById: {},
    stats: null,
    filterOptions: { accounts: [] },
    isLoading: false,
    hasMore: false,
    currentPage: 1,
    lastPage: 1,
};

export function labelingReducer(state: LabelingState, action: LabelingAction): LabelingState {
    switch (action.type) {
        case 'SET_FILTER':
            return {
                ...state,
                filters: { ...state.filters, ...action.payload, page: 1 },
                selectedIds: new Set(), // Clear selection on filter change
                expandedIds: new Set(),
                activeRowId: null,
            };

        case 'SET_SEARCH':
            return {
                ...state,
                filters: { ...state.filters, search: action.payload, page: 1 },
                selectedIds: new Set(),
                expandedIds: new Set(),
                activeRowId: null,
            };

        case 'LOAD_TRANSACTIONS_SUCCESS': {
            const rowsMap: Record<number, AdminTransactionRow> = {};
            const rowOrder: number[] = [];

            action.payload.rows.forEach((row) => {
                rowsMap[row.id] = row;
                rowOrder.push(row.id);
            });

            return {
                ...state,
                rows: rowsMap,
                rowOrder,
                stats: action.payload.stats,
                filterOptions: action.payload.filterOptions,
                currentPage: action.payload.page,
                lastPage: action.payload.lastPage,
                hasMore: action.payload.page < action.payload.lastPage,
                isLoading: false,
                selectedIds: new Set(),
            };
        }

        case 'APPEND_TRANSACTIONS': {
            const rowsMap = { ...state.rows };
            const rowOrder = [...state.rowOrder];

            action.payload.rows.forEach((row) => {
                if (!rowsMap[row.id]) {
                    rowsMap[row.id] = row;
                    rowOrder.push(row.id);
                }
            });

            return {
                ...state,
                rows: rowsMap,
                rowOrder,
                currentPage: action.payload.page,
                lastPage: action.payload.lastPage,
                hasMore: action.payload.page < action.payload.lastPage,
                isLoading: false,
            };
        }

        case 'TOGGLE_SELECT': {
            const newSelected = new Set(state.selectedIds);
            if (newSelected.has(action.payload)) {
                newSelected.delete(action.payload);
            } else {
                newSelected.add(action.payload);
            }
            return { ...state, selectedIds: newSelected };
        }

        case 'SELECT_ALL': {
            const allIds = state.rowOrder;
            const allSelected = allIds.every((id) => state.selectedIds.has(id));
            return {
                ...state,
                selectedIds: allSelected ? new Set() : new Set(allIds),
            };
        }

        case 'CLEAR_SELECTION':
            return { ...state, selectedIds: new Set(), expandedIds: new Set() };

        case 'SET_ACTIVE_ROW':
            return { ...state, activeRowId: action.payload };

        case 'TOGGLE_EXPAND': {
            const newExpanded = new Set(state.expandedIds);
            if (newExpanded.has(action.payload)) {
                newExpanded.delete(action.payload);
            } else {
                newExpanded.add(action.payload);
                // Close other expanded rows for better UX
                newExpanded.clear();
                newExpanded.add(action.payload);
            }
            return { ...state, expandedIds: newExpanded };
        }

        case 'PATCH_TRANSACTION': {
            const { id, patch } = action.payload;
            const row = state.rows[id];
            if (!row) return state;

            // Optimistic update — only apply known patch fields, full row comes from PATCH_SUCCESS
            const updatedRow: AdminTransactionRow = {
                ...row,
                ...(patch.category_id !== undefined && { category_id: patch.category_id ?? undefined }),
                ...(patch.counterparty_id !== undefined && { counterparty_id: patch.counterparty_id ?? undefined }),
                ...(patch.type !== undefined && { type: patch.type }),
                flags: {
                    ...row.flags,
                    ...(patch.is_recurring !== undefined && { is_recurring: patch.is_recurring }),
                    ...(patch.is_duplicate !== undefined && { is_duplicate: patch.is_duplicate }),
                    ...(patch.is_uncertain !== undefined && { is_uncertain: patch.is_uncertain }),
                    ...(patch.is_transfer !== undefined && { is_transfer: patch.is_transfer }),
                },
            };

            return {
                ...state,
                rows: { ...state.rows, [id]: updatedRow },
                pendingPatches: {
                    ...state.pendingPatches,
                    [id]: { ...state.pendingPatches[id], ...patch },
                },
                savingIds: new Set(state.savingIds).add(id),
            };
        }

        case 'PATCH_SUCCESS': {
            const { id, row } = action.payload;
            const newPending = { ...state.pendingPatches };
            delete newPending[id];

            const newSaving = new Set(state.savingIds);
            newSaving.delete(id);

            return {
                ...state,
                rows: { ...state.rows, [id]: row },
                pendingPatches: newPending,
                savingIds: newSaving,
                errorById: { ...state.errorById, [id]: null },
            };
        }

        case 'PATCH_FAILURE': {
            const { id, error } = action.payload;
            const newSaving = new Set(state.savingIds);
            newSaving.delete(id);

            return {
                ...state,
                savingIds: newSaving,
                errorById: { ...state.errorById, [id]: error },
            };
        }

        case 'BULK_APPLY': {
            const { ids, patch } = action.payload;
            const newRows = { ...state.rows };

            ids.forEach((id) => {
                const row = newRows[id];
                if (row) {
                    newRows[id] = {
                        ...row,
                        ...(patch.category_id !== undefined && { category_id: patch.category_id ?? undefined }),
                        ...(patch.counterparty_id !== undefined && { counterparty_id: patch.counterparty_id ?? undefined }),
                        ...(patch.type !== undefined && { type: patch.type }),
                        flags: {
                            ...row.flags,
                            ...(patch.is_recurring !== undefined && { is_recurring: patch.is_recurring }),
                            ...(patch.is_duplicate !== undefined && { is_duplicate: patch.is_duplicate }),
                            ...(patch.is_uncertain !== undefined && { is_uncertain: patch.is_uncertain }),
                            ...(patch.is_transfer !== undefined && { is_transfer: patch.is_transfer }),
                        },
                    };
                }
            });

            return {
                ...state,
                rows: newRows,
                selectedIds: new Set(),
            };
        }

        case 'ACCEPT_ML_SUGGESTION': {
            const { id, field } = action.payload;
            const row = state.rows[id];
            if (!row || !row.ml[field]) return state;

            const patch: Record<string, unknown> = {};
            if (field === 'category' && row.ml.category) {
                patch.accept_ml_category = true;
            } else if (field === 'counterparty' && row.ml.counterparty) {
                patch.accept_ml_counterparty = true;
            }

            return {
                ...state,
                rows: {
                    ...state.rows,
                    [id]: { ...row, ...patch },
                },
                pendingPatches: {
                    ...state.pendingPatches,
                    [id]: { ...state.pendingPatches[id], ...patch },
                },
                savingIds: new Set(state.savingIds).add(id),
            };
        }

        case 'SELECT_SIMILAR_GROUP': {
            const groupKey = action.payload;
            const similarIds = state.rowOrder.filter((id) => {
                const row = state.rows[id];
                return row?.similar_group?.key === groupKey;
            });

            const newSelected = new Set(state.selectedIds);
            similarIds.forEach((id) => {
                newSelected.add(id);
            });

            return { ...state, selectedIds: newSelected };
        }

        case 'SET_LOADING':
            return { ...state, isLoading: action.payload };

        case 'RESET':
            return initialState;

        default:
            return state;
    }
}
