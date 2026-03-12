import type { BudgetWithProgress } from '@/types';
import { CalendarClock, FolderOpen, Landmark, type LucideIcon, RefreshCw, Tag, Users, Wallet } from 'lucide-react';

interface TargetDisplay {
    label: string;
    color: string;
    icon: LucideIcon;
    typeBadge: string;
}

export function budgetTargetDisplay(b: BudgetWithProgress): TargetDisplay {
    switch (b.target_type) {
        case 'category':
            return {
                label: b.category?.name ?? 'Unknown Category',
                color: b.category?.color ?? '#94a3b8',
                icon: FolderOpen,
                typeBadge: 'category',
            };
        case 'tag':
            return {
                label: b.tag?.name ?? 'Unknown Tag',
                color: b.tag?.color ?? '#8b5cf6',
                icon: Tag,
                typeBadge: 'tag',
            };
        case 'counterparty':
            return {
                label: b.counterparty?.name ?? 'Unknown Counterparty',
                color: '#f59e0b',
                icon: Users,
                typeBadge: 'counterparty',
            };
        case 'subscription':
            return {
                label: b.recurring_group?.name ?? 'Unknown Subscription',
                color: '#10b981',
                icon: RefreshCw,
                typeBadge: 'subscription',
            };
        case 'account':
            return {
                label: b.account?.name ?? 'Unknown Account',
                color: '#3b82f6',
                icon: Landmark,
                typeBadge: 'account',
            };
        case 'all_subscriptions':
            return {
                label: 'All Subscriptions',
                color: '#a855f7',
                icon: CalendarClock,
                typeBadge: 'all subscriptions',
            };
        case 'overall':
        default:
            return {
                label: 'Overall',
                color: '#64748b',
                icon: Wallet,
                typeBadge: 'overall',
            };
    }
}
