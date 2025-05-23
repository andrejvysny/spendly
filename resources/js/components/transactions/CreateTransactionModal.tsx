import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SelectInput, TextInput } from '@/components/ui/form-inputs';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import { Transaction } from '@/types/index';
import { z } from 'zod';

interface CreateTransactionModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSubmit: (transaction: Omit<Transaction, 'id' | 'created_at' | 'updated_at' | 'account'>) => void;
}

const transactionSchema = z.object({
    transaction_id: z.string().min(1, { message: 'Transaction ID is required' }),
    amount: z.coerce.number().min(0.01, { message: 'Amount must be greater than 0' }),
    currency: z.string().min(1, { message: 'Currency is required' }),
    booked_date: z.string().min(1, { message: 'Booked date is required' }),
    processed_date: z.string().min(1, { message: 'Processed date is required' }),
    description: z.string().min(1, { message: 'Description is required' }),
    target_iban: z.string().nullable(),
    source_iban: z.string().nullable(),
    partner: z.string().min(1, { message: 'Partner is required' }),
    type: z.enum(['TRANSFER', 'DEPOSIT', 'WITHDRAWAL', 'PAYMENT'], {
        required_error: 'Type is required',
    }),
    metadata: z.record(z.any()).nullable(),
    balance_after_transaction: z.coerce.number(),
    account_id: z.number(),
    import_data: z.record(z.any()).nullable(),
});

type FormValues = InferFormValues<typeof transactionSchema>;

const transactionTypes = [
    { value: 'TRANSFER', label: 'Transfer' },
    { value: 'DEPOSIT', label: 'Deposit' },
    { value: 'WITHDRAWAL', label: 'Withdrawal' },
    { value: 'PAYMENT', label: 'Payment' },
];

const currencies = [
    { value: 'EUR', label: 'Euro (€)' },
    { value: 'USD', label: 'US Dollar ($)' },
    { value: 'GBP', label: 'British Pound (£)' },
];

export default function CreateTransactionModal({ isOpen, onClose, onSubmit }: CreateTransactionModalProps) {
    const defaultValues: FormValues = {
        transaction_id: `TRX-${Date.now()}`,
        amount: 0,
        currency: 'EUR',
        booked_date: new Date().toISOString().split('T')[0],
        processed_date: new Date().toISOString().split('T')[0],
        description: '',
        target_iban: null,
        source_iban: null,
        partner: '',
        type: 'PAYMENT',
        metadata: null,
        balance_after_transaction: 0,
        account_id: 1,
        import_data: null,
    };

    const handleSubmit = (values: FormValues) => {
        onSubmit({
            ...values,
            balance_after_transaction: values.amount, // Set initial balance to amount
        });
        onClose();
    };

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New Transaction</DialogTitle>
                    <DialogDescription>Fill in the details to create a new transaction.</DialogDescription>
                </DialogHeader>
                <SmartForm schema={transactionSchema} defaultValues={defaultValues} onSubmit={handleSubmit} formProps={{ className: 'space-y-4' }}>
                    {() => (
                        <>
                            <TextInput<FormValues> name="partner" label="Partner" required />

                            <TextInput<FormValues> name="amount" label="Amount" type="number" required />

                            <SelectInput<FormValues> name="currency" label="Currency" options={currencies} required />

                            <TextInput<FormValues> name="description" label="Description" required />

                            <SelectInput<FormValues> name="type" label="Type" options={transactionTypes} required />

                            <TextInput<FormValues> name="target_iban" label="Target IBAN" />

                            <TextInput<FormValues> name="source_iban" label="Source IBAN" />

                            <TextInput<FormValues> name="booked_date" label="Booked Date" type="date" required />

                            <TextInput<FormValues> name="processed_date" label="Processed Date" type="date" required />

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={onClose}>
                                    Cancel
                                </Button>
                                <Button type="submit">Create Transaction</Button>
                            </DialogFooter>
                        </>
                    )}
                </SmartForm>
            </DialogContent>
        </Dialog>
    );
}
