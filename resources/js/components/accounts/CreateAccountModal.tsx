import { SelectInput, TextInput } from '@/components/ui/form-inputs';
import { FormModal } from '@/components/ui/form-modal';
import { InferFormValues } from '@/components/ui/smart-form';
import { Currency } from '@/types/index';
import { z } from 'zod';

interface CreateAccountModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSubmit: (data: {
        name: string;
        account_id: string;
        bank_name: string;
        iban: string;
        currency: Currency;
        balance: number;
        type: string;
    }) => void;
}

const formSchema = z.object({
    name: z.string().min(1, { message: 'Name is required' }),
    account_id: z.string().min(1, { message: 'Account ID is required' }),
    bank_name: z.string().min(1, { message: 'Bank name is required' }),
    iban: z.string().min(1, { message: 'IBAN is required' }),
    type: z.string(),
    currency: z.nativeEnum(Currency),
    balance: z.string().transform((val) => parseFloat(val)),
});

type FormValues = InferFormValues<typeof formSchema>;

export default function CreateAccountModal({ isOpen, onClose, onSubmit }: CreateAccountModalProps) {
    const defaultValues: FormValues = {
        name: '',
        account_id: '',
        bank_name: '',
        iban: '',
        type: 'Default',
        currency: Currency.EUR,
        balance: 0,
    };

    const handleSubmit = (values: FormValues) => {
        onSubmit(values);
    };

    return (
        <FormModal
            isOpen={isOpen}
            onClose={onClose}
            title="Create New Account"
            description="Fill in the details to create a new account."
            schema={formSchema}
            defaultValues={defaultValues}
            onSubmit={handleSubmit}
            submitLabel="Create Account"
        >
            {() => (
                <>
                    <TextInput<FormValues> name="name" label="Account Name" required />
                    <TextInput<FormValues> name="account_id" label="Account ID" required />
                    <SelectInput<FormValues>
                        name="type"
                        label="Account Type"
                        options={[
                            { value: 'Default', label: 'Default' },
                            { value: 'Manual', label: 'Manual' },
                        ]}
                    />
                    <TextInput<FormValues> name="bank_name" label="Bank Name" required />
                    <TextInput<FormValues> name="iban" label="IBAN" required />
                    <SelectInput<FormValues>
                        name="currency"
                        label="Currency"
                        options={Object.values(Currency).map((currency) => ({
                            value: currency,
                            label: currency,
                        }))}
                    />
                    <TextInput<FormValues> name="balance" label="Initial Balance" type="number" required />
                </>
            )}
        </FormModal>
    );
}
