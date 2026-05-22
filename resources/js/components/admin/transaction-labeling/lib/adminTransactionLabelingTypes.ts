import type { Transaction } from '@/types';

export interface MlSuggestion {
    id: number | null;
    name: string | null;
    confidence: number;
    source: string;
    status?: 'pending' | 'accepted' | 'rejected' | 'superseded';
    accepted_at?: string | null;
}

export interface SimilarGroup {
    key: string;
    count: number;
}

export interface Flags {
    is_recurring: boolean;
    is_duplicate: boolean;
    is_uncertain: boolean;
    is_transfer: boolean;
}

export interface LabelState {
    is_labeled: boolean;
    is_ml_suggested: boolean;
}

export interface RawData {
    source_iban?: string;
    target_iban?: string;
    import_data?: Record<string, unknown>;
    metadata?: Record<string, unknown>;
}

export interface AdminAccountInfo {
    id: number;
    name: string;
    color: string;
    iban: string | null;
    user?: {
        id: number;
        name: string;
        email: string;
    };
}

export interface AdminTransactionRow extends Omit<Transaction, 'account'> {
    account?: AdminAccountInfo;
    flags: Flags;
    label_state: LabelState;
    ml: {
        category: MlSuggestion | null;
        counterparty: MlSuggestion | null;
        generated_at?: string | null;
        origin?: string | null;
        model_version?: string | null;
    };
    similar_group: SimilarGroup | null;
    raw: RawData;
}

export interface LabelingStats {
    total: number;
    labeled: number;
    unlabeled: number;
    flagged: number;
    duplicates: number;
    progress_percent: number;
}

export interface FilterAccount {
    id: number;
    name: string;
    color: string;
    iban: string | null;
    count: number;
}

export interface FilterOptions {
    accounts: FilterAccount[];
}

export interface LabelingFilters {
    status: 'unlabeled' | 'all' | 'labeled' | 'flagged' | 'duplicates';
    type: 'all' | 'debit' | 'transfer' | 'credit';
    account_ids: number[];
    search: string;
    user_id?: number;
    category_id?: number;
    merchant_id?: number;
    date_from?: string;
    date_to?: string;
    page: number;
    per_page: number;
}

export interface TransactionPatch {
    category_id?: number | null;
    counterparty_id?: number | null;
    type?: string;
    needs_manual_review?: boolean;
    tags?: number[];
    is_recurring?: boolean;
    is_duplicate?: boolean;
    is_uncertain?: boolean;
    is_transfer?: boolean;
    accept_ml_category?: boolean;
    accept_ml_counterparty?: boolean;
}

export interface LabelingState {
    filters: LabelingFilters;
    rows: Record<number, AdminTransactionRow>;
    rowOrder: number[];
    selectedIds: Set<number>;
    expandedIds: Set<number>;
    activeRowId: number | null;
    pendingPatches: Record<number, TransactionPatch>;
    savingIds: Set<number>;
    errorById: Record<number, string | null>;
    stats: LabelingStats | null;
    filterOptions: FilterOptions;
    isLoading: boolean;
    hasMore: boolean;
    currentPage: number;
    lastPage: number;
}

export type LabelingAction =
    | { type: 'SET_FILTER'; payload: Partial<LabelingFilters> }
    | { type: 'SET_SEARCH'; payload: string }
    | {
          type: 'LOAD_TRANSACTIONS_SUCCESS';
          payload: { rows: AdminTransactionRow[]; stats: LabelingStats; filterOptions: FilterOptions; page: number; lastPage: number };
      }
    | { type: 'APPEND_TRANSACTIONS'; payload: { rows: AdminTransactionRow[]; page: number; lastPage: number } }
    | { type: 'TOGGLE_SELECT'; payload: number }
    | { type: 'SELECT_ALL' }
    | { type: 'CLEAR_SELECTION' }
    | { type: 'SET_ACTIVE_ROW'; payload: number | null }
    | { type: 'TOGGLE_EXPAND'; payload: number }
    | { type: 'PATCH_TRANSACTION'; payload: { id: number; patch: TransactionPatch } }
    | { type: 'PATCH_SUCCESS'; payload: { id: number; row: AdminTransactionRow } }
    | { type: 'PATCH_FAILURE'; payload: { id: number; error: string } }
    | { type: 'BULK_APPLY'; payload: { ids: number[]; patch: TransactionPatch } }
    | { type: 'ACCEPT_ML_SUGGESTION'; payload: { id: number; field: 'category' | 'counterparty' } }
    | { type: 'SELECT_SIMILAR_GROUP'; payload: string }
    | { type: 'SET_LOADING'; payload: boolean }
    | { type: 'RESET' };
