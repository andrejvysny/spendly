import { Badge } from '@/components/ui/badge';
import { Checkbox } from '@/components/ui/checkbox';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { Category, Counterparty, Tag } from '@/types';
import { Fragment } from 'react';
import { CategoryInlineSelect } from './CategoryInlineSelect';
import { CounterpartyInlineSelect } from './CounterpartyInlineSelect';
import { FlagButtons } from './FlagButtons';
import type { AdminTransactionRow, TransactionPatch } from './lib/adminTransactionLabelingTypes';
import { TransactionExpandedPanel } from './TransactionExpandedPanel';

interface TransactionLabelingTableProps {
    rows: AdminTransactionRow[];
    selectedIds: Set<number>;
    expandedIds: Set<number>;
    activeRowId: number | null;
    categories: Category[];
    counterparties: Counterparty[];
    allTags: Tag[];
    onToggleSelect: (id: number) => void;
    onToggleExpand: (id: number) => void;
    onSetActive: (id: number | null) => void;
    onPatch: (id: number, patch: TransactionPatch) => void;
    onAcceptML: (id: number, field: 'category' | 'counterparty') => void;
    onSelectAll: () => void;
    onCreateCategory?: (name: string) => Promise<number | null>;
}

const typeStyles: Record<string, { bg: string; color: string; label: string }> = {
    PAYMENT: { bg: 'bg-red-100', color: 'text-red-800', label: 'Debit' },
    CARD_PAYMENT: { bg: 'bg-red-100', color: 'text-red-800', label: 'Card' },
    DIRECT_DEBIT: { bg: 'bg-red-100', color: 'text-red-800', label: 'Direct' },
    CREDIT: { bg: 'bg-green-100', color: 'text-green-800', label: 'Credit' },
    TRANSFER: { bg: 'bg-blue-100', color: 'text-blue-800', label: 'Transfer' },
};

export function TransactionLabelingTable({
    rows,
    selectedIds,
    expandedIds,
    activeRowId,
    categories,
    counterparties,
    allTags,
    onToggleSelect,
    onToggleExpand,
    onSetActive,
    onPatch,
    onAcceptML,
    onSelectAll,
    onCreateCategory,
}: TransactionLabelingTableProps) {
    const formatAmount = (amount: number, currency: string) => {
        const formatted = new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currency || 'EUR',
        }).format(amount);
        return formatted;
    };

    const formatDate = (dateStr: string) => {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
    };

    const getTypeStyle = (type: string) => {
        return typeStyles[type] || { bg: 'bg-gray-100', color: 'text-gray-800', label: type };
    };

    const allSelected = rows.length > 0 && rows.every((row) => selectedIds.has(row.id));

    return (
        <div className="rounded-md border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead className="w-10">
                            <Checkbox checked={allSelected} onCheckedChange={onSelectAll} />
                        </TableHead>
                        <TableHead className="w-20">Date</TableHead>
                        <TableHead className="w-24">Type</TableHead>
                        <TableHead className="w-28">Account</TableHead>
                        <TableHead>Description / Partner</TableHead>
                        <TableHead className="w-28 text-right">Amount</TableHead>
                        <TableHead className="w-40">Category</TableHead>
                        <TableHead className="w-40">Merchant</TableHead>
                        <TableHead className="w-24">Flags</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row) => {
                        const isSelected = selectedIds.has(row.id);
                        const isExpanded = expandedIds.has(row.id);
                        const isActive = activeRowId === row.id;
                        const typeStyle = getTypeStyle(row.type);

                        return (
                            <Fragment key={row.id}>
                                <TableRow
                                    className={cn(
                                        'cursor-pointer transition-colors',
                                        isSelected && 'bg-primary/5',
                                        isActive && 'bg-muted',
                                        !isSelected && !isActive && 'hover:bg-muted/50',
                                    )}
                                    onClick={() => {
                                        onSetActive(row.id);
                                        onToggleExpand(row.id);
                                    }}
                                >
                                    <TableCell onClick={(e) => e.stopPropagation()}>
                                        <Checkbox checked={isSelected} onCheckedChange={() => onToggleSelect(row.id)} />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground text-sm">{formatDate(row.booked_date)}</TableCell>
                                    <TableCell>
                                        <Badge variant="secondary" className={cn('text-xs', typeStyle.bg, typeStyle.color)}>
                                            {typeStyle.label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <span className="h-2 w-2 rounded-full" style={{ backgroundColor: row.account?.color || '#ccc' }} />
                                            <span className="truncate text-sm">{row.account?.name}</span>
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div className="font-medium">{row.description}</div>
                                        {row.partner && <div className="text-muted-foreground text-sm">Partner: {row.partner}</div>}
                                        {row.similar_group && row.similar_group.count > 1 && (
                                            <Badge
                                                variant="outline"
                                                className="mt-1 cursor-pointer border-amber-200 bg-amber-50 text-xs text-amber-700"
                                                onClick={(e) => {
                                                    e.stopPropagation();
                                                    // Select similar will be handled by parent
                                                }}
                                            >
                                                x{row.similar_group.count} similar
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right font-mono">
                                        <span className={row.amount > 0 ? 'text-green-600' : ''}>{formatAmount(row.amount, row.currency)}</span>
                                    </TableCell>
                                    <TableCell onClick={(e) => e.stopPropagation()}>
                                        <CategoryInlineSelect
                                            value={row.category_id}
                                            suggestion={row.ml.category}
                                            categories={categories}
                                            onChange={(value) => onPatch(row.id, { category_id: value })}
                                            onCreate={onCreateCategory}
                                        />
                                    </TableCell>
                                    <TableCell onClick={(e) => e.stopPropagation()}>
                                        <CounterpartyInlineSelect
                                            value={row.counterparty_id}
                                            suggestion={row.ml.counterparty}
                                            counterparties={counterparties}
                                            onChange={(value) => onPatch(row.id, { counterparty_id: value })}
                                        />
                                    </TableCell>
                                    <TableCell onClick={(e) => e.stopPropagation()}>
                                        <FlagButtons
                                            flags={row.flags}
                                            onToggle={(flag) => {
                                                onPatch(row.id, { [flag]: !row.flags[flag] });
                                            }}
                                        />
                                    </TableCell>
                                </TableRow>
                                {isExpanded && (
                                    <TableRow className="bg-muted/30">
                                        <TableCell colSpan={9} className="p-0">
                                            <TransactionExpandedPanel
                                                row={row}
                                                allTags={allTags}
                                                onPatch={(patch) => onPatch(row.id, patch)}
                                                onAcceptML={(field) => onAcceptML(row.id, field)}
                                            />
                                        </TableCell>
                                    </TableRow>
                                )}
                            </Fragment>
                        );
                    })}
                </TableBody>
            </Table>
        </div>
    );
}
