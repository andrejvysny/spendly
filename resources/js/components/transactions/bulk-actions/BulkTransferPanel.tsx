import { Tag, Transaction } from '@/types/index';
import axios from 'axios';

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

export default function BulkTransferPanel({ selectedTransactions, transactions, onComplete }: BulkPanelProps) {
    const selected = transactions.filter((t) => selectedTransactions.includes(String(t.id)));
    const sum = selected.reduce((acc, t) => acc + Number(t.amount), 0);
    const isAutoPairable = selectedTransactions.length === 2 && Math.abs(sum) < 0.01;

    const handleMark = async () => {
        try {
            await axios.post('/transactions/bulk-type-update', {
                transaction_ids: selectedTransactions,
                type: 'TRANSFER',
            });
            onComplete({ ids: selectedTransactions, type: 'TRANSFER' });
        } catch (error) {
            console.error('Failed to mark as transfer:', error);
        }
    };

    const handleUnmark = async () => {
        try {
            await axios.post('/transactions/bulk-type-update', {
                transaction_ids: selectedTransactions,
                type: 'PAYMENT',
                clear_transfer_pair: true,
            });
            onComplete({ ids: selectedTransactions, type: 'PAYMENT' });
        } catch (error) {
            console.error('Failed to unmark transfer:', error);
        }
    };

    return (
        <div className="space-y-3">
            {isAutoPairable && <p className="text-muted-foreground text-xs">These will be auto-paired as a transfer.</p>}
            <div className="flex gap-2">
                <button
                    onClick={handleMark}
                    className="bg-primary text-primary-foreground hover:bg-primary/90 flex-1 rounded-md px-3 py-1.5 text-sm font-medium"
                >
                    Mark as Transfer
                </button>
                <button
                    onClick={handleUnmark}
                    className="bg-secondary text-secondary-foreground hover:bg-secondary/80 flex-1 rounded-md px-3 py-1.5 text-sm font-medium"
                >
                    Unmark Transfer
                </button>
            </div>
        </div>
    );
}
