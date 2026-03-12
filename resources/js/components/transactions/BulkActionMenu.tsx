import BulkCategoryPanel from '@/components/transactions/bulk-actions/BulkCategoryPanel';
import BulkCounterpartyPanel from '@/components/transactions/bulk-actions/BulkCounterpartyPanel';
import BulkDeletePanel from '@/components/transactions/bulk-actions/BulkDeletePanel';
import BulkNotePanel from '@/components/transactions/bulk-actions/BulkNotePanel';
import BulkRecurringPanel from '@/components/transactions/bulk-actions/BulkRecurringPanel';
import BulkTagPanel from '@/components/transactions/bulk-actions/BulkTagPanel';
import BulkTransferPanel from '@/components/transactions/bulk-actions/BulkTransferPanel';
import { Category, Counterparty, RecurringGroup, Tag, Transaction } from '@/types/index';
import { useState } from 'react';

type ActiveMenu = 'category' | 'counterparty' | 'note' | 'tags' | 'transfer' | 'recurring' | 'delete' | null;

interface BulkUpdateData {
    ids: string[];
    category_id?: string | null;
    counterparty_id?: string | null;
    recurring_group_id?: string | null;
    updated_transactions?: Array<{ id: number; note?: string; tags?: Tag[] }>;
    type?: string;
    deleted_ids?: string[];
}

interface Props {
    selectedTransactions: string[];
    transactions?: Transaction[];
    categories?: Category[];
    counterparties?: Counterparty[];
    tags?: Tag[];
    recurringGroups?: RecurringGroup[];
    onUpdate: (data?: BulkUpdateData) => void;
}

export default function BulkActionMenu({
    selectedTransactions,
    transactions = [],
    categories = [],
    counterparties = [],
    tags = [],
    recurringGroups = [],
    onUpdate,
}: Props) {
    const [activeMenu, setActiveMenu] = useState<ActiveMenu>(null);

    const selectedTransactionObjects = transactions.filter((t) => selectedTransactions.includes(String(t.id)));
    const relativeTotal = selectedTransactionObjects.reduce((sum, t) => sum + Number(t.amount), 0);
    const absoluteTotal = selectedTransactionObjects.reduce((sum, t) => sum + Math.abs(Number(t.amount)), 0);
    const currency = selectedTransactionObjects.length > 0 ? selectedTransactionObjects[0].currency : 'EUR';

    const handleComplete = (data?: BulkUpdateData) => {
        onUpdate(data);
        setActiveMenu(null);
    };

    const toggleMenu = (menu: ActiveMenu) => setActiveMenu(activeMenu === menu ? null : menu);

    const actionButtonClass = (menu: ActiveMenu) =>
        `w-full rounded-md px-3 py-1.5 text-sm font-medium ${
            activeMenu === menu ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground hover:bg-secondary/80'
        }`;

    if (selectedTransactions.length === 0) return null;

    const panelProps = {
        selectedTransactions,
        transactions,
        onComplete: handleComplete,
    };

    return (
        <div className="fixed right-4 bottom-4 z-50">
            <div className="bg-card border-border w-[500px] rounded-lg border shadow-lg">
                {/* Header */}
                <div className="border-border border-b p-3">
                    <div className="flex items-center justify-between">
                        <div className="flex flex-col gap-1">
                            <span className="text-sm font-medium">
                                {selectedTransactions.length} {selectedTransactions.length === 1 ? 'transaction' : 'transactions'} selected
                            </span>
                            {transactions.length > 0 && (
                                <div className="text-muted-foreground flex gap-3 text-xs">
                                    <span className={relativeTotal >= 0 ? 'text-green-600' : 'text-red-500'}>
                                        Total: {relativeTotal >= 0 ? '+' : ''}
                                        {relativeTotal.toFixed(2)} {currency}
                                    </span>
                                    <span>
                                        Absolute: {absoluteTotal.toFixed(2)} {currency}
                                    </span>
                                </div>
                            )}
                        </div>
                        <button onClick={() => onUpdate()} className="text-muted-foreground hover:text-foreground" title="Cancel">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="16"
                                height="16"
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                strokeWidth="2"
                                strokeLinecap="round"
                                strokeLinejoin="round"
                            >
                                <path d="M18 6 6 18" />
                                <path d="m6 6 12 12" />
                            </svg>
                        </button>
                    </div>
                </div>

                {/* Action buttons */}
                <div className="p-3">
                    <div className="space-y-1.5">
                        <button onClick={() => toggleMenu('category')} className={actionButtonClass('category')}>
                            Assign Category
                        </button>
                        <button onClick={() => toggleMenu('counterparty')} className={actionButtonClass('counterparty')}>
                            Assign Counterparty
                        </button>
                        <button onClick={() => toggleMenu('note')} className={actionButtonClass('note')}>
                            Add Note
                        </button>
                        <button onClick={() => toggleMenu('tags')} className={actionButtonClass('tags')}>
                            Manage Tags
                        </button>
                        <button onClick={() => toggleMenu('transfer')} className={actionButtonClass('transfer')}>
                            Transfer
                        </button>
                        <button onClick={() => toggleMenu('recurring')} className={actionButtonClass('recurring')}>
                            Recurring Group
                        </button>
                        <button
                            onClick={() => toggleMenu('delete')}
                            className={`w-full rounded-md px-3 py-1.5 text-sm font-medium ${
                                activeMenu === 'delete' ? 'bg-red-600 text-white' : 'bg-secondary hover:bg-secondary/80 text-red-600'
                            }`}
                        >
                            Delete
                        </button>
                    </div>
                </div>

                {/* Active panel */}
                {activeMenu && (
                    <div className="border-border border-t p-3">
                        {activeMenu === 'category' && <BulkCategoryPanel {...panelProps} categories={categories} />}
                        {activeMenu === 'counterparty' && <BulkCounterpartyPanel {...panelProps} counterparties={counterparties} />}
                        {activeMenu === 'note' && <BulkNotePanel {...panelProps} />}
                        {activeMenu === 'tags' && <BulkTagPanel {...panelProps} tags={tags} />}
                        {activeMenu === 'transfer' && <BulkTransferPanel {...panelProps} />}
                        {activeMenu === 'recurring' && <BulkRecurringPanel {...panelProps} recurringGroups={recurringGroups} />}
                        {activeMenu === 'delete' && <BulkDeletePanel {...panelProps} />}
                    </div>
                )}
            </div>
        </div>
    );
}
