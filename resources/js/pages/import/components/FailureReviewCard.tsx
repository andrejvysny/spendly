import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { SelectInput, TextInput } from '@/components/ui/form-inputs';
import { ImportFailure } from '@/types/index';
import { zodResolver } from '@hookform/resolvers/zod';
import { AlertTriangle, CheckCircle, Copy, Eye, Info, XCircle } from 'lucide-react';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { z } from 'zod';

const transactionSchema = z.object({
    transaction_id: z.string().min(1, { message: 'Transaction ID is required' }),
    amount: z.coerce.number().min(0.01, { message: 'Amount must be greater than 0' }),
    currency: z.string().min(1, { message: 'Currency is required' }),
    booked_date: z.string().min(1, { message: 'Booked date is required' }),
    processed_date: z.string().min(1, { message: 'Processed date is required' }),
    description: z.string().min(1, { message: 'Description is required' }),
    target_iban: z.string().nullable(),
    source_iban: z.string().nullable(),
    partner: z.string().min(1, { message: 'Partner is required' }),
    type: z.enum(['TRANSFER', 'DEPOSIT', 'WITHDRAWAL', 'PAYMENT'], {
        required_error: 'Type is required',
    }),
    account_id: z.number(),
});

type FormValues = z.infer<typeof transactionSchema>;

const transactionTypes = [
    { value: 'TRANSFER', label: 'Transfer' },
    { value: 'DEPOSIT', label: 'Deposit' },
    { value: 'WITHDRAWAL', label: 'Withdrawal' },
    { value: 'PAYMENT', label: 'Payment' },
];

const currencies = [
    { value: 'EUR', label: 'Euro (€)' },
    { value: 'USD', label: 'US Dollar ($)' },
    { value: 'GBP', label: 'British Pound (£)' },
];

interface FieldMapping {
    confidence: number;
    suggestedValue: any;
    originalValue: string;
    fieldName: string;
}

interface FailureReviewCardProps {
    failure: ImportFailure;
    onTransactionSubmit: (values: FormValues) => Promise<void>;
    onMarkAsReviewed: (notes?: string) => Promise<void>;
    onMarkAsIgnored: (notes?: string) => Promise<void>;
    onNext: () => void;
    currentIndex: number;
    totalCount: number;
    isSubmitting?: boolean;
}

// Enhanced field mapping service
class FieldMappingService {
    private static fieldPatterns = {
        amount: /^(amount|betrag|sum|total|wert|saldo)$/i,
        partner: /^(partner|empf[aä]nger|sender|name|company|auftraggeber)$/i,
        date: /^(date|datum|booking|gebucht|valuta|buchungstag)$/i,
        description: /^(description|verwendung|zweck|memo|reference|beschreibung)$/i,
        target_iban: /^(target.*iban|empf[aä]nger.*iban|ziel.*iban)$/i,
        source_iban: /^(source.*iban|sender.*iban|auftraggeber.*iban)$/i,
        currency: /^(currency|w[aä]hrung|curr)$/i,
        transaction_id: /^(transaction.*id|trans.*id|id|referenz)$/i,
    };

    static mapFields(failure: ImportFailure, importData: any): FormValues {
        const { raw_data, metadata, parsed_data } = failure;
        const headers = metadata.headers || [];

        const mappings = new Map<string, FieldMapping>();

        headers.forEach((header, index) => {
            const value = raw_data[index];
            if (!value && value !== 0) return;

            Object.entries(this.fieldPatterns).forEach(([fieldName, pattern]) => {
                if (pattern.test(header)) {
                    const confidence = this.calculateConfidence(header, fieldName, value);
                    if (!mappings.has(fieldName) || mappings.get(fieldName)!.confidence < confidence) {
                        mappings.set(fieldName, {
                            confidence,
                            suggestedValue: this.transformValue(fieldName, value),
                            originalValue: value,
                            fieldName: header,
                        });
                    }
                }
            });
        });

        return {
            transaction_id: parsed_data?.transaction_id || this.getBestMapping('transaction_id', mappings)?.suggestedValue || `TRX-${Date.now()}`,
            amount: Math.abs(parsed_data?.amount || this.getBestMapping('amount', mappings)?.suggestedValue || 0),
            currency: parsed_data?.currency || this.getBestMapping('currency', mappings)?.suggestedValue || importData?.currency || 'EUR',
            booked_date: parsed_data?.booked_date || this.getBestMapping('date', mappings)?.suggestedValue || new Date().toISOString().split('T')[0],
            processed_date:
                parsed_data?.processed_date || this.getBestMapping('date', mappings)?.suggestedValue || new Date().toISOString().split('T')[0],
            description: parsed_data?.description || this.getBestMapping('description', mappings)?.suggestedValue || failure.error_message,
            target_iban: parsed_data?.target_iban || this.getBestMapping('target_iban', mappings)?.suggestedValue || null,
            source_iban: parsed_data?.source_iban || this.getBestMapping('source_iban', mappings)?.suggestedValue || null,
            partner: parsed_data?.partner || this.getBestMapping('partner', mappings)?.suggestedValue || '',
            type: 'PAYMENT' as const,
            account_id: parsed_data?.account_id || 1,
        };
    }

    private static getBestMapping(fieldName: string, mappings: Map<string, FieldMapping>): FieldMapping | undefined {
        return mappings.get(fieldName);
    }

    private static calculateConfidence(header: string, fieldName: string, value: any): number {
        let confidence = 0.5;

        if (this.fieldPatterns[fieldName as keyof typeof this.fieldPatterns]?.test(header)) {
            confidence += 0.3;
        }

        // Value format validation
        if (fieldName === 'amount' && !isNaN(parseFloat(value))) confidence += 0.2;
        if (fieldName === 'date' && this.isValidDate(value)) confidence += 0.2;
        if (fieldName === 'currency' && /^[A-Z]{3}$/.test(value)) confidence += 0.2;

        return Math.min(confidence, 1.0);
    }

    private static transformValue(fieldName: string, value: any): any {
        switch (fieldName) {
            case 'amount': {
                const numericValue = parseFloat(
                    value
                        .toString()
                        .replace(/[^\d.,-]/g, '')
                        .replace(',', '.'),
                );
                return isNaN(numericValue) ? 0 : Math.abs(numericValue);
            }
            case 'date':
                return this.parseDate(value);
            case 'currency':
                return value.toString().toUpperCase();
            default:
                return value.toString().trim();
        }
    }

    private static isValidDate(dateString: any): boolean {
        const date = new Date(dateString);
        return date instanceof Date && !isNaN(date.getTime());
    }

    private static parseDate(dateString: any): string {
        const date = new Date(dateString);
        if (this.isValidDate(dateString)) {
            return date.toISOString().split('T')[0];
        }
        return new Date().toISOString().split('T')[0];
    }
}

// Raw Data Viewer Component

// Error Details Panel Component
function ErrorDetailsPanel({ failure }: { failure: ImportFailure }) {
    const getErrorTypeIcon = (errorType: string) => {
        switch (errorType) {
            case 'validation_failed':
                return <AlertTriangle className="h-4 w-4 text-red-500" />;
            case 'duplicate':
                return <Copy className="h-4 w-4 text-yellow-500" />;
            case 'processing_error':
                return <XCircle className="h-4 w-4 text-orange-500" />;
            case 'parsing_error':
                return <Info className="h-4 w-4 text-purple-500" />;
            default:
                return <AlertTriangle className="h-4 w-4 text-gray-500" />;
        }
    };

    const getSuggestions = (failure: ImportFailure): string[] => {
        const suggestions: string[] = [];

        switch (failure.error_type) {
            case 'validation_failed':
                suggestions.push('Check required fields are filled');
                suggestions.push('Verify date and amount formats');
                suggestions.push('Ensure partner information is complete');
                break;
            case 'duplicate':
                suggestions.push('Review if this is a legitimate duplicate');
                suggestions.push('Check transaction ID and amount');
                suggestions.push('Consider marking as ignored if acceptable');
                break;
            case 'processing_error':
                suggestions.push('Check data format consistency');
                suggestions.push('Verify field mappings are correct');
                break;
            case 'parsing_error':
                suggestions.push('Check CSV delimiter and encoding');
                suggestions.push('Verify field structure matches headers');
                break;
        }

        return suggestions;
    };

    return (
        <div className="space-y-4">
            <div className="mb-3 flex items-center space-x-2">
                {getErrorTypeIcon(failure.error_type)}
                <Badge variant="outline" className="capitalize">
                    {failure.error_type.replace('_', ' ')}
                </Badge>
            </div>

            <Alert variant="destructive">
                <AlertDescription>
                    <strong>Error:</strong> {failure.error_message}
                </AlertDescription>
            </Alert>

            {failure.error_details?.errors && failure.error_details.errors.length > 0 && (
                <div>
                    <h4 className="mb-2 text-sm font-medium">Detailed Errors:</h4>
                    <ul className="space-y-1 text-sm">
                        {failure.error_details.errors.map((error, index) => (
                            <li key={index} className="text-red-600">
                                • {error}
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            <div>
                <h4 className="mb-2 text-sm font-medium">Suggestions:</h4>
                <ul className="space-y-1 text-sm">
                    {getSuggestions(failure).map((suggestion, index) => (
                        <li key={index} className="text-gray-600">
                            • {suggestion}
                        </li>
                    ))}
                </ul>
            </div>

            {failure.error_details?.duplicate_fingerprint && (
                <div>
                    <h4 className="mb-1 text-sm font-medium">Duplicate Info:</h4>
                    <p className="font-mono text-xs text-gray-500">{failure.error_details.duplicate_fingerprint}</p>
                </div>
            )}
        </div>
    );
}

export function FailureReviewCard({
    failure,
    onTransactionSubmit,
    onMarkAsReviewed,
    onMarkAsIgnored,
    onNext,
    currentIndex,
    totalCount,
    isSubmitting = false,
}: FailureReviewCardProps) {
    const [reviewNotes, setReviewNotes] = useState('');

    // Get enhanced default values
    const defaultValues = FieldMappingService.mapFields(failure, {});

    const form = useForm<FormValues>({
        resolver: zodResolver(transactionSchema),
        defaultValues,
    });

    // Identify highlighted fields (those that were mapped with confidence)
    const getHighlightedFields = (): Set<string> => {
        const highlighted = new Set<string>();
        const headers = failure.metadata.headers || [];

        headers.forEach((header) => {
            Object.values(FieldMappingService['fieldPatterns']).forEach((pattern) => {
                if (pattern.test(header)) {
                    highlighted.add(header.toLowerCase());
                }
            });
        });

        return highlighted;
    };

    const handleSubmit = async (values: FormValues) => {
        try {
            await onTransactionSubmit(values);
        } catch (error) {
            console.error('Transaction submission failed:', error);
        }
    };

    const handleQuickFill = (fieldName: keyof FormValues, value: any) => {
        form.setValue(fieldName, value);
    };

    return (
        <div className="space-y-4">
            {/* Progress Header */}
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-xl font-semibold">Review Import Failure</h2>
                    <p className="text-sm text-gray-600">
                        Row {failure.row_number} • {currentIndex + 1} of {totalCount} pending failures
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" onClick={() => onMarkAsReviewed(reviewNotes)} disabled={isSubmitting}>
                        <Eye className="mr-2 h-4 w-4" />
                        Mark as Reviewed
                    </Button>
                    <Button variant="outline" onClick={() => onMarkAsIgnored(reviewNotes)} disabled={isSubmitting}>
                        <XCircle className="mr-2 h-4 w-4" />
                        Ignore
                    </Button>
                </div>
            </div>

            {/* Three Column Layout */}
            <div className="grid min-h-[600px] grid-cols-12 gap-6">
                {/* Left: Raw Data Panel */}
                <Card className="col-span-4">
                    <CardHeader>
                        <CardTitle className="text-base">Raw CSV Data</CardTitle>
                        <p className="text-sm text-gray-600">Fields highlighted in blue were auto-mapped</p>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <RawDataViewer
                            headers={failure.metadata.headers || []}
                            data={failure.raw_data || []}
                            highlightedFields={getHighlightedFields()}
                        />
                    </CardContent>
                </Card>

                {/* Center: Transaction Form */}
                <Card className="col-span-5">
                    <CardHeader>
                        <CardTitle className="text-base">Create Transaction</CardTitle>
                        <p className="text-sm text-gray-600">Review and adjust the transaction details below</p>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={form.handleSubmit(handleSubmit)} className="space-y-4">
                            <div className="grid grid-cols-2 gap-4">
                                <TextInput label="Partner" {...form.register('partner')} error={form.formState.errors.partner?.message} required />
                                <TextInput
                                    label="Amount"
                                    type="number"
                                    step="0.01"
                                    {...form.register('amount')}
                                    error={form.formState.errors.amount?.message}
                                    required
                                />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <SelectInput label="Currency" {...form.register('currency')} error={form.formState.errors.currency?.message} required>
                                    {currencies.map((currency) => (
                                        <option key={currency.value} value={currency.value}>
                                            {currency.label}
                                        </option>
                                    ))}
                                </SelectInput>
                                <SelectInput label="Type" {...form.register('type')} error={form.formState.errors.type?.message} required>
                                    {transactionTypes.map((type) => (
                                        <option key={type.value} value={type.value}>
                                            {type.label}
                                        </option>
                                    ))}
                                </SelectInput>
                            </div>

                            <TextInput
                                label="Description"
                                {...form.register('description')}
                                error={form.formState.errors.description?.message}
                                required
                            />

                            <div className="grid grid-cols-2 gap-4">
                                <TextInput label="Target IBAN" {...form.register('target_iban')} error={form.formState.errors.target_iban?.message} />
                                <TextInput label="Source IBAN" {...form.register('source_iban')} error={form.formState.errors.source_iban?.message} />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <TextInput
                                    label="Booked Date"
                                    type="date"
                                    {...form.register('booked_date')}
                                    error={form.formState.errors.booked_date?.message}
                                    required
                                />
                                <TextInput
                                    label="Processed Date"
                                    type="date"
                                    {...form.register('processed_date')}
                                    error={form.formState.errors.processed_date?.message}
                                    required
                                />
                            </div>

                            <TextInput
                                label="Transaction ID"
                                {...form.register('transaction_id')}
                                error={form.formState.errors.transaction_id?.message}
                                required
                            />

                            <div className="flex gap-2 pt-4">
                                <Button type="submit" className="flex-1 bg-green-600 hover:bg-green-700" disabled={isSubmitting}>
                                    <CheckCircle className="mr-2 h-4 w-4" />
                                    {isSubmitting ? 'Creating...' : 'Create Transaction & Next'}
                                </Button>
                            </div>
                        </form>

                        {/* Review Notes */}
                        <div className="mt-4 border-t pt-4">
                            <TextInput
                                label="Review Notes (optional)"
                                value={reviewNotes}
                                onChange={(e) => setReviewNotes(e.target.value)}
                                placeholder="Add notes about this failure..."
                            />
                        </div>
                    </CardContent>
                </Card>

                {/* Right: Error Details Panel */}
                <Card className="col-span-3">
                    <CardHeader>
                        <CardTitle className="text-base">Error Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ErrorDetailsPanel failure={failure} />
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
