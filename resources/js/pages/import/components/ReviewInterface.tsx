import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import RawDataViewer from '@/pages/import/components/RawDataViewer';
import { zodResolver } from '@hookform/resolvers/zod';
import { ArrowLeft, CheckCircle, Eye, XCircle } from 'lucide-react';
import { useForm } from 'react-hook-form';
import { useEffect, useState } from 'react';
import axios from 'axios';
import { z } from 'zod';
import ErrorDetailsPanel from '@/pages/import/components/ErrorDetailsPanel';

// Define types for field definitions
interface FieldOption {
    value: string | number;
    label: string;
}

interface FieldDefinition {
    type: 'text' | 'number' | 'date' | 'select' | 'textarea';
    label: string;
    required: boolean;
    step?: string;
    options?: FieldOption[];
    description?: string;
}

interface FieldDefinitions {
    fields: Record<string, FieldDefinition>;
    field_order: string[];
}

// Create dynamic schema based on field definitions
const createDynamicSchema = (fieldDefs: FieldDefinitions) => {
    const schemaObject: Record<string, z.ZodTypeAny> = {};

    fieldDefs.field_order.forEach(fieldName => {
        const fieldDef = fieldDefs.fields[fieldName];
        if (!fieldDef) return;

        let schema: z.ZodTypeAny;

        switch (fieldDef.type) {
            case 'number':
                if (fieldDef.required) {
                    // Allow negative amounts for expenses - don't enforce minimum value for amount field
                    if (fieldName === 'amount') {
                        schema = z.coerce.number({
                            required_error: `${fieldDef.label} is required`,
                            invalid_type_error: `${fieldDef.label} must be a number`
                        });
                    } else {
                        schema = z.coerce.number().min(0.01, { message: `${fieldDef.label} must be greater than 0` });
                    }
                } else {
                    schema = z.coerce.number().optional().nullable();
                }
                break;
            case 'date':
                if (fieldDef.required) {
                    schema = z.string().min(1, { message: `${fieldDef.label} is required` });
                } else {
                    schema = z.string().optional().nullable();
                }
                break;
            case 'select':
                if (fieldDef.options && fieldDef.options.length > 0) {
                    const values = fieldDef.options.map(opt => opt.value.toString());
                    // Add '__none__' as a valid option for form compatibility
                    const validValues = [...values, '__none__'];
                    if (fieldDef.required) {
                        schema = z.enum(validValues as [string, ...string[]], {
                            required_error: `${fieldDef.label} is required`,
                        }).refine((val) => val !== '__none__', {
                            message: `${fieldDef.label} is required`,
                        });
                    } else {
                        schema = z.enum(validValues as [string, ...string[]]).nullable().optional();
                    }
                } else {
                    schema = fieldDef.required
                        ? z.string().min(1, { message: `${fieldDef.label} is required` })
                        : z.string().nullable().optional();
                }
                break;
            default:
                if (fieldDef.required) {
                    schema = z.string().min(1, { message: `${fieldDef.label} is required` });
                } else {
                    schema = z.string().optional().nullable();
                }
        }

        schemaObject[fieldName] = schema;
    });

    return z.object(schemaObject);
};

type FormValues = Record<string, any>;

import FieldMappingService from '@/pages/import/FieldMappingService';

function ReviewInterface({
    pendingFailures,
    currentReviewIndex,
    setReviewMode,
    handleReviewTransactionCreate,
    handleMarkAsReviewed,
    handleMarkAsIgnored,
    isSubmitting,
    importData,
}: {
    pendingFailures: any[];
    currentReviewIndex: number;
    setReviewMode: (mode: string) => void;
    handleReviewTransactionCreate: (values: any) => Promise<void>;
    handleMarkAsReviewed: () => void;
    handleMarkAsIgnored: () => void;
    isSubmitting: boolean;
    importData: any;
}) {
    // ALL HOOKS MUST BE CALLED FIRST - BEFORE ANY CONDITIONAL RETURNS
    const [fieldDefinitions, setFieldDefinitions] = useState<FieldDefinitions | null>(null);
    const [isLoadingFields, setIsLoadingFields] = useState(true);
    const [mappedValues, setMappedValues] = useState<Record<string, any>>({});
    const [actuallyMappedFields, setActuallyMappedFields] = useState<Set<string>>(new Set());

    // Load field definitions from backend
    useEffect(() => {
        const loadFieldDefinitions = async () => {
            try {
                const response = await axios.get('/api/transactions/field-definitions');
                setFieldDefinitions(response.data);
            } catch (error) {
                console.error('Failed to load field definitions:', error);
            } finally {
                setIsLoadingFields(false);
            }
        };

        loadFieldDefinitions();
    }, []);

    // Get current failure and prepare initial default values
    const currentFailure = pendingFailures[currentReviewIndex];

    // Create dynamic schema - use empty schema if fieldDefinitions not ready
    const dynamicSchema = fieldDefinitions
        ? createDynamicSchema(fieldDefinitions)
        : z.object({});

    // useForm hook must always be called - cannot be conditional
    const form = useForm<FormValues>({
        resolver: zodResolver(dynamicSchema),
        defaultValues: {}, // Start with empty defaults, will be populated in useEffect
    });

    // Map fields and reset form when currentFailure or fieldDefinitions change
    useEffect(() => {
        if (currentFailure && fieldDefinitions) {
            console.log('🔄 Mapping fields for failure:', currentFailure.id);

            // Get mapped values from FieldMappingService
            const mappingResult = FieldMappingService.mapFields(
                currentFailure,
                importData,
                fieldDefinitions
            );

            const newMappedValues = mappingResult.values;
            const newActuallyMappedFields = mappingResult.actuallyMappedFields;

            console.log('📊 Mapped values:', newMappedValues);
            console.log('🎯 Actually mapped fields:', Array.from(newActuallyMappedFields));

            setMappedValues(newMappedValues);
            setActuallyMappedFields(newActuallyMappedFields);

            // Transform values for form compatibility (especially select fields)
            const formValues: Record<string, any> = {};

            fieldDefinitions.field_order.forEach((fieldName) => {
                const fieldDef = fieldDefinitions.fields[fieldName];
                let value = newMappedValues[fieldName];

                // Handle select fields - convert null/undefined to __none__ for form compatibility
                if (fieldDef?.type === 'select') {
                    if (value === null || value === undefined) {
                        value = fieldDef.required ? '' : '__none__';
                    } else {
                        value = value.toString();
                    }
                }

                // Handle number fields - ensure they're numbers
                if (fieldDef?.type === 'number' && value !== null && value !== undefined) {
                    value = Number(value);
                }

                formValues[fieldName] = value;
            });

            console.log('📝 Form values being set:', formValues);

            // Reset form with transformed values
            form.reset(formValues);

            // Force form to re-render by triggering validation
            setTimeout(() => {
                form.trigger();
            }, 0);
        }
    }, [currentFailure, fieldDefinitions, form, importData]);

    // NOW WE CAN HAVE CONDITIONAL RENDERING
    if (isLoadingFields) {
        return (
            <div className="py-12 text-center">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600 mx-auto"></div>
                <p className="mt-2 text-gray-500">Loading form fields...</p>
            </div>
        );
    }

    if (!fieldDefinitions) {
        return (
            <div className="py-12 text-center">
                <p className="text-red-500">Failed to load form fields. Please refresh the page.</p>
            </div>
        );
    }

    if (!currentFailure) {
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

    const handleSubmit = async (values: FormValues) => {
        // Transform form values back for submission
        const submitValues: Record<string, any> = {};

        Object.entries(values).forEach(([fieldName, value]) => {
            const fieldDef = fieldDefinitions.fields[fieldName];

            // Convert __none__ back to null for select fields
            if (fieldDef?.type === 'select' && value === '__none__') {
                submitValues[fieldName] = null;
            } else {
                submitValues[fieldName] = value;
            }
        });

        console.log('🚀 Submitting values:', submitValues);
        await handleReviewTransactionCreate(submitValues);
    };

    const renderField = (fieldName: string, fieldDef: FieldDefinition) => {
        const error = form.formState.errors[fieldName];
        const currentValue = form.watch(fieldName);
        // Only mark as mapped if it was actually mapped from raw data, not just has a value
        const isMapped = actuallyMappedFields.has(fieldName);

        const commonProps = {
            id: fieldName,
            className: `${error ? 'border-destructive' : ''} ${isMapped ? 'border-primary bg-primary/5' : ''}`,
        };

        switch (fieldDef.type) {
            case 'number':
                return (
                    <Input
                        {...commonProps}
                        type="number"
                        step={fieldDef.step || '0.01'}
                        {...form.register(fieldName, { valueAsNumber: true })}
                    />
                );
            case 'date':
                return (
                    <Input
                        {...commonProps}
                        type="date"
                        {...form.register(fieldName)}
                    />
                );
            case 'select':
                return (
                    <Select
                        value={currentValue?.toString() || '__none__'}
                        onValueChange={(value) => {
                            console.log(`🔄 Select field ${fieldName} changed to:`, value);
                            form.setValue(fieldName, value === '__none__' ? null : value, {
                                shouldValidate: true,
                                shouldDirty: true
                            });
                        }}
                    >
                        <SelectTrigger className={`${error ? 'border-destructive' : ''} ${isMapped ? 'border-primary bg-primary/5' : ''}`}>
                            <SelectValue placeholder={`Select ${fieldDef.label}`} />
                        </SelectTrigger>
                        <SelectContent>
                            {!fieldDef.required && (
                                <SelectItem value="__none__">-- None --</SelectItem>
                            )}
                            {fieldDef.options?.map((option) => (
                                <SelectItem key={option.value} value={option.value.toString()}>
                                    {option.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                );
            case 'textarea':
                return (
                    <Textarea
                        {...commonProps}
                        {...form.register(fieldName)}
                        rows={3}
                    />
                );
            default:
                return (
                    <Input
                        {...commonProps}
                        type="text"
                        {...form.register(fieldName)}
                    />
                );
        }
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
                            {/* Auto-mapping Summary */}
                            {actuallyMappedFields.size > 0 && (
                                <div className="mb-4 p-3 bg-primary/10 border border-primary/20 rounded-lg">
                                    <h4 className="text-sm font-medium text-primary mb-2">
                                        🤖 Auto-mapped Fields ({actuallyMappedFields.size})
                                    </h4>
                                    <div className="text-xs text-primary/80 grid grid-cols-2 gap-1">
                                        {Array.from(actuallyMappedFields)
                                            .map((fieldName) => {
                                                const fieldDef = fieldDefinitions.fields[fieldName];
                                                const value = mappedValues[fieldName];
                                                return (
                                                    <div key={fieldName} className="flex justify-between">
                                                        <span className="font-medium">{fieldDef?.label || fieldName}:</span>
                                                        <span className="ml-2 truncate max-w-20" title={value?.toString()}>
                                                            {value?.toString().length > 15
                                                                ? `${value.toString().substring(0, 15)}...`
                                                                : value?.toString()}
                                                        </span>
                                                    </div>
                                                );
                                            })}
                                    </div>
                                </div>
                            )}

                            {fieldDefinitions.field_order.map((fieldName) => {
                                const fieldDef = fieldDefinitions.fields[fieldName];
                                if (!fieldDef) return null;

                                const isMapped = actuallyMappedFields.has(fieldName);

                                return (
                                    <div key={fieldName} className="relative">
                                        <Label htmlFor={fieldName} className="flex items-center gap-2">
                                            {fieldDef.label}
                                            {fieldDef.required && ' *'}
                                            {isMapped && (
                                                <span className="inline-flex items-center gap-1 rounded-full px-2  text-xs font-medium text-blue-500 ">
                                                    <span className="h-2 w-2 rounded-full bg-blue-500"></span>
                                                    auto-mapped
                                                </span>
                                            )}
                                        </Label>
                                        {renderField(fieldName, fieldDef)}
                                        {form.formState.errors[fieldName] && (
                                            <p className="mt-1 text-sm text-destructive">
                                                {form.formState.errors[fieldName]?.message as string}
                                            </p>
                                        )}
                                        {fieldDef.description && (
                                            <p className="mt-1 text-xs text-muted-foreground">{fieldDef.description}</p>
                                        )}
                                    </div>
                                );
                            })}

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
