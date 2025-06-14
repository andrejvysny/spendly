import HeadingSmall from '@/components/app/heading-small';
import InputError from '@/components/app/input-error';
import GoCardlessImportWizard from '@/components/settings/GoCardlessImportWizard';
import Requisition from '@/components/settings/requisition';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, useForm } from '@inertiajs/react';
import axios from 'axios';
import { FormEventHandler, useEffect, useState } from 'react';

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

type BankDataForm = {
    gocardless_secret_id: string;
    gocardless_secret_key: string;
};

export default function BankData({ gocardless_secret_id, gocardless_secret_key }: { gocardless_secret_id?: string; gocardless_secret_key?: string }) {
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm<BankDataForm>({
        gocardless_secret_id: gocardless_secret_id || '',
        gocardless_secret_key: gocardless_secret_key || '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('bank_data.update'), {
            preserveScroll: true,
        });
    };

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

        if (!gocardless_secret_id || !gocardless_secret_key) {
            setIsLoading(false);
            return;
        }
        fetchRequisitions();
    }, [gocardless_secret_id, gocardless_secret_key]);

    const handlePurgeCredentials = () => {
        if (!confirm('Are you sure you want to clear your GoCardless credentials? This action cannot be undone.')) {
            return;
        }

        try {
            axios.delete(route('bank_data.purgeGoCardlessCredentials')).then(() => {
                alert('GoCardless credentials cleared successfully.');
                setRequisitions({ count: 0, next: null, previous: null, results: [] });
                setData({ gocardless_secret_id: '', gocardless_secret_key: '' });
                window.location.reload(); // TODO proper reload of content
            });
        } catch (error) {
            console.error('Error purging credentials:', error);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Data settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="GoCardless Bank Data Settings" description="Setup account sync from your bank" />

                    <div>
                        <p className="text-muted-foreground">
                            Connect your bank account to automatically sync transactions and manage your finances more effectively. This integration
                            allows you to view and manage your bank data directly within the app.
                        </p>

                        <form onSubmit={submit} className="mt-6 space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="gocardless_secret_id">GoCardless Secret ID</Label>
                                <Input
                                    id="gocardless_secret_id"
                                    type="password"
                                    className="mt-1 block w-full"
                                    value={data.gocardless_secret_id || ''}
                                    onChange={(e) => setData('gocardless_secret_id', e.target.value)}
                                    autoComplete="off"
                                    placeholder="Enter your GoCardless Secret ID"
                                />
                                <InputError className="mt-2" message={errors.gocardless_secret_id} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="gocardless_secret_key">GoCardless Secret Key</Label>
                                <Input
                                    id="gocardless_secret_key"
                                    type="password"
                                    className="mt-1 block w-full"
                                    value={data.gocardless_secret_key || ''}
                                    onChange={(e) => setData('gocardless_secret_key', e.target.value)}
                                    autoComplete="off"
                                    placeholder="Enter your GoCardless Secret Key"
                                />
                                <InputError className="mt-2" message={errors.gocardless_secret_key} />
                            </div>

                            <div className="flex items-center gap-4">
                                <Button disabled={processing}>Save</Button>
                                <Transition
                                    show={recentlySuccessful}
                                    enter="transition ease-in-out"
                                    enterFrom="opacity-0"
                                    leave="transition ease-in-out"
                                    leaveTo="opacity-0"
                                >
                                    <p className="text-sm text-neutral-600">Saved</p>
                                </Transition>
                            </div>
                        </form>
                        {data.gocardless_secret_id &&
                        data.gocardless_secret_key &&
                        !processing &&
                        !errors.gocardless_secret_id &&
                        !errors.gocardless_secret_key ? (
                            <Button variant="destructive" onClick={() => handlePurgeCredentials()}>
                                Clear credentials
                            </Button>
                        ) : null}
                    </div>

                    <Button onClick={() => setIsImportWizardOpen(true)} disabled={!gocardless_secret_key || !gocardless_secret_id}>
                        Connect Bank Account
                    </Button>

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
