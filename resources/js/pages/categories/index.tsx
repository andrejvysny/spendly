import { DataTable } from '@/components/DataTable';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { ColorInput, SelectInput, TextInput, TextareaInput } from '@/components/ui/form-inputs';
import { Icon } from '@/components/ui/icon';
import { IconPicker, icons } from '@/components/ui/icon-picker';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { z } from 'zod';

interface Category {
    id: number;
    name: string;
    description: string | null;
    color: string | null;
    icon: string | null;
    parent_category_id: number | null;
    parent_category?: Category | null;
}

interface Props {
    categories: Category[];
}

const formSchema = z.object({
    name: z.string().min(1, { message: 'Name is required' }),
    description: z.string().nullable(),
    color: z.string().nullable(),
    icon: z.string().nullable(),
    parent_category_id: z.string(),
});

const deleteFormSchema = z.object({
    replacement_action: z.string(),
    replacement_category_id: z.string().nullable(),
});

type FormValues = InferFormValues<typeof formSchema>;
type DeleteFormValues = InferFormValues<typeof deleteFormSchema>;

/**
 * Displays and manages a list of categories, providing functionality to create, edit, and delete categories with modal dialogs.
 *
 * Allows users to assign colors, icons, and parent categories, and handles reassignment or removal of related transactions when deleting a category.
 *
 * @param categories - The list of categories to display and manage.
 */
export default function Categories({ categories }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [isDeleteOpen, setIsDeleteOpen] = useState(false);
    const [editingCategory, setEditingCategory] = useState<Category | null>(null);
    const [deletingCategory, setDeletingCategory] = useState<Category | null>(null);
    const [icon, setIcon] = useState<string | null>(null);

    const defaultValues: FormValues = {
        name: '',
        description: '',
        color: '#6366F1',
        icon: 'ShoppingBag',
        parent_category_id: '0',
    };

    const defaultDeleteValues: DeleteFormValues = {
        replacement_action: 'remove',
        replacement_category_id: null,
    };

    const openCreateModal = () => {
        setEditingCategory(null);
        setIcon(null);
        setIsOpen(true);
    };

    const openEditModal = (category: Category) => {
        setEditingCategory(category);
        setIcon(category.icon);
        setIsOpen(true);
    };

    const openDeleteModal = (category: Category) => {
        setDeletingCategory(category);
        setIsDeleteOpen(true);
    };

    const onSubmit = (values: FormValues) => {
        const formData = {
            ...values,
            icon: icon || values.icon,
            parent_category_id: values.parent_category_id === '0' ? null : values.parent_category_id,
        };

        if (editingCategory) {
            router.put(`/categories/${editingCategory.id}`, formData, {
                onSuccess: () => {
                    setIsOpen(false);
                    setEditingCategory(null);
                    setIcon(null);
                },
            });
        } else {
            router.post('/categories', formData, {
                onSuccess: () => {
                    setIsOpen(false);
                    setIcon(null);
                },
            });
        }
    };

    const onDeleteSubmit = (values: DeleteFormValues) => {
        if (!deletingCategory) return;

        router.delete(`/categories/${deletingCategory.id}`, {
            data: values,
            onSuccess: () => {
                setIsDeleteOpen(false);
                setDeletingCategory(null);
            },
        });
    };

    // Prepare parent category options for select input
    const parentCategoryOptions = categories
        .filter((c) => (editingCategory ? c.id !== editingCategory.id : true))
        .map((category) => ({
            value: category.id.toString(),
            label: category.name,
        }));

    // Add "None" option with a special value
    parentCategoryOptions.unshift({ value: '0', label: 'None' });

    // Prepare category options for replacement in delete modal
    const replacementCategoryOptions = categories
        .filter((c) => (deletingCategory ? c.id !== deletingCategory.id : true))
        .map((category) => ({
            value: category.id.toString(),
            label: category.name,
        }));

    return (
        <AppLayout>
            <Head title="Categories" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    <PageHeader
                        title="Categories"
                        buttons={[
                            {
                                onClick: () => openCreateModal(),
                                label: 'New Category',
                            },
                        ]}
                    />
                </div>
            </div>

            <DataTable
                columns={[
                    {
                        header: '',
                        key: 'color',
                        render: (row) => (
                            <div
                                className="m-auto flex h-12 w-12 items-center justify-center rounded-full p-2"
                                style={{ backgroundColor: row.color || '#6366F1' }}
                            >
                                <Icon iconNode={icons[row.icon || '']} className="h-8 w-8 text-white" />
                            </div>
                        ),
                    },
                    { header: 'Name', key: 'name' },
                    { header: 'Description', key: 'description' },
                    { header: 'Parent Category', key: 'parent_category', render: (row) => <span>{row.parent_category?.name || 'None'}</span> },
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
                data={categories}
                rowKey={(record) => record.id}
            />

            {/* Edit/Create Modal */}
            <Dialog open={isOpen} onOpenChange={setIsOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editingCategory ? 'Edit Category' : 'Add Category'}</DialogTitle>
                        <DialogDescription>
                            {editingCategory ? 'Update the category details below.' : 'Fill in the details to create a new category.'}
                        </DialogDescription>
                    </DialogHeader>
                    <SmartForm
                        schema={formSchema}
                        defaultValues={
                            editingCategory
                                ? {
                                      name: editingCategory.name,
                                      description: editingCategory.description || '',
                                      color: editingCategory.color || '#6366F1',
                                      icon: editingCategory.icon || 'ShoppingBag',
                                      parent_category_id: editingCategory.parent_category_id ? editingCategory.parent_category_id.toString() : '0',
                                  }
                                : defaultValues
                        }
                        onSubmit={onSubmit}
                        formProps={{
                            className: 'space-y-4',
                        }}
                    >
                        {() => (
                            <>
                                <TextInput<FormValues> name="name" label="Name" required />

                                <TextareaInput<FormValues> name="description" label="Description" />

                                <ColorInput<FormValues> name="color" label="Color" />

                                <div className="space-y-2">
                                    <label className="text-sm font-medium">Icon</label>
                                    <div onMouseDown={(e) => e.preventDefault()}>
                                        <IconPicker
                                            value={icon || 'ShoppingBag'}
                                            onChange={(value) => {
                                                setIcon(value);
                                                if (editingCategory) {
                                                    setEditingCategory({ ...editingCategory, icon: value });
                                                }
                                            }}
                                        />
                                    </div>
                                </div>

                                <SelectInput<FormValues> name="parent_category_id" label="Parent Category" options={parentCategoryOptions} />

                                <DialogFooter>
                                    <Button type="submit">{editingCategory ? 'Update' : 'Create'}</Button>
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
                        <DialogTitle>Delete Category</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete this category? This action cannot be undone. Please select what should happen to
                            transactions associated with this category.
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
                                            { value: 'remove', label: 'Remove category from transactions' },
                                            { value: 'replace', label: 'Replace with another category' },
                                        ]}
                                    />

                                    {replacementAction === 'replace' && (
                                        <SelectInput<DeleteFormValues>
                                            name="replacement_category_id"
                                            label="Replace with"
                                            options={replacementCategoryOptions}
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
