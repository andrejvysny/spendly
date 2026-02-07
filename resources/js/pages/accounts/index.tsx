import GoCardlessSyncModal from '@/components/accounts/GoCardlessSyncModal';
import ValueSplit from '@/components/ui/value-split';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Account } from '@/types/index';
import { formatAmount } from '@/utils/currency';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';

interface Flash {
    success?: string;
    error?: string;
    open_go_cardless_modal?: boolean;
}

interface Props {
    accounts: Account[];
    gocardless_use_mock?: boolean;
    gocardless_configured?: boolean;
}

/**
 * Displays a list of bank accounts and provides functionality to sync/import accounts from GoCardless.
 *
 * Renders account cards with details and a button to open the GoCardless import wizard (country → bank selection) in a modal. The sync button is enabled when GoCardless is configured (mock mode or user has Secret ID and Key set).
 *
 * @param accounts - The list of accounts to display.
 * @param gocardless_use_mock - When true, sandbox (mock) is enabled; sync button is enabled without user credentials.
 * @param gocardless_configured - When true, user has GoCardless Secret ID and Key set; sync button is enabled.
 */
export default function Index({ accounts, gocardless_use_mock = false, gocardless_configured = false }: Props) {
    const [isGoCardlessModalOpen, setIsGoCardlessModalOpen] = useState(false);
    const { props } = usePage<{ flash?: Flash }>();

    const canSyncFromGoCardless = gocardless_use_mock || gocardless_configured;

    useEffect(() => {
        if (props.flash?.open_go_cardless_modal && canSyncFromGoCardless) {
            setIsGoCardlessModalOpen(true);
        }
    }, [props.flash?.open_go_cardless_modal, canSyncFromGoCardless]);

    const syncButton = {
        onClick: () => setIsGoCardlessModalOpen(true),
        label: 'Sync from GoCardless',
        disabled: !canSyncFromGoCardless,
        ...(canSyncFromGoCardless ? {} : { tooltipContent: 'Configure GoCardless in Settings' }),
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Accounts', href: '/accounts' }]}>
            <Head title="Your accounts" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    <PageHeader title="Accounts" buttons={[syncButton]} />
                </div>
            </div>
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    {!canSyncFromGoCardless && (
                        <p className="text-muted-foreground mb-4 text-sm">
                            <Link href={route('bank_data.edit')} className="underline hover:no-underline">
                                Configure GoCardless
                            </Link>{' '}
                            in Settings to import accounts from your bank.
                        </p>
                    )}
                    <div className="grid grid-cols-1 gap-6 md:grid-cols-2 lg:grid-cols-3">
                        {accounts.map((account) => (
                            <Link
                                key={account.id}
                                href={`/accounts/${account.id}`}
                                className="bg-card block cursor-pointer rounded-lg border-1 p-6 shadow-xs transition-colors hover:border-current"
                            >
                                <div className="mb-4 flex items-start justify-between">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <h3 className="text-lg font-medium">{account.name}</h3>
                                            {account.is_gocardless_synced && (
                                                <Badge variant="secondary" title="Imported from Bank via GoCardless">
                                                    GoCardless
                                                </Badge>
                                            )}
                                        </div>
                                        <p className="text-sm">{account.bank_name}</p>
                                    </div>
                                    <span className="text-sm">{account.currency}</span>
                                </div>

                                <ValueSplit
                                    data={[
                                        { label: 'IBAN', value: account.iban },
                                        { label: 'Balance', value: formatAmount(account.balance, account.currency) },
                                    ]}
                                />
                            </Link>
                        ))}
                    </div>
                </div>
            </div>

            <GoCardlessSyncModal
                isOpen={isGoCardlessModalOpen}
                onClose={() => setIsGoCardlessModalOpen(false)}
                onAccountImported={() => {
                    router.reload({ only: ['accounts'] });
                }}
            />
        </AppLayout>
    );
}
