import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { RecurringGroup, Tag, Transaction } from '@/types/index';
import axios from 'axios';
import { useState } from 'react';

interface BulkUpdateData {
    ids: string[];
    category_id?: string | null;
    counterparty_id?: string | null;
    recurring_group_id?: string | null;
    updated_transactions?: Array<{ id: number; note?: string; tags?: Tag[] }>;
    type?: string;
    deleted_ids?: string[];
}

interface BulkPanelProps {
    selectedTransactions: string[];
    transactions: Transaction[];
    onComplete: (data?: BulkUpdateData) => void;
}

interface Props extends BulkPanelProps {
    recurringGroups: RecurringGroup[];
}

type RecurringMode = 'attach' | 'detach';

export default function BulkRecurringPanel({ selectedTransactions, onComplete, recurringGroups }: Props) {
    const [mode, setMode] = useState<RecurringMode>('attach');
    const [selectedGroup, setSelectedGroup] = useState<string>('');

    const handleAttach = async () => {
        if (!selectedGroup) return;
        try {
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                recurring_group_id: selectedGroup,
            });
            onComplete({ ids: selectedTransactions, recurring_group_id: selectedGroup });
            setSelectedGroup('');
        } catch (error) {
            console.error('Failed to attach recurring group:', error);
        }
    };

    const handleDetach = async () => {
        try {
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                recurring_group_id: '',
            });
            onComplete({ ids: selectedTransactions, recurring_group_id: null });
        } catch (error) {
            console.error('Failed to detach recurring group:', error);
        }
    };

    const modeButtonClass = (m: RecurringMode) =>
        `flex-1 rounded-md px-3 py-1.5 text-sm font-medium ${
            mode === m ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground hover:bg-secondary/80'
        }`;

    return (
        <div className="space-y-3">
            <div className="flex gap-1">
                <button onClick={() => setMode('attach')} className={modeButtonClass('attach')}>
                    Attach
                </button>
                <button onClick={() => setMode('detach')} className={modeButtonClass('detach')}>
                    Detach
                </button>
            </div>
            {mode === 'attach' ? (
                <div className="space-y-2">
                    <Select value={selectedGroup} onValueChange={setSelectedGroup}>
                        <SelectTrigger>
                            <SelectValue placeholder="Select a recurring group" />
                        </SelectTrigger>
                        <SelectContent>
                            {recurringGroups.length > 0 ? (
                                recurringGroups.map((group) => (
                                    <SelectItem key={group.id} value={String(group.id)}>
                                        {group.name}
                                    </SelectItem>
                                ))
                            ) : (
                                <SelectItem value="no-groups" disabled>
                                    No recurring groups available
                                </SelectItem>
                            )}
                        </SelectContent>
                    </Select>
                    <button
                        onClick={handleAttach}
                        disabled={!selectedGroup}
                        className="bg-primary text-primary-foreground hover:bg-primary/90 w-full rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50"
                    >
                        Apply
                    </button>
                </div>
            ) : (
                <button
                    onClick={handleDetach}
                    className="bg-secondary text-secondary-foreground hover:bg-secondary/80 w-full rounded-md px-3 py-1.5 text-sm font-medium"
                >
                    Detach from Recurring Group
                </button>
            )}
        </div>
    );
}
