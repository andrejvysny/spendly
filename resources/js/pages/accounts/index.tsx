import CreateAccountModal from '@/components/accounts/CreateAccountModal';
import ValueSplit from '@/components/ui/value-split';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Account } from '@/types/index';
import { formatAmount } from '@/utils/currency';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';

interface Props {
    accounts: Account[];
}

/**
 * Displays a list of bank accounts and provides functionality to create a new account.
 *
 * Renders account cards with details and a button to open a modal for creating new accounts. Submits new account data via Inertia.js and updates the UI upon success.
 *
 * @param accounts - The list of accounts to display.
 */
export default function Index({ accounts }: Props) {
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);

    const handleCreateAccount = (data: Record<string, string | number | boolean | File | null>) => {
        router.post('/accounts', data, {
            onSuccess: () => {
                setIsCreateModalOpen(false);
            },
        });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'Accounts', href: '/accounts' }]}>
            <Head title="Your accounts" />
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    <PageHeader
                        title="Accounts"
                        buttons={[
                            {
                                onClick: () => setIsCreateModalOpen(true),
                                label: '+ New Account',
                            },
                        ]}
                    />
                </div>
            </div>
            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
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

            <CreateAccountModal isOpen={isCreateModalOpen} onClose={() => setIsCreateModalOpen(false)} onSubmit={handleCreateAccount} />
        </AppLayout>
    );
}
