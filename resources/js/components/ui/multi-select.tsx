import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { Check, ChevronDown, X } from 'lucide-react';
import React, { useEffect, useRef, useState } from 'react';

export interface Option {
    value: string | number;
    label: string;
    description?: string;
}

interface MultiSelectProps<T extends string | number> {
    options: Option[];
    selected: T[];
    onChange: (value: T[]) => void;
    placeholder?: string;
    className?: string;
    badgeClassName?: string;
    disabled?: boolean;
    maxDisplayItems?: number;
    renderOption?: (option: Option) => React.ReactNode;
}

/**
 * Renders a multi-select dropdown component with search, selection, and badge display features.
 *
 * Allows users to select multiple options from a list, filter options via a search input, and manage selections with "Select All" and "Clear" actions. Selected items are displayed as badges, with overflow detection to condense the display when necessary. Supports custom option rendering and accessibility attributes.
 *
 * @param options - The list of selectable options.
 * @param selected - The currently selected option values.
 * @param onChange - Callback invoked with the updated selection.
 * @param placeholder - Placeholder text shown when no options are selected.
 * @param className - Additional CSS classes for the component container.
 * @param badgeClassName - Additional CSS classes for selected item badges.
 * @param disabled - If true, disables interaction with the dropdown.
 * @param maxDisplayItems - Maximum number of badges to display before condensing to a count badge.
 * @param renderOption - Optional custom render function for options.
 *
 * @template T - The type of option values, constrained to string or number.
 */
export function MultiSelect<T extends string | number>({
    options,
    selected,
    onChange,
    placeholder = 'Select options',
    className,
    badgeClassName,
    disabled = false,
    maxDisplayItems = 2,
    renderOption,
}: MultiSelectProps<T>) {
    const [isOpen, setIsOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const dropdownRef = useRef<HTMLDivElement>(null);
    const triggerRef = useRef<HTMLButtonElement>(null);
    const triggerContainerRef = useRef<HTMLDivElement>(null);
    const badgesRef = useRef<HTMLDivElement>(null);
    const [isOverflowing, setIsOverflowing] = useState(false);

    // Filter options based on search query
    const filteredOptions = options.filter(
        (option) =>
            option.label.toLowerCase().includes(searchQuery.toLowerCase()) || option.description?.toLowerCase().includes(searchQuery.toLowerCase()),
    );

    // Check if badges overflow the container
    useEffect(() => {
        if (selected.length === 0) {
            setIsOverflowing(false);
            return;
        }
        if (triggerContainerRef.current && badgesRef.current) {
            const containerWidth = triggerContainerRef.current.offsetWidth;
            const badgesWidth = badgesRef.current.scrollWidth;
            setIsOverflowing(badgesWidth > containerWidth);
        }
    }, [selected, options, maxDisplayItems]);

    // Handle clicks outside to close the dropdown
    useEffect(() => {
        const handleOutsideClick = (e: MouseEvent) => {
            if (dropdownRef.current && triggerRef.current) {
                const target = e.target as Node;
                // Close only if the click is outside both the dropdown and the trigger button
                if (!dropdownRef.current.contains(target) && !triggerRef.current.contains(target)) {
                    setIsOpen(false);
                }
            }
        };

        if (isOpen) {
            document.addEventListener('mousedown', handleOutsideClick);
        }

        return () => {
            document.removeEventListener('mousedown', handleOutsideClick);
        };
    }, [isOpen]);

    // Toggle dropdown
    const toggleDropdown = () => {
        if (!disabled) {
            setIsOpen(!isOpen);
        }
    };

    // Handle selection toggle
    const handleSelect = (value: T) => {
        // Create a new array to avoid mutating the original
        let newSelected;
        if (selected.includes(value)) {
            newSelected = selected.filter((item) => item !== value);
        } else {
            newSelected = [...selected, value];
        }
        onChange(newSelected);
        // Keep the dropdown open
    };

    // Handle clicking "Select All"
    const handleSelectAll = () => {
        onChange(options.map((option) => option.value as T));
    };

    // Clear all selections
    const handleClear = () => {
        onChange([] as T[]);
    };

    // Handle search input change
    const handleSearchChange = (e: React.ChangeEvent<HTMLInputElement>) => {
        setSearchQuery(e.target.value);
    };

    // Get option data by value
    const getOptionByValue = (value: T) => {
        return options.find((option) => option.value === value);
    };

    // Display labels for selected items
    const displaySelection = () => {
        if (selected.length === 0) {
            return <span className="text-muted-foreground">{placeholder}</span>;
        }

        // If overflowing or too many items, show count badge
        if (isOverflowing || selected.length > maxDisplayItems) {
            return (
                <>
                    <Badge variant="secondary" className={cn('mr-1', badgeClassName)}>
                        {selected.length} selected
                    </Badge>
                </>
            );
        }

        // Otherwise, show all selected badges
        return (
            <div ref={badgesRef} className="flex items-center gap-1">
                {selected.map((value) => {
                    const option = getOptionByValue(value);
                    return option ? (
                        <Badge key={value} variant="secondary" className={cn('mr-1', badgeClassName)}>
                            {option.label}
                        </Badge>
                    ) : null;
                })}
            </div>
        );
    };

    return (
        <div className={cn('relative', className)}>
            {/* Trigger button */}
            <button
                ref={triggerRef}
                disabled={disabled}
                className={cn(
                    'border-input flex w-full items-center justify-between rounded-md border px-3 py-2 text-sm',
                    'bg-background hover:bg-accent hover:text-accent-foreground',
                    'focus-visible:ring-ring focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
                    'disabled:pointer-events-none disabled:opacity-50',
                )}
                onClick={toggleDropdown}
                aria-expanded={isOpen}
                aria-haspopup="listbox"
                type="button"
            >
                <div ref={triggerContainerRef} className="flex max-w-[170px] items-center gap-1 whitespace-nowrap">
                    {displaySelection()}
                </div>
                <ChevronDown className="ml-2 h-4 w-4 shrink-0 opacity-50" />
            </button>

            {/* Dropdown menu */}
            {isOpen && (
                <div
                    ref={dropdownRef}
                    className={cn(
                        'bg-popover absolute z-[9999] mt-1 w-full min-w-[220px] overflow-hidden rounded-md border p-0 shadow-md',
                        'animate-in fade-in-0 zoom-in-95',
                    )}
                >
                    {/* Search input */}
                    <div className="flex items-center border-b px-3">
                        <input
                            value={searchQuery}
                            onChange={handleSearchChange}
                            className="placeholder:text-muted-foreground flex h-11 w-full rounded-md bg-transparent py-3 text-sm outline-none"
                            placeholder={`Search ${options.length} options...`}
                            onClick={(e) => e.stopPropagation()}
                        />
                        {searchQuery && (
                            <X
                                className="h-4 w-4 cursor-pointer opacity-70 hover:opacity-100"
                                onClick={(e) => {
                                    e.stopPropagation();
                                    setSearchQuery('');
                                }}
                            />
                        )}
                    </div>

                    {/* Options list */}
                    <div className="max-h-64 overflow-y-auto p-1">
                        {filteredOptions.length === 0 ? (
                            <div className="py-6 text-center text-sm">No options found.</div>
                        ) : (
                            filteredOptions.map((option) => (
                                <div
                                    key={option.value}
                                    className={cn(
                                        'flex cursor-pointer items-center justify-between rounded-sm px-2 py-1.5 text-sm',
                                        'hover:bg-accent hover:text-accent-foreground',
                                        selected.includes(option.value as T) ? 'bg-accent/50' : '',
                                    )}
                                    onClick={(e) => {
                                        e.stopPropagation();
                                        handleSelect(option.value as T);
                                    }}
                                    role="option"
                                    aria-selected={selected.includes(option.value as T)}
                                >
                                    <div className="flex flex-col gap-1">
                                        {renderOption ? (
                                            renderOption(option)
                                        ) : (
                                            <>
                                                <span>{option.label}</span>
                                                {option.description && <span className="text-muted-foreground text-xs">{option.description}</span>}
                                            </>
                                        )}
                                    </div>
                                    <div className="flex h-4 w-4 items-center justify-center">
                                        {selected.includes(option.value as T) && <Check className="h-4 w-4" />}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>

                    {/* Footer with actions */}
                    {options.length > 0 && (
                        <div className="flex items-center justify-between border-t p-2">
                            <div className="text-muted-foreground text-sm">
                                {selected.length} of {options.length} selected
                            </div>
                            <div className="flex gap-2">
                                {selected.length < options.length && (
                                    <button
                                        type="button"
                                        className={cn(
                                            'inline-flex h-auto items-center p-1 text-xs',
                                            'hover:bg-accent hover:text-accent-foreground rounded-sm',
                                        )}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            handleSelectAll();
                                        }}
                                    >
                                        Select All
                                    </button>
                                )}
                                {selected.length > 0 && (
                                    <button
                                        type="button"
                                        className={cn(
                                            'inline-flex h-auto items-center p-1 text-xs',
                                            'hover:bg-accent hover:text-accent-foreground rounded-sm',
                                        )}
                                        onClick={(e) => {
                                            e.stopPropagation();
                                            handleClear();
                                        }}
                                    >
                                        <X className="mr-1 h-3 w-3" />
                                        Clear
                                    </button>
                                )}
                            </div>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}
