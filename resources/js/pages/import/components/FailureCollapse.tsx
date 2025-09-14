import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { ErrorTypeBadge, StatusBadge } from '@/pages/import/components/Badges';
import { formatDate } from '@/utils/date';
import { ChevronDown, ChevronRight, RotateCcw } from 'lucide-react';
import { useState } from 'react';

interface FailureCollapseProps {
    failure: any;
    selectedFailures: number[];
    handleSelectFailure: (id: number, checked: boolean) => void;
    handleUnmarkAsPending?: (failureId: number, notes?: string) => void;
    isMarkingReviewed?: boolean;
}

function FailureCollapse({ failure, selectedFailures, handleSelectFailure, handleUnmarkAsPending, isMarkingReviewed }: FailureCollapseProps) {
    const [expandedFailure, setExpandedFailure] = useState(false);

    const canUnmark = failure.status !== 'pending' && handleUnmarkAsPending;

    return (
        <div>
            <Card key={failure.id} className="hover:border-muted-foreground overflow-hidden">
                <CardHeader className="cursor-pointer pb-3" onClick={() => setExpandedFailure(expandedFailure === failure.id ? null : failure.id)}>
                    <div className="flex items-center justify-between">
                        <div className="flex items-center space-x-3">
                            <Checkbox
                                checked={selectedFailures.includes(failure.id)}
                                onCheckedChange={(checked) => handleSelectFailure(failure.id, checked as boolean)}
                            />

                            {expandedFailure === failure.id ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                            <div>
                                <div className="flex items-center space-x-2">
                                    <span className="font-medium">Row {failure.row_number}</span>
                                    <ErrorTypeBadge errorType={failure.error_type} />
                                    <StatusBadge status={failure.status} />
                                </div>

                                <p className="text-foreground mt-1 text-xs">
                                    Created {formatDate(failure.created_at)} {failure.reviewed_at && ` • Reviewed ${formatDate(failure.reviewed_at)}`}
                                </p>
                            </div>
                        </div>

                        {/* Quick action button for reviewed/ignored/resolved failures */}
                        {canUnmark && (
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={(e) => {
                                    e.stopPropagation(); // Prevent collapse toggle
                                    handleUnmarkAsPending(failure.id, `Unmarked from ${failure.status} status`);
                                }}
                                disabled={isMarkingReviewed}
                                className="ml-2"
                            >
                                <RotateCcw className="mr-1 h-3 w-3" />
                                Unmark
                            </Button>
                        )}
                    </div>
                </CardHeader>

                {expandedFailure === failure.id && (
                    <CardContent className="bg-card border-t pt-0">
                        <div className="grid grid-cols-1 gap-6 md:grid-cols-2">
                            <div>
                                <h4 className="mb-2 font-medium">Raw CSV Data</h4>
                                <div className="bg-card rounded border p-3 text-sm">
                                    {failure.metadata.headers && (
                                        <div className="space-y-1">
                                            {failure.metadata.headers.map((header: string, index: number) => (
                                                <div key={index} className="botder-b border-muted-foreground flex justify-between border-dashed py-1">
                                                    <span className="text-muted-foreground font-medium">{header}:</span>
                                                    <span className="text-foreground">{failure.raw_data[index] || '-'}</span>
                                                </div>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            </div>

                            <div>
                                <h4 className="mb-2 font-medium">Error Details</h4>
                                <div className="rounded border border-red-200 bg-red-50 p-3 text-sm">
                                    <p className="mb-2 font-medium text-red-800">{failure.error_details.message}</p>
                                    {failure.error_details.errors && failure.error_details.errors.length > 0 && (
                                        <ul className="list-inside list-disc space-y-1 text-red-700">
                                            {failure.error_details.fingerprint}

                                            {failure.error_details.errors.map((error: string, index: number) => (
                                                <li key={index}>{error}</li>
                                            ))}
                                        </ul>
                                    )}
                                </div>

                                {/* Additional action section for expanded view */}
                                {canUnmark && (
                                    <div className="mt-4 border-t pt-4">
                                        <h5 className="mb-2 text-sm font-medium text-gray-700">Actions</h5>
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                handleUnmarkAsPending(failure.id, `Unmarked from ${failure.status} status - detailed review`)
                                            }
                                            disabled={isMarkingReviewed}
                                            className="w-full"
                                        >
                                            <RotateCcw className="mr-2 h-4 w-4" />
                                            Unmark and Reset to Pending
                                        </Button>
                                        <p className="mt-1 text-xs text-gray-500">
                                            This will revert the failure back to pending status for re-review
                                        </p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </CardContent>
                )}
            </Card>
        </div>
    );
}

export default FailureCollapse;
