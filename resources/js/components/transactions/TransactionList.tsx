import TransactionComponent from '@/components/transactions/Transaction';
import { Button } from '@/components/ui/button';
import { LoadingDots } from '@/components/ui/loading-dots';
import { Category, Merchant, Transaction } from '@/types/index';
import { useEffect, useState } from 'react';
import BulkActionMenu from './BulkActionMenu';

interface Props {
    transactions: Transaction[];
    monthlySummaries: Record<string, { income: number; expense: number; balance: number }>;
    categories: Category[];
    merchants: Merchant[];
    showMonthlySummary?: boolean;
    hasMorePages?: boolean;
    onLoadMore?: () => Promise<void>;
    isLoadingMore?: boolean;
}

/**
 * Displays a list of financial transactions grouped by month and date, with selection, bulk actions, and optional pagination.
 *
 * Allows users to select individual or multiple transactions, perform bulk updates, and view monthly summaries. Supports loading additional transactions when pagination is enabled.
 *
 * @param transactions - The initial list of transactions to display.
 * @param monthlySummaries - Summary data for each month, keyed by month name.
 * @param categories - List of available categories for transactions.
 * @param merchants - List of available merchants for transactions.
 * @param showMonthlySummary - Whether to display monthly income, expense, and balance summaries.
 * @param hasMorePages - Indicates if more pages of transactions are available for loading.
 * @param onLoadMore - Callback to load additional transactions when pagination is enabled.
 * @param isLoadingMore - Indicates if additional transactions are currently being loaded.
 *
 * @returns A React component rendering the transaction list with grouping, selection, bulk actions, and pagination controls.
 */
function TransactionList({
    transactions: initialTransactions,
    monthlySummaries,
    categories,
    merchants,
    showMonthlySummary = true,
    hasMorePages = false,
    onLoadMore,
    isLoadingMore = false,
}: Props) {
    const [selectedTransactions, setSelectedTransactions] = useState<string[]>([]);
    const [transactions, setTransactions] = useState<Transaction[]>(initialTransactions);

    // Use effect to update transactions when initialTransactions change
    useEffect(() => {
        setTransactions(initialTransactions);
    }, [initialTransactions]);

    const handleSelect = (id: string, selected: boolean) => {
        if (selected) {
            setSelectedTransactions([...selectedTransactions, id]);
        } else {
            setSelectedTransactions(selectedTransactions.filter((t) => t !== id));
        }
    };

    const handleResetSelection = (updatedData?: { ids: string[]; category_id?: string | null; merchant_id?: string | null }) => {
        // If we have updated data, update the local transactions
        if (updatedData) {
            const updatedTransactions = [...transactions];

            // Find the category object if a category_id was provided
            let selectedCategory: Category | null = null;
            if (updatedData.category_id) {
                selectedCategory = categories.find((c) => String(c.id) === updatedData.category_id) || null;
            }

            // Find the merchant object if a merchant_id was provided
            let selectedMerchant: Merchant | null = null;
            if (updatedData.merchant_id) {
                selectedMerchant = merchants.find((m) => String(m.id) === updatedData.merchant_id) || null;
            }

            // Update all selected transactions
            updatedData.ids.forEach((id) => {
                const index = updatedTransactions.findIndex((t) => String(t.id) === id);
                if (index !== -1) {
                    // Create a new object to trigger re-render
                    updatedTransactions[index] = {
                        ...updatedTransactions[index],
                        category: updatedData.category_id === null ? undefined : selectedCategory || undefined,
                        merchant: updatedData.merchant_id === null ? undefined : selectedMerchant || undefined,
                    };
                }
            });

            setTransactions(updatedTransactions);
        }

        // Reset selection
        setSelectedTransactions([]);
    };

    const handleSelectAllInMonth = (monthTransactions: Transaction[]) => {
        const transactionIds = monthTransactions.map((t) => String(t.id));
        const allSelected = transactionIds.every((id) => selectedTransactions.includes(id));

        if (allSelected) {
            // Deselect all transactions in this month
            setSelectedTransactions(selectedTransactions.filter((id) => !transactionIds.includes(id)));
        } else {
            // Select all transactions in this month
            const newSelection = [...selectedTransactions.filter((id) => !transactionIds.includes(id)), ...transactionIds];
            setSelectedTransactions(newSelection);
        }
    };

    const handleSelectAll = () => {
        const allTransactionIds = transactions.map((t) => String(t.id));
        const allSelected = allTransactionIds.length > 0 && allTransactionIds.every((id) => selectedTransactions.includes(id));

        if (allSelected) {
            // Deselect all transactions
            setSelectedTransactions([]);
        } else {
            // Select all transactions
            setSelectedTransactions(allTransactionIds);
        }
    };

    // Group transactions by month and then by date
    const groupedByMonth: Record<string, Record<string, Transaction[]>> = {};
    const transactionsByMonth: Record<string, Transaction[]> = {};

    transactions.forEach((transaction) => {
        const monthKey = new Date(transaction.booked_date).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        const dateKey = new Date(transaction.booked_date).toLocaleDateString('sk-SK', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        });

        if (!groupedByMonth[monthKey]) groupedByMonth[monthKey] = {};
        if (!groupedByMonth[monthKey][dateKey]) groupedByMonth[monthKey][dateKey] = [];
        groupedByMonth[monthKey][dateKey].push(transaction);

        if (!transactionsByMonth[monthKey]) transactionsByMonth[monthKey] = [];
        transactionsByMonth[monthKey].push(transaction);
    });

    const sortedMonths = Object.keys(groupedByMonth).sort((a, b) => {
        // Sort by year and month descending
        const [monthA, yearA] = a.split(' ');
        const [monthB, yearB] = b.split(' ');
        const dateA = new Date(`${yearA}-${monthA}-01`);
        const dateB = new Date(`${yearB}-${monthB}-01`);
        return dateB.getTime() - dateA.getTime();
    });

    const allTransactionIds = transactions.map((t) => String(t.id));
    const allSelected = allTransactionIds.length > 0 && allTransactionIds.every((id) => selectedTransactions.includes(id));

    return (
        <div className="mx-auto flex w-full max-w-xl flex-col gap-0">
            {/* Global Select All button - only show if there are transactions */}
            {transactions.length > 0 && (
                <div className="mb-4 flex justify-end">
                    <button onClick={handleSelectAll} className="text-primary text-sm font-medium hover:underline">
                        {allSelected ? 'Deselect All Transactions' : 'Select All Transactions'}
                    </button>
                </div>
            )}

            {transactions.length === 0 ? (
                <div className="flex h-[200px] items-center justify-center rounded-xl border-1 border-dashed p-8 text-center">
                    <div>
                        <p className="text-muted-foreground text-lg font-medium">No transactions found</p>
                        <p className="text-muted-foreground mt-1 text-sm">Try adjusting your filters or add a new transaction</p>
                    </div>
                </div>
            ) : (
                sortedMonths.map((month) => {
                    const summary =
                        monthlySummaries[month] &&
                        typeof monthlySummaries[month].income === 'number' &&
                        typeof monthlySummaries[month].expense === 'number' &&
                        typeof monthlySummaries[month].balance === 'number'
                            ? monthlySummaries[month]
                            : { income: 0, expense: 0, balance: 0 };
                    const dateGroups = groupedByMonth[month];
                    const sortedDates = Object.keys(dateGroups).sort((a, b) => {
                        return new Date(b).getTime() - new Date(a).getTime();
                    });

                    const monthTransactions = transactionsByMonth[month] || [];
                    const monthTransactionIds = monthTransactions.map((t) => String(t.id));
                    const allMonthSelected = monthTransactionIds.length > 0 && monthTransactionIds.every((id) => selectedTransactions.includes(id));

                    return (
                        <div key={month} className="mb-10 flex flex-col gap-2 pb-10">
                            {/* Summary at the top of the month */}
                            <div className="mb-1 flex items-center justify-between">
                                <span className="text-2xl font-semibold">{month}</span>
                                <button
                                    onClick={() => handleSelectAllInMonth(monthTransactions)}
                                    className="text-primary cursor-pointer text-sm font-medium hover:underline"
                                >
                                    {allMonthSelected ? `Deselect All in ${month}` : `Select All in ${month}`}
                                </button>
                            </div>
                            {showMonthlySummary && (
                                <div className="bg-card xs mb-4 flex rounded-xl border-1 border-current shadow">
                                    <div className="flex w-full divide-x divide-gray-400 p-4">
                                        <div className="flex flex-1 flex-col items-start pr-6">
                                            <span className="mb-1 text-xs text-gray-400">Income</span>
                                            <span className="text-xl font-medium">+{summary.income.toFixed(2)}€</span>
                                        </div>
                                        <div className="flex flex-1 flex-col items-start px-6">
                                            <span className="mb-1 text-xs text-gray-400">Expense</span>
                                            <span className="text-xl font-medium">-{summary.expense.toFixed(2)}€</span>
                                        </div>
                                        <div className="flex flex-1 flex-col items-start pl-6">
                                            <span className="mb-1 text-xs text-gray-400">Balance</span>
                                            <span
                                                className={`text-xl font-bold ${summary.balance >= 0 ? 'text-green-500' : 'text-destructive-foreground'}`}
                                            >
                                                {summary.balance.toFixed(2)}€
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            )}
                            {/* All date groups for this month */}
                            {sortedDates.map((date) => (
                                <div key={date} className="flex flex-col gap-2">
                                    <h3 className="text-muted-foreground px-2 text-sm">{date}</h3>
                                    <div className="flex flex-col gap-3">
                                        {dateGroups[date].map((transaction) => (
                                            <TransactionComponent
                                                key={transaction.id}
                                                {...transaction}
                                                isSelected={selectedTransactions.includes(String(transaction.id))}
                                                onSelect={handleSelect}
                                            />
                                        ))}
                                    </div>
                                </div>
                            ))}
                        </div>
                    );
                })
            )}

            {selectedTransactions.length > 0 && (
                <BulkActionMenu
                    selectedTransactions={selectedTransactions}
                    categories={categories}
                    merchants={merchants}
                    onUpdate={handleResetSelection}
                />
            )}

            {/* Load More Button */}
            {hasMorePages && onLoadMore && (
                <div className="mt-6 flex justify-center">
                    <Button variant="outline" onClick={onLoadMore} disabled={isLoadingMore}>
                        {isLoadingMore ? <LoadingDots size="sm" className="text-primary" /> : 'Load More'}
                    </Button>
                </div>
            )}
        </div>
    );
}

export default TransactionList;
