import { Button } from '@/components/ui/button';
import { Import, Transaction, Category } from '@/types/index';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';
import CleanStep from './wizard-steps/CleanStep';
import ConfigureStep from './wizard-steps/ConfigureStep';
import ConfirmStep from './wizard-steps/ConfirmStep';
import MapStep from './wizard-steps/MapStep';
import UploadStep from './wizard-steps/UploadStep';

interface ImportWizardProps {
    onComplete: (importData: Import) => void;
    onCancel: () => void;
}

type WizardStep = 'upload' | 'configure' | 'clean' | 'map' | 'confirm';

export default function ImportWizard({ onComplete, onCancel }: ImportWizardProps) {
    const [currentStep, setCurrentStep] = useState<WizardStep>('upload');
    const [uploadedData, setUploadedData] = useState<{
        importId: number;
        headers: string[];
        sampleRows: string[][];
        accountId: number;
        totalRows: number;
    } | null>(null);
    const [configuredData, setConfiguredData] = useState<{
        columnMapping: Record<string, number | null>;
        dateFormat: string;
        amountFormat: string;
        amountTypeStrategy: string;
        currency: string;
    } | null>(null);
    const [previewData, setPreviewData] = useState<Partial<Transaction>[]>([]);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // Map step configuration
    const [categories, setCategories] = useState<Category[]>([]);
    const [categoryMappings, setCategoryMappings] = useState<Record<string, Record<string, string>>>({});

    // Load categories for mapping step
    useEffect(() => {
        if (currentStep === 'map') {
            axios
                .get('/imports/categories')
                .then((response) => {
                    setCategories(response.data.categories);
                })
                .catch((error) => {
                    console.error('Failed to load categories', error);
                    setError('Failed to load categories');
                });
        }
    }, [currentStep]);

    const handleUploadComplete = useCallback(
        (data: { importId: number; headers: string[]; sampleRows: string[][]; accountId: number; totalRows: number }) => {
            setUploadedData(data);
            setCurrentStep('configure');
        },
        [],
    );

    const handleConfigureComplete = useCallback(
        (data: {
            columnMapping: Record<string, number | null>;
            dateFormat: string;
            amountFormat: string;
            amountTypeStrategy: string;
            currency: string;
            previewData: Partial<Transaction>[];
        }) => {
            setConfiguredData({
                columnMapping: data.columnMapping,
                dateFormat: data.dateFormat,
                amountFormat: data.amountFormat,
                amountTypeStrategy: data.amountTypeStrategy,
                currency: data.currency,
            });
            setPreviewData(data.previewData);
            setCurrentStep('clean');
        },
        [],
    );

    const handleCleanComplete = useCallback((cleanedData: Partial<Transaction>[]) => {
        setPreviewData(cleanedData);
        setCurrentStep('map');
    }, []);

    const handleMapComplete = useCallback((mappings: Record<string, Record<string, string>>) => {
        setCategoryMappings(mappings);
        setCurrentStep('confirm');
    }, []);

    const handleProcessImport = useCallback(async () => {
        if (!uploadedData || !configuredData) return;

        setIsLoading(true);
        setError(null);

        try {
            const response = await axios.post(`/imports/${uploadedData.importId}/process`, {
                category_mappings: categoryMappings,
                account_id: uploadedData.accountId,
            });

            onComplete(response.data.import);
        } catch (err) {
            const axiosError = err as import('axios').AxiosError<{ message: string }>;
            setError(axiosError.response?.data?.message || 'Failed to process import');
        } finally {
            setIsLoading(false);
        }
    }, [uploadedData, configuredData, categoryMappings, onComplete]);

    const renderStepContent = () => {
        switch (currentStep) {
            case 'upload':
                return <UploadStep onComplete={handleUploadComplete} />;
            case 'configure':
                return uploadedData ? (
                    <ConfigureStep
                        headers={uploadedData.headers}
                        sampleRows={uploadedData.sampleRows}
                        importId={uploadedData.importId}
                        onComplete={handleConfigureComplete}
                    />
                ) : null;
            case 'clean':
                return configuredData ? <CleanStep data={previewData} onComplete={handleCleanComplete} /> : null;
            case 'map':
                return <MapStep data={previewData} categories={categories} onComplete={handleMapComplete} />;
            case 'confirm':
                return (
                    <ConfirmStep
                        data={previewData}
                        mappings={categoryMappings}
                        categories={categories}
                        onConfirm={handleProcessImport}
                        isLoading={isLoading}
                        error={error}
                        totalRows={uploadedData?.totalRows || 0}
                    />
                );
            default:
                return null;
        }
    };

    return (
        <div className="bg-background fixed inset-0 z-50 flex flex-col overflow-hidden text-white">
            {/* Header */}
            <div className="absolute top-0 right-0 p-5">
                <Button variant="ghost" onClick={onCancel}>
                    X
                </Button>
            </div>

            {/* Steps Indicator */}
            <div className="px-8 py-4">
                <div className="mx-auto flex max-w-3xl items-center justify-between">
                    <StepIndicator
                        number={1}
                        title="Upload"
                        isActive={currentStep === 'upload'}
                        isCompleted={currentStep === 'configure' || currentStep === 'clean' || currentStep === 'map' || currentStep === 'confirm'}
                        onClick={() => uploadedData && setCurrentStep('upload')}
                    />
                    <StepDivider isActive={currentStep !== 'upload'} />
                    <StepIndicator
                        number={2}
                        title="Configure"
                        isActive={currentStep === 'configure'}
                        isCompleted={currentStep === 'clean' || currentStep === 'map' || currentStep === 'confirm'}
                        onClick={() => configuredData && setCurrentStep('configure')}
                    />
                    <StepDivider isActive={currentStep !== 'upload' && currentStep !== 'configure'} />
                    <StepIndicator
                        number={3}
                        title="Clean"
                        isActive={currentStep === 'clean'}
                        isCompleted={currentStep === 'map' || currentStep === 'confirm'}
                        onClick={() => previewData.length > 0 && currentStep !== 'upload' && currentStep !== 'configure' && setCurrentStep('clean')}
                    />
                    <StepDivider isActive={currentStep === 'map' || currentStep === 'confirm'} />
                    <StepIndicator
                        number={4}
                        title="Map"
                        isActive={currentStep === 'map'}
                        isCompleted={currentStep === 'confirm'}
                        onClick={() => categoryMappings && Object.keys(categoryMappings).length > 0 && setCurrentStep('map')}
                    />
                    <StepDivider isActive={currentStep === 'confirm'} />
                    <StepIndicator
                        number={5}
                        title="Confirm"
                        isActive={currentStep === 'confirm'}
                        isCompleted={false}
                        onClick={() => Object.keys(categoryMappings).length > 0 && setCurrentStep('confirm')}
                    />
                </div>
            </div>

            {/* Content */}
            <div className="flex-1 overflow-auto p-6">{renderStepContent()}</div>
        </div>
    );
}

interface StepIndicatorProps {
    number: number;
    title: string;
    isActive: boolean;
    isCompleted: boolean;
    onClick: () => void;
}

function StepIndicator({ number, title, isActive, isCompleted, onClick }: StepIndicatorProps) {
    const bgColor = isActive
        ? 'bg-foreground text-current font-semibold'
        : isCompleted
          ? 'bg-green-500 text-current font-semibold'
          : ' text-foreground border font-semibold border-foreground';

    return (
        <div className="flex cursor-pointer flex-col items-center" onClick={onClick}>
            <div className={`flex h-8 w-8 items-center justify-center rounded-full ${bgColor}`}>{isCompleted ? '✓' : number}</div>
            <div className="text-foreground mt-2 text-sm">{title}</div>
        </div>
    );
}

function StepDivider({ isActive }: { isActive: boolean }) {
    return <div className={`h-1 w-12 ${isActive ? 'bg-green-500' : 'bg-foreground'}`} />;
}
