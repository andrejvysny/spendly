import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Transaction } from '@/types/index';
import { useCallback, useEffect, useState } from 'react';

// Define a type that includes additional properties not in the original Transaction type
interface ImportedData extends Partial<Transaction> {
    category?: string;
    tag?: string;
    merchant?: string;
    [key: string]: any;
}

interface MapStepProps {
    data: Partial<Transaction>[];
    categories: { id: number; name: string }[];
    tags?: { id: number; name: string }[];
    merchants?: { id: number; name: string }[];
    onComplete: (mappings: Record<string, Record<string, string>>) => void;
}

type MappingType = 'category' | 'tag' | 'merchant';

const previewFields = [
    { key: 'booked_date', label: 'Booked Date' },
    { key: 'processed_date', label: 'Processed Date' },
    { key: 'amount', label: 'Amount' },
    { key: 'currency', label: 'Currency' },
    { key: 'description', label: 'Description' },
    { key: 'partner', label: 'Partner' },
    { key: 'target_iban', label: 'Target IBAN' },
    { key: 'source_iban', label: 'Source IBAN' },
    { key: 'type', label: 'Type' },
    { key: 'note', label: 'Note' },
    { key: 'recipient_note', label: 'Recipient Note' },
    { key: 'place', label: 'Place' },
];

export default function MapStep({ data, categories, tags = [], merchants = [], onComplete }: MapStepProps) {
    const [mappings, setMappings] = useState<Record<MappingType, Record<string, string>>>({
        category: {},
        tag: {},
        merchant: {},
    });

    const [uniqueValues, setUniqueValues] = useState<Record<MappingType, string[]>>({
        category: [],
        tag: [],
        merchant: [],
    });

    // Extract unique values for each mapping type from the data
    useEffect(() => {
        const values: Record<MappingType, Set<string>> = {
            category: new Set<string>(),
            tag: new Set<string>(),
            merchant: new Set<string>(),
        };

        data.forEach((item) => {
            const importedData = item as ImportedData;

            // Extract categories
            if (importedData.category && typeof importedData.category === 'string' && importedData.category.trim()) {
                values.category.add(importedData.category.trim());
            } else if (importedData.type && typeof importedData.type === 'string' && importedData.type.trim()) {
                values.category.add(importedData.type.trim());
            }

            // Extract tags
            if (importedData.tag && typeof importedData.tag === 'string' && importedData.tag.trim()) {
                values.tag.add(importedData.tag.trim());
            }

            // Extract merchants
            if (importedData.merchant && typeof importedData.merchant === 'string' && importedData.merchant.trim()) {
                values.merchant.add(importedData.merchant.trim());
            } else if (importedData.partner && typeof importedData.partner === 'string' && importedData.partner.trim()) {
                values.merchant.add(importedData.partner.trim());
            }
        });

        setUniqueValues({
            category: Array.from(values.category).sort(),
            tag: Array.from(values.tag).sort(),
            merchant: Array.from(values.merchant).sort(),
        });
    }, [data]);

    // Handle mapping change
    const handleMappingChange = useCallback((type: MappingType, sourceValue: string, targetId: string) => {
        setMappings((prev) => ({
            ...prev,
            [type]: {
                ...prev[type],
                [sourceValue]: targetId,
            },
        }));
    }, []);

    // Handle completion
    const handleComplete = useCallback(() => {
        onComplete(mappings);
    }, [mappings, onComplete]);

    // Function to render mapping table for a specific type
    const renderMappingTable = (type: MappingType, values: string[], options: { id: number; name: string }[]) => {
        if (values.length === 0) return null;

        const typeTitle = type.charAt(0).toUpperCase() + type.slice(1);

        return (
            <div className="text-foreground mb-8">
                <h4 className="text-foreground mb-3 text-lg font-medium">Map {typeTitle}s</h4>
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="text-foreground w-1/3">Source {typeTitle}</TableHead>
                            <TableHead className="text-foreground w-2/3">Target {typeTitle}</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {values.map((value) => (
                            <TableRow key={`${type}-${value}`}>
                                <TableCell className="text-muted-foreground font-medium">{value}</TableCell>
                                <TableCell>
                                    <Select
                                        value={mappings[type][value] || 'unmapped'}
                                        onValueChange={(targetId) => handleMappingChange(type, value, targetId)}
                                    >
                                        <SelectTrigger>
                                            <SelectValue placeholder={`Select a ${type}`} />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="unmapped">Leave unmapped</SelectItem>
                                            <SelectItem value="new">Create new</SelectItem>
                                            {options.map((option) => (
                                                <SelectItem key={`${type}-${option.id}`} value={option.id.toString()}>
                                                    {option.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        );
    };

    return (
        <div className="text-foreground mx-auto max-w-4xl">
            <h3 className="mb-4 text-xl font-semibold">Map Imported Fields</h3>
            <p className="mb-6">Map the values from your import file to your existing categories, tags, and merchants.</p>

            {renderMappingTable('category', uniqueValues.category, categories)}
            {renderMappingTable('tag', uniqueValues.tag, tags)}
            {renderMappingTable('merchant', uniqueValues.merchant, merchants)}

            {Object.values(uniqueValues).every((arr) => arr.length === 0) && (
                <div className="mb-8 rounded-md border border-dashed border-gray-700 p-8 text-center">
                    <p className="text-gray-400">No mappable values found in imported data.</p>
                </div>
            )}

            <div className="flex justify-end">
                <Button onClick={handleComplete}>Continue to Confirmation</Button>
            </div>
        </div>
    );
}
