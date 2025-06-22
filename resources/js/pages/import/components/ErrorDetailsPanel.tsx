import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ErrorTypeBadge } from '@/pages/import/components/Badges';
import { ImportFailure } from '@/types/index';
import { AlertTriangle, Copy, Info, XCircle } from 'lucide-react';

export const getErrorTypeIcon = (errorType: string) => {
    switch (errorType) {
        case 'validation_failed':
            return <AlertTriangle className="h-6 w-6 text-red-500" />;
        case 'duplicate':
            return <Copy className="h-6 w-6 text-yellow-500" />;
        case 'processing_error':
            return <XCircle className="h-6 w-6 text-orange-500" />;
        case 'parsing_error':
            return <Info className="h-6 w-6 text-purple-500" />;
        default:
            return <AlertTriangle className="h-6 w-6 text-gray-500" />;
    }
};

function ErrorDetailsPanel({ failure }: { failure: ImportFailure }) {
    const getSuggestions = (failure: ImportFailure): string[] => {
        const suggestions: string[] = [];

        switch (failure.error_type) {
            case 'validation_failed':
                suggestions.push('Check required fields are filled');
                suggestions.push('Verify date and amount formats');
                suggestions.push('Ensure partner information is complete');
                break;
            case 'duplicate':
                suggestions.push('Review if this is a legitimate duplicate');
                suggestions.push('Check transaction ID and amount');
                suggestions.push('Consider marking as ignored if acceptable');
                break;
            case 'processing_error':
                suggestions.push('Check data format consistency');
                suggestions.push('Verify field mappings are correct');
                break;
            case 'parsing_error':
                suggestions.push('Check CSV delimiter and encoding');
                suggestions.push('Verify field structure matches headers');
                break;
        }

        return suggestions;
    };

    return (
        <Card className="mb-6">
            <CardHeader>
                <CardTitle className="text-base">
                    <div className="flex items-center space-x-2">
                        <div>Error Details</div>
                        <div className="flex items-center space-x-2">
                            {getErrorTypeIcon(failure.error_type)}

                            <ErrorTypeBadge errorType={failure.error_type} />
                        </div>
                    </div>
                </CardTitle>
            </CardHeader>
            <CardContent>
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <div>
                        <div className="space-y-4">
                            {failure.error_message}

                            {failure.error_details?.errors && failure.error_details.errors.length > 0 && (
                                <div>
                                    <h4 className="mb-2 text-sm font-medium">Detailed Errors:</h4>
                                    <ul className="space-y-1 text-sm">
                                        {failure.error_details.errors.map((error, index) => (
                                            <li key={index} className="text-red-600">
                                                • {error}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}

                            {failure.error_details?.duplicate_fingerprint && (
                                <div>
                                    <h4 className="mb-1 text-sm font-medium">Duplicate Info:</h4>
                                    <p className="font-mono text-xs text-gray-500">{failure.error_details.duplicate_fingerprint}</p>
                                </div>
                            )}
                        </div>
                    </div>
                    <div>
                        <div>
                            <h4 className="mb-2 text-sm font-medium">Suggestions:</h4>
                            <ul className="space-y-1 text-sm">
                                {getSuggestions(failure).map((suggestion, index) => (
                                    <li key={index} className="text-gray-600">
                                        • {suggestion}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
export default ErrorDetailsPanel;
