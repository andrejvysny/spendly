import HeadingSmall from '@/components/app/heading-small';
import InputError from '@/components/app/input-error';
import GoCardlessImportWizard from '@/components/settings/GoCardlessImportWizard';
import Requisition, { RequisitionDto } from '@/components/settings/requisition';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Transition } from '@headlessui/react';
import { Head, router, useForm } from '@inertiajs/react';
import axios from 'axios';
import { AlertTriangle } from 'lucide-react';
import { FormEventHandler, useCallback, useEffect, useState } from 'react';
import { toast } from 'react-toastify';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Bank Data Settings',
        href: '/settings/bank_data',
    },
];

interface RequisitionsResponse {
    count: number;
    results: RequisitionDto[];
}

type BankDataForm = {
    gocardless_secret_id: string;
    gocardless_secret_key: string;
};

type CredentialSource = 'user' | 'instance' | 'none';

interface BankDataProps {
    /** Whether bank sync has usable credentials from any source. */
    has_gocardless_credentials: boolean;
    gocardless_credential_source: CredentialSource;
    gocardless_has_instance_credentials: boolean;
    gocardless_has_user_override: boolean;
    /** Masked tail of the user's own Secret ID. Always null for instance credentials — those are shared. */
    gocardless_secret_id_masked: string | null;
    gocardless_use_mock?: boolean;
    /** Non-null when APP_URL cannot produce a working bank redirect in production. */
    app_url_warning: string | null;
}

export default function BankData({
    has_gocardless_credentials,
    gocardless_credential_source,
    gocardless_has_instance_credentials,
    gocardless_has_user_override,
    gocardless_secret_id_masked,
    gocardless_use_mock = false,
    app_url_warning,
}: BankDataProps) {
    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm<BankDataForm>({
        gocardless_secret_id: '',
        gocardless_secret_key: '',
    });

    // When the server already supplies credentials the personal form is opt-in: most users on such
    // an installation never need it, and showing it unprompted reads as "you must fill this in".
    const [showOverrideForm, setShowOverrideForm] = useState(gocardless_has_user_override);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        patch(route('bank_data.update'), {
            preserveScroll: true,
        });
    };

    const [isImportWizardOpen, setIsImportWizardOpen] = useState(false);
    const [requisitions, setRequisitions] = useState<RequisitionsResponse>({ count: 0, results: [] });
    const [isLoading, setIsLoading] = useState(true);
    const [isRefreshing, setIsRefreshing] = useState(false);

    const fetchRequisitions = useCallback(async () => {
        try {
            const response = await axios.get('/api/bank-data/gocardless/requisitions');
            setRequisitions(response.data);
        } catch (error) {
            console.error('Error fetching requisitions:', error);
            if (axios.isAxiosError(error) && error.response?.status === 429) {
                const retryAfter = error.response?.data?.retry_after ?? 60;
                toast.error(`Rate limited by bank. Please wait ${retryAfter}s and try again.`);
            } else {
                toast.error('Failed to load requisitions. Please try again.');
            }
        } finally {
            setIsLoading(false);
            setIsRefreshing(false);
        }
    }, []);

    useEffect(() => {
        if (gocardless_use_mock || has_gocardless_credentials) {
            fetchRequisitions();
            return;
        }
        setIsLoading(false);
    }, [gocardless_use_mock, has_gocardless_credentials, fetchRequisitions]);

    const reconnectCount = requisitions.results.filter((req) => req.needs_reconnect).length;

    const handleRefreshRequisitions = () => {
        if (!gocardless_use_mock && !has_gocardless_credentials) return;
        setIsRefreshing(true);
        fetchRequisitions();
    };

    const handlePurgeCredentials = () => {
        const consequence = gocardless_has_instance_credentials
            ? 'Bank sync will fall back to the credentials configured by the server administrator.'
            : 'Bank sync will stop until you add credentials again.';

        if (!confirm(`Remove your personal GoCardless credentials? ${consequence}`)) {
            return;
        }

        axios
            .delete(route('bank_data.purgeGoCardlessCredentials'))
            .then(() => {
                toast.success(`Personal credentials removed. ${consequence}`);
                setRequisitions({ count: 0, results: [] });
                router.reload();
            })
            .catch((error) => {
                console.error('Error purging credentials:', error);
                toast.error('Failed to remove credentials. Please try again.');
            });
    };

    const credentialForm = (
        <>
            {gocardless_secret_id_masked && (
                <div className="mt-4 rounded-md border p-4">
                    <p className="text-sm">
                        <span className="text-muted-foreground">Your Secret ID:</span>{' '}
                        <code className="text-foreground">{gocardless_secret_id_masked}</code>
                    </p>
                </div>
            )}

            <form onSubmit={submit} className="mt-6 space-y-6">
                <div className="grid gap-2">
                    <Label htmlFor="gocardless_secret_id">
                        GoCardless Secret ID {gocardless_has_user_override && '(leave blank to keep current)'}
                    </Label>
                    <Input
                        id="gocardless_secret_id"
                        type="password"
                        className="mt-1 block w-full"
                        value={data.gocardless_secret_id || ''}
                        onChange={(e) => setData('gocardless_secret_id', e.target.value)}
                        autoComplete="off"
                        placeholder={gocardless_has_user_override ? 'Enter new Secret ID to update' : 'Enter your GoCardless Secret ID'}
                    />
                    <InputError className="mt-2" message={errors.gocardless_secret_id} />
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="gocardless_secret_key">
                        GoCardless Secret Key {gocardless_has_user_override && '(leave blank to keep current)'}
                    </Label>
                    <Input
                        id="gocardless_secret_key"
                        type="password"
                        className="mt-1 block w-full"
                        value={data.gocardless_secret_key || ''}
                        onChange={(e) => setData('gocardless_secret_key', e.target.value)}
                        autoComplete="off"
                        placeholder={gocardless_has_user_override ? 'Enter new Secret Key to update' : 'Enter your GoCardless Secret Key'}
                    />
                    <InputError className="mt-2" message={errors.gocardless_secret_key} />
                </div>

                <div className="flex items-center gap-4">
                    <Button disabled={processing}>{gocardless_has_instance_credentials ? 'Save personal credentials' : 'Save'}</Button>
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

            {gocardless_has_user_override ? (
                <Button variant="destructive" className="mt-4" onClick={() => handlePurgeCredentials()}>
                    Remove personal credentials
                </Button>
            ) : null}
        </>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Bank Data settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    {app_url_warning && (
                        <Alert variant="destructive">
                            <AlertTriangle className="h-4 w-4" />
                            <AlertTitle title="APP_URL misconfigured" />
                            <AlertDescription>{app_url_warning}</AlertDescription>
                        </Alert>
                    )}

                    <HeadingSmall
                        title={gocardless_use_mock ? 'GoCardless Bank Data Settings (Sandbox)' : 'GoCardless Bank Data Settings'}
                        description={
                            gocardless_use_mock
                                ? 'Sandbox mode: connect mock banks and sync fixture data without credentials.'
                                : 'Setup account sync from your bank'
                        }
                    />

                    {gocardless_use_mock ? (
                        <Alert variant="default">
                            <AlertTitle title="Sandbox mode" />
                            <AlertDescription>
                                GoCardless sandbox (mock) is enabled. No credentials are required. You can connect mock banks (e.g. Revolut, SLSP) and
                                sync fixture data for development and testing.
                            </AlertDescription>
                        </Alert>
                    ) : gocardless_credential_source === 'instance' ? (
                        <div>
                            <Alert variant="default">
                                <AlertTitle title="Managed by this server" />
                                <AlertDescription>
                                    Bank sync is configured by the server administrator. You can connect your bank without entering any GoCardless
                                    credentials of your own.
                                </AlertDescription>
                            </Alert>

                            <div className="mt-4">
                                <Button
                                    type="button"
                                    variant="outline"
                                    aria-expanded={showOverrideForm}
                                    onClick={() => setShowOverrideForm((open) => !open)}
                                >
                                    {showOverrideForm ? 'Hide personal credentials' : 'Use my own GoCardless credentials'}
                                </Button>

                                {showOverrideForm && <div className="mt-2">{credentialForm}</div>}
                            </div>
                        </div>
                    ) : (
                        <div>
                            <p className="text-muted-foreground">
                                Connect your bank account to automatically sync transactions and manage your finances more effectively. This
                                integration allows you to view and manage your bank data directly within the app.
                            </p>

                            {credentialForm}
                        </div>
                    )}

                    <Button onClick={() => setIsImportWizardOpen(true)} disabled={!gocardless_use_mock && !has_gocardless_credentials}>
                        Connect Bank Account
                    </Button>

                    <div className="flex items-center justify-between">
                        <span className="text-muted-foreground text-sm">Requisitions</span>
                        <Button
                            variant="outline"
                            onClick={handleRefreshRequisitions}
                            disabled={(!gocardless_use_mock && !has_gocardless_credentials) || isRefreshing}
                        >
                            {isRefreshing ? 'Refreshing...' : 'Refresh'}
                        </Button>
                    </div>
                </div>

                <div className="mt-6 w-full">
                    <HeadingSmall title="GoCardless Requisitions" description="Linked bank accounts." />

                    {reconnectCount > 0 && (
                        <Alert className="mt-4 border-2 border-amber-500/60 bg-amber-50 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200">
                            <AlertTriangle className="h-4 w-4" />
                            <AlertTitle title="Action required" />
                            <AlertDescription>
                                {reconnectCount} bank connection{reconnectCount === 1 ? '' : 's'} need
                                {reconnectCount === 1 ? 's' : ''} reconnecting. Bank access expires 90 days after you authorize it.
                            </AlertDescription>
                        </Alert>
                    )}

                    {isLoading ? (
                        <div className="flex flex-col items-center justify-center py-12">
                            <div className="border-foreground h-12 w-12 animate-spin rounded-full border-4 border-t-transparent"></div>
                            <p className="text-muted-foreground mt-4">Loading...</p>
                        </div>
                    ) : (
                        <div className="mt-4 grid grid-cols-1 gap-4">
                            {requisitions.results.map((req) => (
                                <Requisition key={req.id} requisition={req} setRequisitions={setRequisitions} onRefresh={fetchRequisitions} />
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
                    router.reload();
                }}
            />
        </AppLayout>
    );
}
