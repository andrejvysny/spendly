import { cn } from '@/lib/utils';
import * as React from 'react';
import { useFormContext } from 'react-hook-form';
import { DateRangePicker } from '../date-range-picker';

export interface DateRangeInputProps extends React.InputHTMLAttributes<HTMLDivElement> {
    name: string;
    label?: string;
    description?: string;
    placeholder?: string;
}

export function DateRangeInput({ name, label, description, placeholder, className, ...props }: DateRangeInputProps) {
    const { formState, setValue } = useFormContext();
    const error = formState.errors[name];

    const handleChange = (value: { from: string; to: string }) => {
        setValue(name, value, { shouldDirty: true, shouldTouch: true });
    };

    return (
        <div className={cn('space-y-1', className)} {...props}>
            <DateRangePicker name={name} label={label} placeholder={placeholder} onChange={handleChange} />
            {description && <p className="text-muted-foreground text-xs">{description}</p>}
            {error && <p className="text-destructive text-xs">{error.message?.toString()}</p>}
        </div>
    );
}
