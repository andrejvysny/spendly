import { Account } from '@/types';

interface Props {
    accounts: Account[];
}

function getTimeSince(dateStr: string): string {
    const now = new Date();
    const date = new Date(dateStr);
    const diffMs = now.getTime() - date.getTime();
    const hours = Math.floor(diffMs / (1000 * 60 * 60));
    if (hours < 1) return 'Just now';
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

function getStatusColor(dateStr: string | null): string {
    if (!dateStr) return 'bg-gray-500';
    const now = new Date();
    const date = new Date(dateStr);
    const hours = (now.getTime() - date.getTime()) / (1000 * 60 * 60);
    if (hours > 72) return 'bg-red-500';
    if (hours > 24) return 'bg-yellow-500';
    return 'bg-green-500';
}

export default function SyncStatusCard({ accounts }: Props) {
    const syncedAccounts = accounts.filter((a) => a.is_gocardless_synced);

    if (syncedAccounts.length === 0) return null;

    return (
        <div className="bg-card rounded-xl p-6 shadow-xs">
            <h3 className="mb-4 text-lg font-semibold">Bank Sync Status</h3>
            <div className="space-y-3">
                {syncedAccounts.map((account) => (
                    <div key={account.id} className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <div className={`h-2.5 w-2.5 rounded-full ${getStatusColor(account.gocardless_last_synced_at)}`} />
                            <span className="text-sm font-medium">{account.name}</span>
                        </div>
                        <span className="text-muted-foreground text-xs">
                            {account.gocardless_last_synced_at ? getTimeSince(account.gocardless_last_synced_at) : 'Never synced'}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}
