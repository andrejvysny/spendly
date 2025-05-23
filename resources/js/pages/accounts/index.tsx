import CreateAccountModal from '@/components/accounts/CreateAccountModal';
import GoCardlessImportWizard from '@/components/accounts/GoCardlessImportWizard';
import ValueSplit from '@/components/ui/value-split';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { Account } from '@/types/index';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { formatAmount } from '@/utils/currency';

interface Props {
    accounts: Account[];
}

export default function Index({ accounts }: Props) {
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isImportWizardOpen, setIsImportWizardOpen] = useState(false);

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
                            {
                                onClick: () => setIsImportWizardOpen(true),
                                label: 'Import Account',
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
                                        <h3 className="text-lg font-medium">{account.name}</h3>
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
