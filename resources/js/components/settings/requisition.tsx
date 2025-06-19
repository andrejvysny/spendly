import { SimpleCollapse } from '@/components/transactions/TransactionDetails';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import axios from 'axios';
import React, { useState } from 'react';

export interface RequisitionDto {
    id: string;
    created: string;
    redirect: string;
    status: string;
    institution_id: string;
    agreement: string;
    reference: string;
    accounts: string[];
    user_language: string;
    link: string;
    ssn: string | null;
    account_selection: boolean;
    redirect_immediate: boolean;
}

interface RequisitionsResponse {
    count: number;
    next: string | null;
    previous: string | null;
    results: RequisitionDto[];
}

/**
 * Displays details for a single bank requisition and provides options to view, delete, and manage its linked accounts.
 *
 * Renders requisition information including institution, creation date, status, agreement, language, and linked accounts. Allows users to delete the requisition with confirmation and updates the requisitions list upon successful deletion. If accounts are linked, displays them with import options.
 *
 * @param requisition - The requisition object to display and manage.
 * @param setRequisitions - State setter to update the list of requisitions after deletion.
 */
function Requisition({
    requisition,
    setRequisitions,
}: {
    requisition: RequisitionDto;
    setRequisitions: React.Dispatch<React.SetStateAction<RequisitionsResponse>>;
}) {
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [requisitionToDelete, setRequisitionToDelete] = useState<string | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    const confirmDelete = (requisitionId: string) => {
        setRequisitionToDelete(requisitionId);
        setDeleteDialogOpen(true);
    };

    const deleteRequisition = async () => {
        if (!requisitionToDelete) return;

        setIsDeleting(true);
        try {
            // await the delete so errors go into the catch below
            await axios.delete(`/api/bank-data/gocardless/requisitions/${requisitionToDelete}`);

            setRequisitions((prev) => ({
                ...prev,
                results: prev.results.filter((req) => req.id !== requisitionToDelete),
                count: prev.count - 1,
            }));

            setDeleteDialogOpen(false);
            setRequisitionToDelete(null);
        } catch (error) {
            console.error('Error deleting requisition:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    return (
        <>
            <div className="bg-card border-foreground rounded-lg p-4 shadow">
                <div className="mb-4 flex items-start justify-between">
                    <div>
                        <h3 className="text-lg font-semibold text-gray-900 dark:text-white">{requisition.institution_id}</h3>
                        <p className="text-sm text-gray-500 dark:text-gray-400">Created: {new Date(requisition.created).toLocaleDateString()}</p>
                    </div>
                    <span
                        className={`rounded-full px-2 py-1 text-xs font-medium ${
                            requisition.status === 'LN'
                                ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'
                                : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300'
                        }`}
                    >
                        {requisition.status === 'LN' ? 'Linked' : 'Pending'}
                    </span>
                </div>

                <div className="space-y-2 text-sm">
                    <div className="flex justify-between">
                        <span className="text-gray-500 dark:text-gray-400">ID:</span>
                        <span className="font-medium text-gray-900 dark:text-white">{requisition.id}</span>
                    </div>
                    <div className="flex justify-between">
                        <span className="text-gray-500 dark:text-gray-400">Agreement ID:</span>
                        <span className="font-medium text-gray-900 dark:text-white">{requisition.agreement}</span>
                    </div>
                    <div className="flex justify-between">
                        <span className="text-gray-500 dark:text-gray-400">Language:</span>
                        <span className="font-medium text-gray-900 dark:text-white">{requisition.user_language}</span>
                    </div>
                    <div className="flex justify-between">
                        <span className="text-gray-500 dark:text-gray-400">Accounts:</span>
                        <span className="font-medium text-gray-900 dark:text-white">{requisition.accounts?.length || 0}</span>
                    </div>
                </div>

                {requisition.accounts && requisition.accounts.length > 0 && (
                    <SimpleCollapse title={'Linked Accounts'} className="mt-3">
                        {requisition.accounts.map((account_id: string) => (
                            <AccountComponent key={account_id} account_id={account_id} />
                        ))}
                    </SimpleCollapse>
                )}

                <div className="mt-4 border-t border-gray-200 pt-4 dark:border-gray-700">
                    <div className="flex items-center justify-between">
                        <div>
                            {requisition.status !== 'LN' ? (
                                <a
                                    href={requisition.link}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                >
                                    View in GoCardless
                                </a>
                            ) : null}
                        </div>

                        <Button variant="outline_destructive" size="sm" onClick={() => confirmDelete(requisition.id)}>
                            Delete
                        </Button>
                    </div>
                </div>
            </div>
            <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Bank Connection</DialogTitle>
                        <DialogDescription>Are you sure you want to delete this bank connection? This action cannot be undone.</DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDeleteDialogOpen(false)} disabled={isDeleting}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={deleteRequisition} disabled={isDeleting}>
                            {isDeleting ? 'Deleting...' : 'Delete'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

export default Requisition;

/**
 * Displays minimal account information and provides an option to import the account.
 *
 * Renders the account ID and placeholder fields for additional details. Includes an "Import" button that triggers an API request to import the account by its ID, showing a loading indicator during the process.
 *
 * @param account_id - The unique identifier of the account to display and import.
 */
function AccountComponent({ account_id }: { account_id: string }) {
    //TODO: load account details

    const [isLoading, setIsLoading] = useState(false);
    const handleAccountImport = (account_id: string) => {
        console.log('Importing account:', account_id);
        setIsLoading(true);

        axios
            .post(`/api/bank-data/gocardless/import/account`, {
                account_id: account_id,
            })
            .then((response) => {
                setIsLoading(false);
                console.log('Account imported:', response.data);
            })
            .catch((error) => {
                setIsLoading(false);
                console.error('Error importing account:', error);
            });
    };

    return (
        <div key={account_id} className="bg-background rounded p-3 text-sm">
            <div className="grid grid-cols-2 gap-2">
                <div>
                    <span className="text-gray-500 dark:text-gray-400">ID:</span>
                    <span className="ml-2 text-gray-900 dark:text-white">{account_id}</span>
                </div>
                <div>
                    <span className="text-gray-500 dark:text-gray-400">IBAN:</span>
                    <span className="ml-2 text-gray-900 dark:text-white"></span>
                </div>
                <div>
                    <span className="text-gray-500 dark:text-gray-400">Owner:</span>
                    <span className="ml-2 text-gray-900 dark:text-white"></span>
                </div>
                <div>
                    <span className="text-gray-500 dark:text-gray-400">Status:</span>
                    <span className="ml-2 text-gray-900 dark:text-white"></span>
                </div>
                <div>
                    <span className="text-gray-500 dark:text-gray-400">Last Accessed:</span>
                    <span className="ml-2 text-gray-900 dark:text-white"></span>
                </div>
            </div>

            {isLoading ? (
                <div className="border-foreground ms-4 mt-2 h-8 w-8 animate-spin rounded-full border-2 border-t-transparent"></div>
            ) : (
                <Button variant="default" size="sm" className="mt-2" onClick={() => handleAccountImport(account_id)}>
                    Import
                </Button>
            )}
        </div>
    );
}
