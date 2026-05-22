import { Card, CardContent } from '@/components/ui/card';
import type { LabelingStats } from './lib/adminTransactionLabelingTypes';

interface LabelingStatsBarProps {
    stats: LabelingStats | null;
}

export function LabelingStatsBar({ stats }: LabelingStatsBarProps) {
    if (!stats) return null;

    const cards = [
        { label: 'Total', value: stats.total, color: 'text-foreground' },
        { label: 'Labeled', value: stats.labeled, color: 'text-green-600' },
        { label: 'Unlabeled', value: stats.unlabeled, color: 'text-amber-600' },
        { label: 'Flagged', value: stats.flagged, color: 'text-red-600' },
    ];

    return (
        <div className="grid grid-cols-5 gap-4">
            {cards.map(({ label, value, color }) => (
                <Card key={label}>
                    <CardContent className="p-4">
                        <div className={`text-3xl font-bold ${color}`}>{value}</div>
                        <div className="text-muted-foreground text-sm">{label}</div>
                    </CardContent>
                </Card>
            ))}

            <Card>
                <CardContent className="p-4">
                    <div className="space-y-2">
                        <div className="flex items-center justify-between">
                            <span className="text-2xl font-bold">{stats.progress_percent}%</span>
                        </div>
                        <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                            <div className="h-full bg-green-500 transition-all duration-300" style={{ width: `${stats.progress_percent}%` }} />
                        </div>
                        <div className="text-muted-foreground text-sm">
                            {stats.labeled} of {stats.total} labeled
                        </div>
                    </div>
                </CardContent>
            </Card>
        </div>
    );
}
