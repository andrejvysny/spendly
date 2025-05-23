import { DataTable } from '@/components/DataTable';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { BreadcrumbItem, Import } from '@/types/index';
import { formatDate } from '@/utils/date';
import { Head } from '@inertiajs/react';
import { useState } from 'react';
import ImportWizard from './components/ImportWizard';

interface Props {
    imports: Import[];
}

export default function Index({ imports }: Props) {
    const [isWizardOpen, setIsWizardOpen] = useState(false);
    const [importsList, setImportsList] = useState<Import[]>(imports);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Imports',
            href: '/imports',
        },
    ];

    const getStatusBadgeClass = (status: string) => {
        switch (status) {
            case 'completed':
                return 'bg-green-100 text-green-800 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
            case 'completed_skipped_duplicates':
                return 'bg-yellow-100 text-yellow-800 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
            case 'processing':
                return 'bg-blue-100 text-blue-800 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
            case 'partially_failed':
                return 'bg-orange-100 text-orange-800 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
            case 'failed':
                return 'bg-red-100 text-red-800 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
            default:
                return 'bg-gray-100 text-gray-800 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
        }
    };

    const getStatusLabel = (status: string) => {
        switch (status) {
            case 'completed':
                return 'Completed';
            case 'completed_skipped_duplicates':
                return 'Completed (with duplicates)';
            case 'processing':
                return 'Processing';
            case 'partially_failed':
                return 'Partially Failed';
            case 'failed':
                return 'Failed';
            default:
                return status.charAt(0).toUpperCase() + status.slice(1);
        }
    };

    const handleImportComplete = (newImport: Import) => {
        setImportsList([newImport, ...importsList]);
        setIsWizardOpen(false);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Imports" />

            <div className="mx-auto w-full max-w-7xl p-4">
                <div className="mx-auto w-full max-w-7xl">
                    <PageHeader
                        title="Import tasks"
                        buttons={[
                            {
                                onClick: () => setIsWizardOpen(true),
                                label: 'Import',
                            },
                        ]}
                    />
                </div>
            </div>

            <DataTable
                columns={[
                    { header: 'File', key: 'original_filename' },
                    { header: 'Import Date', key: 'created_at', render: (row) => formatDate(row.created_at) },
                    { header: 'Status', key: 'status', render: (row) => <span className={`${getStatusBadgeClass(row.status)}`}>{getStatusLabel(row.status)}</span> },
                    {
                        header: 'Processed',
                        key: 'processed_rows',
                        render: (row) => (
                            <p>
                                {row.processed_rows} / {row.total_rows}
                            </p>
                        ),
                    },
                    { header: 'Actions', key: 'actions', className: 'text-right', render: (row) => <p>Action-{row.id}</p> }, // Custom render for actions column,
                ]}
                emptyMessage="No import tasks found. Please create a new import task."
                data={importsList}
                rowKey={(record) => record.id}
            />

            {isWizardOpen && (
                <div className="fixed inset-0 z-50">
                    <ImportWizard onComplete={handleImportComplete} onCancel={() => setIsWizardOpen(false)} />
                </div>
            )}
        </AppLayout>
    );
}
