import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { Category, Counterparty, Tag } from '@/types/index';
import { zodResolver } from '@hookform/resolvers/zod';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const createTransactionSchema = z.object({
    amount: z.coerce.number().min(0.01, 'Amount must be greater than 0'),
    direction: z.enum(['income', 'expense']),
    currency: z.string().min(1),
    booked_date: z.string().min(1, 'Date is required'),
    description: z.string().min(1, 'Description is required'),
    type: z.enum(['PAYMENT', 'TRANSFER']),
    account_id: z.coerce.number().min(1, 'Account is required'),
    partner: z.string().optional(),
    note: z.string().optional(),
    place: z.string().optional(),
    category_id: z.coerce.number().optional(),
    counterparty_id: z.coerce.number().optional(),
    tags: z.array(z.number()).optional(),
    target_iban: z.string().optional(),
    source_iban: z.string().optional(),
});

export type CreateTransactionFormValues = z.infer<typeof createTransactionSchema>;

interface CreateTransactionModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSubmit: (values: CreateTransactionFormValues) => void;
    accounts: Array<{ id: number; name: string }>;
    categories: Category[];
    counterparties: Counterparty[];
    tags: Tag[];
}

const currencies = [
    { value: 'EUR', label: 'Euro (€)' },
    { value: 'USD', label: 'US Dollar ($)' },
    { value: 'GBP', label: 'British Pound (£)' },
    { value: 'CZK', label: 'Czech Koruna (Kč)' },
];

export default function CreateTransactionModal({
    isOpen,
    onClose,
    onSubmit,
    accounts,
    categories,
    counterparties,
    tags,
}: CreateTransactionModalProps) {
    const [showAdvanced, setShowAdvanced] = useState(false);

    const {
        register,
        handleSubmit,
        setValue,
        watch,
        reset,
        formState: { errors, isSubmitting },
    } = useForm<CreateTransactionFormValues>({
        resolver: zodResolver(createTransactionSchema),
        defaultValues: {
            amount: 0,
            direction: 'expense',
            currency: 'EUR',
            booked_date: new Date().toISOString().split('T')[0],
            description: '',
            type: 'PAYMENT',
            account_id: accounts[0]?.id ?? 0,
            partner: '',
            note: '',
            place: '',
            tags: [],
            target_iban: '',
            source_iban: '',
        },
    });

    const direction = watch('direction');
    const selectedTags = watch('tags') ?? [];
    const accountId = watch('account_id');
    const currency = watch('currency');
    const type = watch('type');

    const handleFormSubmit = (values: CreateTransactionFormValues) => {
        onSubmit(values);
        reset();
        setShowAdvanced(false);
    };

    const handleClose = () => {
        reset();
        setShowAdvanced(false);
        onClose();
    };

    const toggleTag = (tagId: number) => {
        const current = selectedTags;
        const next = current.includes(tagId) ? current.filter((id) => id !== tagId) : [...current, tagId];
        setValue('tags', next);
    };

    return (
        <Dialog open={isOpen} onOpenChange={(open) => !open && handleClose()}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>New Transaction</DialogTitle>
                    <DialogDescription>Create a new manual transaction.</DialogDescription>
                </DialogHeader>

                <form onSubmit={handleSubmit(handleFormSubmit)} className="space-y-4">
                    {/* Amount + Direction */}
                    <div className="space-y-2">
                        <Label>Amount</Label>
                        <div className="flex gap-2">
                            <Input type="number" step="0.01" min="0.01" className="flex-1" {...register('amount')} />
                            <div className="flex rounded-md border">
                                <button
                                    type="button"
                                    className={`rounded-l-md px-3 py-2 text-sm font-medium transition-colors ${
                                        direction === 'expense' ? 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300' : 'hover:bg-muted'
                                    }`}
                                    onClick={() => setValue('direction', 'expense')}
                                >
                                    Expense
                                </button>
                                <button
                                    type="button"
                                    className={`rounded-r-md px-3 py-2 text-sm font-medium transition-colors ${
                                        direction === 'income'
                                            ? 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300'
                                            : 'hover:bg-muted'
                                    }`}
                                    onClick={() => setValue('direction', 'income')}
                                >
                                    Income
                                </button>
                            </div>
                        </div>
                        {errors.amount && <p className="text-sm text-red-500">{errors.amount.message}</p>}
                    </div>

                    {/* Account */}
                    <div className="space-y-2">
                        <Label>Account</Label>
                        <Select value={String(accountId)} onValueChange={(v) => setValue('account_id', Number(v))}>
                            <SelectTrigger>
                                <SelectValue placeholder="Select account" />
                            </SelectTrigger>
                            <SelectContent>
                                {accounts.map((acc) => (
                                    <SelectItem key={acc.id} value={String(acc.id)}>
                                        {acc.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {errors.account_id && <p className="text-sm text-red-500">{errors.account_id.message}</p>}
                    </div>

                    {/* Description */}
                    <div className="space-y-2">
                        <Label>Description</Label>
                        <Input {...register('description')} />
                        {errors.description && <p className="text-sm text-red-500">{errors.description.message}</p>}
                    </div>

                    {/* Date */}
                    <div className="space-y-2">
                        <Label>Date</Label>
                        <Input type="date" {...register('booked_date')} />
                        {errors.booked_date && <p className="text-sm text-red-500">{errors.booked_date.message}</p>}
                    </div>

                    {/* Type + Currency row */}
                    <div className="grid grid-cols-2 gap-4">
                        <div className="space-y-2">
                            <Label>Type</Label>
                            <Select value={type} onValueChange={(v) => setValue('type', v as 'PAYMENT' | 'TRANSFER')}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="PAYMENT">Payment</SelectItem>
                                    <SelectItem value="TRANSFER">Transfer</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label>Currency</Label>
                            <Select value={currency} onValueChange={(v) => setValue('currency', v)}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {currencies.map((c) => (
                                        <SelectItem key={c.value} value={c.value}>
                                            {c.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {/* More options toggle */}
                    <button
                        type="button"
                        className="text-muted-foreground hover:text-foreground flex items-center gap-1 text-sm transition-colors"
                        onClick={() => setShowAdvanced(!showAdvanced)}
                    >
                        {showAdvanced ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                        More options
                    </button>

                    {showAdvanced && (
                        <div className="space-y-4 border-t pt-4">
                            {/* Partner */}
                            <div className="space-y-2">
                                <Label>Partner</Label>
                                <Input {...register('partner')} placeholder="e.g. Amazon, Landlord" />
                            </div>

                            {/* Category */}
                            <div className="space-y-2">
                                <Label>Category</Label>
                                <Select
                                    value={watch('category_id') ? String(watch('category_id')) : 'none'}
                                    onValueChange={(v) => setValue('category_id', v === 'none' ? undefined : Number(v))}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="None" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">None</SelectItem>
                                        {categories.map((cat) => (
                                            <SelectItem key={cat.id} value={String(cat.id)}>
                                                {cat.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Counterparty */}
                            <div className="space-y-2">
                                <Label>Counterparty</Label>
                                <Select
                                    value={watch('counterparty_id') ? String(watch('counterparty_id')) : 'none'}
                                    onValueChange={(v) => setValue('counterparty_id', v === 'none' ? undefined : Number(v))}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="None" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">None</SelectItem>
                                        {counterparties.map((cp) => (
                                            <SelectItem key={cp.id} value={String(cp.id)}>
                                                {cp.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>

                            {/* Tags */}
                            {tags.length > 0 && (
                                <div className="space-y-2">
                                    <Label>Tags</Label>
                                    <div className="flex flex-wrap gap-2">
                                        {tags.map((tag) => (
                                            <label key={tag.id} className="flex cursor-pointer items-center gap-1.5 text-sm">
                                                <Checkbox checked={selectedTags.includes(tag.id)} onCheckedChange={() => toggleTag(tag.id)} />
                                                {tag.name}
                                            </label>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {/* Note */}
                            <div className="space-y-2">
                                <Label>Note</Label>
                                <Textarea {...register('note')} rows={2} />
                            </div>

                            {/* Place */}
                            <div className="space-y-2">
                                <Label>Place</Label>
                                <Input {...register('place')} />
                            </div>

                            {/* IBANs */}
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label>Target IBAN</Label>
                                    <Input {...register('target_iban')} />
                                </div>
                                <div className="space-y-2">
                                    <Label>Source IBAN</Label>
                                    <Input {...register('source_iban')} />
                                </div>
                            </div>
                        </div>
                    )}

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={handleClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={isSubmitting}>
                            Create Transaction
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
