import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Category, Transaction } from '@/types/index';
import { CheckCircle2, FileText, Loader2 } from 'lucide-react';
import { useMemo } from 'react';

interface ConfirmStepProps {
    data: Partial<Transaction>[];
    mappings: Record<string, Record<string, string>>;
    categories: Category[];
    tags?: { id: number; name: string }[];
    merchants?: { id: number; name: string }[];
    onConfirm: () => void;
    isLoading: boolean;
    error: string | null;
    totalRows: number;
}

// Extended transaction data that includes additional fields
interface ExtendedTransactionData extends Omit<Partial<Transaction>, 'merchant' | 'category' | 'tags'> {
    category?: string;
    tag?: string;
    merchant?: string;
    note?: string;
    recipient_note?: string;
    place?: string;
}

type MappingType = 'category' | 'tag' | 'merchant';

export default function ConfirmStep({
    data,
    mappings = { category: {}, tag: {}, merchant: {} },
    categories,
    tags = [],
    merchants = [],
    onConfirm,
    isLoading,
    error,
    totalRows,
}: ConfirmStepProps) {
    // Calculate statistics for the import summary
    const stats = useMemo(() => {
        // Count unique values for each mapping type
        const uniqueValues: Record<MappingType, Set<string>> = {
            category: new Set<string>(),
            tag: new Set<string>(),
            merchant: new Set<string>(),
        };

        data.forEach((item) => {
            const extendedItem = item as ExtendedTransactionData;
            if (extendedItem.category) uniqueValues.category.add(extendedItem.category);
            if (extendedItem.tag) uniqueValues.tag.add(extendedItem.tag);
            if (extendedItem.merchant) uniqueValues.merchant.add(extendedItem.merchant);
        });

        // Count new vs existing for each mapping type
        const mappingStats = Object.entries(mappings || {}).reduce(
            (acc, [type, typeMappings]) => {
                if (!typeMappings) return acc;

                const values = Array.from(uniqueValues[type as MappingType] || new Set());
                acc[type] = {
                    total: values.length,
                    new: values.filter((v) => typeMappings[v] === 'new').length,
                    existing: values.filter((v) => typeMappings[v] !== 'new' && typeMappings[v] !== 'unmapped').length,
                    unmapped: values.filter((v) => typeMappings[v] === 'unmapped').length,
                };
                return acc;
            },
            {} as Record<string, { total: number; new: number; existing: number; unmapped: number }>,
        );

        // Count expenses vs income
        const expenses = data.filter((item) => (item.amount || 0) < 0).length;
        const income = data.filter((item) => (item.amount || 0) >= 0).length;

        return {
            totalRows,
            expenses,
            income,
            mappings: mappingStats,
        };
    }, [data, mappings, totalRows]);

    // Function to render mapping table for a specific type
    const renderMappingTable = (type: MappingType, typeMappings: Record<string, string> = {}, options: { id: number; name: string }[] = []) => {
        const typeTitle = type.charAt(0).toUpperCase() + type.slice(1);
        const mappingEntries = Object.entries(typeMappings || {});

        if (mappingEntries.length === 0) return null;

        return (
            <div className="mt-6">
                <h5 className="text-foreground mb-2 font-medium">{typeTitle} Mappings</h5>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>From</TableHead>
                            <TableHead>To</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {mappingEntries.map(([from, to]) => (
                            <TableRow key={`${type}-${from}`}>
                                <TableCell>{from}</TableCell>
                                <TableCell>
                                    {to === 'new' ? (
                                        <span className="text-green-400">+ Create "{from}"</span>
                                    ) : to === 'unmapped' ? (
                                        <span className="text-foreground">Unmapped</span>
                                    ) : (
                                        options.find((opt) => opt.id.toString() === to)?.name || to
                                    )}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        );
    };

    return (
        <div className="mx-auto max-w-3xl">
            <h3 className="text-foreground mb-4 text-xl font-semibold">Confirm Import</h3>
            <p className="text-muted-foreground mb-6">Please review the summary below and confirm to process the import.</p>

            {/* Error message */}
            {error && <div className="text-destructive-foreground mb-6 rounded-md border border-red-800 bg-red-900/20 p-3">{error}</div>}

            {/* Loading overlay */}
            {isLoading && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm">
                    <div className="bg-card mx-4 flex w-full max-w-md flex-col items-center gap-6 rounded-lg p-8 shadow-md">
                        <div className="flex items-center gap-4">
                            <Loader2 className="text-primary h-8 w-8 animate-spin" />
                            <h3 className="text-foreground text-xl font-semibold">Processing Import</h3>
                        </div>

                        <div className="w-full space-y-4">
                            <div className="text-foreground flex items-center gap-3">
                                <FileText className="h-5 w-5" />
                                <span>Importing {totalRows} transactions...</span>
                            </div>

                            <div className="text-foreground flex items-center gap-3">
                                <CheckCircle2 className="h-5 w-5 text-green-400" />
                                <span>Creating new categories, tags, and merchants...</span>
                            </div>

                            <div className="text-foreground flex items-center gap-3">
                                <CheckCircle2 className="h-5 w-5 text-green-400" />
                                <span>Mapping relationships...</span>
                            </div>
                        </div>

                        <p className="text-muted-foreground mt-2 text-sm">Please wait while we process your import. This may take a few moments.</p>
                    </div>
                </div>
            )}

            {/* Import Summary */}
            <div className={`border-foreground mb-8 rounded-lg border p-6 ${isLoading ? 'pointer-events-none opacity-50' : ''}`}>
                <h4 className="text-foreground mb-4 text-lg font-medium">Import Summary</h4>

                <div className="grid grid-cols-2 gap-4">
                    <div className="space-y-2">
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Total Rows:</span>
                            <span className="text-foreground font-medium">{stats.totalRows}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Expense Transactions:</span>
                            <span className="text-foreground font-medium">{stats.expenses}</span>
                        </div>
                        <div className="flex justify-between">
                            <span className="text-muted-foreground">Income Transactions:</span>
                            <span className="text-foreground font-medium">{stats.income}</span>
                        </div>
                    </div>

                    {Object.entries(stats.mappings).map(([type, typeStats]) => (
                        <div key={type} className="space-y-2">
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Unique {type.charAt(0).toUpperCase() + type.slice(1)}s:</span>
                                <span className="text-foreground font-medium">{typeStats.total}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">New {type}s:</span>
                                <span className="text-foreground font-medium">{typeStats.new}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Mapped to Existing:</span>
                                <span className="text-foreground font-medium">{typeStats.existing}</span>
                            </div>
                            <div className="flex justify-between">
                                <span className="text-muted-foreground">Unmapped:</span>
                                <span className="text-foreground font-medium">{typeStats.unmapped}</span>
                            </div>
                        </div>
                    ))}
                </div>

                {/* Mapping Tables */}
                {renderMappingTable('category', mappings?.category, categories)}
                {renderMappingTable('tag', mappings?.tag, tags)}
                {renderMappingTable('merchant', mappings?.merchant, merchants)}
            </div>

            {/* Sample Records */}
            <div className={`mb-8 rounded-lg border border-1 p-6 ${isLoading ? 'pointer-events-none opacity-50' : ''}`}>
                <h4 className="text-foreground mb-4 text-lg font-medium">Sample Records</h4>
                <div className="overflow-x-auto">
                    <Table className="text-foreground">
                        <TableHeader>
                            <TableRow>
                                <TableHead>Date</TableHead>
                                <TableHead>Amount</TableHead>
                                <TableHead>Description</TableHead>
                                <TableHead>Partner</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Note</TableHead>
                                <TableHead>Recipient Note</TableHead>
                                <TableHead>Place</TableHead>
                                <TableHead>Category</TableHead>
                                <TableHead>Tag</TableHead>
                                <TableHead>Merchant</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.slice(0, 5).map((item, index) => {
                                const extendedItem = item as ExtendedTransactionData;
                                return (
                                    <TableRow key={index}>
                                        <TableCell>{item.booked_date}</TableCell>
                                        <TableCell>{item.amount}</TableCell>
                                        <TableCell>{item.description}</TableCell>
                                        <TableCell>{item.partner}</TableCell>
                                        <TableCell>{item.type}</TableCell>
                                        <TableCell>{item.note}</TableCell>
                                        <TableCell>{item.recipient_note}</TableCell>
                                        <TableCell>{item.place}</TableCell>
                                        <TableCell>{extendedItem.category}</TableCell>
                                        <TableCell>{extendedItem.tag}</TableCell>
                                        <TableCell>{extendedItem.merchant}</TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </div>

            {/* Action Buttons */}
            <div className="flex justify-end">
                <Button onClick={onConfirm} disabled={isLoading} className="min-w-[150px]">
                    {isLoading ? (
                        <>
                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            Processing...
                        </>
                    ) : (
                        'Confirm and Import'
                    )}
                </Button>
            </div>
        </div>
    );
}
