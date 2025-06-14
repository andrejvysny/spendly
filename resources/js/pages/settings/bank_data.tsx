import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { FormEventHandler, useEffect } from 'react';
import { useState } from 'react';
import { ChevronDown, ChevronUp } from 'lucide-react';
import GoCardlessImportWizard from '@/components/settings/GoCardlessImportWizard';
import axios from 'axios';
import DeleteUser from '@/components/app/delete-user';
import HeadingSmall from '@/components/app/heading-small';
import InputError from '@/components/app/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Bank Data Settings',
        href: '/settings/bank_data',
    },
];



interface Requisition {
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
    results: Requisition[];
}

interface User {
    gocardless_secret_id: string;
    gocardless_secret_key: string;
    [key: string]: any;
}

interface PageProps {
    auth: {
        user: User;
    };
    [key: string]: any;
}

interface FormData {
    gocardless_secret_id: string;
    gocardless_secret_key: string;
    [key: string]: any;
}

interface BankDataProps {
    access_token: string;
}

export default function BankData({ access_token }: BankDataProps) {
    const { auth } = usePage<PageProps>().props;
    const [isImportWizardOpen, setIsImportWizardOpen] = useState(false);
    const [requisitions, setRequisitions] = useState<RequisitionsResponse>({ count: 0, next: null, previous: null, results: [] });
    const [isLoading, setIsLoading] = useState(true);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [requisitionToDelete, setRequisitionToDelete] = useState<string | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);

    useEffect(() => {
        const fetchRequisitions = async () => {
            try {
                const response = await axios.get('/settings/bank-data/requisitions');
                setRequisitions(response.data);
            } catch (error) {
                console.error('Error fetching requisitions:', error);
            } finally {
                setIsLoading(false);
            }
        };

        fetchRequisitions();
    }, []);

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm<FormData>({
        gocardless_secret_id: auth.user.gocardless_secret_id,
        gocardless_secret_key: auth.user.gocardless_secret_key,
    });

    const confirmDelete = (requisitionId: string) => {
        setRequisitionToDelete(requisitionId);
        setDeleteDialogOpen(true);
    };

    const handleAccountImport = (account: string) => {
        console.log('Importing account:', account);

        axios.post(`/api/gocardless/import/account`, {
            account_id: account,
            requisition_id: requisitionToDelete,
        })
            .then(response => {
                console.log('Account imported:', response.data);
            })
            .catch(error => {
                console.error('Error importing account:', error);
            });
    };

    const deleteRequisition = async () => {
        if (!requisitionToDelete) return;

        setIsDeleting(true);
        try {
            const config = {
                method: 'delete',
                url: `/settings/bank-data/requisitions/${requisitionToDelete}`,
                headers: {
                    'Accept': 'application/json',
                }
            };

            await axios(config);

            // Update local state
            setRequisitions(prev => ({
                ...prev,
                results: prev.results.filter(req => req.id !== requisitionToDelete),
                count: prev.count - 1
            }));

            // Close dialog and reset state
            setDeleteDialogOpen(false);
            setRequisitionToDelete(null);
        } catch (error) {
            console.error('Error deleting requisition:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    const [expandedRequisitions, setExpandedRequisitions] = useState<Set<string>>(new Set());

    const toggleRequisition = (id: string) => {
        const newExpanded = new Set(expandedRequisitions);
        if (newExpanded.has(id)) {
            newExpanded.delete(id);
        } else {
            newExpanded.add(id);
        }
        setExpandedRequisitions(newExpanded);
    };

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        console.log(e);

        patch(route('bank_data.update'), {
            preserveScroll: true,
        });
    };

    const handleRequisitionAction = (id: string) => {
        // Implement your requisition action logic here
        console.log('Handling requisition:', id);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Data settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="GoCardless Bank Data Settings" description="Setup account sync from your bank" />

                    <Button onClick={() => setIsImportWizardOpen(true)}>Connect Bank Account</Button>


                    <div className="flex justify-between items-center">
                        <span>Last updated: {new Date().toLocaleDateString()}</span>
                        <Button variant="outline">Refresh</Button>
                    </div>
                </div>


                <div className="mt-6 w-full">
                    <HeadingSmall title="GoCardless Bank Data Settings" description="Setup account sync from your bank" />

                    {isLoading ? (
                        <div className="flex flex-col items-center justify-center py-12">
                        <div className="h-12 w-12 animate-spin rounded-full border-4 border-foreground border-t-transparent"></div>
                        <p className="mt-4 text-muted-foreground">Loading...</p>
                    </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-4">
                            {requisitions.results.map((requisition) => (
                                <div
                                    key={requisition.id}
                                    className="bg-card rounded-lg shadow p-4 border-foreground"
                                >
                                    <div className="flex justify-between items-start mb-4">
                                        <div>
                                            <h3 className="text-lg font-semibold text-gray-900 dark:text-white">
                                                {requisition.institution_id}
                                            </h3>
                                            <p className="text-sm text-gray-500 dark:text-gray-400">
                                                Created: {new Date(requisition.created).toLocaleDateString()}
                                            </p>
                                        </div>
                                        <span className={`px-2 py-1 text-xs font-medium rounded-full ${
                                            requisition.status === 'LN'
                                                ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300'
                                                : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300'
                                        }`}>
                                            {requisition.status === 'LN' ? 'Linked' : 'Pending'}
                                        </span>
                                    </div>

                                    <div className="space-y-2 text-sm">
                                        <div className="flex justify-between">
                                            <span className="text-gray-500 dark:text-gray-400">ID:</span>
                                            <span className="text-gray-900 dark:text-white font-medium">
                                                {requisition.id}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-500 dark:text-gray-400">Agreement ID:</span>
                                            <span className="text-gray-900 dark:text-white font-medium">
                                                {requisition.agreement}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-500 dark:text-gray-400">Language:</span>
                                            <span className="text-gray-900 dark:text-white font-medium">
                                                {requisition.user_language}
                                            </span>
                                        </div>
                                        <div className="flex justify-between">
                                            <span className="text-gray-500 dark:text-gray-400">Accounts:</span>
                                            <span className="text-gray-900 dark:text-white font-medium">
                                                {requisition.accounts?.length || 0}
                                            </span>
                                        </div>
                                    </div>

                                    {requisition.accounts && requisition.accounts.length > 0 && (
                                        <div className="mt-4">
                                            <button
                                                onClick={() => toggleRequisition(requisition.id)}
                                                className="flex items-center justify-between w-full text-sm text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300"
                                            >
                                                <span>Linked Accounts</span>
                                                {expandedRequisitions.has(requisition.id) ? (
                                                    <ChevronUp className="h-4 w-4" />
                                                ) : (
                                                    <ChevronDown className="h-4 w-4" />
                                                )}
                                            </button>

                                            {expandedRequisitions.has(requisition.id) && (
                                                <div className="mt-2 space-y-2">
                                                    {requisition.accounts.map((account) => (
                                                        <div
                                                            key={account}
                                                            className="bg-background rounded p-3 text-sm"
                                                        >
                                                            <div className="grid grid-cols-2 gap-2">
                                                                        <div>
                                                                    <span className="text-gray-500 dark:text-gray-400">ID:</span>
                                                                    <span className="ml-2 text-gray-900 dark:text-white">
                                                                        {account}
                                                                    </span>
                                                                </div>
                                                                <div>
                                                                    <span className="text-gray-500 dark:text-gray-400">IBAN:</span>
                                                                    <span className="ml-2 text-gray-900 dark:text-white">
                                                                    </span>
                                                                </div>
                                                                <div>
                                                                    <span className="text-gray-500 dark:text-gray-400">Owner:</span>
                                                                    <span className="ml-2 text-gray-900 dark:text-white">

                                                                    </span>
                                                                </div>
                                                                <div>
                                                                    <span className="text-gray-500 dark:text-gray-400">Status:</span>
                                                                    <span className="ml-2 text-gray-900 dark:text-white">

                                                                    </span>
                                                                </div>
                                                                <div>
                                                                    <span className="text-gray-500 dark:text-gray-400">Last Accessed:</span>
                                                                    <span className="ml-2 text-gray-900 dark:text-white">

                                                                    </span>
                                                                </div>
                                                            </div>
                                                            <Button variant="outline" className="mt-2" onClick={() => handleAccountImport(account)}>Import</Button>
                                                        </div>
                                                    ))}
                                                </div>
                                            )}
                                        </div>
                                    )}

                                    <div className="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                                        <div className="flex justify-between items-center">
                                            <a
                                                href={requisition.link}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300"
                                            >
                                                View in GoCardless
                                            </a>
                                            <button
                                                onClick={() => confirmDelete(requisition.id)}
                                                className="text-sm text-red-600 hover:text-red-800 dark:text-red-400 dark:hover:text-red-300"
                                            >
                                                Remove
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}

                    {!isLoading && requisitions.count === 0 && (
                        <div className="text-center py-8">
                            <p className="text-gray-500 dark:text-gray-400">
                                No bank connections found. Add your first bank account to get started.
                            </p>
                        </div>
                    )}
                </div>

            </SettingsLayout>

            <Dialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Delete Bank Connection</DialogTitle>
                        <DialogDescription>
                            Are you sure you want to delete this bank connection? This action cannot be undone.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setDeleteDialogOpen(false)}
                            disabled={isDeleting}
                        >
                            Cancel
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={deleteRequisition}
                            disabled={isDeleting}
                        >
                            {isDeleting ? 'Deleting...' : 'Delete'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <GoCardlessImportWizard
                isOpen={isImportWizardOpen}
                onClose={() => setIsImportWizardOpen(false)}
                onSuccess={() => {
                    setIsImportWizardOpen(false);
                    window.location.reload();
                }}
            />
        </AppLayout>
    );
}
