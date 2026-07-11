import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { Category, Counterparty } from '@/types';
import { Check, X } from 'lucide-react';
import { useState } from 'react';

interface BulkActionBarProps {
    selectedCount: number;
    categories: Category[];
    counterparties: Counterparty[];
    onSetCategory: (categoryId: number | null) => void;
    onSetCounterparty: (counterpartyId: number | null) => void;
    onMarkRecurring: () => void;
    onMarkTransfer: () => void;
    onMarkDuplicate: () => void;
    onAcceptML: () => void;
    onFlagUncertain: () => void;
    onClear: () => void;
    /** Set when the whole selection shares one fingerprint group owned by one user. */
    groupInfo?: { key: string; count: number } | null;
    applyToGroup?: boolean;
    onToggleApplyToGroup?: (value: boolean) => void;
}

export function BulkActionBar({
    selectedCount,
    categories,
    counterparties,
    onSetCategory,
    onSetCounterparty,
    onMarkRecurring,
    onMarkTransfer,
    onMarkDuplicate,
    onAcceptML,
    onFlagUncertain,
    onClear,
    groupInfo,
    applyToGroup = false,
    onToggleApplyToGroup,
}: BulkActionBarProps) {
    const [showCategorySelect, setShowCategorySelect] = useState(false);
    const [showCounterpartySelect, setShowCounterpartySelect] = useState(false);

    if (selectedCount === 0) return null;

    return (
        <div className="bg-primary/5 border-primary/20 flex flex-wrap items-center gap-3 rounded-lg border p-4">
            <div className="flex items-center gap-2">
                <span className="text-primary font-semibold">{selectedCount} selected</span>
                <Button variant="ghost" size="sm" onClick={onClear}>
                    <X className="mr-1 h-4 w-4" />
                    Clear
                </Button>
            </div>

            <div className="bg-border h-6 w-px" />

            {showCategorySelect ? (
                <Select
                    onValueChange={(value) => {
                        onSetCategory(value === '__none__' ? null : parseInt(value, 10));
                        setShowCategorySelect(false);
                    }}
                    onOpenChange={(open) => !open && setShowCategorySelect(false)}
                >
                    <SelectTrigger className="h-8 w-40">
                        <SelectValue placeholder="Select category..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__none__">None</SelectItem>
                        {categories.map((cat) => (
                            <SelectItem key={cat.id} value={cat.id.toString()}>
                                <div className="flex items-center gap-2">
                                    <span className="h-2 w-2 rounded-full" style={{ backgroundColor: cat.color || '#ccc' }} />
                                    {cat.name}
                                </div>
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            ) : (
                <Button variant="outline" size="sm" onClick={() => setShowCategorySelect(true)}>
                    Set category
                </Button>
            )}

            {showCounterpartySelect ? (
                <Select
                    onValueChange={(value) => {
                        onSetCounterparty(value === '__none__' ? null : parseInt(value, 10));
                        setShowCounterpartySelect(false);
                    }}
                    onOpenChange={(open) => !open && setShowCounterpartySelect(false)}
                >
                    <SelectTrigger className="h-8 w-40">
                        <SelectValue placeholder="Select merchant..." />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="__none__">None</SelectItem>
                        {counterparties.map((cp) => (
                            <SelectItem key={cp.id} value={cp.id.toString()}>
                                {cp.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            ) : (
                <Button variant="outline" size="sm" onClick={() => setShowCounterpartySelect(true)}>
                    Set merchant
                </Button>
            )}

            <Button variant="outline" size="sm" onClick={onMarkRecurring}>
                Mark recurring
            </Button>

            <Button variant="outline" size="sm" onClick={onMarkTransfer}>
                Mark transfer
            </Button>

            <Button variant="outline" size="sm" onClick={onMarkDuplicate}>
                Mark duplicate
            </Button>

            <Button variant="outline" size="sm" onClick={onAcceptML} className="border-green-500 text-green-700 hover:bg-green-50">
                <Check className="mr-1 h-4 w-4" />
                Accept ML
            </Button>

            <Button variant="outline" size="sm" onClick={onFlagUncertain} className="border-red-500 text-red-700 hover:bg-red-50">
                Flag uncertain
            </Button>

            {groupInfo && groupInfo.count > selectedCount && onToggleApplyToGroup && (
                <>
                    <div className="bg-border h-6 w-px" />
                    <label className="flex cursor-pointer items-center gap-2 text-sm text-amber-700">
                        <Checkbox checked={applyToGroup} onCheckedChange={(checked) => onToggleApplyToGroup(checked === true)} />
                        Apply to all {groupInfo.count} similar transactions (all pages)
                    </label>
                </>
            )}
        </div>
    );
}
