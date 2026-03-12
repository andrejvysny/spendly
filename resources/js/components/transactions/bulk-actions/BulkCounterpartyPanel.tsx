import { TextInput } from '@/components/ui/form-inputs';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { SmartForm } from '@/components/ui/smart-form';
import { Counterparty, Tag, Transaction } from '@/types/index';
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
    counterparties: Counterparty[];
}

const counterpartySchema = z.object({
    name: z.string().min(1, 'Name is required'),
    description: z.string().optional(),
    logo: z.string().optional(),
});

type CounterpartyFormValues = z.infer<typeof counterpartySchema>;

export default function BulkCounterpartyPanel({ selectedTransactions, onComplete, counterparties }: Props) {
    const [selectedCounterparty, setSelectedCounterparty] = useState<string>('');
    const [isCreating, setIsCreating] = useState(false);

    const handleAssign = async () => {
        if (!selectedCounterparty) return;
        try {
            const counterpartyId = selectedCounterparty === 'none' ? '' : selectedCounterparty;
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                counterparty_id: counterpartyId,
            });
            onComplete({
                ids: selectedTransactions,
                counterparty_id: counterpartyId === '' ? null : counterpartyId,
            });
            setSelectedCounterparty('');
        } catch (error) {
            console.error('Failed to assign counterparty:', error);
        }
    };

    const handleCreate = async (values: CounterpartyFormValues) => {
        try {
            const response = await axios.post('/counterparties', values);
            const newCounterparty = response.data;
            await axios.post('/transactions/bulk-update', {
                transaction_ids: selectedTransactions,
                counterparty_id: newCounterparty.id,
            });
            onComplete({
                ids: selectedTransactions,
                counterparty_id: String(newCounterparty.id),
            });
            setIsCreating(false);
        } catch (error) {
            console.error('Failed to create counterparty:', error);
        }
    };

    if (isCreating) {
        return (
            <SmartForm schema={counterpartySchema} onSubmit={handleCreate} formProps={{ className: 'space-y-3' }}>
                {() => (
                    <>
                        <TextInput<CounterpartyFormValues> name="name" placeholder="Counterparty Name" />
                        <TextInput<CounterpartyFormValues> name="description" placeholder="Description (optional)" />
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
            <Select value={selectedCounterparty} onValueChange={setSelectedCounterparty}>
                <SelectTrigger>
                    <SelectValue placeholder="Select a counterparty" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value="none">None (Remove Counterparty)</SelectItem>
                    {counterparties && counterparties.length > 0 ? (
                        counterparties.map((counterparty) => (
                            <SelectItem key={counterparty.id} value={String(counterparty.id)}>
                                {counterparty.name}
                            </SelectItem>
                        ))
                    ) : (
                        <SelectItem value="no-counterparties" disabled>
                            No counterparties available
                        </SelectItem>
                    )}
                </SelectContent>
            </Select>
            <div className="flex gap-2">
                <button
                    onClick={handleAssign}
                    disabled={!selectedCounterparty}
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
