import { router } from '@inertiajs/react';
import { useState } from 'react';
import { KeyRound } from 'lucide-react';
import { toast } from 'sonner';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { PageProps, Workspace } from '@/types';

/**
 * Workspace settings → Developer API: admins allow or block members' personal API keys for this
 * workspace (on by default). Blocking only affects this workspace; the keys keep working elsewhere.
 */
export function WorkspaceApiKeysSetting({ workspace }: { workspace: Workspace }) {
    const [enabled, setEnabled] = useState(workspace.api_keys_enabled ?? true);
    const [saving, setSaving] = useState(false);

    const save = (next: boolean) => {
        const previous = enabled;
        setEnabled(next);
        setSaving(true);
        router.patch(
            route('workspaces.api-keys.update', workspace.slug),
            { api_keys_enabled: next },
            {
                preserveScroll: true,
                onSuccess: (page) =>
                    toast.success((page.props as PageProps).flash?.success ?? (next ? 'API keys are allowed in this workspace.' : 'API keys are turned off for this workspace.')),
                onError: () => {
                    setEnabled(previous);
                    toast.error('Could not save the API key setting.');
                },
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Card className="mt-6">
            <CardHeader>
                <CardTitle className="flex items-center gap-3 text-xl">
                    <KeyRound className="size-5 text-primary" aria-hidden />
                    Developer API
                </CardTitle>
                <CardDescription>
                    Members can create personal API keys that reach this workspace's content. Turn this off to block every key for this workspace
                    (the keys keep working elsewhere).
                </CardDescription>
            </CardHeader>
            <CardContent>
                <label className="flex cursor-pointer items-center gap-3 text-sm font-medium">
                    <input
                        type="checkbox"
                        className="size-4 accent-primary"
                        checked={enabled}
                        disabled={saving}
                        onChange={(e) => save(e.target.checked)}
                        data-testid="workspace-api-keys-enabled"
                    />
                    Allow API keys
                </label>
            </CardContent>
        </Card>
    );
}
