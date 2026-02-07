import { ConfidenceBadge } from '@/pages/import/components/ConfidenceBadge';
import { FormatConfiguration } from '@/pages/import/components/FormatConfiguration';
import { InteractiveSampleTable } from '@/pages/import/components/InteractiveSampleTable';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { ImportMapping, Transaction } from '@/types/index';
import axios from 'axios';
import { Save, Trash2 } from 'lucide-react';
import React, { useCallback, useEffect, useMemo, useState } from 'react';

interface ConfigureStepProps {
    headers: string[];
    sampleRows: string[][];
    importId: number;
    onComplete: (data: {
        columnMapping: Record<string, number | null>;
        dateFormat: string;
        amountFormat: string;
        amountTypeStrategy: string;
        currency: string;
        previewData: Partial<Transaction>[];
    }) => void;
}

// All possible transaction fields that could be mapped
const transactionFields = [
    { key: 'transaction_id', label: 'Transaction ID', required: false },
    { key: 'booked_date', label: 'Date', required: true },
    { key: 'amount', label: 'Amount', required: true },
    { key: 'description', label: 'Description', required: false },
    { key: 'partner', label: 'Partner', required: true },
    { key: 'type', label: 'Type', required: false },
    { key: 'target_iban', label: 'Target IBAN', required: false },
    { key: 'source_iban', label: 'Source IBAN', required: false },
    { key: 'category', label: 'Category', required: false },
    { key: 'tags', label: 'Tags', required: false },
    { key: 'notes', label: 'Notes', required: false },
];

// Date formats
const dateFormats = [
    { value: 'd.m.Y', label: 'DD.MM.YYYY' },
    { value: 'Y-m-d', label: 'YYYY-MM-DD' },
    { value: 'm/d/Y', label: 'MM/DD/YYYY' },
    { value: 'Y-m-d H:i:s', label: 'YYYY-MM-DD HH:MM:SS' },
    { value: 'd.m.Y H:i:s', label: 'DD.MM.YYYY HH:MM:SS' },
];

// Amount formats
const amountFormats = [
    { value: '1,234.56', label: '1,234.56' },
    { value: '1.234,56', label: '1.234,56' },
    { value: '1234.56', label: '1234.56' },
    { value: '1234,56', label: '1234,56' },
];

// Amount type strategies
const amountTypeStrategies = [
    { value: 'signed_amount', label: 'Signed amount (negative for expenses, positive for income)' },
    { value: 'income_positive', label: 'Incomes are positive' },
    { value: 'expense_positive', label: 'Expenses are positive' },
];

// Currencies
const currencies = [
    { value: 'EUR', label: 'Euro (€)' },
    { value: 'USD', label: 'US Dollar ($)' },
    { value: 'GBP', label: 'British Pound (£)' },
    { value: 'CZK', label: 'Czech Koruna (Kč)' },
];

/**
 * React component for configuring CSV import mappings for transaction data.
 *
 * Allows users to map CSV columns to transaction fields, select date and amount formats, choose amount type strategies and currency, and optionally save configurations for future use. Supports loading, applying, and deleting saved mappings, and provides a preview of sample CSV data. On successful configuration, invokes the provided callback with the selected settings and preview data.
 *
 * @param headers - Array of CSV column headers.
 * @param sampleRows - Sample rows from the uploaded CSV file.
 * @param importId - Identifier for the current import session.
 * @param onComplete - Callback invoked with the completed configuration and preview data after successful submission.
 */
export default function ConfigureStep({ headers, sampleRows, importId, onComplete }: ConfigureStepProps) {
    const [columnMapping, setColumnMapping] = useState<Record<string, number | null>>({});
    const [dateFormat, setDateFormat] = useState('d.m.Y');
    const [amountFormat, setAmountFormat] = useState('1,234.56');
    const [amountTypeStrategy, setAmountTypeStrategy] = useState('signed_amount');
    const [currency, setCurrency] = useState('EUR');
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const [savedMappings, setSavedMappings] = useState<ImportMapping[]>([]);
    const [selectedMapping, setSelectedMapping] = useState<string>('none');
    const [saveMapping, setSaveMapping] = useState(false);
    const [mappingName, setMappingName] = useState('');
    const [bankName, setBankName] = useState('');
    const [activeTab, setActiveTab] = useState('saved');

    // Load saved mappings on component mount
    useEffect(() => {
        const fetchSavedMappings = async () => {
            try {
                const response = await axios.get(route('imports.mappings.get'));
                setSavedMappings(response.data.mappings || []);
            } catch (err) {
                console.error('Failed to load saved mappings', err);
            }
        };

        fetchSavedMappings();
    }, []);

    const [detectedFormats, setDetectedFormats] = useState<{ date: string | null; amount: string | null }>({ date: null, amount: null });
    const [mappingConfidence, setMappingConfidence] = useState<Record<number, { field: string; confidence: number; signals: Record<string, number> }>>({});

    // Auto-detect column mappings on initial render with enhanced backend logic
    useEffect(() => {
        const autoDetectMapping = async () => {
            try {
                // Prefer wizard auto-detect when we have importId (returns mappings + confidence + detected formats)
                if (importId) {
                    const wizardUrl = route('imports.wizard.auto-detect', { import: importId });
                    const res = await axios.post(wizardUrl);
                    const mappings = res.data.mappings as Record<number, { field: string | null; confidence: number; signals?: Record<string, number> }> | undefined;
                    const formats = res.data.detected_formats as { date?: string | null; amount?: string | null } | undefined;
                    if (formats) {
                        setDetectedFormats({ date: formats.date ?? null, amount: formats.amount ?? null });
                        if (formats.date) setDateFormat(formats.date === 'Y-m-d' ? 'Y-m-d' : formats.date === 'm/d/Y' ? 'm/d/Y' : 'd.m.Y');
                        if (formats.amount) setAmountFormat(formats.amount === 'us' ? '1,234.56' : formats.amount === 'eu' ? '1.234,56' : amountFormat);
                    }
                    if (mappings && typeof mappings === 'object') {
                        const newMapping: Record<string, number | null> = {};
                        transactionFields.forEach((f) => (newMapping[f.key] = null));
                        Object.entries(mappings).forEach(([colStr, data]) => {
                            const col = parseInt(colStr, 10);
                            if (data?.field && data.confidence >= 0.5) {
                                if (transactionFields.some((f) => f.key === data.field)) {
                                    newMapping[data.field] = col;
                                }
                                setMappingConfidence((prev) => ({ ...prev, [col]: { field: data.field!, confidence: data.confidence, signals: data.signals ?? {} } }));
                            }
                        });
                        setColumnMapping(newMapping);
                        return;
                    }
                }
            } catch (e) {
                console.warn('Wizard auto-detect failed:', e);
            }
            try {
                const response = await axios.post('/imports/mappings/auto-detect', {
                    headers: headers,
                });
                const detectedMapping = response.data.mapping;
                if (detectedMapping) {
                    setColumnMapping(detectedMapping);
                    return;
                }
            } catch (error) {
                console.warn('Enhanced auto-detection failed, using fallback:', error);
            }

            // Fallback to local auto-detection logic
            const initialMapping: Record<string, number | null> = {};

            // Initialize all fields as null (not mapped)
            transactionFields.forEach((field) => {
                initialMapping[field.key] = null;
            });

            // Try to automatically map columns based on headers
            headers.forEach((header, index) => {
                const headerLower = header.toLowerCase();

                // Match date fields
                if (headerLower.includes('date') || headerLower.includes('time')) {
                    initialMapping['booked_date'] = index;
                }
                // Match amount fields
                else if (headerLower.includes('amount') || headerLower.includes('sum') || headerLower === 'value' || headerLower.includes('suma')) {
                    initialMapping['amount'] = index;
                }
                // Match description fields
                else if (
                    headerLower.includes('description') ||
                    headerLower.includes('details') ||
                    headerLower.includes('note') ||
                    headerLower.includes('text') ||
                    headerLower.includes('popis')
                ) {
                    initialMapping['description'] = index;
                }
                // Match category fields
                else if (headerLower.includes('category') || headerLower.includes('type')) {
                    initialMapping['category'] = index;
                }
                // Match partner/payee fields
                else if (
                    headerLower.includes('partner') ||
                    headerLower.includes('payee') ||
                    headerLower.includes('recipient') ||
                    headerLower.includes('merchant')
                ) {
                    initialMapping['partner'] = index;
                }
                // Match IBAN fields
                else if (headerLower.includes('iban') || headerLower.includes('account')) {
                    if (headerLower.includes('source') || headerLower.includes('from')) {
                        initialMapping['source_iban'] = index;
                    } else if (headerLower.includes('target') || headerLower.includes('to') || headerLower.includes('destination')) {
                        initialMapping['target_iban'] = index;
                    }
                }
                // Match transaction ID fields
                else if (headerLower.includes('id') || headerLower.includes('reference')) {
                    initialMapping['transaction_id'] = index;
                }
                // Match tag fields
                else if (headerLower.includes('tag') || headerLower.includes('label')) {
                    initialMapping['tags'] = index;
                }
            });

            setColumnMapping(initialMapping);
        };

        if (headers.length > 0) {
            autoDetectMapping();
        }
    }, [headers]);

    // Handle column selection change
    const handleColumnMappingChange = useCallback((field: string, value: string) => {
        setColumnMapping((prev) => ({
            ...prev,
            [field]: value ? parseInt(value) : null,
        }));
    }, []);

    // Handle saved mapping selection
    const handleMappingSelect = useCallback(
        async (mappingId: string) => {
            setSelectedMapping(mappingId);
            setError(null);

            if (mappingId !== 'none') {
                const mapping = savedMappings.find((m) => m.id.toString() === mappingId);
                if (mapping) {
                    try {
                        // Apply the mapping intelligently using the backend service
                        const response = await axios.post('/imports/mappings/apply', {
                            saved_mapping: mapping.column_mapping,
                            current_headers: headers,
                        });

                        const appliedMapping = response.data.applied_mapping;
                        const warnings = response.data.warnings || [];

                        // Set the applied mapping
                        setColumnMapping(appliedMapping);
                        setDateFormat(mapping.date_format);
                        setAmountFormat(mapping.amount_format);
                        setAmountTypeStrategy(mapping.amount_type_strategy);
                        setCurrency(mapping.currency);

                        // Show warnings if any
                        if (warnings.length > 0) {
                            setError(`Mapping applied with warnings: ${warnings.join(', ')}`);
                        }

                        // Update the usage timestamp
                        axios.put(`/imports/mappings/${mapping.id}`).catch((err) => {
                            console.error('Failed to update mapping usage', err);
                        });
                    } catch (err) {
                        console.error('Failed to apply mapping', err);
                        setError('Failed to apply saved mapping. Please configure manually.');

                        // Fallback to converting saved header-based mapping to indices before applying
                        const rawMapping = mapping.column_mapping || ({} as Record<string, unknown>);
                        const convertedMapping: Record<string, number | null> = {};

                        // Ensure we handle values safely by narrowing to expected types
                        Object.entries(rawMapping).forEach(([field, val]) => {
                            const v = val as string | number | null | undefined;

                            if (v === null || v === undefined) {
                                convertedMapping[field] = null;
                                return;
                            }

                            if (typeof v === 'number') {
                                convertedMapping[field] = v;
                                return;
                            }

                            if (typeof v === 'string') {
                                const target = v.trim();

                                // Try exact match
                                let idx = headers.findIndex((h) => h === target);
                                if (idx !== -1) {
                                    convertedMapping[field] = idx;
                                    return;
                                }

                                // Case-insensitive exact
                                idx = headers.findIndex((h) => h.toLowerCase() === target.toLowerCase());
                                if (idx !== -1) {
                                    convertedMapping[field] = idx;
                                    return;
                                }

                                // Partial / fuzzy fallback
                                idx = headers.findIndex(
                                    (h) => h.toLowerCase().includes(target.toLowerCase()) || target.toLowerCase().includes(h.toLowerCase()),
                                );

                                convertedMapping[field] = idx !== -1 ? idx : null;
                                return;
                            }

                            // Unknown type -> null
                            convertedMapping[field] = null;
                        });

                        setColumnMapping(convertedMapping);
                        setDateFormat(mapping.date_format);
                        setAmountFormat(mapping.amount_format);
                        setAmountTypeStrategy(mapping.amount_type_strategy);
                        setCurrency(mapping.currency);
                    }
                }
            }
        },
        [savedMappings, headers],
    );

    // Handle mapping deletion
    const handleDeleteMapping = useCallback(
        async (mappingId: number, e: React.MouseEvent) => {
            e.stopPropagation();

            if (confirm('Are you sure you want to delete this mapping?')) {
                try {
                    await axios.delete(`/imports/mappings/${mappingId}`);
                    setSavedMappings((prev) => prev.filter((m) => m.id !== mappingId));
                    if (selectedMapping === mappingId.toString()) {
                        setSelectedMapping('none');
                    }
                } catch (err) {
                    console.error('Failed to delete mapping', err);
                }
            }
        },
        [selectedMapping],
    );

    // Submit configuration
    const handleSubmit = useCallback(async () => {
        // Validate required fields
        const requiredFields = transactionFields.filter((field) => field.required);
        const missingFields = requiredFields.filter((field) => columnMapping[field.key] === null);

        if (missingFields.length > 0) {
            setError(`Missing required field mappings: ${missingFields.map((f) => f.label).join(', ')}`);
            return;
        }

        setIsLoading(true);
        setError(null);

        try {
            const response = await axios.post(route('imports.wizard.configure', { import: importId }), {
                column_mapping: columnMapping,
                date_format: dateFormat,
                amount_format: amountFormat,
                amount_type_strategy: amountTypeStrategy,
                currency: currency,
            });

            // Save mapping if requested
            if (saveMapping && mappingName) {
                try {
                    await axios.post('/imports/mappings', {
                        name: mappingName,
                        bank_name: bankName,
                        column_mapping: columnMapping,
                        headers: headers, // Include headers for intelligent conversion
                        date_format: dateFormat,
                        amount_format: amountFormat,
                        amount_type_strategy: amountTypeStrategy,
                        currency: currency,
                    });
                } catch (err) {
                    console.error('Failed to save mapping', err);
                }
            }

            onComplete({
                columnMapping,
                dateFormat,
                amountFormat,
                amountTypeStrategy,
                currency,
                previewData: response.data.preview_data || [],
            });
        } catch (err) {
            const axiosError = err as import('axios').AxiosError<{ message: string; errors: Record<string, string[]> } | undefined>;
            if (axiosError.response?.data?.errors) {
                console.error('Configuration error:', axiosError.response.data.errors);
                const firstError = Object.values(axiosError.response.data.errors)[0]?.[0];
                setError(firstError || 'Failed to configure import');
            } else {
                setError(axiosError.response?.data?.message || 'Failed to configure import');
                console.error('Configuration error:', axiosError);
            }
        } finally {
            setIsLoading(false);
        }
    }, [columnMapping, dateFormat, amountFormat, amountTypeStrategy, currency, importId, onComplete, saveMapping, mappingName, bankName]);

    // Helper function to calculate mapping compatibility
    const calculateMappingCompatibility = useCallback((mapping: ImportMapping, currentHeaders: string[]): number => {
        if (!mapping.column_mapping || Object.keys(mapping.column_mapping).length === 0) {
            return 0;
        }

        const mappedFields = Object.entries(mapping.column_mapping).filter(([, value]) => value !== null);
        if (mappedFields.length === 0) {
            return 0;
        }

        let matchCount = 0;
        mappedFields.forEach(([, value]) => {
            if (typeof value === 'number') {
                // Index-based mapping
                if (value >= 0 && value < currentHeaders.length) {
                    matchCount++;
                }
            } else if (typeof value === 'string') {
                // Header-based mapping
                if (currentHeaders.includes(value)) {
                    matchCount++;
                } else {
                    // Check for partial matches
                    const valueStr = String(value);
                    const hasPartialMatch = currentHeaders.some(
                        (header) => header.toLowerCase().includes(valueStr.toLowerCase()) || valueStr.toLowerCase().includes(header.toLowerCase()),
                    );
                    if (hasPartialMatch) {
                        matchCount += 0.7;
                    }
                }
            }
        });

        return mappedFields.length > 0 ? matchCount / mappedFields.length : 0;
    }, []);

    return (
        <div className="mx-auto max-w-4xl">
            <h3 className="text-foreground mb-4 text-xl font-semibold">Configure your import</h3>

            <Tabs value={activeTab} onValueChange={setActiveTab} className="mb-6">
                <TabsList className="mb-4">
                    <TabsTrigger value="saved">Use Saved Mapping</TabsTrigger>
                    <TabsTrigger value="manual">Manual Configuration</TabsTrigger>
                </TabsList>

                <TabsContent value="saved">
                    {savedMappings.length > 0 ? (
                        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                            {savedMappings.map((mapping) => {
                                // Calculate basic compatibility score
                                const compatibilityScore = calculateMappingCompatibility(mapping, headers);
                                const isHighlyCompatible = compatibilityScore >= 0.8;
                                const isPartiallyCompatible = compatibilityScore >= 0.5;

                                return (
                                    <Card
                                        key={mapping.id}
                                        className={`bg-card border-foreground text-foreground cursor-pointer ${selectedMapping === mapping.id.toString() ? 'outline-foreground outline-3' : ''} ${
                                            isHighlyCompatible
                                                ? 'border-green-500 shadow-green-500/20'
                                                : isPartiallyCompatible
                                                  ? 'border-yellow-500 shadow-yellow-500/20'
                                                  : 'border-red-500 shadow-red-500/20'
                                        }`}
                                        onClick={() => handleMappingSelect(mapping.id.toString())}
                                    >
                                        <CardHeader className="flex flex-row items-center justify-between p-4">
                                            <div>
                                                <CardTitle className="flex items-center gap-2 text-lg">
                                                    {mapping.name}
                                                    {isHighlyCompatible && <span className="text-sm text-green-500">✓ Fully Compatible</span>}
                                                    {isPartiallyCompatible && !isHighlyCompatible && (
                                                        <span className="text-sm text-yellow-500">⚠ Partially Compatible</span>
                                                    )}
                                                    {!isPartiallyCompatible && <span className="text-sm text-red-500">⚠ May Need Adjustment</span>}
                                                </CardTitle>
                                                {mapping.bank_name && (
                                                    <CardDescription className="text-muted-foreground">{mapping.bank_name}</CardDescription>
                                                )}
                                            </div>
                                            <button
                                                onClick={(e) => handleDeleteMapping(mapping.id, e)}
                                                className="text-muted-foreground hover:text-destructive-foreground transition"
                                            >
                                                <Trash2 size={16} />
                                            </button>
                                        </CardHeader>
                                        <CardContent className="text-muted-foreground px-4 py-2 text-xs">
                                            <div>
                                                Format: {mapping.date_format}, {mapping.amount_format}
                                            </div>
                                            <div>Currency: {mapping.currency}</div>
                                            <div>Compatibility: {Math.round(compatibilityScore * 100)}%</div>
                                            <div>Last used: {new Date(mapping.last_used_at || '').toLocaleDateString()}</div>
                                        </CardContent>
                                    </Card>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="rounded-md border border-dashed border-gray-700 p-8 text-center">
                            <p className="text-muted-foreground">No saved mappings yet.</p>
                            <p className="text-muted-foreground mt-2">Configure manually and save for future use.</p>
                        </div>
                    )}

                    {selectedMapping !== 'none' && (
                        <div className="mt-6 flex justify-end">
                            <Button onClick={() => setActiveTab('manual')}>Review Selected Mapping</Button>
                        </div>
                    )}
                </TabsContent>

                <TabsContent value="manual">
                    <p className="text-foreground mb-6">Select the columns that correspond to each field in your CSV.</p>

                    {/* Column Mapping */}
                    <div className="text-foreground mb-8 grid grid-cols-3 gap-6">
                        {transactionFields.map((field) => {
                            const colIndex = columnMapping[field.key] ?? null;
                            const confidenceData = colIndex !== null ? mappingConfidence[colIndex] : null;
                            return (
                                <div key={field.key} className="space-y-2">
                                <Label className="flex items-center gap-1">
                                    {field.label}
                                    {field.required && <span className="text-red-500">*</span>}
                                    {confidenceData && confidenceData.field === field.key && (
                                        <ConfidenceBadge confidence={confidenceData.confidence} signals={confidenceData.signals} showDetails />
                                    )}
                                </Label>
                                <Select
                                    value={columnMapping[field.key]?.toString() ?? 'none'}
                                    onValueChange={(value) => handleColumnMappingChange(field.key, value === 'none' ? '' : value)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select column" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">Select column</SelectItem>
                                        {headers.map((header, index) => (
                                            <SelectItem key={index} value={index.toString()}>
                                                {header}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                </div>
                            );
                        })}
                    </div>

                    {/* Date and Amount Format with detected hints */}
                    <div className="text-foreground mb-8">
                        <FormatConfiguration
                            detectedDateFormat={detectedFormats.date}
                            detectedAmountFormat={detectedFormats.amount === 'eu' ? '1.234,56' : detectedFormats.amount === 'us' ? '1,234.56' : detectedFormats.amount}
                            dateFormat={dateFormat}
                            amountFormat={amountFormat}
                            onDateFormatChange={setDateFormat}
                            onAmountFormatChange={setAmountFormat}
                            sampleDateValues={sampleRows.map((r) => r[columnMapping.booked_date ?? -1]?.toString()).filter(Boolean) as string[]}
                            sampleAmountValues={sampleRows.map((r) => r[columnMapping.amount ?? -1]?.toString()).filter(Boolean) as string[]}
                        />
                    </div>

                    {/* Amount Type Strategy and Currency */}
                    <div className="text-foreground mb-8 grid grid-cols-2 gap-6">
                        <div className="space-y-2">
                            <Label>Amount Type Strategy</Label>
                            <Select value={amountTypeStrategy} onValueChange={setAmountTypeStrategy}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select amount type strategy" />
                                </SelectTrigger>
                                <SelectContent>
                                    {amountTypeStrategies.map((strategy) => (
                                        <SelectItem key={strategy.value} value={strategy.value}>
                                            {strategy.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="space-y-2">
                            <Label>Currency</Label>
                            <Select value={currency} onValueChange={setCurrency}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Select currency" />
                                </SelectTrigger>
                                <SelectContent>
                                    {currencies.map((curr) => (
                                        <SelectItem key={curr.value} value={curr.value}>
                                            {curr.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    {/* Save Mapping Option */}
                    <div className="border-muted-foreground bg-card mb-8 rounded-md border p-4">
                        <div className="mb-4 flex items-center justify-between">
                            <div className="flex items-center space-x-2">
                                <Switch id="save-mapping" checked={saveMapping} onCheckedChange={setSaveMapping} />
                                <Label htmlFor="save-mapping" className="text-foreground cursor-pointer">
                                    Save this configuration for future imports
                                </Label>
                            </div>
                            <Save size={16} className="text-foreground" />
                        </div>

                        {saveMapping && (
                            <div className="grid grid-cols-2 gap-4">
                                <div className="space-y-2">
                                    <Label htmlFor="mapping-name">
                                        Mapping Name <span className="text-red-500">*</span>
                                    </Label>
                                    <Input
                                        id="mapping-name"
                                        value={mappingName}
                                        onChange={(e) => setMappingName(e.target.value)}
                                        placeholder="e.g. My Bank Export"
                                        className="border-foreground text-foreground border-1 shadow-xs"
                                    />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="bank-name">Bank Name (optional)</Label>
                                    <Input
                                        id="bank-name"
                                        value={bankName}
                                        onChange={(e) => setBankName(e.target.value)}
                                        placeholder="e.g. Chase Bank"
                                        className="border-foreground text-foreground border-1 shadow-xs"
                                    />
                                </div>
                            </div>
                        )}
                    </div>
                </TabsContent>
            </Tabs>

            {/* Sample Data */}
            <div className="mb-8">
                <h6 className="text-foreground mb-4">Sample data from your uploaded CSV</h6>
                <InteractiveSampleTable
                    headers={headers}
                    rows={sampleRows}
                    columnMappings={columnMapping}
                    suggestedMappings={useMemo(() => {
                        const out: Record<number, { field: string; confidence: number }> = {};
                        Object.entries(mappingConfidence).forEach(([col, data]) => {
                            out[parseInt(col, 10)] = { field: data.field, confidence: data.confidence };
                        });
                        return out;
                    }, [mappingConfidence])}
                />
            </div>

            {error && <div className="mb-6 rounded-md border border-red-800 bg-red-900/20 p-3 text-red-300">{error}</div>}

            <div className="flex justify-end">
                <Button onClick={handleSubmit} disabled={isLoading || (activeTab === 'saved' && selectedMapping === 'none')}>
                    {isLoading ? 'Processing...' : 'Apply configuration'}
                </Button>
            </div>
        </div>
    );
}
