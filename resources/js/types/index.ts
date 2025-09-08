import { LucideIcon } from 'lucide-react';

export enum Currency {
    EUR = 'EUR',
    USD = 'USD',
    GBP = 'GBP',
    CZK = 'CZK',
}

export interface Account {
    id: number;
    user_id: number;
    name: string;
    bank_name: string | null;
    iban: string | null;
    type: string;
    currency: string;
    balance: number;
    gocardless_account_id: string | null;
    is_gocardless_synced: boolean;
    gocardless_last_synced_at: string | null;
    sync_options?: {
        update_existing?: boolean;
        force_max_date_range?: boolean;
    } | null;
    created_at: string;
    updated_at: string;
}

export interface Transaction {
    id: number;
    transaction_id: string;
    amount: number;
    currency: string;
    booked_date: string;
    processed_date: string;
    description: string;
    target_iban?: string;
    source_iban?: string;
    partner?: string;
    type: string;
    metadata?: Record<string, unknown> | undefined;
    balance_after_transaction: number;
    account_id: number;
    duplicate_identifier?: string;
    original_amount?: number;
    original_currency?: string;
    original_booked_date?: string;
    original_source_iban?: string;
    original_target_iban?: string;
    original_partner?: string;
    import_data?: Record<string, unknown> | undefined;
    merchant_id?: number;
    category_id?: number;
    note?: string;
    recipient_note?: string;
    place?: string;
    account?: Account;
    merchant?: Merchant;
    category?: Category;
    tags?: Tag[];
}

export interface TransactionRule {
    id: number;
    user_id: number;
    name: string;
    condition_type: 'amount' | 'iban' | 'description';
    condition_operator: 'equals' | 'contains' | 'greater_than' | 'less_than';
    condition_value: string;
    action_type: 'add_tag' | 'set_category' | 'set_type';
    action_value: string;
    is_active: boolean;
    order: number;
    created_at: string;
    updated_at: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon | null;
}

export interface BreadcrumbItem {
    href: string;
    title: string;
}

export interface Import {
    id: number;
    user_id: number;
    filename: string;
    original_filename: string;
    status: 'pending' | 'processing' | 'completed' | 'failed' | 'partially_failed' | 'completed_skipped_duplicates' | 'reverted';
    total_rows: number;
    processed_rows: number;
    failed_rows: number;
    column_mapping: Record<string, number | null>;
    date_format: string;
    amount_format: string;
    amount_type_strategy: string;
    currency: string;
    metadata: {
        headers?: string[];
        sample_rows?: string[][];
        skipped_rows?: number;
        failed_rows?: number;
        processed_rows?: number;
        total_rows?: number;
    };
    processed_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface ImportFailure {
    id: number;
    import_id: number;
    row_number: number | null;
    raw_data: any[];
    error_type: 'validation_failed' | 'duplicate' | 'processing_error' | 'parsing_error';
    error_message: string;
    error_details: {
        message: string;
        errors?: string[];
        field?: string;
        validation_errors?: string[];
        duplicate_fingerprint?: string;
        exception?: string;
    };
    parsed_data: {
        booked_date?: string;
        amount?: number;
        partner?: string;
        description?: string;
        currency?: string;
        account_id?: number;
        transaction_id?: string;
        [key: string]: any;
    } | null;
    metadata: {
        row_number?: number;
        headers?: string[];
        delimiter?: string;
        quote_char?: string;
        duplicate?: boolean;
        [key: string]: any;
    };
    status: 'pending' | 'reviewed' | 'resolved' | 'ignored';
    review_notes: string | null;
    reviewed_at: string | null;
    reviewed_by: number | null;
    created_at: string;
    updated_at: string;
    reviewer?: User;
    import?: Import;
}

export interface Category {
    id: number;
    name: string;
    color: string;
    icon?: string;
    user_id: number;
    created_at: string;
    updated_at: string;
}

export interface ImportMapping {
    id: number;
    user_id: number;
    name: string;
    bank_name: string | null;
    column_mapping: Record<string, number | string | null>;
    date_format: string;
    amount_format: string;
    amount_type_strategy: string;
    currency: string;
    last_used_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at: string;
}

export interface PageProps {
    auth: {
        user: User;
    };
}

export interface Merchant {
    id: number;
    name: string;
    user_id: number;
    logo?: string | null;
}

export interface Tag {
    id: number;
    name: string;
    user_id: number;
}
