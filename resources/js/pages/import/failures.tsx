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
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { AlertCircle, AlertTriangle, CheckCircle, Eye, FileX, Loader2, Search } from 'lucide-react';
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

export default function ImportFailures({ import: importData, failures: initialFailures, stats: initialStats }: Props) {
    const [failures, setFailures] = useState(initialFailures);
    const [stats, setStats] = useState(initialStats);

    const [selectedFailures, setSelectedFailures] = useState<number[]>([]);
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

    const loadMoreFailures = async () => {
        try {
            setIsLoadingFilters(true);
            const params = new URLSearchParams();
            if (filters.error_type) params.append('error_type', filters.error_type);
            if (filters.status) params.append('status', filters.status);
            if (filters.search) params.append('search', filters.search);
            params.append('page', (failures.meta.current_page + 1).toString());

            const response = await axios.get(`/api/imports/${importData.id}/failures?${params.toString()}`);

            // Append new failures to existing ones
            setFailures((prevFailures) => ({
                ...response.data.failures,
                data: [...prevFailures.data, ...response.data.failures.data],
            }));
            setStats(response.data.stats);
        } catch (error) {
            toast.error('Failed to load more failures');
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

    const handleBulkAction = async (action: 'reviewed' | 'resolved' | 'ignored' | 'pending', notes?: string) => {
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

            const actionName = action === 'pending' ? 'unmarked (reverted to pending)' : action;
            toast.success(`Marked ${selectedFailures.length} failures as ${actionName}`);
            setSelectedFailures([]);
            await loadFailures();
        } catch (error) {
            const actionName = action === 'pending' ? 'unmark' : `mark as ${action}`;
            toast.error(`Failed to ${actionName} failures`);
        } finally {
            setIsMarkingReviewed(false);
        }
    };

    const handleUnmarkAsPending = async (failureId: number, notes?: string) => {
        setIsMarkingReviewed(true);
        try {
            await axios.patch(`/api/imports/${importData.id}/failures/${failureId}/pending`, {
                notes: notes || 'Unmarked and reverted to pending',
            });

            toast.success('Failure unmarked and reverted to pending');
            await loadFailures();
        } catch (error) {
            toast.error('Failed to unmark failure');
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
            // Create transaction using the new endpoint
            await axios.post(`/api/imports/${importData.id}/failures/${currentFailure.id}/create-transaction`, values);

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
                        importData={importData}
                    />
                ) : (
                    <>
                        {/* Statistics Cards */}
                        <div className="mb-6 grid grid-cols-1 gap-4 md:grid-cols-4">
                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-muted-foreground text-sm font-semibold">Total</p>
                                            <p className="text-destructive-foreground text-3xl font-bold">{stats.total}</p>
                                        </div>
                                        <AlertCircle className="text-destructive-foreground h-8 w-8" />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent className="p-4">
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <p className="text-muted-foreground text-sm font-semibold">Pending Review</p>
                                            <p className="text-warning text-3xl font-bold">{stats.pending}</p>
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
                                            <p className="text-success text-3xl font-bold">{stats.reviewed}</p>
                                        </div>
                                        <CheckCircle className="text-success h-8 w-8" />
                                    </div>
                                </CardContent>
                            </Card>

                            <Card>
                                <CardContent className="p-4">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <span className="text-muted-foreground text-sm font-semibold">Validation Errors:</span>
                                            <span className="text-destructive-foreground font-semibold">{stats.by_type.validation_failed || 0}</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="text-muted-foreground text-sm font-semibold">Processing Errors:</span>
                                            <span className="text-destructive-foreground font-semibold">{stats.by_type.processing_failed || 0}</span>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <span className="text-muted-foreground text-sm font-semibold">Skipped duplicates:</span>
                                            <span className="font-semibold text-blue-500">{stats.by_type.skipped_duplicates || 0}</span>
                                        </div>
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
                                            <Button
                                                variant="outline"
                                                size="sm"
                                                onClick={() => handleBulkAction('pending', 'Bulk unmarked')}
                                                disabled={isMarkingReviewed}
                                            >
                                                Unmark ({selectedFailures.length})
                                            </Button>
                                        </div>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        {/* Failures List */}
                        <div className="space-y-4">
                            {failures.data.length > 0 && (
                                <div className="mb-4 flex items-center justify-between">
                                    <div className="flex items-center space-x-2">
                                        <Checkbox
                                            id="select-all"
                                            checked={selectedFailures.length === failures.data.length}
                                            onCheckedChange={handleSelectAll}
                                        />
                                        <Label htmlFor="select-all" className="text-sm font-medium">
                                            Select all ({failures.data.length})
                                        </Label>
                                    </div>
                                    {failures.meta && failures.meta.total > failures.data.length && (
                                        <div className="text-sm text-gray-500">
                                            Showing {failures.data.length} of {failures.meta.total} failures
                                        </div>
                                    )}
                                </div>
                            )}

                            {failures.data.map((failure) => (
                                <FailureCollapse
                                    key={failure.id}
                                    failure={failure}
                                    selectedFailures={selectedFailures}
                                    handleSelectFailure={handleSelectFailure}
                                    handleUnmarkAsPending={handleUnmarkAsPending}
                                    isMarkingReviewed={isMarkingReviewed}
                                />
                            ))}

                            {/* Load More Button */}
                            {failures.meta && failures.meta.current_page < failures.meta.last_page && (
                                <div className="mt-6 text-center">
                                    <Button variant="outline" onClick={loadMoreFailures} disabled={isLoadingFilters} className="w-full md:w-auto">
                                        {isLoadingFilters ? (
                                            <>
                                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                                Loading...
                                            </>
                                        ) : (
                                            <>Load More ({failures.meta.total - failures.data.length} remaining)</>
                                        )}
                                    </Button>
                                </div>
                            )}

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
