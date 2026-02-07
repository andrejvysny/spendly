import { cn } from '@/lib/utils';
import React from 'react';

export interface InteractiveSampleTableProps {
    headers: string[];
    rows: (string | null)[][];
    columnMappings: Record<string, number | null>;
    suggestedMappings?: Record<number, { field: string; confidence: number }>;
    onColumnClick?: (columnIndex: number) => void;
    validationIndicators?: Record<string, boolean>;
    className?: string;
}

export function InteractiveSampleTable({
    headers,
    rows,
    columnMappings,
    suggestedMappings = {},
    onColumnClick,
    validationIndicators = {},
    className,
}: InteractiveSampleTableProps) {
    const getMappedField = (colIndex: number): string | null => {
        const entry = Object.entries(columnMappings).find(([, idx]) => idx === colIndex);
        return entry ? entry[0] : null;
    };

    const getStatus = (colIndex: number): 'mapped' | 'suggested' | 'unmapped' => {
        if (getMappedField(colIndex)) return 'mapped';
        if (suggestedMappings[colIndex]) return 'suggested';
        return 'unmapped';
    };

    return (
        <div className={cn('overflow-x-auto rounded-md border', className)}>
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b bg-muted/50">
                        {headers.map((header, colIndex) => {
                            const status = getStatus(colIndex);
                            const mappedField = getMappedField(colIndex);
                            const suggested = suggestedMappings[colIndex];
                            return (
                                <th
                                    key={colIndex}
                                    className={cn(
                                        'cursor-pointer px-3 py-2 text-left font-medium transition-colors hover:bg-muted',
                                        status === 'mapped' && 'bg-emerald-50',
                                        status === 'suggested' && 'bg-amber-50',
                                        status === 'unmapped' && 'bg-muted/30',
                                    )}
                                    onClick={() => onColumnClick?.(colIndex)}
                                    title={mappedField ? `Mapped to ${mappedField}` : suggested ? `Suggested: ${suggested.field}` : 'Click to map'}
                                >
                                    <span className="block truncate">{header}</span>
                                    {mappedField && (
                                        <span className="mt-0.5 block text-xs text-muted-foreground">{mappedField}</span>
                                    )}
                                </th>
                            );
                        })}
                    </tr>
                </thead>
                <tbody>
                    {rows.slice(0, 10).map((row, rowIndex) => (
                        <tr key={rowIndex} className="border-b last:border-0">
                            {row.map((cell, colIndex) => (
                                <td
                                    key={colIndex}
                                    className={cn(
                                        'px-3 py-1.5',
                                        validationIndicators[`${rowIndex}-${colIndex}`] === false && 'bg-destructive/10',
                                    )}
                                >
                                    <span className="truncate block max-w-[200px]" title={cell ?? ''}>
                                        {cell ?? '—'}
                                    </span>
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
