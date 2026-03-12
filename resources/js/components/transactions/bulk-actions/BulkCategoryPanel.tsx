import { TextInput } from '@/components/ui/form-inputs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SmartForm } from '@/components/ui/smart-form';
import { Category, Tag, Transaction } from '@/types/index';
import axios from 'axios';
import { useState } from 'react';
import { z } from 'zod';

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
    categories: Category[];
}

const categorySchema = z.object({
    name: z.string().min(1, 'Name is required'),
    color: z.string().min(1, 'Color is required'),
    icon: z.string().optional(),
    description: z.string().optional(),
});

type CategoryFormValues = z.infer<typeof categorySchema>;

export default function BulkCategoryPanel({ selectedTransactions, onComplete, categories }: Props) {
    const [selectedCategory, setSelectedCategory] = useState<string>('');
    const [isCreating, setIsCreating] = useState(false);

    const handleAssign = async () => {
        if (!selectedCategory) return;
        try {
            const categoryId = selectedCategory === 'none' ? '' : selectedCategory;
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                category_id: categoryId,
            });
            onComplete({
                ids: selectedTransactions,
                category_id: categoryId === '' ? null : categoryId,
            });
            setSelectedCategory('');
        } catch (error) {
            console.error('Failed to assign category:', error);
        }
    };

    const handleCreate = async (values: CategoryFormValues) => {
        try {
            const response = await axios.post('/categories', values);
            const newCategory = response.data;
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                category_id: newCategory.id,
            });
            onComplete({
                ids: selectedTransactions,
                category_id: String(newCategory.id),
            });
            setIsCreating(false);
        } catch (error) {
            console.error('Failed to create category:', error);
        }
    };

    if (isCreating) {
        return (
            <SmartForm schema={categorySchema} onSubmit={handleCreate} formProps={{ className: 'space-y-3' }}>
                {() => (
                    <>
                        <TextInput<CategoryFormValues> name="name" placeholder="Category Name" />
                        <TextInput<CategoryFormValues> name="color" type="color" label="Color" />
                        <TextInput<CategoryFormValues> name="description" placeholder="Description (optional)" />
                        <div className="flex gap-2">
                            <button
                                type="submit"
                                className="bg-primary text-primary-foreground hover:bg-primary/90 flex-1 rounded-md px-3 py-1.5 text-sm font-medium"
                            >
                                Create & Assign
                            </button>
                            <button
                                type="button"
                                onClick={() => setIsCreating(false)}
                                className="bg-secondary text-secondary-foreground hover:bg-secondary/80 rounded-md px-3 py-1.5 text-sm font-medium"
                            >
                                Cancel
                            </button>
                        </div>
                    </>
                )}
            </SmartForm>
        );
    }

    return (
        <div className="space-y-2">
            <Select value={selectedCategory} onValueChange={setSelectedCategory}>
                <SelectTrigger>
                    <SelectValue placeholder="Select a category" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="none">None (Remove Category)</SelectItem>
                    {categories && categories.length > 0 ? (
                        categories.map((category) => (
                            <SelectItem key={category.id} value={String(category.id)}>
                                {category.name}
                            </SelectItem>
                        ))
                    ) : (
                        <SelectItem value="no-categories" disabled>
                            No categories available
                        </SelectItem>
                    )}
                </SelectContent>
            </Select>
            <div className="flex gap-2">
                <button
                    onClick={handleAssign}
                    disabled={!selectedCategory}
                    className="bg-primary text-primary-foreground hover:bg-primary/90 flex-1 rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50"
                >
                    Apply
                </button>
                <button
                    onClick={() => setIsCreating(true)}
                    className="bg-secondary text-secondary-foreground hover:bg-secondary/80 rounded-md px-3 py-1.5 text-sm font-medium"
                >
                    Create New
                </button>
            </div>
        </div>
    );
}
