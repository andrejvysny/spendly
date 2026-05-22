import { cn } from '@/lib/utils';
import type { FilterAccount } from './lib/adminTransactionLabelingTypes';

interface AccountFilterPillsProps {
    selectedIds: number[];
    accounts: FilterAccount[];
    onChange: (ids: number[]) => void;
}

export function AccountFilterPills({ selectedIds, accounts, onChange }: AccountFilterPillsProps) {
    const toggleAccount = (id: number) => {
        const newIds = selectedIds.includes(id) ? selectedIds.filter((i) => i !== id) : [...selectedIds, id];
        onChange(newIds);
    };

    return (
        <div className="flex items-center gap-1">
            {accounts.map((account) => (
                <button
                    key={account.id}
                    onClick={() => toggleAccount(account.id)}
                    className={cn(
                        'flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm font-medium transition-colors',
                        selectedIds.includes(account.id)
                            ? 'bg-primary/10 border-primary/50 text-primary'
                            : 'border-border text-muted-foreground hover:bg-muted bg-transparent',
                    )}
                >
                    <span className="h-2 w-2 rounded-full" style={{ backgroundColor: account.color }} />
                    {account.name}
                </button>
            ))}
        </div>
    );
}
