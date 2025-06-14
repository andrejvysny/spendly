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
import Requisition from '@/components/settings/requisition';

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
                            {requisitions.results.map((req) =>
                                <Requisition requisition={req} setRequisitions={setRequisitions}/>
                            )}
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
