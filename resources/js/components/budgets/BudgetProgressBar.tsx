import { cn } from '@/lib/utils';

interface BudgetProgressBarProps {
    percentageUsed: number;
    isExceeded: boolean;
    className?: string;
    showLabel?: boolean;
}

export function BudgetProgressBar({ percentageUsed, isExceeded, className, showLabel = true }: BudgetProgressBarProps) {
    const barColor = isExceeded ? 'bg-destructive' : percentageUsed >= 80 ? 'bg-yellow-500' : 'bg-primary';

    return (
        <div className={cn('w-32', className)}>
            <div className="bg-muted h-2 w-full overflow-hidden rounded-full">
                <div className={`h-full rounded-full ${barColor}`} style={{ width: `${Math.min(100, percentageUsed)}%` }} />
            </div>
            {showLabel && <p className="text-muted-foreground mt-1 text-xs">{percentageUsed.toFixed(1)}% used</p>}
        </div>
    );
}
