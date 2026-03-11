import { cn } from '@/lib/utils';

interface BudgetProgressBarProps {
    percentageUsed: number;
    isExceeded: boolean;
    className?: string;
    showLabel?: boolean;
    pacePosition?: number;
}

export function BudgetProgressBar({ percentageUsed, isExceeded, className, showLabel = true, pacePosition }: BudgetProgressBarProps) {
    const barColor = isExceeded ? 'bg-destructive' : percentageUsed >= 80 ? 'bg-yellow-500' : 'bg-primary';

    return (
        <div className={cn('w-32', className)}>
            <div className="bg-muted relative h-2 w-full overflow-hidden rounded-full">
                <div className={`h-full rounded-full ${barColor}`} style={{ width: `${Math.min(100, percentageUsed)}%` }} />
                {pacePosition !== undefined && pacePosition > 0 && pacePosition < 100 && (
                    <div
                        className="absolute top-0 h-full w-0.5 bg-gray-400 dark:bg-gray-500"
                        style={{ left: `${pacePosition}%` }}
                        title={`Day ${Math.round(pacePosition)}% through period`}
                    />
                )}
            </div>
            {showLabel && <p className="text-muted-foreground mt-1 text-xs">{percentageUsed.toFixed(1)}% used</p>}
        </div>
    );
}
