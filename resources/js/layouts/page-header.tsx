import { Button } from '@/components/ui/button';

function PageHeader({ 
    title, 
    subtitle, 
    buttons = [] 
}: { 
    title: string; 
    subtitle?: string;
    buttons?: { onClick: () => void; label: string; icon?: React.ComponentType<any> }[] 
}) {
    return (
        <div className="flex items-center justify-between mb-6">
            <div>
                <h1 className="mb-0 text-2xl font-semibold">{title}</h1>
                {subtitle && (
                    <p className="text-sm text-gray-600 mt-1">{subtitle}</p>
                )}
            </div>
            {buttons.length > 0 && (
                <div>
                    {buttons.map((button, index) => {
                        const Icon = button.icon;
                        return (
                            <Button 
                                key={button.label} 
                                onClick={button.onClick} 
                                className={index !== buttons.length - 1 ? 'mr-2' : ''}
                            >
                                {Icon && <Icon className="h-4 w-4 mr-2" />}
                                {button.label}
                            </Button>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

export default PageHeader;
