import { Badge } from '@/components/ui/badge';

export function ErrorTypeBadge({ errorType }: { errorType: string }) {
    const badges = {
        validation_failed: (
            <Badge variant="destructive" className="bg-red-100 text-red-800">
                Validation Failed
            </Badge>
        ),
        duplicate: (
            <Badge variant="secondary" className="bg-yellow-100 text-yellow-800">
                Duplicate
            </Badge>
        ),
        processing_error: (
            <Badge variant="destructive" className="bg-orange-100 text-orange-800">
                Processing Error
            </Badge>
        ),
        parsing_error: (
            <Badge variant="destructive" className="bg-purple-100 text-purple-800">
                Parsing Error
            </Badge>
        ),
    };
    return badges[errorType as keyof typeof badges] || <Badge variant="outline">{errorType}</Badge>;
}

export function StatusBadge({ status }: { status: string }) {
    const badges = {
        pending: (
            <Badge variant="secondary" className="bg-gray-100 text-gray-800">
                Pending
            </Badge>
        ),
        reviewed: (
            <Badge variant="outline" className="bg-blue-100 text-blue-800">
                Reviewed
            </Badge>
        ),
        resolved: (
            <Badge variant="default" className="bg-green-100 text-green-800">
                Resolved
            </Badge>
        ),
        ignored: (
            <Badge variant="outline" className="bg-gray-100 text-gray-600">
                Ignored
            </Badge>
        ),
    };
    return badges[status as keyof typeof badges] || <Badge variant="outline">{status}</Badge>;
}
