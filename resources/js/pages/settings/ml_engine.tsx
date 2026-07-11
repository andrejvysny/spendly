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

interface PerClassMetrics {
    precision: number;
    recall: number;
    f1: number;
    support: number;
}

interface MlMetrics {
    version: number;
    trained_at: string | null;
    training_samples: number;
    evaluation: {
        samples: number;
        temporal?: {
            error?: string;
            samples?: number;
            accuracy?: number;
            f1_weighted?: number;
            f1_macro?: number;
            per_class?: Record<string, PerClassMetrics>;
        };
        thresholds?: Record<string, number>;
    };
}

interface Props {
    settings: MlSettings | null;
    metrics: MlMetrics | null;
    categoryNames?: Record<string, string>;
}

export default function MlEngine({ settings, metrics, categoryNames = {} }: Props) {
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

                    {metrics?.evaluation && (
                        <div className="space-y-4 rounded-lg border p-4">
                            <HeadingSmall
                                title="Model Quality"
                                description="Temporal holdout: trained on the oldest 80% of your labels, tested on the newest 20% — how the model performs on future imports"
                            />

                            {metrics.evaluation.temporal?.error ? (
                                <p className="text-muted-foreground text-sm">{metrics.evaluation.temporal.error}</p>
                            ) : (
                                <>
                                    <div className="grid gap-4 sm:grid-cols-4">
                                        <div>
                                            <p className="text-muted-foreground text-sm font-medium">Accuracy</p>
                                            <p className="text-lg font-semibold">
                                                {((metrics.evaluation.temporal?.accuracy ?? 0) * 100).toFixed(1)}%
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-sm font-medium">F1 (weighted)</p>
                                            <p className="text-lg font-semibold">
                                                {((metrics.evaluation.temporal?.f1_weighted ?? 0) * 100).toFixed(1)}%
                                            </p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-sm font-medium">Training Samples</p>
                                            <p className="text-lg font-semibold">{metrics.training_samples}</p>
                                        </div>
                                        <div>
                                            <p className="text-muted-foreground text-sm font-medium">Model Version</p>
                                            <p className="text-lg font-semibold">v{metrics.version}</p>
                                        </div>
                                    </div>

                                    {metrics.evaluation.temporal?.per_class && (
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="text-muted-foreground border-b text-left">
                                                        <th className="py-1 pr-4 font-medium">Category</th>
                                                        <th className="py-1 pr-4 font-medium">Precision</th>
                                                        <th className="py-1 pr-4 font-medium">Recall</th>
                                                        <th className="py-1 pr-4 font-medium">F1</th>
                                                        <th className="py-1 pr-4 font-medium">Samples</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {Object.entries(metrics.evaluation.temporal.per_class)
                                                        .sort(([, a], [, b]) => b.support - a.support)
                                                        .map(([id, c]) => (
                                                            <tr key={id} className="border-b last:border-0">
                                                                <td className="py-1 pr-4">{categoryNames[id] ?? `#${id}`}</td>
                                                                <td className="py-1 pr-4">{(c.precision * 100).toFixed(0)}%</td>
                                                                <td className="py-1 pr-4">{(c.recall * 100).toFixed(0)}%</td>
                                                                <td className="py-1 pr-4">{(c.f1 * 100).toFixed(0)}%</td>
                                                                <td className="py-1 pr-4">{c.support}</td>
                                                            </tr>
                                                        ))}
                                                </tbody>
                                            </table>
                                            <p className="text-muted-foreground mt-2 text-xs">
                                                Weak categories (low F1, few samples) improve fastest by labeling more of their transactions.
                                            </p>
                                        </div>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
