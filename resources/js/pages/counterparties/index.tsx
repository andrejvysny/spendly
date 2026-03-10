import { DataTable } from '@/components/DataTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SelectInput, TextInput, TextareaInput } from '@/components/ui/form-inputs';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { z } from 'zod';

const COUNTERPARTY_TYPES = ['merchant', 'person', 'institution', 'employer', 'other'] as const;
type CounterpartyType = (typeof COUNTERPARTY_TYPES)[number];

const TYPE_BADGE_VARIANTS: Record<CounterpartyType, 'default' | 'secondary' | 'outline' | 'destructive'> = {
    merchant: 'default',
    person: 'secondary',
    institution: 'outline',
    employer: 'secondary',
    other: 'outline',
};

interface Counterparty {
    id: number;
    name: string;
    type: string;
    description: string | null;
    logo: string | null;
}

interface Props {
    counterparties: Counterparty[];
}

const formSchema = z.object({
    name: z.string().min(1, { message: 'Name is required' }),
    type: z.string().min(1, { message: 'Type is required' }),
    description: z.string().nullable(),
    logo: z.string().nullable(),
});

const deleteFormSchema = z.object({
    replacement_action: z.string(),
    replacement_counterparty_id: z.string().nullable(),
});

type FormValues = InferFormValues<typeof formSchema>;
type DeleteFormValues = InferFormValues<typeof deleteFormSchema>;

const TYPE_OPTIONS = COUNTERPARTY_TYPES.map((t) => ({
    value: t,
    label: t.charAt(0).toUpperCase() + t.slice(1),
}));

/**
 * Displays and manages a list of counterparties with create, edit, and delete functionality.
 */
export default function Counterparties({ counterparties }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [editingCounterparty, setEditingCounterparty] = useState<Counterparty | null>(null);
    const [deletingCounterparty, setDeletingCounterparty] = useState<Counterparty | null>(null);

    const defaultValues: FormValues = {
        name: '',
        type: 'merchant',
        description: '',
        logo: '',
    };

    const defaultDeleteValues: DeleteFormValues = {
        replacement_action: 'remove',
        replacement_counterparty_id: null,
    };

    const openCreateModal = () => {
        setEditingCounterparty(null);
        setIsOpen(true);
    };

    const openEditModal = (counterparty: Counterparty) => {
        setEditingCounterparty(counterparty);
        setIsOpen(true);
    };

    const openDeleteModal = (counterparty: Counterparty) => {
        setDeletingCounterparty(counterparty);
        setIsDeleteOpen(true);
    };

    const onSubmit = (values: FormValues) => {
        if (editingCounterparty) {
            router.put(`/counterparties/${editingCounterparty.id}`, values, {
                onSuccess: () => {
                    setIsOpen(false);
                    setEditingCounterparty(null);
                },
            });
        } else {
            router.post('/counterparties', values, {
                onSuccess: () => {
                    setIsOpen(false);
                },
            });
        }
    };

    const onDeleteSubmit = (values: DeleteFormValues) => {
        if (!deletingCounterparty) return;

        router.delete(`/counterparties/${deletingCounterparty.id}`, {
            data: values,
            onSuccess: () => {
                setIsDeleteOpen(false);
                setDeletingCounterparty(null);
            },
        });
    };

    const counterpartyOptions = counterparties
        .filter((c) => (deletingCounterparty ? c.id !== deletingCounterparty.id : true))
        .map((counterparty) => ({
            value: counterparty.id.toString(),
            label: counterparty.name,
        }));

    return (
        <AppLayout>
            <Head title="Counterparties" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    <PageHeader
                        title="Counterparties"
                        buttons={[
                            {
                                onClick: () => openCreateModal(),
                                label: 'New Counterparty',
                            },
                        ]}
                    />
                </div>
            </div>

            <DataTable
                columns={[
                    {
                        header: 'Logo',
                        key: 'logo',
                        className: 'p-0',
                        render: (row) => (row.logo ? <img src={row.logo} alt={row.name} className="w-full max-w-20 object-contain" /> : null),
                    },
                    { header: 'Name', key: 'name' },
                    {
                        header: 'Type',
                        key: 'type',
                        render: (row) => (
                            <Badge variant={TYPE_BADGE_VARIANTS[row.type as CounterpartyType] ?? 'outline'}>
                                {row.type ? row.type.charAt(0).toUpperCase() + row.type.slice(1) : '—'}
                            </Badge>
                        ),
                    },
                    { header: 'Description', key: 'description' },
                    {
                        header: 'Actions',
                        key: 'actions',
                        className: 'text-right',
                        render: (row) => (
                            <div className="flex justify-end">
                                <Button variant="outline" size="sm" className="mr-2" onClick={() => openEditModal(row)}>
                                    Edit
                                </Button>
                                <Button variant="destructive" size="sm" onClick={() => openDeleteModal(row)}>
                                    Delete
                                </Button>
                            </div>
                        ),
                    },
                ]}
                data={counterparties}
                rowKey={(record) => record.id}
            />

            {/* Edit/Create Modal */}
            <Dialog open={isOpen} onOpenChange={setIsOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editingCounterparty ? 'Edit Counterparty' : 'Add Counterparty'}</DialogTitle>
                        <DialogDescription>
                            {editingCounterparty ? 'Update the counterparty details below.' : 'Fill in the details to create a new counterparty.'}
                        </DialogDescription>
                    </DialogHeader>
                    <SmartForm
                        schema={formSchema}
                        defaultValues={
                            editingCounterparty
                                ? {
                                      name: editingCounterparty.name,
                                      type: editingCounterparty.type || 'merchant',
                                      description: editingCounterparty.description || '',
                                      logo: editingCounterparty.logo || '',
                                  }
                                : defaultValues
                        }
                        onSubmit={onSubmit}
                        formProps={{ className: 'space-y-4' }}
                    >
                        {() => (
                            <>
                                <TextInput<FormValues> name="name" label="Name" required />

                                <SelectInput<FormValues> name="type" label="Type" options={TYPE_OPTIONS} required />

                                <TextareaInput<FormValues> name="description" label="Description" />

                                <TextInput<FormValues> name="logo" label="Logo URL" />

                                <DialogFooter>
                                    <Button type="submit">{editingCounterparty ? 'Update' : 'Create'}</Button>
                                </DialogFooter>
                            </>
                        )}
                    </SmartForm>
                </DialogContent>
            </Dialog>

            {/* Delete Confirmation Modal */}
            <Dialog open={isDeleteOpen} onOpenChange={setIsDeleteOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Counterparty</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete this counterparty? This action cannot be undone. Please select what should happen to
                            transactions associated with this counterparty.
                        </DialogDescription>
                    </DialogHeader>
                    <SmartForm
                        schema={deleteFormSchema}
                        defaultValues={defaultDeleteValues}
                        onSubmit={onDeleteSubmit}
                        formProps={{ className: 'space-y-4' }}
                    >
                        {({ watch }) => {
                            const replacementAction = watch('replacement_action');

                            return (
                                <>
                                    <SelectInput<DeleteFormValues>
                                        name="replacement_action"
                                        label="What should happen to related transactions?"
                                        options={[
                                            { value: 'remove', label: 'Remove counterparty from transactions' },
                                            { value: 'replace', label: 'Replace with another counterparty' },
                                        ]}
                                    />

                                    {replacementAction === 'replace' && (
                                        <SelectInput<DeleteFormValues>
                                            name="replacement_counterparty_id"
                                            label="Replace with"
                                            options={counterpartyOptions}
                                            required
                                        />
                                    )}

                                    <DialogFooter>
                                        <Button type="button" variant="outline" onClick={() => setIsDeleteOpen(false)}>
                                            Cancel
                                        </Button>
                                        <Button type="submit" variant="destructive">
                                            Delete
                                        </Button>
                                    </DialogFooter>
                                </>
                            );
                        }}
                    </SmartForm>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
