import { DataTable } from '@/components/DataTable';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SelectInput, TextInput, TextareaInput } from '@/components/ui/form-inputs';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { z } from 'zod';

interface Merchant {
    id: number;
    name: string;
    description: string | null;
    logo: string | null;
}

interface Props {
    merchants: Merchant[];
}

const formSchema = z.object({
    name: z.string().min(1, { message: 'Name is required' }),
    description: z.string().nullable(),
    logo: z.string().nullable(),
});

const deleteFormSchema = z.object({
    replacement_action: z.string(),
    replacement_merchant_id: z.string().nullable(),
});

type FormValues = InferFormValues<typeof formSchema>;
type DeleteFormValues = InferFormValues<typeof deleteFormSchema>;

/**
 * Displays and manages a list of merchants with create, edit, and delete functionality.
 *
 * Renders a table of merchants and provides modals for creating, editing, and deleting merchants. Deletion includes options for handling related transactions.
 *
 * @param merchants - The list of merchants to display and manage.
 */
export default function Merchants({ merchants }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [editingMerchant, setEditingMerchant] = useState<Merchant | null>(null);
    const [deletingMerchant, setDeletingMerchant] = useState<Merchant | null>(null);

    const defaultValues: FormValues = {
        name: '',
        description: '',
        logo: '',
    };

    const defaultDeleteValues: DeleteFormValues = {
        replacement_action: 'remove',
        replacement_merchant_id: null,
    };

    const openCreateModal = () => {
        setEditingMerchant(null);
        setIsOpen(true);
    };

    const openEditModal = (merchant: Merchant) => {
        setEditingMerchant(merchant);
        setIsOpen(true);
    };

    const openDeleteModal = (merchant: Merchant) => {
        setDeletingMerchant(merchant);
        setIsDeleteOpen(true);
    };

    const onSubmit = (values: FormValues) => {
        if (editingMerchant) {
            router.put(`/merchants/${editingMerchant.id}`, values, {
                onSuccess: () => {
                    setIsOpen(false);
                    setEditingMerchant(null);
                },
            });
        } else {
            router.post('/merchants', values, {
                onSuccess: () => {
                    setIsOpen(false);
                },
            });
        }
    };

    const onDeleteSubmit = (values: DeleteFormValues) => {
        if (!deletingMerchant) return;

        router.delete(`/merchants/${deletingMerchant.id}`, {
            data: values,
            onSuccess: () => {
                setIsDeleteOpen(false);
                setDeletingMerchant(null);
            },
        });
    };

    // Prepare merchant options for select input
    const merchantOptions = merchants
        .filter((m) => (deletingMerchant ? m.id !== deletingMerchant.id : true))
        .map((merchant) => ({
            value: merchant.id.toString(),
            label: merchant.name,
        }));

    return (
        <AppLayout>
            <Head title="Merchants" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    <PageHeader
                        title="Merchants"
                        buttons={[
                            {
                                onClick: () => openCreateModal(),
                                label: 'New Merchant',
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
                data={merchants}
                rowKey={(record) => record.id}
            />

            {/* Edit/Create Modal */}
            <Dialog open={isOpen} onOpenChange={setIsOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editingMerchant ? 'Edit Merchant' : 'Add Merchant'}</DialogTitle>
                        <DialogDescription>
                            {editingMerchant ? 'Update the merchant details below.' : 'Fill in the details to create a new merchant.'}
                        </DialogDescription>
                    </DialogHeader>
                    <SmartForm
                        schema={formSchema}
                        defaultValues={
                            editingMerchant
                                ? {
                                      name: editingMerchant.name,
                                      description: editingMerchant.description || '',
                                      logo: editingMerchant.logo || '',
                                  }
                                : defaultValues
                        }
                        onSubmit={onSubmit}
                        formProps={{ className: 'space-y-4' }}
                    >
                        {() => (
                            <>
                                <TextInput<FormValues> name="name" label="Name" required />

                                <TextareaInput<FormValues> name="description" label="Description" />

                                <TextInput<FormValues> name="logo" label="Logo URL" />

                                <DialogFooter>
                                    <Button type="submit">{editingMerchant ? 'Update' : 'Create'}</Button>
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
                        <DialogTitle>Delete Merchant</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete this merchant? This action cannot be undone. Please select what should happen to
                            transactions associated with this merchant.
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
                                            { value: 'remove', label: 'Remove merchant from transactions' },
                                            { value: 'replace', label: 'Replace with another merchant' },
                                        ]}
                                    />

                                    {replacementAction === 'replace' && (
                                        <SelectInput<DeleteFormValues>
                                            name="replacement_merchant_id"
                                            label="Replace with"
                                            options={merchantOptions}
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
