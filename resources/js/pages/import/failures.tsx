import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SelectInput, TextInput } from '@/components/ui/form-inputs';
import { FormModal } from '@/components/ui/form-modal';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { InferFormValues } from '@/components/ui/smart-form';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { BreadcrumbItem, ImportFailure, Import } from '@/types/index';
import { formatDate } from '@/utils/date';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, AlertTriangle, CheckCircle, ChevronDown, ChevronRight, Download, FileX, MoreHorizontal, Plus, Search } from 'lucide-react';
import { useState, useEffect } from 'react';
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Review Failures - ${importData.original_filename}`} />

            <div className="mx-auto w-full max-w-7xl p-4">
                <PageHeader
                    title="Review Import Failures"
                    subtitle={`${importData.original_filename} - ${stats.total} failures found`}
                />

                {/* Statistics Cards */}
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-600">Total Failures</p>
                                    <p className="text-2xl font-bold">{stats.total}</p>
                                </div>
                                <FileX className="h-8 w-8 text-red-500" />
                            </div>
                        </CardContent>
                    </Card>
                    
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-600">Pending Review</p>
                                    <p className="text-2xl font-bold text-orange-600">{stats.pending}</p>
                                </div>
                                <AlertTriangle className="h-8 w-8 text-orange-500" />
                            </div>
                        </CardContent>
                    </Card>
                    
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-600">Reviewed</p>
                                    <p className="text-2xl font-bold text-green-600">{stats.reviewed}</p>
                                </div>
                                <CheckCircle className="h-8 w-8 text-green-500" />
                            </div>
                        </CardContent>
                    </Card>
                    
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center justify-between">
                                <div>
                                    <p className="text-sm font-medium text-gray-600">Validation Errors</p>
                                    <p className="text-2xl font-bold text-red-600">{stats.by_type.validation_failed || 0}</p>
                                </div>
                                <AlertCircle className="h-8 w-8 text-red-500" />
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
                        <Card key={failure.id} className="overflow-hidden">
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
                                <CardContent className="pt-0 border-t bg-gray-50">
                                    <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                                        <div>
                                            <h4 className="font-medium mb-2">Raw CSV Data</h4>
                                            <div className="bg-white rounded border p-3 text-sm">
                                                {failure.metadata.headers && (
                                                    <div className="space-y-1">
                                                        {failure.metadata.headers.map((header, index) => (
                                                            <div key={index} className="flex justify-between py-1">
                                                                <span className="font-medium text-gray-600">{header}:</span>
                                                                <span className="text-gray-900">{failure.raw_data[index] || '-'}</span>
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
            </div>

            {/* Create Transaction Modal */}
            {isCreateTransactionModalOpen && selectedFailureForTransaction && (
                <FormModal
                    isOpen={isCreateTransactionModalOpen}
                    onClose={() => {
                        setIsCreateTransactionModalOpen(false);
                        setSelectedFailureForTransaction(null);
                    }}
                    title="Create Transaction from Failed Import"
                    description="Review and adjust the transaction details below. Data has been pre-populated from the failed import row."
                    schema={transactionSchema}
                    defaultValues={getTransactionDefaultValues(selectedFailureForTransaction)}
                    onSubmit={handleTransactionSubmit}
                    submitLabel="Create Transaction"
                >
                    {() => (
                        <>
                            <TextInput<FormValues> name="partner" label="Partner" required />
                            <TextInput<FormValues> name="amount" label="Amount" type="number" required />
                            <SelectInput<FormValues> name="currency" label="Currency" options={currencies} required />
                            <TextInput<FormValues> name="description" label="Description" required />
                            <SelectInput<FormValues> name="type" label="Type" options={transactionTypes} required />
                            <TextInput<FormValues> name="target_iban" label="Target IBAN" />
                            <TextInput<FormValues> name="source_iban" label="Source IBAN" />
                            <TextInput<FormValues> name="booked_date" label="Booked Date" type="date" required />
                            <TextInput<FormValues> name="processed_date" label="Processed Date" type="date" required />
                        </>
                    )}
                </FormModal>
            )}
        </AppLayout>
    );
}
