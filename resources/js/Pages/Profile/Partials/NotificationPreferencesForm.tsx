import { categoryStyle } from '@/components/notifications/categories';
import { cn } from '@/lib/utils';
import type { NotificationCategoryPreference, NotificationChannels } from '@/types';
import axios from 'axios';
import { useState } from 'react';
import { toast } from 'sonner';

const CHANNELS: { key: keyof NotificationChannels; label: string }[] = [
    { key: 'in_app', label: 'In-app' },
    { key: 'push', label: 'Push' },
    { key: 'email', label: 'Email' },
];

function ChannelSwitch({ checked, onChange, label, disabled }: { checked: boolean; onChange: (on: boolean) => void; label: string; disabled?: boolean }) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            title={label}
            disabled={disabled}
            onClick={() => onChange(!checked)}
            className={cn(
                'relative inline-flex h-5 w-9 shrink-0 cursor-pointer items-center rounded-full border-2 border-transparent transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background disabled:cursor-wait disabled:opacity-60',
                checked ? 'bg-primary' : 'bg-input',
            )}
        >
            <span className={cn('pointer-events-none block size-4 rounded-full bg-background shadow-sm ring-0 transition-transform', checked ? 'translate-x-4' : 'translate-x-0')} />
        </button>
    );
}

/**
 * Profile → Notifications (BRAI-102): per category, which channels deliver — saved at once
 * (`notifications.preferences`, stored in users.notification_preferences).
 */
export default function NotificationPreferencesForm({ categories: initial, className = '' }: { categories: NotificationCategoryPreference[]; className?: string }) {
    const [categories, setCategories] = useState(initial);
    const [saving, setSaving] = useState<string | null>(null);

    const toggle = async (category: NotificationCategoryPreference, channel: keyof NotificationChannels, on: boolean) => {
        const before = categories;
        const id = `${category.key}.${channel}`;
        setSaving(id);
        setCategories((prev) => prev.map((c) => (c.key === category.key ? { ...c, channels: { ...c.channels, [channel]: on } } : c)));
        try {
            const res = await axios.patch<{ categories: NotificationCategoryPreference[] }>(route('notifications.preferences'), {
                preferences: { [category.key]: { [channel]: on } },
            });
            setCategories(res.data.categories);
            toast.success(`${category.label}: ${CHANNELS.find((c) => c.key === channel)?.label} ${on ? 'on' : 'off'}`);
        } catch {
            setCategories(before);
            toast.error('Could not save the setting.');
        } finally {
            setSaving(null);
        }
    };

    return (
        <div className={className}>
            <div className="overflow-hidden rounded-lg border">
                <table className="w-full text-sm">
                    <caption className="sr-only">Notification channels per category</caption>
                    <thead className="bg-muted/40 text-xs text-muted-foreground">
                        <tr>
                            <th scope="col" className="px-3 py-2 text-left font-medium">
                                Notify me about
                            </th>
                            {CHANNELS.map((c) => (
                                <th key={c.key} scope="col" className="w-14 px-1 py-2 text-center font-medium sm:w-20">
                                    {c.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y">
                        {categories.map((category) => {
                            const style = categoryStyle(category.key);
                            const Icon = style.icon;
                            return (
                                <tr key={category.key} data-category={category.key}>
                                    <th scope="row" className="px-3 py-2.5 text-left font-normal">
                                        <div className="flex items-start gap-2.5">
                                            <span className={cn('mt-0.5 hidden size-7 shrink-0 items-center justify-center rounded-full sm:flex', style.tint)}>
                                                <Icon className="size-3.5" aria-hidden />
                                            </span>
                                            <div className="min-w-0">
                                                <p className="font-medium">{category.label}</p>
                                                <p className="text-xs text-muted-foreground">{category.description}</p>
                                            </div>
                                        </div>
                                    </th>
                                    {CHANNELS.map((c) => (
                                        <td key={c.key} className="px-1 py-2.5 text-center align-middle">
                                            <ChannelSwitch
                                                checked={category.channels[c.key]}
                                                onChange={(on) => toggle(category, c.key, on)}
                                                label={`${category.label}: ${c.label}`}
                                                disabled={saving === `${category.key}.${c.key}`}
                                            />
                                        </td>
                                    ))}
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            <p className="mt-2 text-xs text-muted-foreground">
                In-app is the bell and the inbox. Push reaches every browser where you turned on browser notifications below. Email goes to your account address.
            </p>
        </div>
    );
}
