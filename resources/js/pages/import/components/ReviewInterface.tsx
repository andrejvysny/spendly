import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import RawDataViewer from '@/pages/import/components/RawDataViewer';
import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft, CheckCircle, Eye, XCircle } from 'lucide-react';
import { useForm } from 'react-hook-form';
function ReviewInterface({
    pendingFailures,
    currentReviewIndex,
    setReviewMode,
    handleReviewTransactionCreate,
    handleMarkAsReviewed,
    handleMarkAsIgnored,
    isSubmitting,
    importData,
    transactionSchema,
    currencies,
    transactionTypes,
    FieldMappingService,
    ErrorDetailsPanel,
}: {
    pendingFailures: any[];
    currentReviewIndex: number;
    setReviewMode: (mode: string) => void;
    handleReviewTransactionCreate: (values: any) => Promise<void>;
    handleMarkAsReviewed: () => void;
    handleMarkAsIgnored: () => void;
    isSubmitting: boolean;
    importData: any;
    transactionSchema: any;
    currencies: { value: string; label: string }[];
    transactionTypes: { value: string; label: string }[];
    FieldMappingService: any;
    formatDate: (date: string) => string;
    RawDataViewer: any;
    ErrorDetailsPanel: any;
}) {
    if (!pendingFailures[currentReviewIndex]) {
        return (
            <div className="py-12 text-center">
                <CheckCircle className="mx-auto mb-4 h-12 w-12 text-green-500" />
                <h3 className="mb-2 text-lg font-medium text-gray-900">All failures reviewed!</h3>
                <p className="mb-4 text-gray-500">Great job! You've reviewed all pending failures.</p>
                <Button onClick={() => setReviewMode('list')}>
                    <ArrowLeft className="mr-2 h-4 w-4" />
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
            <div className="flex items-center justify-between">
                <div>
                    <p className="text-foreground">Row {currentFailure.row_number}</p>
                    <p className="text-muted-foreground text-sm">
                        {currentReviewIndex + 1} of {pendingFailures.length} pending failures
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button variant="outline" onClick={() => setReviewMode('list')}>
                        <ArrowLeft className="mr-2 h-4 w-4" />
                        Back to List
                    </Button>
                    <Button variant="outline" onClick={() => handleMarkAsReviewed()} disabled={isSubmitting}>
                        <Eye className="mr-2 h-4 w-4" />
                        Mark as Reviewed
                    </Button>
                    <Button variant="outline" onClick={() => handleMarkAsIgnored()} disabled={isSubmitting}>
                        <XCircle className="mr-2 h-4 w-4" />
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
                        <p className="text-muted-foreground text-sm">Fields highlighted in blue were auto-mapped</p>
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
                        <p className="text-sm text-gray-600">Review and adjust the transaction details below</p>
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
                                        <p className="mt-1 text-sm text-red-600">{form.formState.errors.partner.message}</p>
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
                                        <p className="mt-1 text-sm text-red-600">{form.formState.errors.amount.message}</p>
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
                                            {currencies.map((currency) => (
                                                <SelectItem key={currency.value} value={currency.value}>
                                                    {currency.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.formState.errors.currency && (
                                        <p className="mt-1 text-sm text-red-600">{form.formState.errors.currency.message}</p>
                                    )}
                                </div>
                                <div>
                                    <Label htmlFor="type">Type *</Label>
                                    <Select value={form.watch('type')} onValueChange={(value) => form.setValue('type', value as any)}>
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {transactionTypes.map((type) => (
                                                <SelectItem key={type.value} value={type.value}>
                                                    {type.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    {form.formState.errors.type && <p className="mt-1 text-sm text-red-600">{form.formState.errors.type.message}</p>}
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
                                    <p className="mt-1 text-sm text-red-600">{form.formState.errors.description.message}</p>
                                )}
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div>
                                    <Label htmlFor="target_iban">Target IBAN</Label>
                                    <Input id="target_iban" {...form.register('target_iban')} />
                                </div>
                                <div>
                                    <Label htmlFor="source_iban">Source IBAN</Label>
                                    <Input id="source_iban" {...form.register('source_iban')} />
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
                                        <p className="mt-1 text-sm text-red-600">{form.formState.errors.booked_date.message}</p>
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
                                        <p className="mt-1 text-sm text-red-600">{form.formState.errors.processed_date.message}</p>
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
                                    <p className="mt-1 text-sm text-red-600">{form.formState.errors.transaction_id.message}</p>
                                )}
                            </div>

                            <div className="flex gap-2 pt-4">
                                <Button type="submit" className="flex-1 bg-green-600 hover:bg-green-700" disabled={isSubmitting}>
                                    <CheckCircle className="mr-2 h-4 w-4" />
                                    {isSubmitting ? 'Creating...' : 'Create Transaction & Next'}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}

export default ReviewInterface;
