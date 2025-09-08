import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import React from 'react';

export interface DataTableColumn<T> {
    key: string;
    header: React.ReactNode;
    render?: (row: T) => React.ReactNode;
    className?: string;
}

interface DataTableProps<T> {
    columns: DataTableColumn<T>[];
    data: T[];
    rowKey: (row: T) => string | number;
    emptyMessage?: string;
    embedded?: boolean;
}

export function DataTable<T>({ columns, data, rowKey, emptyMessage = 'No data.', embedded = false }: DataTableProps<T>) {
    return (
        <div className="mx-auto w-full max-w-7xl p-4">
            <div className={'bg-card rounded-lg ' + (embedded ? 'p-0' : 'border-1 p-6 shadow-xs')}>
                <div className="overflow-x-auto">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {columns.map((col) => (
                                    <TableHead key={col.key} className={col.className}>
                                        {col.header}
                                    </TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {data.length === 0 ? (
                                <TableRow>
                                    <TableCell colSpan={columns.length} className="text-muted-foreground py-8 text-center">
                                        {emptyMessage}
                                    </TableCell>
                                </TableRow>
                            ) : (
                                data.map((row) => (
                                    <TableRow key={rowKey(row)}>
                                        {columns.map((col) => (
                                            <TableCell key={col.key} className={col.className}>
                                                {col.render ? col.render(row) : (row as Record<string, React.ReactNode>)[col.key]}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </div>
    );
}
