import { Button } from '@/components/ui/button';
import { Copy } from 'lucide-react';
import { toast } from 'react-toastify';

function RawDataViewer({ headers, data, highlightedFields }: { headers: string[]; data: unknown[]; highlightedFields: Set<string> }) {
    const copyToClipboard = (text: string) => {
        navigator.clipboard.writeText(text);
        toast.success('Copied to clipboard');
    };

    // Debug: Log highlighted fields
    console.log('🎨 RawDataViewer highlighted fields:', Array.from(highlightedFields));
    console.log('📊 RawDataViewer headers:', headers);

    return (
        <div className="space-y-2">
            {headers.map((header, index) => {
                const value = data[index];
                const isHighlighted = highlightedFields.has(header.toLowerCase()) || highlightedFields.has(header);

                // Debug: Log each field's highlight status
                if (isHighlighted) {
                    console.log(`✅ Field "${header}" is highlighted`);
                }

                return (
                    <div
                        key={index}
                        className={`rounded-xl border px-3 py-2 transition-all ${
                            isHighlighted ? 'border-blue-500 shadow-sm' : 'border-border bg-card'
                        }`}
                    >
                        <div className="flex items-start justify-between">
                            <div className="flex-1">
                                <div className="mb-1 flex items-center gap-2 text-xs font-medium">
                                    <span className={isHighlighted ? 'text-blue-500' : 'text-muted-foreground'}>{header}</span>
                                    {isHighlighted && (
                                        <span className="inline-flex items-center gap-1 rounded-full px-2 text-xs font-medium text-blue-500">
                                            <span className="h-2 w-2 rounded-full bg-blue-500"></span>
                                            auto-mapped
                                        </span>
                                    )}
                                </div>
                                <div className={`text-sm break-all ${isHighlighted ? 'text-foreground font-medium' : 'text-muted-foreground'}`}>
                                    {value != null && value !== '' ? String(value) : '-'}
                                </div>
                            </div>
                            {value != null && value !== '' && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() => copyToClipboard(String(value))}
                                    className="hover:bg-accent h-6 w-6 p-0"
                                >
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
