import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import type { Category } from '@/types';
import { useState } from 'react';
import type { MlSuggestion } from './lib/adminTransactionLabelingTypes';

interface CategoryInlineSelectProps {
    value: number | null | undefined;
    suggestion: MlSuggestion | null;
    categories: Category[];
    onChange: (value: number | null) => void;
    /** Persist a new category server-side; resolves with the created id (selected automatically). */
    onCreate?: (name: string) => Promise<number | null>;
}

export function CategoryInlineSelect({ value, suggestion, categories, onChange, onCreate }: CategoryInlineSelectProps) {
    const [isAdding, setIsAdding] = useState(false);
    const [newValue, setNewValue] = useState('');
    const [isSaving, setIsSaving] = useState(false);

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

    const handleAddNew = async () => {
        const name = newValue.trim();
        if (!name || !onCreate || isSaving) {
            setIsAdding(false);
            setNewValue('');
            return;
        }

        setIsSaving(true);
        try {
            const createdId = await onCreate(name);
            if (createdId != null) {
                onChange(createdId);
            }
        } finally {
            setIsSaving(false);
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
                    if (e.key === 'Enter') {
                        void handleAddNew();
                    }
                    if (e.key === 'Escape') setIsAdding(false);
                }}
                onBlur={() => {
                    void handleAddNew();
                }}
                placeholder="New category..."
                disabled={isSaving}
                className="focus:ring-primary/50 w-32 rounded border px-2 py-1 text-sm focus:ring-2 focus:outline-none disabled:opacity-50"
                autoFocus
            />
        );
    }

    return (
        <Select value={value != null ? value.toString() : undefined} onValueChange={handleChange}>
            <SelectTrigger className={cn('h-8 w-36 text-xs', hasSuggestion && 'border-amber-500 bg-amber-50', value && 'border-blue-500 bg-blue-50')}>
                <SelectValue placeholder={hasSuggestion ? `${suggestion.name} (${Math.round(confidence)}%)` : '+ Category'} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value="__none__">None</SelectItem>
                {categories.map((category) => (
                    <SelectItem key={category.id} value={category.id.toString()}>
                        <div className="flex items-center gap-2">
                            <span className="h-2 w-2 rounded-full" style={{ backgroundColor: category.color || '#ccc' }} />
                            {category.name}
                        </div>
                    </SelectItem>
                ))}
                <SelectItem value="__add__">+ Add new...</SelectItem>
            </SelectContent>
        </Select>
    );
}
