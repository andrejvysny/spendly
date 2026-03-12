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

export default function BulkDeletePanel({ selectedTransactions, transactions, onComplete }: BulkPanelProps) {
    const selected = transactions.filter((t) => selectedTransactions.includes(String(t.id)));
    const total = selected.reduce((acc, t) => acc + Math.abs(Number(t.amount)), 0);
    const currency = selected.length > 0 ? selected[0].currency : 'EUR';
    const count = selectedTransactions.length;

    const handleDelete = async () => {
        try {
            const response = await axios.post('/transactions/bulk-delete', {
                transaction_ids: selectedTransactions,
            });
            onComplete({
                ids: selectedTransactions,
                deleted_ids: response.data.deleted_ids ?? selectedTransactions,
            });
        } catch (error) {
            console.error('Failed to delete transactions:', error);
        }
    };

    return (
        <div className="space-y-3">
            <p className="text-muted-foreground text-sm">
                {count} {count === 1 ? 'transaction' : 'transactions'} selected — total {total.toFixed(2)} {currency}
            </p>
            <button onClick={handleDelete} className="w-full rounded-md bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">
                Delete permanently ({count} {count === 1 ? 'transaction' : 'transactions'}, {total.toFixed(2)} {currency})
            </button>
        </div>
    );
}
