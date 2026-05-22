import { cn } from '@/lib/utils';

interface StatusFilterPillsProps {
    value: string;
    onChange: (value: string) => void;
}

const options = [
    { value: 'unlabeled', label: 'Unlabeled' },
    { value: 'all', label: 'All' },
    { value: 'labeled', label: 'Labeled' },
    { value: 'flagged', label: 'Flagged' },
    { value: 'duplicates', label: 'Duplicates' },
];

export function StatusFilterPills({ value, onChange }: StatusFilterPillsProps) {
    return (
        <div className="flex items-center gap-1">
            {options.map((option) => (
                <button
                    key={option.value}
                    onClick={() => onChange(option.value)}
                    className={cn(
                        'rounded-full border px-3 py-1.5 text-sm font-medium transition-colors',
                        value === option.value
                            ? 'bg-primary/10 border-primary/50 text-primary'
                            : 'border-border text-muted-foreground hover:bg-muted bg-transparent',
                    )}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}
