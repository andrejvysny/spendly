import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';

function PageHeader({
    title,
    subtitle,
    buttons = [],
}: {
    title: string;
    subtitle?: string;
    buttons?: {
        onClick: () => void;
        label: string;
        icon?: React.ComponentType<any>;
        disabled?: boolean;
        tooltipContent?: string;
    }[];
}) {
    return (
        <div className="mb-6 flex items-center justify-between">
            <div>
                <h1 className="mb-0 text-2xl font-semibold">{title}</h1>
                {subtitle && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
            </div>
            {buttons.length > 0 && (
                <div>
                    {buttons.map((button, index) => {
                        const Icon = button.icon;
                        const btn = (
                            <Button
                                key={button.label}
                                onClick={button.onClick}
                                disabled={button.disabled}
                                className={index !== buttons.length - 1 ? 'mr-2' : ''}
                            >
                                {Icon && <Icon className="mr-2 h-4 w-4" />}
                                {button.label}
                            </Button>
                        );
                        if (button.disabled && button.tooltipContent) {
                            return (
                                <Tooltip key={button.label}>
                                    <TooltipTrigger asChild>
                                        <span className="inline-block">{btn}</span>
                                    </TooltipTrigger>
                                    <TooltipContent>{button.tooltipContent}</TooltipContent>
                                </Tooltip>
                            );
                        }
                        return btn;
                    })}
                </div>
            )}
        </div>
    );
}

export default PageHeader;
