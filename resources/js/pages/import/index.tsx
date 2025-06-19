import { DataTable } from '@/components/DataTable';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/layouts/page-header';
import { BreadcrumbItem, Import } from '@/types/index';
import { formatDate } from '@/utils/date';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import ImportWizard from './components/ImportWizard';
interface Props {
    imports: Import[];
}

/**
 * Displays and manages a list of import tasks, allowing users to view, create, and revert imports.
 *
 * Renders a data table of import tasks with status indicators and actions. Users can initiate new imports via a wizard and revert existing imports, which removes them from the list upon confirmation and successful server response.
 *
 * @param imports - Initial array of import tasks to display.
 */
export default function Index({ imports }: Props) {
    const [isWizardOpen, setIsWizardOpen] = useState(false);
    const [importsList, setImportsList] = useState<Import[]>(imports);

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Imports',
            href: '/imports',
        },
    ];

    const handleDeleteImport = (importId: number) => {
        if (confirm('Are you sure you want to delete this import? This action cannot be undone.')) {
            axios.delete(route('imports.delete', { import: importId })).then((r) => {
                if (r.status === 200) {
                    setImportsList(importsList.filter((imp) => imp.id !== importId));
                } else {
                    alert('Failed to delete import. Please try again later.');
                }
            });
        }
    };

    const handleRevertImport = (importId: number) => {
        if (confirm('Are you sure you want to revert this import? This action cannot be undone.')) {
            axios.post(route('imports.revert', { import: importId })).then((r) => {
                if (r.status === 200) {
                    setImportsList((prevState) => prevState.map((imp) => (imp.id === importId ? { ...imp, status: 'reverted' } : imp)));
                } else {
                    alert('Failed to revert import. Please try again later.');
                }
            });
        }
    };

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
                return 'bg-gray-200 text-gray-800 inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium';
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
                    {
                        header: 'Status',
                        key: 'status',
                        render: (row) => <span className={`${getStatusBadgeClass(row.status)}`}>{getStatusLabel(row.status)}</span>,
                    },
                    {
                        header: 'Processed',
                        key: 'processed_rows',
                        render: (row) => (
                            <p>
                                {row.processed_rows} / {row.total_rows}
                            </p>
                        ),
                    },
                    {
                        header: 'Actions',
                        key: 'actions',
                        className: 'text-right',
                        render: (row) => (
                            <>
                                <Button variant="outline_destructive" size="sm" onClick={() => handleRevertImport(row.id)}>
                                    Revert
                                </Button>

                                {row.status == 'pending' || row.status == 'failed' || row.status == 'reverted' ? (
                                    <Button variant="destructive" size="sm" onClick={() => handleDeleteImport(row.id)}>
                                        Delete
                                    </Button>
                                ) : null}
                            </>
                        ),
                    }, // Custom render for actions column,
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
