import { router } from '@inertiajs/react';
import { useState } from 'react';
import { formatDistanceToNow } from 'date-fns';
import { Laptop, LogOut, Monitor, Smartphone, Tv } from 'lucide-react';
import { Button } from '@/components/ui/button';
import type { AppDevice } from '@/types';

const PLATFORM_LABELS: Record<AppDevice['platform'], string> = {
    macos: 'macOS',
    windows: 'Windows',
    linux: 'Linux',
    ios: 'iOS',
    android: 'Android',
    android_tv: 'Android TV',
};

const CLIENT_NAMES: Record<AppDevice['client'], string> = {
    desktop: 'Brainitive Desktop',
    mobile: 'Brainitive Mobile',
    tv: 'Brainitive TV',
};

/**
 * Profile → Notifications → "Desktop & mobile apps" (BRAI-385): every signed-in Brainitive app
 * install, with "Sign out" — its tokens stop working at once and the app asks to sign in again.
 */
export default function AppDevices({ devices, className = '' }: { devices: AppDevice[]; className?: string }) {
    const [busy, setBusy] = useState<number | null>(null);

    const signOut = (device: AppDevice) => {
        if (!window.confirm(`Sign out ${device.name}? The app there has to sign in again.`)) return;
        setBusy(device.id);
        router.delete(route('profile.app-devices.destroy', device.id), { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <div className={`space-y-3 ${className}`}>
            <div>
                <p className="text-sm font-medium">Desktop &amp; mobile apps</p>
                <p className="text-sm text-muted-foreground">
                    The Brainitive apps signed in to your account. Sign one out if you no longer use it or lost the device.
                </p>
            </div>
            {devices.length === 0 ? (
                <p className="text-sm text-muted-foreground">No desktop or mobile app is signed in to your account.</p>
            ) : (
                <ul className="divide-y rounded-lg border" aria-label="Signed-in apps">
                    {devices.map((device) => {
                        const Icon = device.client === 'tv' ? Tv : device.client === 'mobile' ? Smartphone : device.platform === 'macos' ? Laptop : Monitor;
                        return (
                            <li key={device.id} className="flex items-center gap-3 px-4 py-3" data-testid="app-device">
                                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <Icon className="size-4" aria-hidden />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-medium">{device.name}</p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {[
                                            `${CLIENT_NAMES[device.client] ?? 'Brainitive app'}${device.app_version ? ` ${device.app_version}` : ''}`,
                                            PLATFORM_LABELS[device.platform] ?? device.platform,
                                        ].join(' · ')}
                                    </p>
                                    <p className="truncate text-xs text-muted-foreground">
                                        {device.last_used_at ? `Last used ${ago(device.last_used_at)}` : 'Not used yet'}
                                        {device.created_at && ` · signed in ${ago(device.created_at)}`}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={busy !== null}
                                    onClick={() => signOut(device)}
                                    aria-label={`Sign out ${device.name}`}
                                >
                                    <LogOut className="size-4" />
                                    <span className="max-sm:sr-only">Sign out</span>
                                </Button>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

function ago(iso: string): string {
    return formatDistanceToNow(new Date(iso), { addSuffix: true });
}
