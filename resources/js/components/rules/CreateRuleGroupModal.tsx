import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { TextInput } from '@/components/ui/form-inputs';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import { Switch } from '@/components/ui/switch';
import { useRulesApi } from '@/hooks/use-rules-api';
import { useEffect } from 'react';
import { toast } from 'react-toastify';
import { z } from 'zod';

interface CreateRuleGroupModalProps {
    isOpen: boolean;
    onClose: () => void;
    onSuccess: () => void;
}

const formSchema = z.object({
    name: z.string().min(1, { message: 'Name is required' }).max(255, { message: 'Name must be less than 255 characters' }),
    description: z.string().optional(),
    is_active: z.boolean(),
});

type FormValues = InferFormValues<typeof formSchema>;

export function CreateRuleGroupModal({ isOpen, onClose, onSuccess }: CreateRuleGroupModalProps) {
    const { createRuleGroup, loading, error, clearError } = useRulesApi();

    // Handle errors with toast
    useEffect(() => {
        if (error) {
            toast.error(error);
            clearError();
        }
    }, [error, clearError]);

    const defaultValues: FormValues = {
        name: '',
        description: '',
        is_active: true,
    };

    const handleSubmit = async (values: FormValues) => {
        const success = await createRuleGroup({
            name: values.name,
            description: values.description || undefined,
            is_active: values.is_active,
        });

        if (success) {
            onSuccess();
            onClose();
        }
    };

    const handleClose = () => {
        clearError();
        onClose();
    };

    return (
        <Dialog open={isOpen} onOpenChange={handleClose}>
            <DialogContent className="max-w-md">
                <DialogHeader>
                    <DialogTitle>Create Rule Group</DialogTitle>
                    <DialogDescription>Create a new rule group to organize your transaction rules.</DialogDescription>
                </DialogHeader>

                <SmartForm schema={formSchema} defaultValues={defaultValues} onSubmit={handleSubmit} formProps={{ className: 'space-y-4' }}>
                    {({ watch, setValue }) => (
                        <>
                            <TextInput<FormValues> name="name" label="Group Name" placeholder="Enter group name" required />

                            <TextInput<FormValues> name="description" label="Description" placeholder="Enter group description (optional)" />

                            <div className="flex items-center justify-between">
                                <div>
                                    <label className="text-sm font-medium">Active</label>
                                    <p className="text-muted-foreground text-xs">Active groups can have their rules executed</p>
                                </div>
                                <Switch checked={watch('is_active')} onCheckedChange={(checked) => setValue('is_active', checked)} />
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={handleClose}>
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={loading}>
                                    {loading ? 'Creating...' : 'Create Group'}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </SmartForm>
            </DialogContent>
        </Dialog>
    );
}
