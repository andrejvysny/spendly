import { SimpleCollapse } from '@/components/transactions/TransactionDetails';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { Building2, CreditCard } from 'lucide-react';
import React, { useState } from 'react';
import { toast } from 'react-toastify';

export interface EnrichedAccountDto {
    id: string;
    local_id: number | null;
    name: string;
    iban: string | null;
    currency: string | null;
    owner_name: string | null;
    status: 'Imported' | 'Ready to import';
    last_synced_at: string | null;
}

export interface RequisitionDto {
    id: string;
    created: string;
    redirect: string;
    status: string;
    institution_id: string;
    agreement: string;
    reference: string;
    accounts: (string | EnrichedAccountDto)[];
    user_language: string;
    link: string;
    ssn: string | null;
    account_selection: boolean;
    redirect_immediate: boolean;
}

export interface RequisitionsResponse {
    count: number;
    next: string | null;
    previous: string | null;
    results: RequisitionDto[];
}

function isEnrichedAccount(account: string | EnrichedAccountDto): account is EnrichedAccountDto {
    return typeof account === 'object' && account !== null && 'id' in account && 'status' in account;
}

/**
 * Displays details for a single bank requisition and provides options to view, delete, and manage its linked accounts.
 */
function Requisition({
    requisition,
    setRequisitions,
    onRefresh,
}: {
    requisition: RequisitionDto;
    setRequisitions: React.Dispatch<React.SetStateAction<RequisitionsResponse>>;
    onRefresh?: () => void;
}) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [requisitionToDelete, setRequisitionToDelete] = useState<string | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const accounts = requisition.accounts ?? [];
    const accountList = accounts.map((acc) =>
        isEnrichedAccount(acc) ? acc : ({ id: acc, local_id: null, name: 'Account', iban: null, currency: null, owner_name: null, status: 'Ready to import' as const, last_synced_at: null })
    );
    const importedCount = accountList.filter((a) => a.status === 'Imported' && a.local_id).length;

    const confirmDelete = (requisitionId: string) => {
        setRequisitionToDelete(requisitionId);
        setDeleteDialogOpen(true);
    };

    const deleteRequisition = async (deleteImportedAccounts: boolean) => {
        if (!requisitionToDelete) return;

        setIsDeleting(true);
        try {
            const url =
                deleteImportedAccounts ?
                    `/api/bank-data/gocardless/requisitions/${requisitionToDelete}?delete_imported_accounts=1`
                :   `/api/bank-data/gocardless/requisitions/${requisitionToDelete}`;
            await axios.delete(url);

            setRequisitions((prev) => ({
                ...prev,
                results: prev.results.filter((req) => req.id !== requisitionToDelete),
                count: prev.count - 1,
            }));

            setDeleteDialogOpen(false);
            setRequisitionToDelete(null);
            toast.success(deleteImportedAccounts ? 'Bank connection and imported accounts removed.' : 'Bank connection removed.');
            onRefresh?.();
        } catch (error) {
            console.error('Error deleting requisition:', error);
            toast.error('Failed to remove bank connection.');
        } finally {
            setIsDeleting(false);
        }
    };

    const handleCloseDeleteDialog = () => {
        if (!isDeleting) {
            setDeleteDialogOpen(false);
            setRequisitionToDelete(null);
        }
    };

    return (
        <>
            <Card className="overflow-hidden">
                <CardHeader className="pb-2">
                    <div className="flex items-start justify-between gap-4">
                        <div className="flex items-center gap-3">
                            <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                                <Building2 className="text-muted-foreground h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="text-lg font-semibold tracking-tight">{requisition.institution_id}</h3>
                                <p className="text-muted-foreground text-sm">
                                    Created {new Date(requisition.created).toLocaleDateString()}
                                </p>
                            </div>
                        </div>
                        <Badge variant={requisition.status === 'LN' ? 'default' : 'secondary'}>
                            {requisition.status === 'LN' ? 'Linked' : 'Pending'}
                        </Badge>
                    </div>
                </CardHeader>
                <CardContent className="space-y-4 pt-0">
                    <div className="text-muted-foreground grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <span>Agreement</span>
                        <span className="font-medium text-foreground truncate">{requisition.agreement}</span>
                        <span>Language</span>
                        <span className="font-medium text-foreground">{requisition.user_language}</span>
                        <span>Accounts</span>
                        <span className="font-medium text-foreground">{accountList.length}</span>
                    </div>

                    {accountList.length > 0 && (
                        <SimpleCollapse title="Linked Accounts" className="mt-3">
                            <ul className="space-y-2">
                                {accountList.map((account) => (
                                    <AccountRow
                                        key={account.id}
                                        account={account}
                                        onImportSuccess={onRefresh}
                                    />
                                ))}
                            </ul>
                        </SimpleCollapse>
                    )}

                    <div className="flex items-center justify-between border-t pt-4">
                        {requisition.status !== 'LN' && (
                            <a
                                href={requisition.link}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="text-muted-foreground hover:text-foreground text-sm underline"
                            >
                                View in GoCardless
                            </a>
                        )}
                        <Button variant="outline_destructive" size="sm" onClick={() => confirmDelete(requisition.id)}>
                            Delete
                        </Button>
                    </div>
                </CardContent>
            </Card>
            <Dialog open={deleteDialogOpen} onOpenChange={(open) => !open && handleCloseDeleteDialog()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Bank Connection</DialogTitle>
                        <DialogDescription>
                            {importedCount > 0 ? (
                                <>
                                    This bank connection has {importedCount} imported account{importedCount !== 1 ? 's' : ''}. Do you want to delete
                                    {importedCount !== 1 ? ' those accounts and all their data' : ' that account and all its data'} (transactions, etc.)
                                    or keep {importedCount !== 1 ? 'them' : 'it'}?
                                </>
                            ) : (
                                <>Are you sure you want to delete this bank connection? This action cannot be undone.</>
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="flex flex-col gap-2 sm:flex-row sm:flex-wrap">
                        <Button variant="outline" onClick={handleCloseDeleteDialog} disabled={isDeleting} className="order-3 sm:order-1">
                            Cancel
                        </Button>
                        {importedCount > 0 ? (
                            <>
                                <Button
                                    variant="outline"
                                    onClick={() => deleteRequisition(false)}
                                    disabled={isDeleting}
                                    className="order-2"
                                >
                                    {isDeleting ? 'Deleting...' : 'Keep accounts, remove connection only'}
                                </Button>
                                <Button
                                    variant="destructive"
                                    onClick={() => deleteRequisition(true)}
                                    disabled={isDeleting}
                                    className="order-1 sm:order-3"
                                >
                                    {isDeleting ? 'Deleting...' : `Delete ${importedCount} account${importedCount !== 1 ? 's' : ''} and all data`}
                                </Button>
                            </>
                        ) : (
                            <Button variant="destructive" onClick={() => deleteRequisition(false)} disabled={isDeleting}>
                                {isDeleting ? 'Deleting...' : 'Delete'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

export default Requisition;

/**
 * Single account row: icon, name, IBAN, currency, status badge, and Import or View action.
 */
function AccountRow({
    account,
    onImportSuccess,
}: {
    account: EnrichedAccountDto;
    onImportSuccess?: () => void;
}) {
    const [isLoading, setIsLoading] = useState(false);

    const handleImport = () => {
        setIsLoading(true);
        axios
            .post(`/api/bank-data/gocardless/import/account`, { account_id: account.id })
            .then(() => {
                toast.success('Account imported successfully.');
                onImportSuccess?.();
            })
            .catch((err) => {
                const message = err.response?.data?.message ?? err.message ?? 'Import failed';
                toast.error(message);
            })
            .finally(() => setIsLoading(false));
    };

    const displayIban = account.iban
        ? `${account.iban.slice(0, 4)} **** ${account.iban.slice(-4)}`
        : account.id.length > 20
          ? `${account.id.slice(0, 8)}…`
          : account.id;

    return (
        <li className="bg-muted/50 flex items-center gap-3 rounded-lg border p-3">
            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-background">
                <CreditCard className="text-muted-foreground h-4 w-4" />
            </div>
            <div className="min-w-0 flex-1">
                <p className="font-medium leading-tight">{account.name}</p>
                <p className="text-muted-foreground truncate text-sm">{displayIban}</p>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                    {account.currency && (
                        <span className="text-muted-foreground text-xs">{account.currency}</span>
                    )}
                    {account.owner_name && (
                        <span className="text-muted-foreground truncate text-xs"> · {account.owner_name}</span>
                    )}
                    {account.status === 'Imported' && (
                        <Badge variant="secondary" className="text-xs">
                            Synced
                        </Badge>
                    )}
                    {account.last_synced_at && (
                        <span className="text-muted-foreground text-xs">
                            Last sync: {new Date(account.last_synced_at).toLocaleDateString()}
                        </span>
                    )}
                </div>
            </div>
            <div className="shrink-0">
                {account.status === 'Imported' && account.local_id ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/accounts/${account.local_id}`}>View account</Link>
                    </Button>
                ) : (
                    <Button
                        variant="default"
                        size="sm"
                        onClick={handleImport}
                        disabled={isLoading}
                    >
                        {isLoading ? 'Importing...' : 'Import'}
                    </Button>
                )}
            </div>
        </li>
    );
}
