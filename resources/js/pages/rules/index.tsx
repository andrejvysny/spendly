import { DataTable } from '@/components/DataTable';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { SelectInput, TextInput } from '@/components/ui/form-inputs';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { z } from 'zod';

interface Rule {
    id: number;
    name: string;
    condition_type: string;
    condition_operator: string;
    condition_value: string;
    action_type: string;
    action_value: string;
    is_active: boolean;
}

interface Props {
    rules: Rule[];
}

const conditionTypes = [
    { value: 'amount', label: 'Amount' },
    { value: 'iban', label: 'IBAN' },
    { value: 'description', label: 'Description' },
];

const conditionOperators = {
    amount: [
        { value: 'greater_than', label: 'Greater Than' },
        { value: 'less_than', label: 'Less Than' },
        { value: 'equals', label: 'Equals' },
    ],
    iban: [
        { value: 'contains', label: 'Contains' },
        { value: 'equals', label: 'Equals' },
    ],
    description: [
        { value: 'contains', label: 'Contains' },
        { value: 'equals', label: 'Equals' },
    ],
};

const actionTypes = [
    { value: 'add_tag', label: 'Add Tag' },
    { value: 'set_category', label: 'Set Category' },
    { value: 'set_type', label: 'Set Type' },
];

const formSchema = z.object({
    name: z.string().min(1, { message: 'Name is required' }),
    condition_type: z.string(),
    condition_operator: z.string(),
    condition_value: z.string(),
    action_type: z.string(),
    action_value: z.string(),
    is_active: z.boolean(),
});

type FormValues = InferFormValues<typeof formSchema>;

export default function Rules({ rules }: Props) {
    const [isOpen, setIsOpen] = useState(false);
    const [editingRule, setEditingRule] = useState<Rule | null>(null);

    const defaultValues: FormValues = {
        name: '',
        condition_type: 'amount',
        condition_operator: 'greater_than',
        condition_value: '',
        action_type: 'add_tag',
        action_value: '',
        is_active: true,
    };

    const openCreateModal = () => {
        setEditingRule(null);
        setIsOpen(true);
    };

    const openEditModal = (rule: Rule) => {
        setEditingRule(rule);
        setIsOpen(true);
    };

    const onSubmit = (values: FormValues) => {
        if (editingRule) {
            router.put(`/rules/${editingRule.id}`, values, {
                onSuccess: () => {
                    setIsOpen(false);
                },
            });
        } else {
            router.post('/rules', values, {
                onSuccess: () => {
                    setIsOpen(false);
                },
            });
        }
    };

    const deleteRule = (id: number) => {
        if (confirm('Are you sure you want to delete this rule?')) {
            router.delete(`/rules/${id}`);
        }
    };

    return (
        <AppLayout>
            <Head title="Rules" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    <PageHeader
                        title="Rules"
                        buttons={[
                            {
                                onClick: () => openCreateModal(),
                                label: 'New Rule',
                            },
                        ]}
                    />
                </div>
            </div>

            <DataTable
                columns={[
                    { header: 'Name', key: 'name' },
                    {
                        header: 'Condition',
                        key: 'condition',
                        render: (row) => (
                            <span>
                                If {row.condition_type} {row.condition_operator} {row.condition_value}
                            </span>
                        ),
                    },
                    {
                        header: 'Action',
                        key: 'action',
                        render: (row) => (
                            <span>
                                Then {row.action_type} {row.action_value}
                            </span>
                        ),
                    },
                    {
                        header: 'Status',
                        key: 'status',
                        render: (row) => (
                            <span
                                className={`rounded-full px-2 py-1 text-xs ${row.is_active ? 'bg-green-900 text-green-300' : 'bg-gray-700 text-gray-300'}`}
                            >
                                {row.is_active ? 'Active' : 'Inactive'}
                            </span>
                        ),
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
                                <Button variant="destructive" size="sm" onClick={() => deleteRule(row.id)}>
                                    Delete
                                </Button>
                            </div>
                        ),
                    },
                ]}
                data={rules}
                rowKey={(record) => record.id}
            />

            <Dialog open={isOpen} onOpenChange={setIsOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editingRule ? 'Edit Rule' : 'Add Rule'}</DialogTitle>
                        <DialogDescription>
                            {editingRule ? 'Update the rule details below.' : 'Fill in the details to create a new rule.'}
                        </DialogDescription>
                    </DialogHeader>
                    <SmartForm
                        schema={formSchema}
                        defaultValues={
                            editingRule
                                ? {
                                      name: editingRule.name,
                                      condition_type: editingRule.condition_type,
                                      condition_operator: editingRule.condition_operator,
                                      condition_value: editingRule.condition_value,
                                      action_type: editingRule.action_type,
                                      action_value: editingRule.action_value,
                                      is_active: editingRule.is_active,
                                  }
                                : defaultValues
                        }
                        onSubmit={onSubmit}
                        formProps={{ className: 'space-y-4' }}
                    >
                        {({ watch, setValue }) => {
                            const conditionType = watch('condition_type');
                            return (
                                <>
                                    <TextInput<FormValues> name="name" label="Name" required />

                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                                        <SelectInput<FormValues> name="condition_type" label="Condition Type" options={conditionTypes} />

                                        <SelectInput<FormValues>
                                            name="condition_operator"
                                            label="Operator"
                                            options={conditionOperators[conditionType as keyof typeof conditionOperators]}
                                        />

                                        <TextInput<FormValues> name="condition_value" label="Value" required />
                                    </div>

                                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <SelectInput<FormValues> name="action_type" label="Action Type" options={actionTypes} />

                                        <TextInput<FormValues> name="action_value" label="Action Value" required />
                                    </div>

                                    <div className="flex items-center space-x-2">
                                        <Switch
                                            id="is_active"
                                            checked={watch('is_active')}
                                            onCheckedChange={(checked) => setValue('is_active', checked)}
                                        />
                                        <label
                                            htmlFor="is_active"
                                            className="text-sm leading-none font-medium peer-disabled:cursor-not-allowed peer-disabled:opacity-70"
                                        >
                                            Active
                                        </label>
                                    </div>

                                    <DialogFooter>
                                        <Button type="submit">{editingRule ? 'Update' : 'Create'}</Button>
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
