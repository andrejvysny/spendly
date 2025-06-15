import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from './dialog';
import { SmartForm, InferFormValues } from './smart-form';
import { Button } from './button';
import { z } from 'zod';
import { ReactNode } from 'react';

interface FormModalProps<T extends z.ZodTypeAny> {
    isOpen: boolean;
    onClose: () => void;
    title: string;
    description?: string;
    schema: T;
    defaultValues: InferFormValues<T>;
    onSubmit: (values: InferFormValues<T>) => void;
    submitLabel?: string;
    children: () => ReactNode;
}

export function FormModal<T extends z.ZodTypeAny>({ isOpen, onClose, title, description, schema, defaultValues, onSubmit, submitLabel = 'Submit', children }: FormModalProps<T>) {
    const handleSubmit = (values: InferFormValues<T>) => {
        onSubmit(values);
        onClose();
    };

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    {description && <DialogDescription>{description}</DialogDescription>}
                </DialogHeader>
                <SmartForm schema={schema} defaultValues={defaultValues} onSubmit={handleSubmit} formProps={{ className: 'space-y-4' }}>
                    {() => (
                        <>
                            {children()}
                            <DialogFooter>
                                <Button type="submit">{submitLabel}</Button>
                            </DialogFooter>
                        </>
                    )}
                </SmartForm>
            </DialogContent>
        </Dialog>
    );
}
