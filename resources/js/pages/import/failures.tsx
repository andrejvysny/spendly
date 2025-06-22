import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SelectInput, TextInput } from '@/components/ui/form-inputs';
import { FormModal } from '@/components/ui/form-modal';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { InferFormValues } from '@/components/ui/smart-form';
import { Alert, AlertDescription } from '@/components/ui/alert';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { BreadcrumbItem, ImportFailure, Import } from '@/types/index';
import { formatDate } from '@/utils/date';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import {
    AlertCircle,
    AlertTriangle,
    CheckCircle,
    ChevronDown,
    ChevronRight,
    Download,
    FileX,
    MoreHorizontal,
    Plus,
    Search,
    ArrowLeft,
    Eye,
    XCircle,
    Copy,
    Info,
    Loader
} from 'lucide-react';
import { useState, useEffect } from 'react';
import { toast } from 'react-toastify';
import { z } from 'zod';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';

interface Props {
    import: Import;
    failures: {
        data: ImportFailure[];
        meta: {
            current_page: number;
            last_page: number;
            per_page: number;
            total: number;
        };
    };
    stats: {
        total: number;
        pending: number;
        reviewed: number;
        by_type: Record<string, number>;
    };
}

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

type FormValues = InferFormValues<typeof transactionSchema>;

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

        const mappings = new Map();

        headers.forEach((header, index) => {
            const value = raw_data[index];
            if (!value && value !== 0) return;

            Object.entries(this.fieldPatterns).forEach(([fieldName, pattern]) => {
                if (pattern.test(header)) {
                    const confidence = this.calculateConfidence(header, fieldName, value);
                    if (!mappings.has(fieldName) || mappings.get(fieldName).confidence < confidence) {
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
            transaction_id: parsed_data?.transaction_id || mappings.get('transaction_id')?.suggestedValue || `TRX-${Date.now()}`,
            amount: Math.abs(parsed_data?.amount || mappings.get('amount')?.suggestedValue || 0),
            currency: parsed_data?.currency || mappings.get('currency')?.suggestedValue || importData?.currency || 'EUR',
            booked_date: parsed_data?.booked_date || mappings.get('date')?.suggestedValue || new Date().toISOString().split('T')[0],
            processed_date: parsed_data?.processed_date || mappings.get('date')?.suggestedValue || new Date().toISOString().split('T')[0],
            description: parsed_data?.description || mappings.get('description')?.suggestedValue || failure.error_message,
            target_iban: parsed_data?.target_iban || mappings.get('target_iban')?.suggestedValue || null,
            source_iban: parsed_data?.source_iban || mappings.get('source_iban')?.suggestedValue || null,
            partner: parsed_data?.partner || mappings.get('partner')?.suggestedValue || '',
            type: 'PAYMENT' as const,
            account_id: parsed_data?.account_id || 1,
        };
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
            case 'amount':
                const numericValue = parseFloat(value.toString().replace(/[^\d.,-]/g, '').replace(',', '.'));
                return isNaN(numericValue) ? 0 : Math.abs(numericValue);
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

    static getHighlightedFields(failure: ImportFailure): Set<string> {
        const highlighted = new Set<string>();
        const headers = failure.metadata.headers || [];

        headers.forEach(header => {
            Object.values(this.fieldPatterns).forEach(pattern => {
                if (pattern.test(header)) {
                    highlighted.add(header.toLowerCase());
                }
            });
        });

        return highlighted;
    }
}

// Raw Data Viewer Component
function RawDataViewer({ headers, data, highlightedFields }: {
    headers: string[],
    data: any[],
    highlightedFields: Set<string>
}) {
    const copyToClipboard = (text: string) => {
        navigator.clipboard.writeText(text);
        toast.success('Copied to clipboard');
    };

    return (
        <div className="space-y-2">
            {headers.map((header, index) => {
                const value = data[index];
                const isHighlighted = highlightedFields.has(header.toLowerCase());

                return (
                    <div
                        key={index}
                        className={`p-2 rounded border ${isHighlighted ? 'bg-blue-50 border-blue-200' : 'bg-gray-50'}`}
                    >
                        <div className="flex justify-between items-start">
                            <div className="flex-1">
                                <div className="text-xs font-medium text-gray-600 mb-1">
                                    {header}
                                    {isHighlighted && <span className="ml-1 text-blue-600">●</span>}
                                </div>
                                <div className="text-sm text-gray-900 break-all">
                                    {value || '-'}
                                </div>
                            </div>
                            {value && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => copyToClipboard(value.toString())}
                                    className="h-6 w-6 p-0"
                                >
                                    <Copy className="h-3 w-3" />
                                </Button>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

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
            <div className="flex items-center space-x-2 mb-3">
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
                    <h4 className="text-sm font-medium mb-2">Detailed Errors:</h4>
                    <ul className="text-sm space-y-1">
                        {failure.error_details.errors.map((error, index) => (
                            <li key={index} className="text-red-600">• {error}</li>
                        ))}
                    </ul>
                </div>
            )}

            <div>
                <h4 className="text-sm font-medium mb-2">Suggestions:</h4>
                <ul className="text-sm space-y-1">
                    {getSuggestions(failure).map((suggestion, index) => (
                        <li key={index} className="text-gray-600">• {suggestion}</li>
                    ))}
                </ul>
            </div>

            {failure.error_details?.duplicate_fingerprint && (
                <div>
                    <h4 className="text-sm font-medium mb-1">Duplicate Info:</h4>
                    <p className="text-xs text-gray-500 font-mono">
                        {failure.error_details.duplicate_fingerprint}
                    </p>
                </div>
            )}
        </div>
    );
}

export default function ImportFailures({ import: importData, failures: initialFailures, stats: initialStats }: Props) {
    const [failures, setFailures] = useState(initialFailures);
    const [stats, setStats] = useState(initialStats);
    const [selectedFailures, setSelectedFailures] = useState<number[]>([]);
    const [expandedFailure, setExpandedFailure] = useState<number | null>(null);
    const [filters, setFilters] = useState({
        error_type: '',
        status: '',
        search: '',
    });
    const [isCreateTransactionModalOpen, setIsCreateTransactionModalOpen] = useState(false);
    const [selectedFailureForTransaction, setSelectedFailureForTransaction] = useState<ImportFailure | null>(null);
    const [isMarkingReviewed, setIsMarkingReviewed] = useState(false);

    // Enhanced review mode state
    const [reviewMode, setReviewMode] = useState<'list' | 'review'>('list');
    const [currentReviewIndex, setCurrentReviewIndex] = useState(0);
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Get pending failures for review mode
    const pendingFailures = failures.data.filter(f => f.status === 'pending');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Imports', href: '/imports' },
        { title: importData.original_filename, href: `/imports/${importData.id}` },
        { title: 'Review Failures', href: `/imports/${importData.id}/failures` },
    ];

    const getErrorTypeBadge = (errorType: string) => {
        const badges = {
            validation_failed: <Badge variant="destructive" className="bg-red-100 text-red-800">Validation Failed</Badge>,
            duplicate: <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">Duplicate</Badge>,
            processing_error: <Badge variant="destructive" className="bg-orange-100 text-orange-800">Processing Error</Badge>,
            parsing_error: <Badge variant="destructive" className="bg-purple-100 text-purple-800">Parsing Error</Badge>,
        };
        return badges[errorType as keyof typeof badges] || <Badge variant="outline">{errorType}</Badge>;
    };

    const getStatusBadge = (status: string) => {
        const badges = {
            pending: <Badge variant="secondary" className="bg-gray-100 text-gray-800">Pending</Badge>,
            reviewed: <Badge variant="outline" className="bg-blue-100 text-blue-800">Reviewed</Badge>,
            resolved: <Badge variant="default" className="bg-green-100 text-green-800">Resolved</Badge>,
            ignored: <Badge variant="outline" className="bg-gray-100 text-gray-600">Ignored</Badge>,
        };
        return badges[status as keyof typeof badges] || <Badge variant="outline">{status}</Badge>;
    };

    const loadFailures = async () => {
        try {
            const params = new URLSearchParams();
            if (filters.error_type) params.append('error_type', filters.error_type);
            if (filters.status) params.append('status', filters.status);
            if (filters.search) params.append('search', filters.search);

            const response = await axios.get(`/api/imports/${importData.id}/failures?${params.toString()}`);
            setFailures(response.data.failures);
            setStats(response.data.stats);
        } catch (error) {
            toast.error('Failed to load failures');
        }
    };

    useEffect(() => {
        const debounceTimer = setTimeout(() => {
            loadFailures();
        }, 300);

        return () => clearTimeout(debounceTimer);
    }, [filters]);

    const handleSelectFailure = (failureId: number, checked: boolean) => {
        if (checked) {
            setSelectedFailures([...selectedFailures, failureId]);
        } else {
            setSelectedFailures(selectedFailures.filter(id => id !== failureId));
        }
    };

    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedFailures(failures.data.map(f => f.id));
        } else {
            setSelectedFailures([]);
        }
    };

    const handleBulkAction = async (action: 'reviewed' | 'resolved' | 'ignored', notes?: string) => {
        if (selectedFailures.length === 0) {
            toast.error('Please select failures to update');
            return;
        }

        setIsMarkingReviewed(true);
        try {
            await axios.patch(`/api/imports/${importData.id}/failures/bulk`, {
                failure_ids: selectedFailures,
                action,
                notes,
            });

            toast.success(`Marked ${selectedFailures.length} failures as ${action}`);
            setSelectedFailures([]);
            await loadFailures();
        } catch (error) {
            toast.error(`Failed to mark failures as ${action}`);
        } finally {
            setIsMarkingReviewed(false);
        }
    };

    const handleCreateTransaction = (failure: ImportFailure) => {
        setSelectedFailureForTransaction(failure);
        setIsCreateTransactionModalOpen(true);
    };

    const getTransactionDefaultValues = (failure: ImportFailure): FormValues => {
        const headers = failure.metadata.headers || [];
        const rawData = failure.raw_data || [];
        const parsedData = failure.parsed_data || {};

        const dataMap: Record<string, any> = {};
        headers.forEach((header, index) => {
            if (rawData[index] !== undefined) {
                dataMap[header.toLowerCase()] = rawData[index];
            }
        });

        return {
            transaction_id: parsedData.transaction_id || `TRX-${Date.now()}`,
            amount: Math.abs(parsedData.amount || parseFloat(dataMap.amount || dataMap.betrag || '0') || 0),
            currency: parsedData.currency || importData.currency || 'EUR',
            booked_date: parsedData.booked_date || dataMap.date || dataMap.datum || new Date().toISOString().split('T')[0],
            processed_date: parsedData.processed_date || dataMap.date || dataMap.datum || new Date().toISOString().split('T')[0],
            description: parsedData.description || dataMap.description || dataMap.verwendungszweck || failure.error_message,
            target_iban: parsedData.target_iban || dataMap.target_iban || dataMap.empfaenger_iban || null,
            source_iban: parsedData.source_iban || dataMap.source_iban || dataMap.sender_iban || null,
            partner: parsedData.partner || dataMap.partner || dataMap.empfaenger || dataMap.sender || '',
            type: 'PAYMENT' as const,
            account_id: parsedData.account_id || 1,
        };
    };

    const handleTransactionSubmit = async (values: FormValues) => {
        try {
            const response = await axios.post('/api/transactions', values);
            if (response.status === 201) {
                toast.success('Transaction created successfully');

                if (selectedFailureForTransaction) {
                    await axios.patch(`/api/imports/${importData.id}/failures/${selectedFailureForTransaction.id}/resolved`, {
                        notes: 'Transaction created manually'
                    });
                }

                setIsCreateTransactionModalOpen(false);
                setSelectedFailureForTransaction(null);
                await loadFailures();
            }
        } catch (error) {
            toast.error('Failed to create transaction');
        }
    };

    // Enhanced review mode handlers
    const handleEnterReviewMode = () => {
        if (pendingFailures.length > 0) {
            setReviewMode('review');
            setCurrentReviewIndex(0);
        }
    };

    const handleNextFailure = async () => {
        if (currentReviewIndex < pendingFailures.length - 1) {
            setCurrentReviewIndex(prev => prev + 1);
        } else {
            // Auto-load next page or return to list view
            setReviewMode('list');
            await loadFailures();
        }
    };

    const handleReviewTransactionCreate = async (values: FormValues) => {
        if (!pendingFailures[currentReviewIndex]) return;

        const currentFailure = pendingFailures[currentReviewIndex];
        setIsSubmitting(true);

        try {
            // Create transaction
            await axios.post('/api/transactions', values);

            // Mark failure as resolved
            await axios.patch(`/api/imports/${importData.id}/failures/${currentFailure.id}/resolved`, {
                notes: 'Transaction created via review interface'
            });

            toast.success('Transaction created successfully');
            await handleNextFailure();

        } catch (error) {
            toast.error('Failed to create transaction');
            console.error('Transaction creation failed:', error);
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleMarkAsReviewed = async (notes?: string) => {
        if (!pendingFailures[currentReviewIndex]) return;

        const currentFailure = pendingFailures[currentReviewIndex];
        setIsSubmitting(true);

        try {
            await axios.patch(`/api/imports/${importData.id}/failures/${currentFailure.id}/reviewed`, {
                notes: notes || 'Reviewed manually'
            });

            toast.success('Marked as reviewed');
            await handleNextFailure();

        } catch (error) {
            toast.error('Failed to mark as reviewed');
        } finally {
            setIsSubmitting(false);
        }
    };

    const handleMarkAsIgnored = async (notes?: string) => {
        if (!pendingFailures[currentReviewIndex]) return;

        const currentFailure = pendingFailures[currentReviewIndex];
        setIsSubmitting(true);

        try {
            await axios.patch(`/api/imports/${importData.id}/failures/${currentFailure.id}/ignored`, {
                notes: notes || 'Ignored manually'
            });

            toast.success('Marked as ignored');
            await handleNextFailure();

        } catch (error) {
            toast.error('Failed to mark as ignored');
        } finally {
            setIsSubmitting(false);
        }
    };

    // Enhanced Review Interface Component
    const FailureReviewInterface = () => {
        if (!pendingFailures[currentReviewIndex]) {
            return (
                <div className="text-center py-12">
                    <CheckCircle className="h-12 w-12 text-green-500 mx-auto mb-4" />
                    <h3 className="text-lg font-medium text-gray-900 mb-2">All failures reviewed!</h3>
                    <p className="text-gray-500 mb-4">Great job! You've reviewed all pending failures.</p>
                    <Button onClick={() => setReviewMode('list')}>
                        <ArrowLeft className="w-4 h-4 mr-2" />
                        Back to List
                    </Button>
                </div>
            );
        }

        const currentFailure = pendingFailures[currentReviewIndex];
        const defaultValues = FieldMappingService.mapFields(currentFailure, importData);

        const form = useForm<FormValues>({
            resolver: zodResolver(transactionSchema),
            defaultValues,
        });

        const handleSubmit = async (values: FormValues) => {
            await handleReviewTransactionCreate(values);
        };

        return (
            <div className="space-y-4">
                {/* Progress Header */}
                <div className="flex justify-between items-center">
                    <div>
                        <h2 className="text-xl font-semibold">Review Import Failure</h2>
                        <p className="text-sm text-gray-600">
                            Row {currentFailure.row_number} • {currentReviewIndex + 1} of {pendingFailures.length} pending failures
                        </p>
                    </div>
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setReviewMode('list')}
                        >
                            <ArrowLeft className="w-4 h-4 mr-2" />
                            Back to List
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => handleMarkAsReviewed()}
                            disabled={isSubmitting}
                        >
                            <Eye className="w-4 h-4 mr-2" />
                            Mark as Reviewed
                        </Button>
                        <Button
                            variant="outline"
                            onClick={() => handleMarkAsIgnored()}
                            disabled={isSubmitting}
                        >
                            <XCircle className="w-4 h-4 mr-2" />
                            Ignore
                        </Button>
                    </div>
                </div>

                {/* Error Details - Top Row */}
                <Card className="mb-6">
                    <CardHeader>
                        <CardTitle className="text-base">Error Details</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ErrorDetailsPanel failure={currentFailure} />
                    </CardContent>
                </Card>

                {/* Two Column Layout - Bottom Row */}
                <div className="grid grid-cols-2 gap-6">
                    {/* Left: Raw Data Panel */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Raw CSV Data</CardTitle>
                            <p className="text-sm text-gray-600">
                                Fields highlighted in blue were auto-mapped
                            </p>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <RawDataViewer
                                headers={currentFailure.metadata.headers || []}
                                data={currentFailure.raw_data || []}
                                highlightedFields={FieldMappingService.getHighlightedFields(currentFailure)}
                            />
                        </CardContent>
                    </Card>

                    {/* Right: Transaction Form */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Create Transaction</CardTitle>
                            <p className="text-sm text-gray-600">
                                Review and adjust the transaction details below
                            </p>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={form.handleSubmit(handleSubmit)} className="space-y-4">
                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="partner">Partner *</Label>
                                        <Input
                                            id="partner"
                                            {...form.register('partner')}
                                            className={form.formState.errors.partner ? 'border-red-500' : ''}
                                        />
                                        {form.formState.errors.partner && (
                                            <p className="text-sm text-red-600 mt-1">{form.formState.errors.partner.message}</p>
                                        )}
                                    </div>
                                    <div>
                                        <Label htmlFor="amount">Amount *</Label>
                                        <Input
                                            id="amount"
                                            type="number"
                                            step="0.01"
                                            {...form.register('amount')}
                                            className={form.formState.errors.amount ? 'border-red-500' : ''}
                                        />
                                        {form.formState.errors.amount && (
                                            <p className="text-sm text-red-600 mt-1">{form.formState.errors.amount.message}</p>
                                        )}
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="currency">Currency *</Label>
                                        <Select value={form.watch('currency')} onValueChange={(value) => form.setValue('currency', value)}>
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {currencies.map(currency => (
                                                    <SelectItem key={currency.value} value={currency.value}>
                                                        {currency.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.formState.errors.currency && (
                                            <p className="text-sm text-red-600 mt-1">{form.formState.errors.currency.message}</p>
                                        )}
                                    </div>
                                    <div>
                                        <Label htmlFor="type">Type *</Label>
                                        <Select value={form.watch('type')} onValueChange={(value) => form.setValue('type', value as any)}>
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {transactionTypes.map(type => (
                                                    <SelectItem key={type.value} value={type.value}>
                                                        {type.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                        {form.formState.errors.type && (
                                            <p className="text-sm text-red-600 mt-1">{form.formState.errors.type.message}</p>
                                        )}
                                    </div>
                                </div>

                                <div>
                                    <Label htmlFor="description">Description *</Label>
                                    <Input
                                        id="description"
                                        {...form.register('description')}
                                        className={form.formState.errors.description ? 'border-red-500' : ''}
                                    />
                                    {form.formState.errors.description && (
                                        <p className="text-sm text-red-600 mt-1">{form.formState.errors.description.message}</p>
                                    )}
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="target_iban">Target IBAN</Label>
                                        <Input
                                            id="target_iban"
                                            {...form.register('target_iban')}
                                        />
                                    </div>
                                    <div>
                                        <Label htmlFor="source_iban">Source IBAN</Label>
                                        <Input
                                            id="source_iban"
                                            {...form.register('source_iban')}
                                        />
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div>
                                        <Label htmlFor="booked_date">Booked Date *</Label>
                                        <Input
                                            id="booked_date"
                                            type="date"
                                            {...form.register('booked_date')}
                                            className={form.formState.errors.booked_date ? 'border-red-500' : ''}
                                        />
                                        {form.formState.errors.booked_date && (
                                            <p className="text-sm text-red-600 mt-1">{form.formState.errors.booked_date.message}</p>
                                        )}
                                    </div>
                                    <div>
                                        <Label htmlFor="processed_date">Processed Date *</Label>
                                        <Input
                                            id="processed_date"
                                            type="date"
                                            {...form.register('processed_date')}
                                            className={form.formState.errors.processed_date ? 'border-red-500' : ''}
                                        />
                                        {form.formState.errors.processed_date && (
                                            <p className="text-sm text-red-600 mt-1">{form.formState.errors.processed_date.message}</p>
                                        )}
                                    </div>
                                </div>

                                <div>
                                    <Label htmlFor="transaction_id">Transaction ID *</Label>
                                    <Input
                                        id="transaction_id"
                                        {...form.register('transaction_id')}
                                        className={form.formState.errors.transaction_id ? 'border-red-500' : ''}
                                    />
                                    {form.formState.errors.transaction_id && (
                                        <p className="text-sm text-red-600 mt-1">{form.formState.errors.transaction_id.message}</p>
                                    )}
                                </div>

                                <div className="flex gap-2 pt-4">
                                    <Button
                                        type="submit"
                                        className="flex-1 bg-green-600 hover:bg-green-700"
                                        disabled={isSubmitting}
                                    >
                                        <CheckCircle className="w-4 h-4 mr-2" />
                                        {isSubmitting ? 'Creating...' : 'Create Transaction & Next'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Review Failures - ${importData.original_filename}`} />

            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="flex justify-between items-center mb-6">
                    <div>
                        <h1 className="text-2xl font-semibold text-foreground">Review Import Failures</h1>
                        <p className="text-muted-foreground mt-1">{importData.original_filename} - {stats.total} failures found</p>
                    </div>
                    {reviewMode === 'list' && pendingFailures.length > 0 && (
                        <Button
                            onClick={handleEnterReviewMode}
                            className=" text-foreground bg-blue-600 hover:bg-blue-700"
                        >
                            <Eye className="w-4 h-4 mr-2" />
                            Start Review ({pendingFailures.length} pending)
                        </Button>
                    )}
                </div>

                {reviewMode === 'review' ? (
                    <FailureReviewInterface />
                ) : (
                    <>
                        {/* Statistics Cards */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-semibold text-muted-foreground">Total Failures</p>
                                    <p className="text-2xl font-bold">{stats.total}</p>
                                </div>
                                <FileX className="h-8 w-8 text-destructive-foreground" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-semibold text-muted-foreground">Pending Review</p>
                                    <p className="text-2xl font-bold text-warning">{stats.pending}</p>
                                </div>
                                <AlertTriangle className="h-8 w-8 text-warning" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-semibold text-muted-foreground">Reviewed</p>
                                    <p className="text-2xl font-bold text-success">{stats.reviewed}</p>
                                </div>
                                <CheckCircle className="h-8 w-8 text-success" />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-semibold text-muted-foreground">Validation Errors</p>
                                    <p className="text-2xl font-bold text-destructive-foreground">{stats.by_type.validation_failed || 0}</p>
                                </div>
                                <AlertCircle className="h-8 w-8 text-destructive-foreground" />
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* Filters */}
                <Card className="mb-6">
                    <CardContent className="p-4">
                        <div className="flex flex-col md:flex-row gap-4 items-start md:items-center justify-between">
                            <div className="flex flex-col md:flex-row gap-4 items-start md:items-center">
                                <div className="flex items-center space-x-2">
                                    <Search className="h-4 w-4 text-gray-400" />
                                    <Input
                                        placeholder="Search in failures..."
                                        value={filters.search}
                                        onChange={(e) => setFilters({ ...filters, search: e.target.value })}
                                        className="w-64"
                                    />
                                </div>

                                <Select
                                    value={filters.error_type || "all"}
                                    onValueChange={(value) => setFilters({ ...filters, error_type: value === "all" ? "" : value })}
                                >
                                    <SelectTrigger className="w-48">
                                        <SelectValue placeholder="Filter by error type" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Error Types</SelectItem>
                                        <SelectItem value="validation_failed">Validation Failed</SelectItem>
                                        <SelectItem value="duplicate">Duplicate</SelectItem>
                                        <SelectItem value="processing_error">Processing Error</SelectItem>
                                        <SelectItem value="parsing_error">Parsing Error</SelectItem>
                                    </SelectContent>
                                </Select>

                                <Select
                                    value={filters.status || "all"}
                                    onValueChange={(value) => setFilters({ ...filters, status: value === "all" ? "" : value })}
                                >
                                    <SelectTrigger className="w-48">
                                        <SelectValue placeholder="Filter by status" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">All Statuses</SelectItem>
                                        <SelectItem value="pending">Pending</SelectItem>
                                        <SelectItem value="reviewed">Reviewed</SelectItem>
                                        <SelectItem value="resolved">Resolved</SelectItem>
                                        <SelectItem value="ignored">Ignored</SelectItem>
                                    </SelectContent>
                                </Select>

                                <div className="flex items-center space-x-2">

                                </div>

                            </div>

                            {selectedFailures.length > 0 && (
                                <div className="flex gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => handleBulkAction('reviewed', 'Bulk reviewed')}
                                        disabled={isMarkingReviewed}
                                    >
                                        Mark as Reviewed ({selectedFailures.length})
                                    </Button>
                                </div>
                            )}
                        </div>
                    </CardContent>
                </Card>

                {/* Failures List */}
                <div className="space-y-4">
                    {failures.data.length > 0 && (
                        <div className="flex items-center space-x-2 mb-4">
                            <Checkbox
                                id="select-all"
                                checked={selectedFailures.length === failures.data.length}
                                onCheckedChange={handleSelectAll}
                            />
                            <Label htmlFor="select-all" className="text-sm font-medium">
                                Select all ({failures.data.length})
                            </Label>
                        </div>
                    )}

                    {failures.data.map((failure) => (
                        <Card key={failure.id} className="overflow-hidden hover:border-muted-foreground">
                            <CardHeader className="pb-3">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center space-x-3">
                                        <Checkbox
                                            checked={selectedFailures.includes(failure.id)}
                                            onCheckedChange={(checked) => handleSelectFailure(failure.id, checked as boolean)}
                                        />
                                        <div className="flex items-center space-x-2">
                                            <Button
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => setExpandedFailure(expandedFailure === failure.id ? null : failure.id)}
                                            >
                                                {expandedFailure === failure.id ?
                                                    <ChevronDown className="h-4 w-4" /> :
                                                    <ChevronRight className="h-4 w-4" />
                                                }
                                            </Button>
                                            <span className="font-medium">Row {failure.row_number}</span>
                                            {getErrorTypeBadge(failure.error_type)}
                                            {getStatusBadge(failure.status)}
                                        </div>
                                    </div>

                                    <div className="flex items-center space-x-2">
                                        {failure.status === 'pending' && (
                                            <Button
                                                size="sm"
                                                onClick={() => handleCreateTransaction(failure)}
                                                className="bg-green-600 hover:bg-green-700"
                                            >
                                                <Plus className="h-4 w-4 mr-1" />
                                                Create Transaction
                                            </Button>
                                        )}
                                    </div>
                                </div>

                                <div className="text-sm text-gray-600">
                                    <p className="font-medium text-red-600">{failure.error_message}</p>
                                    <p className="text-xs text-gray-500 mt-1">
                                        Created {formatDate(failure.created_at)}
                                        {failure.reviewed_at && ` • Reviewed ${formatDate(failure.reviewed_at)}`}
                                    </p>
                                </div>
                            </CardHeader>

                            {expandedFailure === failure.id && (
                                <CardContent className="pt-0 border-t bg-card">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <h4 className="font-medium mb-2">Raw CSV Data</h4>
                                            <div className="bg-card rounded border p-3 text-sm">
                                                {failure.metadata.headers && (
                                                    <div className="space-y-1">
                                                        {failure.metadata.headers.map((header, index) => (
                                                            <div key={index} className="flex justify-between py-1 botder-b border-dashed border-muted-foreground">
                                                                <span className="font-medium text-muted-foreground">{header}:</span>
                                                                <span className="text-foreground">{failure.raw_data[index] || '-'}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <div>
                                            <h4 className="font-medium mb-2">Error Details</h4>
                                            <div className="bg-red-50 rounded border border-red-200 p-3 text-sm">
                                                <p className="font-medium text-red-800 mb-2">{failure.error_details.message}</p>
                                                {failure.error_details.errors && failure.error_details.errors.length > 0 && (
                                                    <ul className="list-disc list-inside text-red-700 space-y-1">
                                                        {failure.error_details.errors.map((error, index) => (
                                                            <li key={index}>{error}</li>
                                                        ))}
                                                    </ul>
                                                )}
                                            </div>
                                        </div>
                                    </div>
                                </CardContent>
                            )}
                        </Card>
                    ))}

                    {failures.data.length === 0 && (
                        <Card>
                            <CardContent className="p-8 text-center">
                                <FileX className="h-12 w-12 text-gray-400 mx-auto mb-4" />
                                <h3 className="text-lg font-medium text-gray-900 mb-2">No failures found</h3>
                                <p className="text-gray-500">
                                    {Object.values(filters).some(v => v)
                                        ? 'Try adjusting your filters to see more results.'
                                        : 'All import transactions were processed successfully.'}
                                </p>
                            </CardContent>
                        </Card>
                    )}
                    </div>
                    </>
                )}
            </div>

        </AppLayout>
    );
}
