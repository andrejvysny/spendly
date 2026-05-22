import { cn } from '@/lib/utils';

interface TypeFilterPillsProps {
    value: string;
    onChange: (value: string) => void;
}

const options = [
    { value: 'debit', label: 'Debit' },
    { value: 'transfer', label: 'Transfer' },
    { value: 'credit', label: 'Credit' },
];

export function TypeFilterPills({ value, onChange }: TypeFilterPillsProps) {
    return (
        <div className="flex items-center gap-1">
            {options.map((option) => (
                <button
                    key={option.value}
                    onClick={() => onChange(value === option.value ? 'all' : option.value)}
                    className={cn(
                        'rounded-full border px-3 py-1.5 text-sm font-medium capitalize transition-colors',
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
