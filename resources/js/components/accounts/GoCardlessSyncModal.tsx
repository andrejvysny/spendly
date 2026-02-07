import Requisition, { type RequisitionsResponse } from '@/components/settings/requisition';
import GoCardlessImportWizard from '@/components/settings/GoCardlessImportWizard';
import { Button } from '@/components/ui/button';
import { Dialog } from '@headlessui/react';
import { XMarkIcon } from '@heroicons/react/24/outline';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { toast } from 'react-toastify';

interface GoCardlessSyncModalProps {
    isOpen: boolean;
    onClose: () => void;
    onAccountImported?: () => void;
}

/**
 * Modal for the accounts page: lists linked bank connections (requisitions) with Import per account,
 * and offers "Connect new bank" to run the GoCardless wizard (country → bank → redirect).
 */
export default function GoCardlessSyncModal({ isOpen, onClose, onAccountImported }: GoCardlessSyncModalProps) {
    const [view, setView] = useState<'list' | 'wizard'>('list');
    const [requisitions, setRequisitions] = useState<RequisitionsResponse>({
        count: 0,
        next: null,
        previous: null,
        results: [],
    });
    const [isLoadingRequisitions, setIsLoadingRequisitions] = useState(true);

    const fetchRequisitions = useCallback(async () => {
        try {
            const response = await axios.get('/api/bank-data/gocardless/requisitions');
            setRequisitions(response.data);
        } catch (error) {
            console.error('Error fetching requisitions:', error);
            toast.error('Failed to load bank connections.');
        } finally {
            setIsLoadingRequisitions(false);
        }
    }, []);

    useEffect(() => {
        if (isOpen) {
            setView('list');
            setIsLoadingRequisitions(true);
            fetchRequisitions();
        }
    }, [isOpen, fetchRequisitions]);

    const handleRefresh = useCallback(() => {
        fetchRequisitions();
        onAccountImported?.();
        router.reload({ only: ['accounts'] });
    }, [fetchRequisitions, onAccountImported]);

    const showList = view === 'list';
    const showWizard = view === 'wizard';

    return (
        <Dialog open={isOpen} onClose={() => {}} className="relative z-50">
            <div className="fixed inset-0 bg-black/30" aria-hidden="true" />

            <div className="fixed inset-0 flex items-center justify-center p-4">
                <Dialog.Panel className="bg-card mx-auto flex w-full max-w-2xl max-h-[90vh] flex-col rounded-xl shadow-lg">
                    <div className="flex shrink-0 items-center justify-between border-b px-6 py-4">
                        <Dialog.Title className="text-foreground text-xl font-semibold">Sync from GoCardless</Dialog.Title>
                        <button type="button" onClick={onClose} className="hover:text-foreground text-gray-400" aria-label="Close">
                            <XMarkIcon className="h-6 w-6" />
                        </button>
                    </div>

                    <div className="flex min-h-0 flex-1 flex-col overflow-y-auto p-6">
                        {showList && (
                            <>
                                <h3 className="text-foreground mb-3 text-sm font-medium">Linked bank connections</h3>
                                {isLoadingRequisitions ? (
                                    <div className="flex flex-col items-center justify-center py-12">
                                        <div className="border-foreground h-10 w-10 animate-spin rounded-full border-4 border-t-transparent" />
                                        <p className="text-muted-foreground mt-3 text-sm">Loading…</p>
                                    </div>
                                ) : requisitions.results.length === 0 ? (
                                    <p className="text-muted-foreground py-4 text-sm">No bank connections yet. Connect a bank to import its accounts.</p>
                                ) : (
                                    <div className="space-y-4">
                                        {requisitions.results.map((req) => (
                                            <Requisition
                                                key={req.id}
                                                requisition={req}
                                                setRequisitions={setRequisitions}
                                                onRefresh={handleRefresh}
                                            />
                                        ))}
                                    </div>
                                )}

                                <div className="mt-6 border-t pt-4">
                                    <Button onClick={() => setView('wizard')} variant="outline" className="w-full sm:w-auto">
                                        Connect new bank
                                    </Button>
                                </div>
                            </>
                        )}

                        {showWizard && (
                            <GoCardlessImportWizard
                                isOpen
                                embed
                                returnTo="accounts"
                                onClose={() => setView('list')}
                                onSuccess={() => {
                                    onClose();
                                    handleRefresh();
                                }}
                            />
                        )}
                    </div>
                </Dialog.Panel>
            </div>
        </Dialog>
    );
}
