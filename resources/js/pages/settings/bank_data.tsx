import HeadingSmall from '@/components/app/heading-small';
import GoCardlessImportWizard from '@/components/settings/GoCardlessImportWizard';
import Requisition from '@/components/settings/requisition';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Bank Data Settings',
        href: '/settings/bank_data',
    },
];

interface RequisitionsResponse {
    count: number;
    next: string | null;
    previous: string | null;
    results: Requisition[];
}
export default function BankData() {
    const [isImportWizardOpen, setIsImportWizardOpen] = useState(false);
    const [requisitions, setRequisitions] = useState<RequisitionsResponse>({ count: 0, next: null, previous: null, results: [] });
    const [isLoading, setIsLoading] = useState(true);

    useEffect(() => {
        const fetchRequisitions = async () => {
            try {
                const response = await axios.get('/api/bank-data/gocardless/requisitions');
                setRequisitions(response.data);
            } catch (error) {
                console.error('Error fetching requisitions:', error);
            } finally {
                setIsLoading(false);
            }
        };

        fetchRequisitions();
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Data settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="GoCardless Bank Data Settings" description="Setup account sync from your bank" />

                    <Button onClick={() => setIsImportWizardOpen(true)}>Connect Bank Account</Button>

                    <div className="flex items-center justify-between">
                        <span>Last updated: {new Date().toLocaleDateString()}</span>
                        <Button variant="outline">Refresh</Button>
                    </div>
                </div>

                <div className="mt-6 w-full">
                    <HeadingSmall title="GoCardless Requisitions" description="Linked bank accounts." />

                    {isLoading ? (
                        <div className="flex flex-col items-center justify-center py-12">
                            <div className="border-foreground h-12 w-12 animate-spin rounded-full border-4 border-t-transparent"></div>
                            <p className="text-muted-foreground mt-4">Loading...</p>
                        </div>
                    ) : (
                        <div className="mt-4 grid grid-cols-1 gap-4">
                            {requisitions.results.map((req) => (
                                <Requisition requisition={req} setRequisitions={setRequisitions} />
                            ))}
                        </div>
                    )}

                    {!isLoading && requisitions.count === 0 && (
                        <div className="py-8 text-center">
                            <p className="text-gray-500 dark:text-gray-400">No bank connections found. Add your first bank account to get started.</p>
                        </div>
                    )}
                </div>
            </SettingsLayout>

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
