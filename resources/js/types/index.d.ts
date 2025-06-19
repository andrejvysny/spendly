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
    balance_after_transaction: number;
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
