import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { cn } from '@/lib/utils';
import React from 'react';

export interface FormatConfigurationProps {
    detectedDateFormat?: string | null;
    detectedAmountFormat?: string | null;
    dateFormat: string;
    amountFormat: string;
    onDateFormatChange: (value: string) => void;
    onAmountFormatChange: (value: string) => void;
    sampleDateValues?: string[];
    sampleAmountValues?: string[];
    className?: string;
}

const dateFormatOptions = [
    { value: 'Y-m-d', label: 'YYYY-MM-DD' },
    { value: 'd.m.Y', label: 'DD.MM.YYYY' },
    { value: 'd/m/Y', label: 'DD/MM/YYYY' },
    { value: 'm/d/Y', label: 'MM/DD/YYYY' },
];

const amountFormatOptions = [
    { value: '1,234.56', label: '1,234.56 (US)' },
    { value: '1.234,56', label: '1.234,56 (EU)' },
    { value: '1234.56', label: '1234.56' },
];

export function FormatConfiguration({
    detectedDateFormat,
    detectedAmountFormat,
    dateFormat,
    amountFormat,
    onDateFormatChange,
    onAmountFormatChange,
    sampleDateValues = [],
    sampleAmountValues = [],
    className,
}: FormatConfigurationProps) {
    return (
        <div className={cn('space-y-4', className)}>
            <div className="space-y-2">
                <Label>Date format</Label>
                {detectedDateFormat && (
                    <p className="text-xs text-muted-foreground">Detected: {detectedDateFormat}</p>
                )}
                <Select value={dateFormat} onValueChange={onDateFormatChange}>
                    <SelectTrigger>
                        <SelectValue placeholder="Select date format" />
                    </SelectTrigger>
                    <SelectContent>
                        {dateFormatOptions.map((opt) => (
                            <SelectItem key={opt.value} value={opt.value}>
                                {opt.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {sampleDateValues.length > 0 && (
                    <p className="text-xs text-muted-foreground">Sample: {sampleDateValues.slice(0, 3).join(', ')}</p>
                )}
            </div>
            <div className="space-y-2">
                <Label>Amount format</Label>
                {detectedAmountFormat && (
                    <p className="text-xs text-muted-foreground">Detected: {detectedAmountFormat}</p>
                )}
                <Select value={amountFormat} onValueChange={onAmountFormatChange}>
                    <SelectTrigger>
                        <SelectValue placeholder="Select amount format" />
                    </SelectTrigger>
                    <SelectContent>
                        {amountFormatOptions.map((opt) => (
                            <SelectItem key={opt.value} value={opt.value}>
                                {opt.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {sampleAmountValues.length > 0 && (
                    <p className="text-xs text-muted-foreground">Sample: {sampleAmountValues.slice(0, 3).join(', ')}</p>
                )}
            </div>
        </div>
    );
}
