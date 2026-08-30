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

/**
 * A live sync state outranks the age of the data: an account that is queued or failing right now
 * is more useful to know about than one whose last successful sync happens to be 20 hours old.
 * 'success' and 'idle' carry no such news, so they fall through to the age heuristic.
 */
function getDotClass(account: Account): string {
    switch (account.gocardless_sync_status) {
        case 'queued':
        case 'syncing':
            return 'bg-yellow-500 animate-pulse';
        case 'failed':
        case 'needs_reconnect':
            return 'bg-red-500';
        case 'rate_limited':
            return 'bg-amber-500';
        default:
            return getStatusColor(account.gocardless_last_synced_at);
    }
}

function getStatusLabel(account: Account): string {
    switch (account.gocardless_sync_status) {
        case 'queued':
            return 'Queued';
        case 'syncing':
            return 'Syncing…';
        case 'failed':
            return 'Sync failed';
        case 'rate_limited':
            return 'Rate limited';
        case 'needs_reconnect':
            return 'Reconnect needed';
        default:
            return account.gocardless_last_synced_at ? getTimeSince(account.gocardless_last_synced_at) : 'Never synced';
    }
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
                            <div className={`h-2.5 w-2.5 rounded-full ${getDotClass(account)}`} />
                            <span className="text-sm font-medium">{account.name}</span>
                        </div>
                        <span className="text-muted-foreground text-xs" title={account.gocardless_sync_error ?? undefined}>
                            {getStatusLabel(account)}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}
