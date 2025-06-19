import AccountDetailMonthlyComparisonChart from '@/components/accounts/AccountDetailMonthlyComparisonChart';
import TransactionList from '@/components/transactions/TransactionList';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Switch } from '@/components/ui/switch';
import ValueSplit from '@/components/ui/value-split';
import AppLayout from '@/layouts/app-layout';
import { Account, Category, Merchant, Transaction } from '@/types/index';
import { formatAmount } from '@/utils/currency';
import { formatDate } from '@/utils/date';
import { Head, router } from '@inertiajs/react';
import axios from 'axios';
import { Settings } from 'lucide-react';
import { useState } from 'react';

interface Props {
    account: Account;
    transactions: Transaction[];
    categories: Category[];
    merchants: Merchant[];
    monthlySummaries: Record<string, { income: number; expense: number; balance: number }>;
    total_transactions: number;
    cashflow_last_month: Array<{
        year: number;
        month: number;
        day: number;
        transaction_count: number;
        daily_spending: number;
        daily_income: number;
        daily_balance: number;
    }>;
    cashflow_this_month: Array<{
        year: number;
        month: number;
        day: number;
        transaction_count: number;
        daily_spending: number;
        daily_income: number;
        daily_balance: number;
    }>;
}

/**
 * Displays detailed information and analytics for a financial account, including account details, transaction history, monthly comparisons, and account management actions.
 *
 * Renders account metadata, transaction lists, monthly cashflow analytics, and provides options to sync transactions or delete the account.
 *
 * @param account - The account to display details for, including sync status and metadata.
 * @param transactions - List of transactions associated with the account.
 * @param categories - Available transaction categories.
 * @param merchants - List of merchants related to the transactions.
 * @param monthlySummaries - Monthly summary data for the account.
 * @param total_transactions - Total number of transactions for the account.
 * @param cashflow_last_month - Daily cashflow data for the previous month.
 * @param cashflow_this_month - Daily cashflow data for the current month.
 */
export default function Detail({
    account,
    transactions,
    categories,
    merchants,
    monthlySummaries,
    total_transactions,
    cashflow_last_month,
    cashflow_this_month,
}: Props) {
    const [syncing, setSyncing] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [updateExisting, setUpdateExisting] = useState(account.sync_options?.update_existing ?? false);
    const [forceMaxDateRange, setForceMaxDateRange] = useState(account.sync_options?.force_max_date_range ?? false);
    const [savingOptions, setSavingOptions] = useState(false);
    const breadcrumbs = [
        { title: 'Accounts', href: '/accounts' },
        { title: account.name, href: `/accounts/${account.id}` },
    ];

    const saveSyncOptions = async (options: { update_existing?: boolean; force_max_date_range?: boolean }) => {
        setSavingOptions(true);
        try {
            const response = await axios.put(`/accounts/${account.id}/sync-options`, options);
            if (response.data.success) {
                console.log('Sync options saved successfully');
            } else {
                console.error('Failed to save sync options:', response.data.message);
            }
        } catch (error) {
            console.error('Error saving sync options:', error);
        } finally {
            setSavingOptions(false);
        }
    };

    const handleUpdateExistingChange = async (checked: boolean) => {
        setUpdateExisting(checked);
        await saveSyncOptions({ update_existing: checked });
    };

    const handleForceMaxDateRangeChange = async (checked: boolean) => {
        setForceMaxDateRange(checked);
        await saveSyncOptions({ force_max_date_range: checked });
    };

    const handleSyncTransactions = async () => {
        setSyncing(true);
        try {
            axios
                .post(`/api/bank-data/gocardless/accounts/${account.id}/sync-transactions`, {
                    account_id: account.id,
                    update_existing: updateExisting,
                    force_max_date_range: forceMaxDateRange,
                })
                .then((response) => {
                    if (response.status === 200) {
                        // Optionally handle success response
                        console.log('Transactions synced successfully');
                        window.location.reload();
                    } else {
                        console.error('Failed to sync transactions:', response.data);
                    }
                });
            // Refresh the page to show new transactions
        } catch {
            // Handle error silently
        } finally {
            setSyncing(false);
        }
    };

    const handleDeleteAccount = async () => {
        setIsDeleting(true);
        try {
            await router.delete(`/accounts/${account.id}`, {
                onSuccess: () => {
                    router.visit('/accounts');
                },
                onError: () => {
                    console.error('Failed to delete account:');
                },
            });
        } finally {
            setIsDeleting(false);
        }
    };

    // Group transactions by month and then by date
    const groupedByMonth: Record<string, Record<string, Transaction[]>> = {};
    transactions.forEach((transaction) => {
        const monthKey = new Date(transaction.booked_date).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
        const dateKey = new Date(transaction.booked_date).toLocaleDateString('sk-SK', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        });
        if (!groupedByMonth[monthKey]) groupedByMonth[monthKey] = {};
        if (!groupedByMonth[monthKey][dateKey]) groupedByMonth[monthKey][dateKey] = [];
        const { ...rest } = transaction as unknown as Omit<Transaction, 'key'>;
        groupedByMonth[monthKey][dateKey].push({
            ...rest,
            account: transaction.account ?? {
                id: 0,
                name: '',
                account_id: '',
                bank_name: '',
                iban: '',
                currency: '',
                balance: 0,
                created_at: '',
                updated_at: '',
            },
        } as Transaction);
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Account: ${account.name}`} />

            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto flex w-full max-w-5xl gap-6">
                    {/* Left: Sticky Account Details, Settings, Analytics */}
                    <div className="w-full max-w-xs flex-shrink-0">
                        <div className="sticky top-8">
                            <div className="bg-card mb-6 w-full rounded-xl border-1 p-6 shadow-xs">
                                <h2 className="text-xl font-semibold">{account.name}</h2>
                                <div className="text-muted-foreground mb-4 font-bold">{account.bank_name}</div>

                                <ValueSplit
                                    className="mb-4"
                                    data={[
                                        { label: 'IBAN', value: account.iban },
                                        { label: 'Type', value: account.type },
                                        { label: 'Currency', value: account.currency },
                                        { label: 'Balance', value: formatAmount(account.balance, account.currency) },
                                        { label: 'Number of transactions', value: total_transactions },
                                    ]}
                                />

                                <AlertDialog>
                                    <AlertDialogTrigger asChild>
                                        <Button variant="destructive" className="w-full">
                                            Delete Account
                                        </Button>
                                    </AlertDialogTrigger>
                                    <AlertDialogContent>
                                        <AlertDialogHeader>
                                            <AlertDialogTitle>Are you absolutely sure?</AlertDialogTitle>
                                            <AlertDialogDescription>
                                                This action cannot be undone. This will permanently delete your account and all associated
                                                transactions. This includes:
                                                <ul className="list-disc pl-5 text-sm">
                                                    <li>All transaction history</li>
                                                    <li>All transaction categories</li>
                                                    <li>All account settings</li>
                                                    <li>All synced data</li>
                                                </ul>
                                            </AlertDialogDescription>
                                        </AlertDialogHeader>
                                        <AlertDialogFooter>
                                            <AlertDialogCancel>Cancel</AlertDialogCancel>
                                            <AlertDialogAction
                                                onClick={handleDeleteAccount}
                                                disabled={isDeleting}
                                                className="bg-red-600 hover:bg-red-700"
                                            >
                                                {isDeleting ? 'Deleting...' : 'Delete Account'}
                                            </AlertDialogAction>
                                        </AlertDialogFooter>
                                    </AlertDialogContent>
                                </AlertDialog>
                            </div>

                            {account.is_gocardless_synced && (
                                <div className="bg-card mb-6 w-full rounded-xl border-1 p-6 shadow-xs">
                                    <h3 className="mb-4 text-lg font-semibold">GoCardless</h3>
                                    <ValueSplit
                                        className="mb-4"
                                        data={[
                                            { label: 'Bank', value: account.bank_name },
                                            { label: 'Account ID', value: account.gocardless_account_id },
                                            {
                                                label: 'Synced',
                                                value: account.gocardless_last_synced_at ? formatDate(account.gocardless_last_synced_at) : 'Never',
                                            },
                                        ]}
                                    />

                                    <div className="flex">
                                        <Button onClick={handleSyncTransactions} disabled={syncing} className="flex-1 rounded-r-none">
                                            {syncing ? 'Syncing...' : 'Sync Transactions'}
                                        </Button>
                                        <DropdownMenu>
                                            <DropdownMenuTrigger asChild>
                                                <Button
                                                    variant="outline"
                                                    className="focus-none bg-foreground border-foreground text-background hover:bg-foreground/90 hover:text-background rounded-l-none border-1 focus-visible:ring-0"
                                                    disabled={syncing}
                                                >
                                                    <Settings className="h-4 w-4" />
                                                </Button>
                                            </DropdownMenuTrigger>
                                            <DropdownMenuContent align="end" className="w-56">
                                                <div className="px-2 py-1.5 text-sm font-semibold">Sync Settings</div>
                                                <DropdownMenuSeparator />
                                                <DropdownMenuItem
                                                    className="flex cursor-pointer items-center justify-between"
                                                    onClick={(e) => {
                                                        e.preventDefault();
                                                        e.stopPropagation();
                                                        handleUpdateExistingChange(!updateExisting);
                                                    }}
                                                >
                                                    <div className="flex flex-col">
                                                        <span className="text-sm">Update existing transactions</span>
                                                        <span className="text-muted-foreground text-xs">
                                                            Update existing transactions with latest data
                                                        </span>
                                                    </div>
                                                    <Switch
                                                        checked={updateExisting}
                                                        onCheckedChange={handleUpdateExistingChange}
                                                        onClick={(e) => e.stopPropagation()}
                                                        disabled={savingOptions}
                                                    />
                                                </DropdownMenuItem>
                                                <DropdownMenuItem
                                                    className="flex cursor-pointer items-center justify-between"
                                                    onClick={(e) => {
                                                        e.preventDefault();
                                                        e.stopPropagation();
                                                        handleForceMaxDateRangeChange(!forceMaxDateRange);
                                                    }}
                                                >
                                                    <div className="flex flex-col">
                                                        <span className="text-sm">Force full sync (max 90 days)</span>
                                                        <span className="text-muted-foreground text-xs">
                                                            Sync from 90 days ago instead of last sync
                                                        </span>
                                                    </div>
                                                    <Switch
                                                        checked={forceMaxDateRange}
                                                        onCheckedChange={handleForceMaxDateRangeChange}
                                                        onClick={(e) => e.stopPropagation()}
                                                        disabled={savingOptions}
                                                    />
                                                </DropdownMenuItem>
                                            </DropdownMenuContent>
                                        </DropdownMenu>
                                    </div>
                                </div>
                            )}
                            {/* Analytics/Graphs Placeholder */}
                            <div className="bg-card mb-6 w-full rounded-xl border-1 p-6 shadow-xs">
                                <h3 className="mb-4 text-lg font-semibold">Category spending</h3>
                                <div className="text-muted-foreground flex h-32 items-center justify-center">
                                    {/* Replace with real chart component */}
                                    <span>coming soon…</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    {/* Right: Transactions List */}
                    <div className="flex-1">
                        <div className="mb-6 flex flex-col">
                            <div className="bg-card rounded-xl border-1 p-5 shadow-xs">
                                <h3 className="mb-4 text-lg font-semibold">Monthly comparison</h3>

                                <AccountDetailMonthlyComparisonChart
                                    currentMonthData={cashflow_this_month.map((item) => ({
                                        date: `${item.year}-${String(item.month).padStart(2, '0')}-${String(item.day).padStart(2, '0')}`,
                                        balance: item.daily_balance,
                                        income: item.daily_income,
                                        expense: -item.daily_spending,
                                    }))}
                                    previousMonthData={cashflow_last_month.map((item) => ({
                                        date: `${item.year}-${String(item.month).padStart(2, '0')}-${String(item.day).padStart(2, '0')}`,
                                        balance: item.daily_balance,
                                        income: item.daily_income,
                                        expense: -item.daily_spending,
                                    }))}
                                />
                            </div>
                        </div>
                        <div className="flex flex-col gap-6">
                            <TransactionList
                                transactions={transactions}
                                monthlySummaries={monthlySummaries}
                                categories={categories}
                                merchants={merchants}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
