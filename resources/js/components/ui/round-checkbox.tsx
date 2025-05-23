interface RoundCheckboxProps {
    checked: boolean;
    onChange: (checked: boolean) => void;
    className?: string;
}

export function RoundCheckbox({ checked, onChange, className = '' }: RoundCheckboxProps) {
    return (
        <div 
            className={`relative flex h-5 w-5 cursor-pointer items-center justify-center rounded-full border 
                ${checked 
                    ? 'border-primary bg-primary' 
                    : 'border-primary/50 bg-background hover:border-primary'} 
                ${className}`}
            onClick={(e) => { 
                e.stopPropagation(); 
                onChange(!checked); 
            }}
        >
            {checked && (
                <svg 
                    xmlns="http://www.w3.org/2000/svg" 
                    width="12" 
                    height="12" 
                    viewBox="0 0 24 24" 
                    fill="none" 
                    stroke="currentColor" 
                    strokeWidth="3" 
                    strokeLinecap="round" 
                    strokeLinejoin="round" 
                    className="text-primary-foreground"
                >
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            )}
        </div>
    );
} 