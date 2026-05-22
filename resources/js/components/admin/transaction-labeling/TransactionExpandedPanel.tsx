import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import type { Tag } from '@/types';
import { Check } from 'lucide-react';
import type { AdminTransactionRow, TransactionPatch } from './lib/adminTransactionLabelingTypes';

interface TransactionExpandedPanelProps {
    row: AdminTransactionRow;
    allTags: Tag[];
    onPatch: (patch: TransactionPatch) => void;
    onAcceptML: (field: 'category' | 'counterparty') => void;
}

export function TransactionExpandedPanel({ row, allTags, onPatch, onAcceptML }: TransactionExpandedPanelProps) {
    const handleToggleTag = (tagId: number) => {
        const currentTagIds = row.tags?.map((t) => t.id) || [];
        const newTagIds = currentTagIds.includes(tagId) ? currentTagIds.filter((id) => id !== tagId) : [...currentTagIds, tagId];
        onPatch({ tags: newTagIds });
    };

    const handleToggleFlag = (flag: string, value: boolean) => {
        onPatch({ [flag]: value });
    };

    return (
        <div className="bg-muted/30 space-y-4 p-4">
            {/* Raw Data Grid */}
            <div className="grid grid-cols-5 gap-4 text-sm">
                <div>
                    <div className="text-muted-foreground mb-1 text-xs uppercase">Raw data</div>
                    <code className="bg-muted block rounded px-2 py-1 text-xs">
                        {row.raw.metadata ? JSON.stringify(row.raw.metadata).slice(0, 100) : 'No raw data'}
                    </code>
                </div>

                <div>
                    <div className="text-muted-foreground mb-1 text-xs uppercase">Account / IBAN</div>
                    <div className="flex items-center gap-2">
                        <span className="h-2 w-2 rounded-full" style={{ backgroundColor: row.account?.color || '#ccc' }} />
                        <span>{row.account?.name}</span>
                    </div>
                    <code className="text-muted-foreground text-xs">{row.account?.iban || '—'}</code>
                </div>

                <div>
                    <div className="text-muted-foreground mb-1 text-xs uppercase">Partner</div>
                    <div>{row.partner || '—'}</div>
                </div>

                <div>
                    <div className="text-muted-foreground mb-1 text-xs uppercase">ML Category</div>
                    <div className="flex items-center gap-2">
                        {row.ml.category ? (
                            <>
                                <span className="text-amber-700">
                                    {row.ml.category.name} ({Math.round(row.ml.category.confidence)}%)
                                </span>
                                {!row.category_id && (
                                    <Button size="sm" variant="ghost" className="h-6 w-6 p-0" onClick={() => onAcceptML('category')}>
                                        <Check className="h-4 w-4 text-green-600" />
                                    </Button>
                                )}
                            </>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </div>
                </div>

                <div>
                    <div className="text-muted-foreground mb-1 text-xs uppercase">ML Merchant</div>
                    <div className="flex items-center gap-2">
                        {row.ml.counterparty ? (
                            <>
                                <span className="text-amber-700">
                                    {row.ml.counterparty.name} ({Math.round(row.ml.counterparty.confidence)}%)
                                </span>
                                {!row.counterparty_id && (
                                    <Button size="sm" variant="ghost" className="h-6 w-6 p-0" onClick={() => onAcceptML('counterparty')}>
                                        <Check className="h-4 w-4 text-green-600" />
                                    </Button>
                                )}
                            </>
                        ) : (
                            <span className="text-muted-foreground">—</span>
                        )}
                    </div>
                </div>
            </div>

            {/* Tags */}
            <div className="border-t pt-4">
                <div className="text-muted-foreground mb-2 text-xs uppercase">Tags</div>
                <div className="flex flex-wrap gap-2">
                    {allTags.map((tag) => {
                        const isSelected = row.tags?.some((t) => t.id === tag.id);
                        return (
                            <button
                                key={tag.id}
                                onClick={() => handleToggleTag(tag.id)}
                                className={`rounded-full border px-3 py-1 text-xs transition-colors ${
                                    isSelected
                                        ? 'border-purple-300 bg-purple-100 text-purple-800'
                                        : 'border-border text-muted-foreground hover:bg-muted bg-transparent'
                                }`}
                            >
                                {tag.name}
                            </button>
                        );
                    })}
                </div>
            </div>

            {/* Toggles */}
            <div className="border-t pt-4">
                <div className="text-muted-foreground mb-2 text-xs uppercase">Flags</div>
                <div className="flex flex-wrap gap-6">
                    {[
                        { key: 'is_recurring', label: 'Recurring' },
                        { key: 'is_uncertain', label: 'Uncertain' },
                        { key: 'is_transfer', label: 'Internal transfer' },
                        { key: 'is_duplicate', label: 'Duplicate' },
                    ].map(({ key, label }) => (
                        <label key={key} className="flex cursor-pointer items-center gap-2">
                            <Switch
                                checked={row.flags[key as keyof typeof row.flags]}
                                onCheckedChange={(checked) => handleToggleFlag(key, checked)}
                            />
                            <span className="text-sm">{label}</span>
                        </label>
                    ))}
                </div>
            </div>
        </div>
    );
}
