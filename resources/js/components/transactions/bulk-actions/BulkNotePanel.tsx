import { TextareaInput } from '@/components/ui/form-inputs';
import { SmartForm } from '@/components/ui/smart-form';
import { Tag, Transaction } from '@/types/index';
import axios from 'axios';
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

const noteSchema = z.object({
    note: z.string().min(1, 'Note is required'),
});

type NoteFormValues = z.infer<typeof noteSchema>;

export default function BulkNotePanel({ selectedTransactions, onComplete }: BulkPanelProps) {
    const handleNote = async (values: NoteFormValues, event?: React.BaseSyntheticEvent) => {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const submitter = (event?.nativeEvent as any)?.submitter;
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
            onComplete({
                ids: selectedTransactions,
                updated_transactions: response.data.updated_transactions,
            });
        } catch (error) {
            console.error('Failed to update notes:', error);
        }
    };

    return (
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
    );
}
