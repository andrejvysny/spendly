import { Tag, Transaction } from '@/types/index';
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
    tags: Tag[];
}

type TagMode = 'add' | 'remove' | 'set';

export default function BulkTagPanel({ selectedTransactions, onComplete, tags }: Props) {
    const [selectedTagIds, setSelectedTagIds] = useState<number[]>([]);
    const [mode, setMode] = useState<TagMode>('add');

    const toggleTag = (id: number) => {
        setSelectedTagIds((prev) => (prev.includes(id) ? prev.filter((t) => t !== id) : [...prev, id]));
    };

    const handleApply = async () => {
        try {
            const response = await axios.post('/transactions/bulk-tag-update', {
                transaction_ids: selectedTransactions,
                tag_ids: selectedTagIds,
                mode,
            });
            onComplete({
                ids: selectedTransactions,
                updated_transactions: response.data.updated_transactions,
            });
            setSelectedTagIds([]);
        } catch (error) {
            console.error('Failed to update tags:', error);
        }
    };

    const modeButtonClass = (m: TagMode) =>
        `flex-1 rounded-md px-3 py-1.5 text-sm font-medium ${
            mode === m ? 'bg-primary text-primary-foreground' : 'bg-secondary text-secondary-foreground hover:bg-secondary/80'
        }`;

    return (
        <div className="space-y-3">
            <div className="flex gap-1">
                <button onClick={() => setMode('add')} className={modeButtonClass('add')}>
                    Add
                </button>
                <button onClick={() => setMode('remove')} className={modeButtonClass('remove')}>
                    Remove
                </button>
                <button onClick={() => setMode('set')} className={modeButtonClass('set')}>
                    Set
                </button>
            </div>
            <div className="max-h-40 space-y-1 overflow-y-auto">
                {tags.length === 0 ? (
                    <p className="text-muted-foreground text-sm">No tags available</p>
                ) : (
                    tags.map((tag) => (
                        <label
                            key={tag.id}
                            className="flex cursor-pointer items-center gap-2 rounded-md px-1 py-1 hover:bg-gray-50 dark:hover:bg-gray-800"
                        >
                            <input type="checkbox" checked={selectedTagIds.includes(tag.id)} onChange={() => toggleTag(tag.id)} className="rounded" />
                            {tag.color && (
                                <span className="inline-block h-2.5 w-2.5 flex-shrink-0 rounded-full" style={{ backgroundColor: tag.color }} />
                            )}
                            <span className="text-sm">{tag.name}</span>
                        </label>
                    ))
                )}
            </div>
            <button
                onClick={handleApply}
                disabled={selectedTagIds.length === 0}
                className="bg-primary text-primary-foreground hover:bg-primary/90 w-full rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50"
            >
                Apply
            </button>
        </div>
    );
}
