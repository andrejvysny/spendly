import { Button } from '@/components/ui/button';
import axios from 'axios';
import { ChevronLeft, ChevronRight, Loader2 } from 'lucide-react';
import { useEffect, useState } from 'react';

interface CleanStepProps {
    importId: number;
    onComplete: () => void;
}

interface ColumnStat {
    value: string;
    count: number;
}

export default function CleanStep({ importId, onComplete }: CleanStepProps) {
    const [rows, setRows] = useState<any[]>([]);
    const [loading, setLoading] = useState(true);
    const [page, setPage] = useState(0);
    const [limit] = useState(50);

    const [columnStats, setColumnStats] = useState<{ column: string; stats: ColumnStat[] } | null>(null);
    const [statsLoading, setStatsLoading] = useState(false);

    const fetchRows = async () => {
        setLoading(true);
        try {
            const response = await axios.get(route('imports.wizard.rows', { import: importId }), {
                params: {
                    limit,
                    offset: page * limit,
                },
            });
            setRows(response.data.rows);
        } catch (error) {
            console.error('Failed to load rows', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchRows();
    }, [page, limit, importId]);

    const handleCellEdit = async (rowIndex: number, column: string, value: string) => {
        // Optimistic update
        const updatedRows = [...rows];
        updatedRows[rowIndex] = { ...updatedRows[rowIndex], [column]: value };
        setRows(updatedRows);

        const rowNumber = updatedRows[rowIndex]._row_number;

        try {
            await axios.patch(route('imports.wizard.rows.update', { import: importId, row: rowNumber }), {
                [column]: value,
            });
        } catch (error) {
            console.error('Failed to update row', error);
        }
    };

    const handleColumnHeaderClick = async (column: string) => {
        if (column.startsWith('_')) return;

        setStatsLoading(true);
        setColumnStats({ column, stats: [] }); // Open modal/view

        try {
            const response = await axios.get(route('imports.wizard.columns.stats', { import: importId, column }));
            setColumnStats({ column, stats: response.data.stats });
        } catch (error) {
            console.error('Failed to load stats', error);
        } finally {
            setStatsLoading(false);
        }
    };

    return (
        <div className="mx-auto flex h-full max-w-6xl flex-col">
            <div className="mb-4 flex flex-shrink-0 justify-between">
                <div>
                    <h3 className="text-foreground text-xl font-semibold">Clean your data</h3>
                    <p className="text-foreground">
                        Review and edit your data. Edits are saved automatically. Click headers to see value distribution.
                    </p>
                </div>
                <div className="flex gap-2">
                    <Button
                        variant="outline"
                        onClick={() => setPage((p) => Math.max(0, p - 1))}
                        disabled={page === 0 || loading}
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </Button>
                    <span className="flex items-center px-2">Page {page + 1}</span>
                    <Button
                        variant="outline"
                        onClick={() => setPage((p) => p + 1)}
                        disabled={rows.length < limit || loading}
                    >
                        <ChevronRight className="h-4 w-4" />
                    </Button>
                </div>
            </div>

            <div className="border-foreground flex-1 overflow-auto rounded-lg border">
                {loading ? (
                    <div className="flex h-full items-center justify-center">
                        <Loader2 className="h-8 w-8 animate-spin" />
                    </div>
                ) : (
                    <table className="w-full relative">
                        <thead className="bg-foreground border-foreground sticky top-0 border-b">
                            <tr>
                                {rows.length > 0 &&
                                    Object.keys(rows[0])
                                        .filter((key) => !key.startsWith('_'))
                                        .map((key) => (
                                            <th
                                                key={key}
                                                className="hover:bg-muted cursor-pointer px-4 py-2 text-left transition-colors"
                                                onClick={() => handleColumnHeaderClick(key)}
                                                title="Click to see value distribution"
                                            >
                                                {key} <span className="text-xs opacity-50">▼</span>
                                            </th>
                                        ))}
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row, rowIndex) => (
                                <tr key={rowIndex} className="border-muted-foreground border-b last:border-0 hover:bg-muted/50">
                                    {Object.entries(row)
                                        .filter(([key]) => !key.startsWith('_'))
                                        .map(([key, value]) => (
                                            <td key={key} className="p-0">
                                                <input
                                                    className="w-full bg-transparent px-4 py-2 outline-none focus:bg-background focus:ring-1 focus:ring-inset"
                                                    value={value as string}
                                                    onChange={(e) => handleCellEdit(rowIndex, key, e.target.value)}
                                                />
                                            </td>
                                        ))}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>

            <div className="mt-4 flex flex-shrink-0 justify-between">
                <div>
                    {/* Column Stats Popover/Display */}
                    {columnStats && (
                        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50" onClick={() => setColumnStats(null)}>
                            <div className="bg-background max-h-[80vh] w-96 overflow-auto rounded-lg p-6 shadow-lg" onClick={e => e.stopPropagation()}>
                                <h4 className="mb-4 text-lg font-bold">Values in '{columnStats.column}'</h4>
                                {statsLoading ? (
                                    <Loader2 className="mx-auto animate-spin" />
                                ) : (
                                    <div className="space-y-2">
                                        {columnStats.stats.map((stat, i) => (
                                            <div key={i} className="flex justify-between border-b border-gray-100 py-1 last:border-0">
                                                <span className="truncate pr-4" title={stat.value}>{stat.value || '(Active Empty)'}</span>
                                                <span className="text-muted-foreground font-mono">{stat.count}</span>
                                            </div>
                                        ))}
                                        {columnStats.stats.length === 0 && <p>No values found.</p>}
                                    </div>
                                )}
                                <div className="mt-6 flex justify-end">
                                    <Button onClick={() => setColumnStats(null)}>Close</Button>
                                </div>
                            </div>
                        </div>
                    )}
                </div>
                <Button onClick={onComplete}>Continue to Category Mapping</Button>
            </div>
        </div>
    );
}
