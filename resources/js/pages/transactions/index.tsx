//WIP

import CreateTransactionModal from '@/components/transactions/CreateTransactionModal';
import TransactionList from '@/components/transactions/TransactionList';
import { Button } from '@/components/ui/button';
import { DateRangeInput, TextInput } from '@/components/ui/form-inputs';
import { LoadingDots } from '@/components/ui/loading-dots';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { InferFormValues, SmartForm } from '@/components/ui/smart-form';
import { Switch } from '@/components/ui/switch';
import useLoadMore from '@/hooks/use-load-more';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { BreadcrumbItem, Category, Merchant, Transaction } from '@/types/index';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import debounce from 'lodash/debounce';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { z } from 'zod';
import '../../bootstrap';

interface Props {
    transactions: {
        data: Transaction[];
        current_page: number;
        has_more_pages: boolean;
    };
    monthlySummaries: Record<string, { income: number; expense: number; balance: number }>;
    totalSummary?: {
        count: number;
        income: number;
        expense: number;
        balance: number;
        categoriesCount: number;
        merchantsCount: number;
        uncategorizedCount: number;
        noMerchantCount: number;
    };
    isFiltered?: boolean;
    categories: Category[];
    merchants: Merchant[];
    accounts: { id: number; name: string }[];
    totalCount: number;
    filters?: {
        search?: string;
        account_id?: string;
        transactionType?: 'income' | 'expense' | 'transfer' | 'all' | '';
        amountFilterType?: 'exact' | 'range' | 'above' | 'below' | 'all' | '';
        amountMin?: string;
        amountMax?: string;
        amountExact?: string;
        amountAbove?: string;
        amountBelow?: string;
        dateFrom?: string;
        dateTo?: string;
        dateRange?: { from: string; to: string };
        merchant_id?: string;
        category_id?: string;
    };
}

const filterSchema = z.object({
    search: z.string().optional(),
    account_id: z.string().optional(),
    transactionType: z.enum(['income', 'expense', 'transfer', 'all', '']).optional(),
    amountFilterType: z.enum(['exact', 'range', 'above', 'below', 'all', '']).optional(),
    amountFilter: z.string().optional(),
    amountMin: z.string().optional(),
    amountMax: z.string().optional(),
    amountExact: z.string().optional(),
    amountAbove: z.string().optional(),
    amountBelow: z.string().optional(),
    dateFrom: z.string().optional(),
    dateTo: z.string().optional(),
    dateRange: z
        .object({
            from: z.string().optional(),
            to: z.string().optional(),
        })
        .optional(),
    merchant_id: z.string().optional(),
    category_id: z.string().optional(),
});

type FilterValues = InferFormValues<typeof filterSchema>;

async function fetchTransactions(
    params: FilterValues,
    page?: number,
): Promise<{
    data: Transaction[];
    current_page: number;
    has_more_pages: boolean;
    monthlySummaries: Record<string, { income: number; expense: number; balance: number }>;
    totalSummary?: Record<string, number>;
    totalCount?: number;
}> {
    const endpoint = page ? '/transactions/load-more' : '/transactions/filter';
    const filteredParams = filterEmptyValues(params);

    function filterEmptyValues(obj: Record<string, unknown>): Record<string, unknown> {
        return Object.fromEntries(
            Object.entries(obj).filter(
                ([, v]) => v !== '' && v !== null && v !== undefined && !(typeof v === 'object' && Object.values(v).every((sv) => sv === '')),
            ),
        );
    }
    const response = await axios.get(endpoint, {
        params: page ? { ...filteredParams, page } : filteredParams,
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            'Content-Type': 'application/json',
            Accept: 'application/json',
        },
    });

    if (response.data.transactions) {
        return {
            data: response.data.transactions.data,
            current_page: response.data.transactions.current_page,
            has_more_pages: getHasMorePages(response.data),
            monthlySummaries: response.data.monthlySummaries || {},
            totalSummary: response.data.totalSummary,
            totalCount: response.data.totalCount,
        };
    }

    interface TransactionData {
        transactions?: {
            has_more_pages?: boolean;
            hasMorePages?: boolean;
        };
        hasMorePages?: boolean;
    }

    function getHasMorePages(data: TransactionData): boolean {
        return data.transactions?.has_more_pages ?? data.transactions?.hasMorePages ?? data.hasMorePages ?? false;
    }
    throw new Error(`Invalid response from endpoint "${endpoint}". Response data: ${JSON.stringify(response.data)}`);
}

/**
 * Displays and manages a paginated, filterable list of financial transactions with summary analytics and transaction creation capabilities.
 *
 * Provides UI for searching, filtering, and paginating transactions, as well as viewing summary statistics and creating new transactions. Supports dynamic filter application with debounced API calls, infinite scroll pagination, and real-time updates upon transaction creation.
 *
 * @param transactions - Initial paginated transaction data, including current page and pagination status.
 * @param monthlySummaries - Initial monthly summary data for transactions.
 * @param totalSummary - Initial total summary metrics for filtered transactions.
 * @param isFiltered - Indicates if filters are initially applied.
 * @param categories - List of available transaction categories.
 * @param merchants - List of available merchants.
 * @param accounts - List of available accounts.
 * @param filters - Optional initial filter values.
 *
 * @returns The main transactions page layout with filters, analytics, transaction list, and creation modal.
 */
export default function Index({
    transactions: initialTransactions,
    monthlySummaries: initialSummaries,
    totalSummary: initialTotalSummary,
    isFiltered: initialIsFiltered,
    categories,
    merchants,
    accounts,
    totalCount: initialTotalCount,
    filters = {},
}: Props) {
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isLoading, setIsLoading] = useState(false);
    const {
        data: transactions,
        loadMore,
        hasMore: hasMorePages,
        isLoadingMore,
        reset,
        totalCount,
    } = useLoadMore<Transaction, FilterValues>({
        initialData: initialTransactions.data,
        initialPage: initialTransactions.current_page,
        initialHasMore: initialTransactions.has_more_pages ?? initialTransactions.hasMorePages,
        initialTotalCount: initialTotalCount,
        fetcher: fetchTransactions,
    });
    const [monthlySummaries, setMonthlySummaries] = useState(initialSummaries);
    const [totalSummary, setTotalSummary] = useState(initialTotalSummary);
    const [isFiltered, setIsFiltered] = useState(initialIsFiltered);
    const [showMonthlySummary, setShowMonthlySummary] = useState(true);
    const [filterValues, setFilterValues] = useState<FilterValues>({
        search: filters.search || '',
        account_id: filters.account_id || 'all',
        transactionType: filters.transactionType || 'all',
        amountFilterType: filters.amountFilterType || 'all',
        amountFilter: '',
        amountMin: filters.amountMin || '',
        amountMax: filters.amountMax || '',
        amountExact: filters.amountExact || '',
        amountAbove: filters.amountAbove || '',
        amountBelow: filters.amountBelow || '',
        dateFrom: filters.dateFrom || '',
        dateTo: filters.dateTo || '',
        dateRange: filters.dateRange || { from: '', to: '' },
        merchant_id: filters.merchant_id || 'all',
        category_id: filters.category_id || 'all',
    });

    // Skip initial fetch on page load if no filters are active
    const [initialLoadComplete, setInitialLoadComplete] = useState(false);

    // Define hasActiveFilters and resetValues before they are used in hooks
    const hasActiveFilters = useCallback(() => {
        return Object.entries(filterValues).some(([key, value]) => {
            if (key === 'dateRange') return false;
            if (key === 'dateFrom' || key === 'dateTo') {
                return value !== '' && value !== null && value !== undefined;
            }
            if (typeof value === 'object' && value !== null) {
                if (Array.isArray(value)) {
                    return value.length > 0;
                }
                return Object.values(value).some((v) => v !== '' && v !== 'all' && v !== null && v !== undefined);
            }
            return value !== '' && value !== 'all' && value !== null && value !== undefined;
        });
    }, [filterValues]);

    const resetValues: FilterValues = useMemo(
        () => ({
            search: '',
            account_id: 'all',
            transactionType: 'all' as const,
            amountFilterType: 'all' as const,
            amountFilter: '',
            amountMin: '',
            amountMax: '',
            amountExact: '',
            amountAbove: '',
            amountBelow: '',
            dateFrom: '',
            dateTo: '',
            dateRange: { from: '', to: '' },
            merchant_id: 'all',
            category_id: 'all',
        }),
        [],
    );

    useEffect(() => {
        // Mark initial load as complete so we know future changes should trigger filters
        setInitialLoadComplete(true);
    }, []);

    // A separate handler for each individual filter change that checks if value is not empty
    const handleFilterChange = (name: keyof FilterValues, value: string | number | boolean | { from: string; to: string } | null | undefined) => {
        // Only proceed if the value has actually changed
        if (filterValues[name] === value) {
            return; // Skip if the value hasn't changed
        }

        // Update the filter values
        const newValues = { ...filterValues, [name]: value };
        setFilterValues(newValues);

        // Only trigger API call if meaningful changes AND initial load is complete
        if (initialLoadComplete) {
            const isValueMeaningful = value !== '' && value !== 'all' && value !== null && value !== undefined;
            const wasValueMeaningful =
                filterValues[name] !== '' && filterValues[name] !== 'all' && filterValues[name] !== null && filterValues[name] !== undefined;

            // Only fetch if adding a meaningful filter or removing a previously active filter
            if (isValueMeaningful || wasValueMeaningful) {
                debouncedFetchTransactions(newValues);
            }
        }
    };

    // Parse amount input to extract operation and values
    const parseAmountInput = (input: string): { type: string; values: number[] } => {
        input = input.trim();

        // Check for range pattern (10-50)
        if (input.includes('-')) {
            const [min, max] = input.split('-').map((v) => Math.abs(parseFloat(v)));
            if (!isNaN(min) && !isNaN(max)) {
                return { type: 'range', values: [min, max] };
            }
        }

        // Check for less than (<50)
        if (input.startsWith('<')) {
            const value = Math.abs(parseFloat(input.substring(1)));
            if (!isNaN(value)) {
                return { type: 'less', values: [value] };
            }
        }

        // Check for greater than (>50)
        if (input.startsWith('>')) {
            const value = Math.abs(parseFloat(input.substring(1)));
            if (!isNaN(value)) {
                return { type: 'greater', values: [value] };
            }
        }

        // Check for exact match (=50 or just 50)
        const exactValue = Math.abs(parseFloat(input.startsWith('=') ? input.substring(1) : input));
        if (!isNaN(exactValue)) {
            return { type: 'exact', values: [exactValue] };
        }

        return { type: 'invalid', values: [] };
    };

    // Handler specifically for amount filtering
    const handleAmountChange = (value: string) => {
        // Update the display value
        const newValues = { ...filterValues, amountFilter: value };

        // Clear all specific amount fields
        newValues.amountExact = '';
        newValues.amountMin = '';
        newValues.amountMax = '';
        newValues.amountAbove = '';
        newValues.amountBelow = '';
        newValues.amountFilterType = 'all'; // Reset the filter type

        if (value) {
            const parsed = parseAmountInput(value);
            console.log('Parsed amount input:', parsed);

            switch (parsed.type) {
                case 'exact':
                    newValues.amountExact = parsed.values[0].toString();
                    newValues.amountFilterType = 'exact';
                    break;
                case 'range':
                    newValues.amountMin = parsed.values[0].toString();
                    newValues.amountMax = parsed.values[1].toString();
                    newValues.amountFilterType = 'range';
                    break;
                case 'greater':
                    newValues.amountAbove = parsed.values[0].toString();
                    newValues.amountFilterType = 'above';
                    break;
                case 'less':
                    newValues.amountBelow = parsed.values[0].toString();
                    newValues.amountFilterType = 'below';
                    break;
            }
        }

        console.log('New filter values:', newValues);
        setFilterValues(newValues);

        // Only fetch if we have a valid input or we're clearing the filter AND initial load is complete
        if (initialLoadComplete && (value === '' || parseAmountInput(value).type !== 'invalid')) {
            debouncedFetchTransactions(newValues);
        }
    };

    // Get unique accounts for dropdown
    const accountOptions = useMemo(() => {
        return [{ value: 'all', label: 'All Accounts' }, ...accounts.map((account) => ({ value: account.id.toString(), label: account.name }))];
    }, [accounts]);

    // Get merchant and category options for dropdowns
    const merchantOptions = useMemo(() => {
        return [{ value: 'all', label: 'All Merchants' }, ...merchants.map((merchant) => ({ value: merchant.id.toString(), label: merchant.name }))];
    }, [merchants]);

    const categoryOptions = useMemo(() => {
        return [
            { value: 'all', label: 'All Categories' },
            ...categories.map((category) => ({ value: category.id.toString(), label: category.name })),
        ];
    }, [categories]);

    // Check if a filter has a non-default value
    const isFilterActive = (name: string): boolean => {
        switch (name) {
            case 'search':
                return !!filterValues.search;
            case 'account_id':
            case 'merchant_id':
            case 'category_id':
                return filterValues[name] !== 'all';
            case 'transactionType':
                return filterValues.transactionType !== 'all';
            case 'amountFilter':
                return !!filterValues.amountFilter;
            case 'amountMin':
            case 'amountMax':
            case 'amountExact':
            case 'amountAbove':
            case 'amountBelow':
            case 'dateFrom':
            case 'dateTo':
                return !!filterValues[name];
            default:
                return false;
        }
    };

    // Get filter label style based on active state
    const getFilterLabelStyle = (name: string): string => {
        return isFilterActive(name) ? 'font-medium text-green-600' : 'font-medium';
    };

    // Debounced fetch function
    const fetchTransactionsLogic = useCallback(
        async (values: FilterValues) => {
            console.log('Fetching transactions with values:', values);
            setIsLoading(true);
            try {
                const isResettingFilters =
                    !hasActiveFilters() ||
                    Object.values(values).every(
                        (value) =>
                            value === '' ||
                            value === 'all' ||
                            value === null ||
                            value === undefined ||
                            (typeof value === 'object' && Object.values(value).every((v) => v === '')),
                    );

                const result = await fetchTransactions(values);
                if (process.env.NODE_ENV === 'development') {
                    console.log('API response:', result);
                }
                if (result.data) {
                    reset(result.data, result.current_page, result.has_more_pages ?? result.hasMorePages, result.totalCount);
                    setMonthlySummaries(result.monthlySummaries || {});
                    if (isResettingFilters) {
                        setTotalSummary(undefined);
                        setIsFiltered(false);
                    } else {
                        setTotalSummary(result.totalSummary || undefined);
                        setIsFiltered(
                            Object.values(values).some((value) => value !== '' && value !== 'all' && value !== null && value !== undefined),
                        );
                    }
                } else {
                    console.error('Invalid response format:', result);
                }
            } catch (error) {
                console.error('Error fetching filtered transactions:', error);
            } finally {
                setIsLoading(false);
            }
        },
        [hasActiveFilters, setIsLoading, reset, setMonthlySummaries, setTotalSummary, setIsFiltered],
    );

    const debouncedFetchTransactions = useMemo(() => debounce(fetchTransactionsLogic, 300), [fetchTransactionsLogic]);

    // Call the API when search term changes
    useEffect(() => {
        if (filterValues.search !== undefined && initialLoadComplete) {
            // Only fetch if the search term has a value
            if (filterValues.search) {
                debouncedFetchTransactions(filterValues);
            }
        }
    }, [filterValues, debouncedFetchTransactions, initialLoadComplete]);

    // Update isFiltered state when filter values change with improved handling
    const previousFilterStateRef = useRef(false);
    useEffect(() => {
        if (initialLoadComplete) {
            const isFilterActive = hasActiveFilters();

            // Only reload if we're going from filtered -> not filtered (clearing filters)
            if (previousFilterStateRef.current && !isFilterActive) {
                // Reset filter state
                setIsFiltered(false);
                // Reload transactions without filters, only once
                debouncedFetchTransactions(resetValues);
            } else {
                // Just update the filtered state
                setIsFiltered(isFilterActive);
            }

            // Update ref for next comparison
            previousFilterStateRef.current = isFilterActive;
        }
    }, [filterValues, initialLoadComplete, debouncedFetchTransactions, hasActiveFilters, resetValues]);

    // Load more transactions
    const handleLoadMore = async () => {
        await loadMore(filterValues);
    };

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Transactions',
            href: '/transactions',
        },
    ];

    const handleCreateTransaction = (transaction: Omit<Transaction, 'id' | 'created_at' | 'updated_at' | 'account'>) => {
        // Create a new object with only the properties we want to send
        const payload = {
            transaction_id: transaction.transaction_id,
            amount: transaction.amount,
            currency: transaction.currency,
            booked_date: transaction.booked_date,
            processed_date: transaction.processed_date,
            description: transaction.description,
            target_iban: transaction.target_iban,
            source_iban: transaction.source_iban,
            partner: transaction.partner,
            type: transaction.type,
            balance_after_transaction: transaction.balance_after_transaction,
            note: transaction.note,
            recipient_note: transaction.recipient_note,
            place: transaction.place,
            category_id: transaction.category?.id,
            merchant_id: transaction.merchant?.id,
        };

        router.post('/transactions', payload, {
            onSuccess: () => {
                setIsCreateModalOpen(false);
                // Refresh transactions after creating a new one
                debouncedFetchTransactions(filterValues);
            },
            preserveScroll: true,
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Transactions" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    <PageHeader
                        title="Transactions"
                        buttons={[
                            {
                                onClick: () => setIsCreateModalOpen(true),
                                label: '+ New Transaction',
                            },
                        ]}
                    />
                </div>
            </div>

            <div className="mx-auto w-full max-w-7xl p-4 pt-0">
                <div className="mx-auto flex w-full max-w-5xl gap-6">
                    {/* Left: Sticky Account Details, Settings, Analytics */}
                    <div className="w-full max-w-xs flex-shrink-0">
                        <div className="sticky top-8">
                            {/* Display Options - Moved above filters */}
                            <div className="bg-card mb-6 w-full rounded-xl border-1 p-6 shadow-xs">
                                <div className="flex items-center justify-between">
                                    <label className="text-sm font-medium">Show Monthly Summary</label>
                                    <Switch checked={showMonthlySummary} onCheckedChange={setShowMonthlySummary} />
                                </div>
                            </div>

                            <div className="bg-card mb-6 w-full rounded-xl border-1 p-6 shadow-xs">
                                <h3 className="mb-4 text-lg font-semibold">Filters</h3>

                                {/* Search input with debounce */}
                                <div className="mb-4">
                                    <label className={`mb-1 block text-sm ${getFilterLabelStyle('search')}`}>Search transactions</label>
                                    <div className="relative">
                                        <SmartForm
                                            schema={filterSchema}
                                            defaultValues={{ search: filterValues.search }}
                                            onChange={(values) => {
                                                // Only update and trigger if the value actually changed
                                                const newSearch = values.search || '';
                                                if (newSearch !== filterValues.search) {
                                                    const newValues = { ...filterValues, search: newSearch };
                                                    setFilterValues(newValues);

                                                    if (newSearch) {
                                                        debouncedFetchTransactions(newValues);
                                                    } else if (filterValues.search) {
                                                        // If clearing a previously set search, also update the filter
                                                        debouncedFetchTransactions(newValues);
                                                    }
                                                }
                                            }}
                                            formProps={{ className: 'space-y-4' }}
                                        >
                                            {() => (
                                                <div className="relative">
                                                    <TextInput name="search" placeholder="Search..." label="" />
                                                    {isLoading && filterValues.search && (
                                                        <div className="absolute top-1/2 right-3 -translate-y-1/2">
                                                            <div className="h-4 w-4 animate-spin rounded-full border-b-2 border-gray-900"></div>
                                                        </div>
                                                    )}
                                                </div>
                                            )}
                                        </SmartForm>
                                    </div>
                                </div>

                                {/* Other filters with automatic application */}
                                <div className="space-y-4">
                                    <div className="mb-2">
                                        <label className={`mb-1 block text-sm ${getFilterLabelStyle('account_id')}`}>Account</label>
                                        <Select value={filterValues.account_id} onValueChange={(value) => handleFilterChange('account_id', value)}>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select account" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {accountOptions.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="mb-2">
                                        <label className={`mb-1 block text-sm ${getFilterLabelStyle('transactionType')}`}>Transaction Type</label>
                                        <Select
                                            value={filterValues.transactionType}
                                            onValueChange={(value) => handleFilterChange('transactionType', value)}
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select transaction type" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">All Types</SelectItem>
                                                <SelectItem value="income">Income</SelectItem>
                                                <SelectItem value="expense">Expense</SelectItem>
                                                <SelectItem value="transfer">Transfer</SelectItem>
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    {/* Absolute amount filter */}
                                    <div className="mb-3">
                                        <label
                                            className={`mb-1 block text-sm ${isFilterActive('amountFilter') ? 'font-medium text-green-600' : 'font-medium'}`}
                                        >
                                            Amount Filter (Absolute Value)
                                        </label>
                                        <div className="relative">
                                            <SmartForm
                                                schema={filterSchema}
                                                defaultValues={{ amountFilter: filterValues.amountFilter }}
                                                onChange={(values) => {
                                                    const newAmountFilter = values.amountFilter || '';
                                                    if (newAmountFilter !== filterValues.amountFilter) {
                                                        handleAmountChange(newAmountFilter);
                                                    }
                                                }}
                                            >
                                                {() => (
                                                    <div>
                                                        <TextInput name="amountFilter" label="" placeholder="e.g. >100, <50, 100-200, =100" />
                                                        <div className="mt-1 text-xs text-gray-500">
                                                            Use absolute values. Transaction type determines sign. Use &gt; for greater than, &lt; for
                                                            less than, - for range, or just enter a number for exact match
                                                        </div>
                                                    </div>
                                                )}
                                            </SmartForm>
                                        </div>
                                    </div>

                                    <div className="mb-2">
                                        <label
                                            className={`mb-1 block text-sm ${isFilterActive('dateFrom') || isFilterActive('dateTo') ? 'font-medium text-green-600' : 'font-medium'}`}
                                        >
                                            Date Range
                                        </label>
                                        <SmartForm
                                            schema={filterSchema}
                                            defaultValues={{
                                                dateRange: {
                                                    from: filterValues.dateFrom || '',
                                                    to: filterValues.dateTo || '',
                                                },
                                            }}
                                            onChange={(values) => {
                                                if (values.dateRange) {
                                                    const newDateFrom = values.dateRange.from || '';
                                                    const newDateTo = values.dateRange.to || '';

                                                    // Only trigger API call if values actually changed
                                                    if (newDateFrom !== filterValues.dateFrom || newDateTo !== filterValues.dateTo) {
                                                        const newValues = {
                                                            ...filterValues,
                                                            dateFrom: newDateFrom,
                                                            dateTo: newDateTo,
                                                        };

                                                        // Always update the filter values
                                                        setFilterValues(newValues);

                                                        // Check if we have any active filters
                                                        const hasActiveFilters =
                                                            newValues.search ||
                                                            newValues.account_id !== 'all' ||
                                                            newValues.transactionType !== 'all' ||
                                                            newValues.amountFilter ||
                                                            newValues.merchant_id !== 'all' ||
                                                            newValues.category_id !== 'all' ||
                                                            newDateFrom ||
                                                            newDateTo;

                                                        // If we have active filters or we're clearing the date range, fetch transactions
                                                        if (initialLoadComplete && (hasActiveFilters || (!newDateFrom && !newDateTo))) {
                                                            debouncedFetchTransactions(newValues);
                                                        }
                                                    }
                                                }
                                            }}
                                        >
                                            {() => <DateRangeInput name="dateRange" placeholder="Select date range" />}
                                        </SmartForm>
                                    </div>

                                    <div className="mb-2">
                                        <label className={`mb-1 block text-sm ${getFilterLabelStyle('merchant_id')}`}>Merchant</label>
                                        <Select value={filterValues.merchant_id} onValueChange={(value) => handleFilterChange('merchant_id', value)}>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select merchant" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {merchantOptions.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="mb-2">
                                        <label className={`mb-1 block text-sm ${getFilterLabelStyle('category_id')}`}>Category</label>
                                        <Select value={filterValues.category_id} onValueChange={(value) => handleFilterChange('category_id', value)}>
                                            <SelectTrigger className="w-full">
                                                <SelectValue placeholder="Select category" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {categoryOptions.map((option) => (
                                                    <SelectItem key={option.value} value={option.value}>
                                                        {option.label}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>

                                    <div className="mt-6 flex justify-end">
                                        <Button
                                            variant="outline"
                                            onClick={() => {
                                                // Set the reset flag to prevent extra API calls in effects
                                                previousFilterStateRef.current = false;

                                                // Reset all filter values, clear total summary, and set filtered to false
                                                setFilterValues(resetValues);
                                                setTotalSummary(undefined);
                                                setIsFiltered(false);

                                                // Force reload the page to get fresh data without filters
                                                router.get(
                                                    '/transactions',
                                                    {},
                                                    {
                                                        preserveState: false,
                                                        preserveScroll: true,
                                                    },
                                                );
                                            }}
                                        >
                                            Reset All Filters
                                        </Button>
                                    </div>
                                </div>
                            </div>

                            {/* Analytics/Graphs Placeholder */}
                            <div className="bg-card mb-6 w-full rounded-xl border-1 p-6 shadow-xs">
                                <h3 className="mb-4 text-lg font-semibold">Category spending</h3>
                                <div className="flex h-32 items-center justify-center text-current">
                                    {/* Replace with real chart component */}
                                    <span>coming soon…</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* Right: Transactions List */}
                    <div className="flex-1">
                        <div className="flex flex-col gap-6">
                            {isLoading ? (
                                <div className="flex h-[calc(100vh-200px)] items-center justify-center">
                                    <LoadingDots size="lg" className="text-primary" />
                                </div>
                            ) : (
                                <>
                                    {isFiltered &&
                                        totalSummary &&
                                        Object.keys(totalSummary).length > 0 &&
                                        (filterValues.search ||
                                            filterValues.account_id !== 'all' ||
                                            filterValues.transactionType !== 'all' ||
                                            filterValues.amountFilter ||
                                            filterValues.dateFrom ||
                                            filterValues.dateTo ||
                                            filterValues.merchant_id !== 'all' ||
                                            filterValues.category_id !== 'all') && (
                                            <div className="mb-4">
                                                <div className="mb-2 flex items-center justify-between">
                                                    <h3 className="text-muted-foreground text-base font-semibold">Filter Results Summary</h3>
                                                    <button
                                                        onClick={() => {
                                                            // Set the reset flag to prevent extra API calls in effects
                                                            previousFilterStateRef.current = false;

                                                            // Reset filter values and state
                                                            setFilterValues(resetValues);
                                                            setIsFiltered(false);

                                                            // Force reload the page to get fresh data without filters
                                                            router.get(
                                                                '/transactions',
                                                                {},
                                                                {
                                                                    preserveState: false,
                                                                    preserveScroll: true,
                                                                },
                                                            );
                                                        }}
                                                        className="text-primary text-sm font-medium hover:underline"
                                                    >
                                                        Clear All Filters
                                                    </button>
                                                </div>
                                                <div className="bg-card mb-4 rounded-xl border-1 border-current p-3 shadow">
                                                    <div className="grid grid-cols-4 gap-2">
                                                        {/* First row - Financial metrics */}
                                                        <div className="flex flex-col">
                                                            <span className="text-xs text-gray-400">Transactions</span>
                                                            <span className="text-base font-medium">{totalSummary.count}</span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-xs text-gray-400">Income</span>
                                                            <span className="text-base font-medium text-green-500">
                                                                +{totalSummary.income.toFixed(2)}€
                                                            </span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-xs text-gray-400">Expense</span>
                                                            <span className="text-destructive-foreground text-base font-medium">
                                                                -{totalSummary.expense.toFixed(2)}€
                                                            </span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-xs text-gray-400">Balance</span>
                                                            <span
                                                                className={`text-base font-medium ${totalSummary.balance >= 0 ? 'text-green-500' : 'text-destructive-foreground'}`}
                                                            >
                                                                {totalSummary.balance.toFixed(2)}€
                                                            </span>
                                                        </div>

                                                        {/* Second row - Categorization metrics */}
                                                        <div className="flex flex-col">
                                                            <span className="text-xs text-gray-400">Categories</span>
                                                            <span className="text-base font-medium">{totalSummary.categoriesCount}</span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-xs text-gray-400">Merchants</span>
                                                            <span className="text-base font-medium">{totalSummary.merchantsCount}</span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-xs text-gray-400">Uncategorized</span>
                                                            <span className="text-base font-medium">{totalSummary.uncategorizedCount}</span>
                                                        </div>
                                                        <div className="flex flex-col">
                                                            <span className="text-xs text-gray-400">No Merchant</span>
                                                            <span className="text-base font-medium">{totalSummary.noMerchantCount}</span>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                    <TransactionList
                                        transactions={transactions}
                                        monthlySummaries={monthlySummaries}
                                        categories={categories}
                                        merchants={merchants}
                                        showMonthlySummary={showMonthlySummary}
                                        hasMorePages={hasMorePages}
                                        onLoadMore={handleLoadMore}
                                        isLoadingMore={isLoadingMore}
                                        totalCount={totalCount}
                                    />
                                </>
                            )}

                            <CreateTransactionModal
                                isOpen={isCreateModalOpen}
                                onClose={() => setIsCreateModalOpen(false)}
                                onSubmit={handleCreateTransaction}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
