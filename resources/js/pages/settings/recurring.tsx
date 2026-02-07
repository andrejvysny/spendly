import HeadingSmall from '@/components/app/heading-small';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';
import { toast } from 'react-toastify';

interface RecurringSettings {
    id: number;
    user_id: number;
    scope: string;
    group_by: string;
    amount_variance_type: string;
    amount_variance_value: string;
    min_occurrences: number;
    run_after_import: boolean;
    scheduled_enabled: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Settings', href: '/settings/profile' },
    { title: 'Recurring detection', href: '/settings/recurring' },
];

export default function RecurringSettingsPage() {
    const [settings, setSettings] = useState<RecurringSettings | null>(null);
    const [loading, setLoading] = useState(true);
    const [saving, setSaving] = useState(false);

    useEffect(() => {
        axios
            .get<{ data: RecurringSettings }>('/api/recurring/settings')
            .then((res) => setSettings(res.data.data))
            .catch(() => toast.error('Failed to load settings'))
            .finally(() => setLoading(false));
    }, []);

    const update = (key: keyof RecurringSettings, value: unknown) => {
        if (!settings) return;
        setSettings({ ...settings, [key]: value });
    };

    const save = async () => {
        if (!settings) return;
        setSaving(true);
        try {
            await axios.put('/api/recurring/settings', {
                scope: settings.scope,
                group_by: settings.group_by,
                amount_variance_type: settings.amount_variance_type,
                amount_variance_value: settings.amount_variance_value,
                min_occurrences: settings.min_occurrences,
                run_after_import: settings.run_after_import,
                scheduled_enabled: settings.scheduled_enabled,
            });
            toast.success('Settings saved');
        } catch {
            toast.error('Failed to save settings');
        } finally {
            setSaving(false);
        }
    };

    if (loading || !settings) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Recurring detection settings" />
                <SettingsLayout>
                    <p className="text-muted-foreground">Loading…</p>
                </SettingsLayout>
            </AppLayout>
        );
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recurring detection settings" />
            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Recurring detection"
                        description="Configure how recurring payments and subscriptions are detected from your transactions."
                    />

                    <div className="space-y-4">
                        <div>
                            <Label>Scope</Label>
                            <Select
                                value={settings.scope}
                                onValueChange={(v) => update('scope', v)}
                            >
                                <SelectTrigger className="mt-1 w-full max-w-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="per_account">Per account</SelectItem>
                                    <SelectItem value="per_user">Across all accounts</SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="mt-1 text-muted-foreground text-sm">
                                Detect recurring per account or across all your accounts.
                            </p>
                        </div>

                        <div>
                            <Label>Group by</Label>
                            <Select
                                value={settings.group_by}
                                onValueChange={(v) => update('group_by', v)}
                            >
                                <SelectTrigger className="mt-1 w-full max-w-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="merchant_only">Merchant only</SelectItem>
                                    <SelectItem value="merchant_and_description">Merchant + description fallback</SelectItem>
                                </SelectContent>
                            </Select>
                            <p className="mt-1 text-muted-foreground text-sm">
                                How to identify the same payee (merchant only, or use description when no merchant).
                            </p>
                        </div>

                        <div>
                            <Label>Amount variance type</Label>
                            <Select
                                value={settings.amount_variance_type}
                                onValueChange={(v) => update('amount_variance_type', v)}
                            >
                                <SelectTrigger className="mt-1 w-full max-w-xs">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="percent">Percent</SelectItem>
                                    <SelectItem value="fixed">Fixed amount</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>

                        <div>
                            <Label>Amount variance value</Label>
                            <input
                                type="number"
                                step="0.01"
                                min="0"
                                className="border-input mt-1 h-9 w-full max-w-xs rounded-md border bg-transparent px-3 py-1 text-sm"
                                value={settings.amount_variance_value}
                                onChange={(e) => update('amount_variance_value', e.target.value)}
                            />
                            <p className="mt-1 text-muted-foreground text-sm">
                                {settings.amount_variance_type === 'percent'
                                    ? 'E.g. 5 for ±5%'
                                    : 'E.g. 2.00 for ±2 in account currency'}
                            </p>
                        </div>

                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Run after import</Label>
                                <p className="text-muted-foreground text-sm">
                                    Run recurring detection after each CSV import or bank sync.
                                </p>
                            </div>
                            <Switch
                                checked={settings.run_after_import}
                                onCheckedChange={(v) => update('run_after_import', v)}
                            />
                        </div>

                        <div className="flex items-center justify-between">
                            <div>
                                <Label>Scheduled detection</Label>
                                <p className="text-muted-foreground text-sm">
                                    Run recurring detection on a schedule (e.g. nightly).
                                </p>
                            </div>
                            <Switch
                                checked={settings.scheduled_enabled}
                                onCheckedChange={(v) => update('scheduled_enabled', v)}
                            />
                        </div>
                    </div>

                    <Button onClick={save} disabled={saving}>
                        {saving ? 'Saving…' : 'Save settings'}
                    </Button>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
