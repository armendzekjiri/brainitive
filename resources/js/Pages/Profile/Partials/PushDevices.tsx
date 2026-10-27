import { formatDistanceToNow } from 'date-fns';
import { AlertTriangle, CheckCircle2, Loader2, Monitor, Send, Smartphone, Trash2, XCircle } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { PushDevice, PushTestResult } from '@/types';

/**
 * Profile → Notifications → "Your devices" (BRAI-154): every browser registered for push, with the
 * push service it uses, when it last received something, its last error, and "Send test" / "Remove".
 * The test result is shown under the row with plain next steps for the device's platform.
 */
export default function PushDevices({
    devices,
    currentId,
    results,
    busy,
    onTest,
    onRemove,
}: {
    devices: PushDevice[];
    /** id of this browser's row (endpoint hash match), if any */
    currentId: number | null;
    results: Record<number, PushTestResult>;
    /** `test:<id>` / `remove:<id>` while a request runs */
    busy: string | null;
    onTest: (device: PushDevice) => void;
    onRemove: (device: PushDevice) => void;
}) {
    if (devices.length === 0) {
        return <p className="text-sm text-muted-foreground">No browser is registered for notifications on your account yet.</p>;
    }

    return (
        <ul className="divide-y rounded-lg border" aria-label="Registered devices">
            {devices.map((device) => {
                const isCurrent = device.id === currentId;
                const result = results[device.id];
                const mobile = device.platform === 'ios' || device.platform === 'android';
                const Icon = mobile ? Smartphone : Monitor;
                return (
                    <li key={device.id} className="space-y-3 p-3 sm:p-4" data-testid="push-device">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                            <div className="flex min-w-0 gap-3">
                                <Icon className="mt-0.5 h-5 w-5 shrink-0 text-muted-foreground" aria-hidden />
                                <div className="min-w-0 space-y-1">
                                    <p className="flex flex-wrap items-center gap-2 text-sm font-medium">
                                        <span className="truncate">{device.label}</span>
                                        {isCurrent && <Badge variant="secondary">This device</Badge>}
                                        {device.expired_at && <Badge variant="destructive">Expired</Badge>}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {device.service}
                                        {device.created_at && <> · registered {ago(device.created_at)}</>}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        {device.last_success_at ? <>Last delivered {ago(device.last_success_at)}</> : 'Nothing delivered yet'}
                                    </p>
                                    {device.last_error && !result && (
                                        <p className="break-words text-xs text-destructive">
                                            {device.expired_at
                                                ? `Stopped ${ago(device.expired_at)}: the browser dropped this subscription, so it gets no more notifications.`
                                                : `Last error${device.last_error_at ? ` ${ago(device.last_error_at)}` : ''}: ${device.last_error}`}
                                        </p>
                                    )}
                                </div>
                            </div>
                            <div className="flex shrink-0 gap-2 pl-8 sm:pl-0">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => onTest(device)}
                                    disabled={busy !== null}
                                    aria-label={`Send test notification to ${device.label}`}
                                >
                                    {busy === `test:${device.id}` ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                                    Send test
                                </Button>
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    onClick={() => onRemove(device)}
                                    disabled={busy !== null}
                                    aria-label={`Remove ${device.label}`}
                                >
                                    <Trash2 className="h-4 w-4" />
                                    <span className="sm:sr-only">Remove</span>
                                </Button>
                            </div>
                        </div>
                        {result && <TestResult device={device} result={result} isCurrent={isCurrent} />}
                    </li>
                );
            })}
        </ul>
    );
}

function ago(iso: string): string {
    return formatDistanceToNow(new Date(iso), { addSuffix: true });
}

/** The push service's answer in plain words, with what to check next. */
export function TestResult({ device, result, isCurrent }: { device: PushDevice; result: PushTestResult; isCurrent: boolean }) {
    const { tone, title, lead, steps } = explain(device, result, isCurrent);
    const ToneIcon = tone === 'ok' ? CheckCircle2 : tone === 'warn' ? AlertTriangle : XCircle;

    return (
        <div
            role="status"
            data-testid="push-test-result"
            data-status={result.status}
            className={cn(
                'space-y-2 rounded-md border p-3 text-sm',
                tone === 'ok' && 'border-emerald-500/30 bg-emerald-500/5',
                tone === 'warn' && 'border-amber-500/30 bg-amber-500/5',
                tone === 'error' && 'border-destructive/30 bg-destructive/5',
            )}
        >
            <p className="flex items-start gap-2 font-medium">
                <ToneIcon
                    className={cn(
                        'mt-0.5 h-4 w-4 shrink-0',
                        tone === 'ok' && 'text-emerald-600 dark:text-emerald-400',
                        tone === 'warn' && 'text-amber-600 dark:text-amber-400',
                        tone === 'error' && 'text-destructive',
                    )}
                    aria-hidden
                />
                <span className="break-words">{title}</span>
            </p>
            {lead && <p className="pl-6 text-muted-foreground">{lead}</p>}
            {steps.length > 0 && (
                <ul className="list-disc space-y-1 pl-10 text-muted-foreground">
                    {steps.map((step) => (
                        <li key={step}>{step}</li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function explain(
    device: PushDevice,
    result: PushTestResult,
    isCurrent: boolean,
): { tone: 'ok' | 'warn' | 'error'; title: string; lead?: string; steps: string[] } {
    const browser = device.browser ?? 'the browser';
    const reason = result.reason ? ` (${result.reason})` : '';
    const reRegister = isCurrent
        ? 'Click “Register this device” above to subscribe again.'
        : `Open Brainitive in ${browser} on that device → Profile → Notifications → Register, then remove this entry.`;

    switch (result.status) {
        case 'delivered':
            return {
                tone: 'ok',
                title: `Sent — the push service accepted it. It should appear on ${device.label} within a few seconds.`,
                lead: 'Nothing showed up? Then the device is hiding it — check:',
                steps: [...platformSteps(device), siteSettingStep(browser)],
            };
        case 'expired':
            return {
                tone: 'error',
                title: `This subscription has expired${result.http_status ? ` (HTTP ${result.http_status})` : ''}: ${browser} dropped it, so this device gets no more notifications.`,
                steps: [
                    reRegister,
                    'This happens after clearing site data, reinstalling the browser, or when the browser revokes notification permission.',
                ],
            };
        case 'rejected':
            return {
                tone: 'error',
                title: `The push service refused the notification${result.http_status ? ` (HTTP ${result.http_status})` : ''}${reason}.`,
                steps: rejectedSteps(result.http_status, reRegister),
            };
        case 'network':
            return {
                tone: 'warn',
                title: 'The server could not reach the push service.',
                steps: ['Try again in a minute. If it keeps failing, the server’s outgoing connection to the push service is blocked.'],
            };
        case 'invalid':
            return { tone: 'error', title: result.reason ?? 'This subscription is invalid.', steps: [reRegister] };
        case 'unconfigured':
        default:
            return {
                tone: 'warn',
                title: result.reason ?? 'Push notifications are not set up on this server.',
                steps: ['An administrator has to configure the VAPID keys (see the self-hosting docs).'],
            };
    }
}

function rejectedSteps(status: number | null, reRegister: string): string[] {
    if (status === 401 || status === 403 || status === 400) {
        return [
            'Usually the server’s VAPID keys changed after this browser subscribed, so the push service no longer trusts the server for it.',
            reRegister,
        ];
    }
    if (status === 413) return ['The notification was too large for the push service.'];
    if (status === 429) return ['The push service is rate-limiting this server. Wait a few minutes and try again.'];
    if (status !== null && status >= 500) return ['The push service has a problem right now. Try again in a few minutes.'];
    return [reRegister];
}

function platformSteps(device: PushDevice): string[] {
    const browser = device.browser ?? 'the browser';
    switch (device.platform) {
        case 'windows':
            return [
                `${browser} must be running — or turn on “Continue running background apps when ${browser} is closed” (${browser} settings → System).`,
                `Windows Settings → System → Notifications: notifications on, and allowed for ${browser}.`,
                'Turn off Focus / Do not disturb (Windows Settings → System → Focus, or the notification center).',
            ];
        case 'macos':
            return [
                `System Settings → Notifications → ${browser}: allow notifications (Banners or Alerts).`,
                'Turn off Focus / Do not disturb in Control Center.',
                ...(device.browser && device.browser !== 'Safari' ? [`${browser} must be running (it can be in the background).`] : []),
            ];
        case 'ios':
            return [
                'On iPhone / iPad notifications only work in the Home Screen app (iOS 16.4 or later): Share → Add to Home Screen, open Brainitive from there and turn notifications on.',
                'Settings → Notifications → Brainitive: Allow Notifications on; Focus off.',
            ];
        case 'android':
            return [
                `Android Settings → Apps → ${browser} → Notifications: allowed (including the category for this site).`,
                'Turn off Do not disturb; battery saver can delay notifications.',
            ];
        default:
            return [`Check the system’s notification settings for ${browser} and turn off Do not disturb.`];
    }
}

function siteSettingStep(browser: string): string {
    return `In ${browser}, the site settings for this site (lock icon next to the address) must allow Notifications.`;
}
