import { LucideIcon } from 'lucide-react';
import type { Config } from 'ziggy-js';

export interface Auth {
    user: User;
    [key: string]: unknown; // This allows for additional properties...
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    ziggy: Config & { location: string };
    sidebarOpen: boolean;
    registrationEnabled: boolean;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
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
    /** Set when the bank withdrew access (90-day consent lapsed); cleared once relinked. */
    gocardless_needs_reconnect?: boolean;
    /** FK to the gocardless_requisitions row that authorized this account. */
    gocardless_requisition_id?: number | null;
    /** Lifecycle of the queued sync job: idle|queued|syncing|success|failed|rate_limited|needs_reconnect. */
    gocardless_sync_status?: string;
    /** Short, redacted reason the last sync failed. Safe to render. */
    gocardless_sync_error?: string | null;
    /** No sync may be started before this instant (bank rate limit cooldown). */
    gocardless_sync_retry_after?: string | null;
    /** When the last sync job finished — distinct from gocardless_last_synced_at, the data watermark. */
    gocardless_sync_finished_at?: string | null;
    created_at: string;
    updated_at: string;
}

interface TransactionType {
    id: number;
    transaction_id: string;
    amount: number;
    currency: string;
    booked_date: string;
    processed_date: string;
    description: string;
    target_iban: string | null;
    source_iban: string | null;
    partner: string;
    type: string;
    metadata: Record<string, unknown> | null;
    balance_after_transaction: number | null;
    account_id: number | null;
    duplicate_identifier?: string;
    original_amount?: number;
    original_currency?: string;
    original_booked_date?: string;
    original_source_iban?: string;
    original_target_iban?: string;
    original_partner?: string;
    created_at: string;
    updated_at: string;
    account: Account | null;
}
