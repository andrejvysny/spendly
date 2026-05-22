import HeadingSmall from '@/components/app/heading-small';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import axios from 'axios';
import { FormEventHandler, useState } from 'react';
import { toast } from 'react-toastify';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'ML Engine',
        href: '/settings/ml_engine',
    },
];

interface MlSettings {
    auto_retrain: boolean;
    retrain_threshold: number;
    model_version: string | null;
    last_trained_at: string | null;
}

interface Props {
    settings: MlSettings | null;
}

export default function MlEngine({ settings }: Props) {
    const { data, setData, patch, processing } = useForm<{
        auto_retrain: boolean;
        retrain_threshold: number;
    }>({
        auto_retrain: settings?.auto_retrain ?? false,
        retrain_threshold: settings?.retrain_threshold ?? 10,
    });

    const [isRetraining, setIsRetraining] = useState(false);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        patch(route('ml_engine.update'), {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('ML settings saved successfully');
            },
        });
    };

    const handleRetrain = async () => {
        setIsRetraining(true);
        try {
            const response = await axios.post('/settings/ml_engine/retrain');
            if (response.data.success) {
                toast.success('ML model retraining started. This may take a few minutes.');
            } else {
                toast.error(response.data.message || 'Failed to start retraining');
            }
        } catch {
            toast.error('Failed to start ML retraining. Please try again.');
        } finally {
            setIsRetraining(false);
        }
    };

    const formatDate = (dateString: string | null) => {
        if (!dateString) return 'Never';
        return new Date(dateString).toLocaleString();
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="ML Engine Settings" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="ML Engine Settings"
                        description="Configure machine learning personalization for transaction categorization"
                    />

                    <form onSubmit={submit} className="space-y-6">
                        <div className="space-y-4">
                            <div className="flex items-center justify-between rounded-lg border p-4">
                                <div className="space-y-0.5">
                                    <Label htmlFor="auto_retrain" className="text-base">
                                        Auto-Retrain Model
                                    </Label>
                                    <p className="text-muted-foreground text-sm">Automatically retrain the model after threshold is reached</p>
                                </div>
                                <Switch
                                    id="auto_retrain"
                                    checked={data.auto_retrain}
                                    onCheckedChange={(checked) => setData('auto_retrain', checked)}
                                />
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor="threshold">Retrain Threshold</Label>
                                <p className="text-muted-foreground text-sm">Number of manual categorizations before auto-retraining</p>
                                <Input
                                    id="threshold"
                                    type="number"
                                    min={5}
                                    max={100}
                                    value={data.retrain_threshold}
                                    onChange={(e) => setData('retrain_threshold', parseInt(e.target.value) || 10)}
                                    className="max-w-xs"
                                />
                            </div>
                        </div>

                        <Button type="submit" disabled={processing}>
                            Save Settings
                        </Button>
                    </form>

                    <div className="space-y-4 rounded-lg border p-4">
                        <HeadingSmall title="Model Status" description="Current ML model information" />

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <p className="text-muted-foreground text-sm font-medium">Last Trained</p>
                                <p className="text-sm">{formatDate(settings?.last_trained_at ?? null)}</p>
                            </div>
                            <div>
                                <p className="text-muted-foreground text-sm font-medium">Model Version</p>
                                <p className="text-sm">{settings?.model_version ?? 'Not trained yet'}</p>
                            </div>
                        </div>

                        <Button onClick={handleRetrain} disabled={isRetraining} variant="secondary">
                            {isRetraining ? 'Retraining...' : 'Retrain Model Now'}
                        </Button>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
