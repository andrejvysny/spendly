import { TextareaInput, TextInput } from '@/components/ui/form-inputs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SmartForm } from '@/components/ui/smart-form';
import { Category, Merchant } from '@/types/index';
import axios from 'axios';
import { useState } from 'react';
import { z } from 'zod';

interface Props {
    selectedTransactions: string[];
    categories?: Category[];
    merchants?: Merchant[];
    onUpdate: (data?: {
        ids: string[];
        category_id?: string | null;
        merchant_id?: string | null;
        updated_transactions?: Array<{ id: number; note: string }>;
    }) => void;
}

const categorySchema = z.object({
    name: z.string().min(1, 'Name is required'),
    color: z.string().min(1, 'Color is required'),
    icon: z.string().optional(),
    description: z.string().optional(),
});

const merchantSchema = z.object({
    name: z.string().min(1, 'Name is required'),
    description: z.string().optional(),
    logo: z.string().optional(),
});

const noteSchema = z.object({
    note: z.string().min(1, 'Note is required'),
});

type CategoryFormValues = z.infer<typeof categorySchema>;
type MerchantFormValues = z.infer<typeof merchantSchema>;
type NoteFormValues = z.infer<typeof noteSchema>;

/**
 * Displays a menu for bulk assigning categories or merchants to selected transactions, with options to create new categories or merchants inline.
 *
 * The menu appears when transactions are selected, allowing users to assign an existing or newly created category or merchant to all selected transactions. After a successful assignment or creation, the parent component is notified via the provided callback.
 *
 * @param selectedTransactions - Array of transaction IDs to update.
 * @param categories - Optional list of available categories for assignment.
 * @param merchants - Optional list of available merchants for assignment.
 * @param onUpdate - Callback invoked after a successful assignment or when the menu is closed.
 *
 * @returns The bulk action menu UI, or `null` if no transactions are selected.
 */
export default function BulkActionMenu({ selectedTransactions, categories = [], merchants = [], onUpdate }: Props) {
    const [activeMenu, setActiveMenu] = useState<'category' | 'merchant' | 'note' | null>(null);
    const [selectedCategory, setSelectedCategory] = useState<string>('');
    const [selectedMerchant, setSelectedMerchant] = useState<string>('');
    const [isCreatingCategory, setIsCreatingCategory] = useState(false);
    const [isCreatingMerchant, setIsCreatingMerchant] = useState(false);

    const handleAssignCategory = async () => {
        if (!selectedCategory) return;

        try {
            const categoryId = selectedCategory === 'none' ? '' : selectedCategory;
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                category_id: categoryId,
            });
            onUpdate({
                ids: selectedTransactions,
                category_id: categoryId === '' ? null : categoryId,
            });
            setActiveMenu(null);
            setSelectedCategory('');
        } catch (error) {
            console.error('Failed to assign category:', error);
        }
    };

    const handleAssignMerchant = async () => {
        if (!selectedMerchant) return;

        try {
            const merchantId = selectedMerchant === 'none' ? '' : selectedMerchant;
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                merchant_id: merchantId,
            });
            onUpdate({
                ids: selectedTransactions,
                merchant_id: merchantId === '' ? null : merchantId,
            });
            setActiveMenu(null);
            setSelectedMerchant('');
        } catch (error) {
            console.error('Failed to assign merchant:', error);
        }
    };

    const handleCreateCategory = async (values: CategoryFormValues) => {
        try {
            const response = await axios.post('/categories', values);
            const newCategory = response.data;

            // Assign the new category to selected transactions
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                category_id: newCategory.id,
            });

            onUpdate({
                ids: selectedTransactions,
                category_id: String(newCategory.id),
            });
            setActiveMenu(null);
            setIsCreatingCategory(false);
        } catch (error) {
            console.error('Failed to create category:', error);
        }
    };

    const handleCreateMerchant = async (values: MerchantFormValues) => {
        try {
            const response = await axios.post('/merchants', values);
            const newMerchant = response.data;

            // Assign the new merchant to selected transactions
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                merchant_id: newMerchant.id,
            });

            onUpdate({
                ids: selectedTransactions,
                merchant_id: String(newMerchant.id),
            });
            setActiveMenu(null);
            setIsCreatingMerchant(false);
        } catch (error) {
            console.error('Failed to create merchant:', error);
        }
    };

    const handleNote = async (values: NoteFormValues, event: { nativeEvent: { submitter: { name: string } } }) => {
        const submitter = event?.nativeEvent?.submitter;
        if (submitter?.name === 'replace') {
            await handleAddNote(values, 'replace');
        } else if (submitter?.name === 'append') {
            await handleAddNote(values, 'append');
        }
    };

    const handleAddNote = async (values: NoteFormValues, method: 'replace' | 'append') => {
        try {
            const response = await axios.post('/transactions/bulk-note-update', {
                transaction_ids: selectedTransactions,
                note: values.note,
                method: method,
            });

            onUpdate({
                ids: selectedTransactions,
                updated_transactions: response.data.updated_transactions,
            });
            setActiveMenu(null);
        } catch (error) {
            console.error('Failed to update notes:', error);
        }
    };

    if (selectedTransactions.length === 0) return null;

    return (
        <div className="fixed right-4 bottom-4 z-50">
            <div className="bg-card border-border w-80 rounded-lg border shadow-lg">
                {/* Header */}
                <div className="border-border border-b p-3">
                    <div className="flex items-center justify-between">
                        <span className="text-sm font-medium">
                            {selectedTransactions.length} {selectedTransactions.length === 1 ? 'transaction' : 'transactions'} selected
                        </span>
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

                {/* Actions */}
                <div className="p-3">
                    <div className="space-y-1.5">
                        <button
                            onClick={() => setActiveMenu(activeMenu === 'category' ? null : 'category')}
                            className={`w-full rounded-md px-3 py-1.5 text-sm font-medium ${
                                activeMenu === 'category'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-secondary text-secondary-foreground hover:bg-secondary/80'
                            }`}
                        >
                            Assign Category
                        </button>

                        <button
                            onClick={() => setActiveMenu(activeMenu === 'merchant' ? null : 'merchant')}
                            className={`w-full rounded-md px-3 py-1.5 text-sm font-medium ${
                                activeMenu === 'merchant'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-secondary text-secondary-foreground hover:bg-secondary/80'
                            }`}
                        >
                            Assign Merchant
                        </button>

                        <button
                            onClick={() => setActiveMenu(activeMenu === 'note' ? null : 'note')}
                            className={`w-full rounded-md px-3 py-1.5 text-sm font-medium ${
                                activeMenu === 'note'
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-secondary text-secondary-foreground hover:bg-secondary/80'
                            }`}
                        >
                            Add Note
                        </button>
                    </div>
                </div>

                {/* Category Selection Panel */}
                {activeMenu === 'category' && (
                    <div className="border-border border-t p-3">
                        {isCreatingCategory ? (
                            <SmartForm schema={categorySchema} onSubmit={handleCreateCategory} formProps={{ className: 'space-y-3' }}>
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
                                                onClick={() => setIsCreatingCategory(false)}
                                                className="bg-secondary text-secondary-foreground hover:bg-secondary/80 rounded-md px-3 py-1.5 text-sm font-medium"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </>
                                )}
                            </SmartForm>
                        ) : (
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
                                        onClick={handleAssignCategory}
                                        disabled={!selectedCategory}
                                        className="bg-primary text-primary-foreground hover:bg-primary/90 flex-1 rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50"
                                    >
                                        Apply
                                    </button>
                                    <button
                                        onClick={() => setIsCreatingCategory(true)}
                                        className="bg-secondary text-secondary-foreground hover:bg-secondary/80 rounded-md px-3 py-1.5 text-sm font-medium"
                                    >
                                        Create New
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                )}

                {/* Merchant Selection Panel */}
                {activeMenu === 'merchant' && (
                    <div className="border-border border-t p-3">
                        {isCreatingMerchant ? (
                            <SmartForm schema={merchantSchema} onSubmit={handleCreateMerchant} formProps={{ className: 'space-y-3' }}>
                                {() => (
                                    <>
                                        <TextInput<MerchantFormValues> name="name" placeholder="Merchant Name" />
                                        <TextInput<MerchantFormValues> name="description" placeholder="Description (optional)" />
                                        <div className="flex gap-2">
                                            <button
                                                type="submit"
                                                className="bg-primary text-primary-foreground hover:bg-primary/90 flex-1 rounded-md px-3 py-1.5 text-sm font-medium"
                                            >
                                                Create & Assign
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => setIsCreatingMerchant(false)}
                                                className="bg-secondary text-secondary-foreground hover:bg-secondary/80 rounded-md px-3 py-1.5 text-sm font-medium"
                                            >
                                                Cancel
                                            </button>
                                        </div>
                                    </>
                                )}
                            </SmartForm>
                        ) : (
                            <div className="space-y-2">
                                <Select value={selectedMerchant} onValueChange={setSelectedMerchant}>
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a merchant" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">None (Remove Merchant)</SelectItem>
                                        {merchants && merchants.length > 0 ? (
                                            merchants.map((merchant) => (
                                                <SelectItem key={merchant.id} value={String(merchant.id)}>
                                                    {merchant.name}
                                                </SelectItem>
                                            ))
                                        ) : (
                                            <SelectItem value="no-merchants" disabled>
                                                No merchants available
                                            </SelectItem>
                                        )}
                                    </SelectContent>
                                </Select>
                                <div className="flex gap-2">
                                    <button
                                        onClick={handleAssignMerchant}
                                        disabled={!selectedMerchant}
                                        className="bg-primary text-primary-foreground hover:bg-primary/90 flex-1 rounded-md px-3 py-1.5 text-sm font-medium disabled:opacity-50"
                                    >
                                        Apply
                                    </button>
                                    <button
                                        onClick={() => setIsCreatingMerchant(true)}
                                        className="bg-secondary text-secondary-foreground hover:bg-secondary/80 rounded-md px-3 py-1.5 text-sm font-medium"
                                    >
                                        Create New
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                )}
                {/* Note Addition Panel */}
                {activeMenu === 'note' && (
                    <div className="border-border border-t p-3">
                        <div className="space-y-2">
                            <SmartForm schema={noteSchema} onSubmit={handleNote} formProps={{ className: 'space-y-3' }}>
                                {() => (
                                    <>
                                        <TextareaInput<NoteFormValues> name="note" label="" placeholder="Note" />
                                        <div className="flex gap-2">
                                            <button
                                                type="submit"
                                                name="replace"
                                                className="bg-primary text-primary-foreground hover:bg-primary/90 flex-1 rounded-md px-3 py-1.5 text-sm font-medium"
                                            >
                                                Replace note
                                            </button>
                                            <button
                                                type="submit"
                                                name="append"
                                                className="bg-primary text-primary-foreground hover:bg-primary/90 flex-1 rounded-md px-3 py-1.5 text-sm font-medium"
                                            >
                                                Append note
                                            </button>
                                        </div>
                                    </>
                                )}
                            </SmartForm>
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}
