import { SimpleCollapse } from '@/components/transactions/TransactionDetails';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle, Building2, CreditCard } from 'lucide-react';
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
    status: string;
    institution_id: string;
    agreement: string;
    accounts: (string | EnrichedAccountDto)[];
    user_language: string;
    link: string | null;
    /** Primary key of the local gocardless_requisitions row; required to reconnect. */
    row_id?: number | null;
    /** Local lifecycle state (App\Enums\GoCardlessRequisitionStatus); absent for legacy remote-only payloads. */
    local_status?: string | null;
    status_label?: string | null;
    access_valid_until?: string | null;
    days_until_expiry?: number | null;
    needs_reconnect?: boolean;
}

export interface RequisitionsResponse {
    count: number;
    results: RequisitionDto[];
}

function isEnrichedAccount(account: string | EnrichedAccountDto): account is EnrichedAccountDto {
    return typeof account === 'object' && account !== null && 'id' in account && 'status' in account;
}

type BadgeVariant = 'default' | 'secondary' | 'destructive' | 'outline';

/**
 * Map the local lifecycle state (App\Enums\GoCardlessRequisitionStatus) onto a badge variant.
 * Falls back to the raw GoCardless code for payloads predating local status tracking.
 */
function statusBadge(requisition: RequisitionDto): { variant: BadgeVariant; label: string } {
    const local = requisition.local_status;

    if (!local) {
        return requisition.status === 'LN' ? { variant: 'default', label: 'Linked' } : { variant: 'secondary', label: 'Pending' };
    }

    const variants: Record<string, BadgeVariant> = {
        linked: 'default',
        pending: 'secondary',
        expired: 'destructive',
        suspended: 'destructive',
        rejected: 'destructive',
        error: 'destructive',
        cancelled: 'outline',
        replaced: 'outline',
        revoked: 'outline',
    };

    return { variant: variants[local] ?? 'secondary', label: requisition.status_label ?? local };
}

/** "Access valid until 12/05/2026 (43 days)" — or "(expired)" once the window has passed. */
function expiryLabel(requisition: RequisitionDto): string | null {
    if (!requisition.access_valid_until) return null;

    const until = new Date(requisition.access_valid_until).toLocaleDateString();
    const days = requisition.days_until_expiry;

    if (days === null || days === undefined) return `Access valid until ${until}`;
    if (days < 0) return `Access expired on ${until}`;

    return `Access valid until ${until} (${days} day${days === 1 ? '' : 's'})`;
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
    const [isReconnecting, setIsReconnecting] = useState(false);

    const badge = statusBadge(requisition);
    const expiry = expiryLabel(requisition);

    // The endpoint keys off the local row id, which only exists on locally tracked connections.
    const canReconnect = Boolean(requisition.needs_reconnect && requisition.row_id);

    const handleReconnect = async () => {
        if (!requisition.row_id) return;

        setIsReconnecting(true);
        try {
            const { data } = await axios.post<{ link: string }>(`/api/bank-data/gocardless/requisitions/${requisition.row_id}/reconnect`);
            window.location.href = data.link;
        } catch (error) {
            console.error('Error starting reconnect:', error);
            const message = axios.isAxiosError(error) ? (error.response?.data?.error ?? error.message) : 'Failed to start reconnect.';
            toast.error(message);
            setIsReconnecting(false);
        }
    };

    const accounts = requisition.accounts ?? [];
    const accountList = accounts.map((acc) =>
        isEnrichedAccount(acc)
            ? acc
            : {
                  id: acc,
                  local_id: null,
                  name: 'Account',
                  iban: null,
                  currency: null,
                  owner_name: null,
                  status: 'Ready to import' as const,
                  last_synced_at: null,
              },
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
            const url = deleteImportedAccounts
                ? `/api/bank-data/gocardless/requisitions/${requisitionToDelete}?delete_imported_accounts=1`
                : `/api/bank-data/gocardless/requisitions/${requisitionToDelete}`;
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
                            <div className="bg-muted flex h-10 w-10 items-center justify-center rounded-lg">
                                <Building2 className="text-muted-foreground h-5 w-5" />
                            </div>
                            <div>
                                <h3 className="text-lg font-semibold tracking-tight">{requisition.institution_id}</h3>
                                <p className="text-muted-foreground text-sm">Created {new Date(requisition.created).toLocaleDateString()}</p>
                            </div>
                        </div>
                        <Badge variant={badge.variant}>{badge.label}</Badge>
                    </div>
                </CardHeader>
                <CardContent className="space-y-4 pt-0">
                    {requisition.needs_reconnect && (
                        <Alert className="border-2 border-amber-500/60 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                            <AlertTriangle className="h-4 w-4" />
                            <AlertTitle title="Reconnect required" />
                            <AlertDescription className="flex flex-col gap-3">
                                <span>Your bank has ended access to this connection. Reconnect to resume syncing transactions.</span>
                                {canReconnect && (
                                    <Button size="sm" className="self-start" onClick={handleReconnect} disabled={isReconnecting}>
                                        {isReconnecting ? 'Starting...' : 'Reconnect'}
                                    </Button>
                                )}
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="text-muted-foreground grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <span>Agreement</span>
                        <span className="text-foreground truncate font-medium">{requisition.agreement}</span>
                        <span>Language</span>
                        <span className="text-foreground font-medium">{requisition.user_language}</span>
                        <span>Accounts</span>
                        <span className="text-foreground font-medium">{accountList.length}</span>
                        {expiry && (
                            <>
                                <span>Access</span>
                                <span className="text-foreground font-medium">{expiry}</span>
                            </>
                        )}
                    </div>

                    {accountList.length > 0 && (
                        <SimpleCollapse title="Linked Accounts" className="mt-3">
                            <ul className="space-y-2">
                                {accountList.map((account) => (
                                    <AccountRow key={account.id} account={account} onImportSuccess={onRefresh} />
                                ))}
                            </ul>
                        </SimpleCollapse>
                    )}

                    <div className="flex items-center justify-between border-t pt-4">
                        {requisition.status !== 'LN' && requisition.link && (
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
                                    {importedCount !== 1 ? ' those accounts and all their data' : ' that account and all its data'} (transactions,
                                    etc.) or keep {importedCount !== 1 ? 'them' : 'it'}?
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
                                <Button variant="outline" onClick={() => deleteRequisition(false)} disabled={isDeleting} className="order-2">
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
function AccountRow({ account, onImportSuccess }: { account: EnrichedAccountDto; onImportSuccess?: () => void }) {
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
                if (err.response?.status === 429) {
                    const retryAfter = err.response?.data?.retry_after ?? 60;
                    toast.error(`Rate limited by bank. Please wait ${retryAfter}s and try again.`);
                } else {
                    const message = err.response?.data?.message ?? err.message ?? 'Import failed';
                    toast.error(message);
                }
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
            <div className="bg-background flex h-9 w-9 shrink-0 items-center justify-center rounded-md">
                <CreditCard className="text-muted-foreground h-4 w-4" />
            </div>
            <div className="min-w-0 flex-1">
                <p className="leading-tight font-medium">{account.name}</p>
                <p className="text-muted-foreground truncate text-sm">{displayIban}</p>
                <div className="mt-1 flex flex-wrap items-center gap-2">
                    {account.currency && <span className="text-muted-foreground text-xs">{account.currency}</span>}
                    {account.owner_name && <span className="text-muted-foreground truncate text-xs"> · {account.owner_name}</span>}
                    {account.status === 'Imported' && (
                        <Badge variant="secondary" className="text-xs">
                            Synced
                        </Badge>
                    )}
                    {account.last_synced_at && (
                        <span className="text-muted-foreground text-xs">Last sync: {new Date(account.last_synced_at).toLocaleDateString()}</span>
                    )}
                </div>
            </div>
            <div className="shrink-0">
                {account.status === 'Imported' && account.local_id ? (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={`/accounts/${account.local_id}`}>View account</Link>
                    </Button>
                ) : (
                    <Button variant="default" size="sm" onClick={handleImport} disabled={isLoading}>
                        {isLoading ? 'Importing...' : 'Import'}
                    </Button>
                )}
            </div>
        </li>
    );
}
