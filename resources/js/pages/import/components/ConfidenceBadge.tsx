import { Badge } from '@/components/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import React from 'react';

export interface ConfidenceBadgeProps {
    confidence: number;
    signals?: Record<string, number>;
    showDetails?: boolean;
    className?: string;
}

function SignalBreakdown({ signals }: { signals: Record<string, number> }) {
    return (
        <div className="space-y-1 text-left">
            {Object.entries(signals).map(([key, value]) => (
                <div key={key} className="flex justify-between gap-2">
                    <span className="capitalize">{key}</span>
                    <span>{Math.round(value * 100)}%</span>
                </div>
            ))}
        </div>
    );
}

export function ConfidenceBadge({ confidence, signals = {}, showDetails = true, className }: ConfidenceBadgeProps) {
    const level = confidence >= 0.9 ? 'high' : confidence >= 0.7 ? 'medium' : 'low';
    const variantClass = {
        high: 'bg-emerald-100 text-emerald-800 border-emerald-200',
        medium: 'bg-amber-100 text-amber-800 border-amber-200',
        low: 'bg-red-100 text-red-800 border-red-200',
    }[level];

    const badge = (
        <Badge variant="outline" className={cn(variantClass, className)}>
            {Math.round(confidence * 100)}%
        </Badge>
    );

    if (showDetails && Object.keys(signals).length > 0) {
        return (
            <Tooltip>
                <TooltipTrigger asChild>{badge}</TooltipTrigger>
                <TooltipContent>
                    <SignalBreakdown signals={signals} />
                </TooltipContent>
            </Tooltip>
        );
    }

    return badge;
}
