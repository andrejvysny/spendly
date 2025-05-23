import { DataTable } from '@/components/DataTable';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ColorInput, TextInput } from '@/components/ui/form-inputs';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { z } from 'zod';

interface Tag {
    id: number;
    name: string;
    color: string | null;
}

interface Props {
    tags: Tag[];
}

const formSchema = z.object({
    name: z.string().min(1, { message: 'Name is required' }),
    color: z.string().nullable(),
});

type FormValues = InferFormValues<typeof formSchema>;

export default function Tags({ tags }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [editingTag, setEditingTag] = useState<Tag | null>(null);

    const defaultValues: FormValues = {
        name: '',
        color: '#6366F1',
    };

    const openCreateModal = () => {
        setEditingTag(null);
        setIsOpen(true);
    };

    const openEditModal = (tag: Tag) => {
        setEditingTag(tag);
        setIsOpen(true);
    };

    const onSubmit = (values: FormValues) => {
        if (editingTag) {
            router.put(`/tags/${editingTag.id}`, values, {
                onSuccess: () => {
                    setIsOpen(false);
                },
            });
        } else {
            router.post('/tags', values, {
                onSuccess: () => {
                    setIsOpen(false);
                },
            });
        }
    };

    const deleteTag = (id: number) => {
        if (confirm('Are you sure you want to delete this tag?')) {
            router.delete(`/tags/${id}`);
        }
    };

    return (
        <AppLayout>
            <Head title="Tags" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    <PageHeader
                        title="Tags"
                        buttons={[
                            {
                                onClick: () => openCreateModal(),
                                label: 'New Tag',
                            },
                        ]}
                    />
                </div>
            </div>

            <DataTable
                columns={[
                    { header: 'Name', key: 'name' },
                    {
                        header: 'Color',
                        key: 'color',
                        render: (row) => (row.color ? <div className="h-6 w-6 rounded-full" style={{ backgroundColor: row.color }} /> : null),
                    },
                    {
                        header: 'Actions',
                        key: 'actions',
                        className: 'text-right',
                        render: (row) => (
                            <div className="flex justify-end">
                                <Button variant="outline" size="sm" className="mr-2" onClick={() => openEditModal(row)}>
                                    Edit
                                </Button>
                                <Button variant="destructive" size="sm" onClick={() => deleteTag(row.id)}>
                                    Delete
                                </Button>
                            </div>
                        ),
                    },
                ]}
                data={tags}
                rowKey={(record) => record.id}
            />

            <Dialog open={isOpen} onOpenChange={setIsOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editingTag ? 'Edit Tag' : 'Add Tag'}</DialogTitle>
                        <DialogDescription>
                            {editingTag ? 'Update the tag details below.' : 'Fill in the details to create a new tag.'}
                        </DialogDescription>
                    </DialogHeader>
                    <SmartForm
                        schema={formSchema}
                        defaultValues={
                            editingTag
                                ? {
                                      name: editingTag.name,
                                      color: editingTag.color || '#6366F1',
                                  }
                                : defaultValues
                        }
                        onSubmit={onSubmit}
                        formProps={{ className: 'space-y-4' }}
                    >
                        {() => (
                            <>
                                <TextInput<FormValues> name="name" label="Name" required />

                                <ColorInput<FormValues> name="color" label="Color" />

                                <DialogFooter>
                                    <Button type="submit">{editingTag ? 'Update' : 'Create'}</Button>
                                </DialogFooter>
                            </>
                        )}
                    </SmartForm>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
