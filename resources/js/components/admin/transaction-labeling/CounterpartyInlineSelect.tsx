import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { Counterparty } from '@/types';
import { useState } from 'react';
import type { MlSuggestion } from './lib/adminTransactionLabelingTypes';

interface CounterpartyInlineSelectProps {
    value: number | null | undefined;
    suggestion: MlSuggestion | null;
    counterparties: Counterparty[];
    onChange: (value: number | null) => void;
}

export function CounterpartyInlineSelect({ value, suggestion, counterparties, onChange }: CounterpartyInlineSelectProps) {
    const [isAdding, setIsAdding] = useState(false);
    const [newValue, setNewValue] = useState('');

    const hasSuggestion = suggestion && suggestion.confidence >= 70 && !value;
    const confidence = suggestion?.confidence ?? 0;

    const handleChange = (selectedValue: string) => {
        if (selectedValue === '__add__') {
            setIsAdding(true);
            return;
        }
        if (selectedValue === '__none__') {
            onChange(null);
            return;
        }
        onChange(parseInt(selectedValue, 10));
    };

    const handleAddNew = () => {
        if (newValue.trim()) {
            setIsAdding(false);
            setNewValue('');
        }
    };

    if (isAdding) {
        return (
            <input
                type="text"
                value={newValue}
                onChange={(e) => setNewValue(e.target.value)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') handleAddNew();
                    if (e.key === 'Escape') setIsAdding(false);
                }}
                onBlur={() => setIsAdding(false)}
                placeholder="New merchant..."
                className="focus:ring-primary/50 w-32 rounded border px-2 py-1 text-sm focus:ring-2 focus:outline-none"
                autoFocus
            />
        );
    }

    return (
        <Select value={value != null ? value.toString() : undefined} onValueChange={handleChange}>
            <SelectTrigger className={cn('h-8 w-36 text-xs', hasSuggestion && 'border-amber-500 bg-amber-50', value && 'border-blue-500 bg-blue-50')}>
                <SelectValue placeholder={hasSuggestion ? `${suggestion.name} (${Math.round(confidence)}%)` : '+ Merchant'} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="__none__">None</SelectItem>
                {counterparties.map((cp) => (
                    <SelectItem key={cp.id} value={cp.id.toString()}>
                        {cp.name}
                    </SelectItem>
                ))}
                <SelectItem value="__add__">+ Add new...</SelectItem>
            </SelectContent>
        </Select>
    );
}
