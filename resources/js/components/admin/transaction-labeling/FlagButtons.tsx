import { cn } from '@/lib/utils';
import type { Flags } from './lib/adminTransactionLabelingTypes';

interface FlagButtonsProps {
    flags: Flags;
    onToggle: (flag: keyof Flags) => void;
    size?: 'sm' | 'md';
}

const flagConfig = {
    is_recurring: { label: 'R', title: 'Recurring', color: 'text-blue-600 border-blue-600 bg-blue-50' },
    is_uncertain: { label: '?', title: 'Uncertain', color: 'text-red-600 border-red-600 bg-red-50' },
    is_duplicate: { label: 'D', title: 'Duplicate', color: 'text-amber-600 border-amber-600 bg-amber-50' },
};

export function FlagButtons({ flags, onToggle, size = 'sm' }: FlagButtonsProps) {
    const sizeClasses = size === 'sm' ? 'w-6 h-6 text-xs' : 'w-8 h-8 text-sm';

    return (
        <div className="flex items-center gap-1">
            {(Object.keys(flagConfig) as Array<keyof typeof flagConfig>).map((key) => {
                const config = flagConfig[key];
                const isActive = flags[key];

                return (
                    <button
                        key={key}
                        title={config.title}
                        onClick={() => onToggle(key)}
                        className={cn(
                            sizeClasses,
                            'flex items-center justify-center rounded border font-semibold transition-colors',
                            isActive ? config.color : 'text-muted-foreground border-border hover:bg-muted bg-transparent',
                        )}
                    >
                        {config.label}
                    </button>
                );
            })}
        </div>
    );
}
