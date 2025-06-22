import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { InferFormValues } from '@/components/ui/smart-form';
import AppLayout from '@/layouts/app-layout';
import FailureCollapse from '@/pages/import/components/FailureCollapse';
import ReviewInterface from '@/pages/import/components/ReviewInterface';
import { BreadcrumbItem, Import, ImportFailure } from '@/types/index';
import { formatDate } from '@/utils/date';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, AlertTriangle, CheckCircle, Copy, Eye, FileX, Info, Loader2, Search, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';
import { z } from 'zod';

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
                const numericValue = parseFloat(
                    value
                        .toString()
                        .replace(/[^\d.,-]/g, '')
                        .replace(',', '.'),
                );
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

        headers.forEach((header) => {
            Object.values(this.fieldPatterns).forEach((pattern) => {
                if (pattern.test(header)) {
                    highlighted.add(header.toLowerCase());
                }
            });
        });

        return highlighted;
    }
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
    const [isLoadingFilters, setIsLoadingFilters] = useState(false);
    const [isMarkingReviewed, setIsMarkingReviewed] = useState(false);

    // Enhanced review mode state
    const [reviewMode, setReviewMode] = useState<'list' | 'review'>('list');
    const [currentReviewIndex, setCurrentReviewIndex] = useState(0);
    const [isSubmitting, setIsSubmitting] = useState(false);

    // Get pending failures for review mode
    const pendingFailures = failures.data.filter((f) => f.status === 'pending');

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Imports', href: '/imports' },
        { title: importData.original_filename, href: `/imports/${importData.id}` },
        { title: 'Review Failures', href: `/imports/${importData.id}/failures` },
    ];

    const loadFailures = async () => {
        try {
            setIsLoadingFilters(true);
            const params = new URLSearchParams();
            if (filters.error_type) params.append('error_type', filters.error_type);
            if (filters.status) params.append('status', filters.status);
            if (filters.search) params.append('search', filters.search);

            const response = await axios.get(`/api/imports/${importData.id}/failures?${params.toString()}`);
            setFailures(response.data.failures);
            setStats(response.data.stats);
        } catch (error) {
            toast.error('Failed to load failures');
        } finally {
            setIsLoadingFilters(false);
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
            setSelectedFailures(selectedFailures.filter((id) => id !== failureId));
        }
    };

    const handleSelectAll = (checked: boolean) => {
        if (checked) {
            setSelectedFailures(failures.data.map((f) => f.id));
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
    // Enhanced review mode handlers
    const handleEnterReviewMode = () => {
        if (pendingFailures.length > 0) {
            setReviewMode('review');
            setCurrentReviewIndex(0);
        }
    };

    const handleNextFailure = async () => {
        if (currentReviewIndex < pendingFailures.length - 1) {
            setCurrentReviewIndex((prev) => prev + 1);
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
                notes: 'Transaction created via review interface',
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
                notes: notes || 'Reviewed manually',
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
                notes: notes || 'Ignored manually',
            });

            toast.success('Marked as ignored');
            await handleNextFailure();
        } catch (error) {
            toast.error('Failed to mark as ignored');
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Review Failures - ${importData.original_filename}`} />

            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mb-6 flex items-center justify-between">
                    <div>
                        <h1 className="text-foreground text-2xl font-semibold">Review Import Failures</h1>
                        <p className="text-muted-foreground mt-1">
                            {importData.original_filename} - {stats.total} failures found
                        </p>
                    </div>
                    {reviewMode === 'list' && pendingFailures.length > 0 && (
                        <Button onClick={handleEnterReviewMode} className="text-foreground bg-blue-600 hover:bg-blue-700">
                            <Eye className="mr-2 h-4 w-4" />
                            Start Review ({pendingFailures.length} pending)
                        </Button>
                    )}
                </div>

                {reviewMode === 'review' ? (
                    <ReviewInterface
                        pendingFailures={pendingFailures}
                        currentReviewIndex={currentReviewIndex}
                        setReviewMode={(mode) => setReviewMode(mode as 'list' | 'review')}
                        handleReviewTransactionCreate={handleReviewTransactionCreate}
                        handleMarkAsReviewed={handleMarkAsReviewed}
                        handleMarkAsIgnored={handleMarkAsIgnored}
                        isSubmitting={isSubmitting}
                        onExitReviewMode={() => setReviewMode('list')}
                        importData={importData}
                        transactionSchema={transactionSchema}
                        transactionTypes={transactionTypes}
                        currencies={currencies}
                        FieldMappingService={FieldMappingService}
                        formatDate={formatDate}
                        ErrorDetailsPanel={ErrorDetailsPanel}
                        highlightedFields={FieldMappingService.getHighlightedFields(pendingFailures[currentReviewIndex])}
                    />
                ) : (
                    <>
                        {/* Statistics Cards */}
                        <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-4">
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-muted-foreground text-sm font-semibold">Total Failures</p>
                                            <p className="text-destructive-foreground text-2xl font-bold">{stats.total}</p>
                                        </div>
                                        <FileX className="text-destructive-foreground h-8 w-8" />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-muted-foreground text-sm font-semibold">Pending Review</p>
                                            <p className="text-warning text-2xl font-bold">{stats.pending}</p>
                                        </div>
                                        <AlertTriangle className="text-warning h-8 w-8" />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-muted-foreground text-sm font-semibold">Reviewed</p>
                                            <p className="text-success text-2xl font-bold">{stats.reviewed}</p>
                                        </div>
                                        <CheckCircle className="text-success h-8 w-8" />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-muted-foreground text-sm font-semibold">Validation Errors</p>
                                            <p className="text-destructive-foreground text-2xl font-bold">{stats.by_type.validation_failed || 0}</p>
                                        </div>
                                        <AlertCircle className="text-destructive-foreground h-8 w-8" />
                                    </div>
                                </CardContent>
                            </Card>
                        </div>

                        {/* Filters */}
                        <Card className="mb-6">
                            <CardContent className="p-4">
                                <div className="flex flex-col items-start justify-between gap-4 md:flex-row md:items-center">
                                    <div className="flex flex-col items-start gap-4 md:flex-row md:items-center">
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
                                            value={filters.error_type || 'all'}
                                            onValueChange={(value) => setFilters({ ...filters, error_type: value === 'all' ? '' : value })}
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
                                            value={filters.status || 'all'}
                                            onValueChange={(value) => setFilters({ ...filters, status: value === 'all' ? '' : value })}
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

                                        {/* Loading filters */}
                                        {isLoadingFilters && (
                                            <div className="filters-loading flex items-center space-x-2">
                                                <Loader2 className="h-6 w-6 animate-spin" />
                                                <p className="text-muted-foreground text-sm">Loading filters...</p>
                                            </div>
                                        )}
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
                                <div className="mb-4 flex items-center space-x-2">
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
                                <FailureCollapse failure={failure} selectedFailures={selectedFailures} handleSelectFailure={handleSelectFailure} />
                            ))}

                            {failures.data.length === 0 && (
                                <Card>
                                    <CardContent className="p-8 text-center">
                                        <FileX className="mx-auto mb-4 h-12 w-12 text-gray-400" />
                                        <h3 className="mb-2 text-lg font-medium text-gray-900">No failures found</h3>
                                        <p className="text-gray-500">
                                            {Object.values(filters).some((v) => v)
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
