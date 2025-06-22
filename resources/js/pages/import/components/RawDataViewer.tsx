import { Button } from '@/components/ui/button';
import { Copy } from 'lucide-react';
import { toast } from 'react-toastify';

function RawDataViewer({ headers, data, highlightedFields }: { headers: string[]; data: any[]; highlightedFields: Set<string> }) {
    const copyToClipboard = (text: string) => {
        navigator.clipboard.writeText(text);
        toast.success('Copied to clipboard');
    };

    return (
        <div className="space-y-2">
            {headers.map((header, index) => {
                const value = data[index];
                const isHighlighted = highlightedFields.has(header.toLowerCase());

                return (
                    <div key={index} className={`bg-card rounded border p-2 ${isHighlighted ? 'border-blue-600' : 'border-none'}`}>
                        <div className="flex items-start justify-between">
                            <div className="flex-1">
                                <div className="text-foreground mb-1 text-xs font-medium">
                                    {header}
                                    {isHighlighted && <span className="ml-1 text-blue-600">●</span>}
                                </div>
                                <div className="text-muted-foreground text-sm break-all">{value || '-'}</div>
                            </div>
                            {value && (
                                <Button variant="ghost" size="sm" onClick={() => copyToClipboard(value.toString())} className="h-6 w-6 p-0">
                                    <Copy className="h-3 w-3" />
                                </Button>
                            )}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

export default RawDataViewer;
