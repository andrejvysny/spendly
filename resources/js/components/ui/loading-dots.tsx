import React from 'react';

interface LoadingDotsProps {
    size?: 'sm' | 'md' | 'lg';
    className?: string;
}

export function LoadingDots({ size = 'md', className = '' }: LoadingDotsProps) {
    const sizeClasses = {
        sm: 'h-2 w-2',
        md: 'h-3 w-3',
        lg: 'h-4 w-4'
    };

    return (
        <div className={`flex items-center justify-center gap-1 ${className}`}>
            <div className={`${sizeClasses[size]} animate-bounce rounded-full bg-current [animation-delay:-0.3s]`}></div>
            <div className={`${sizeClasses[size]} animate-bounce rounded-full bg-current [animation-delay:-0.15s]`}></div>
            <div className={`${sizeClasses[size]} animate-bounce rounded-full bg-current`}></div>
        </div>
    );
} 