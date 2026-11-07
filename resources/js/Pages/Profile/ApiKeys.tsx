import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { ArrowLeft, BookOpen, ExternalLink, Plus } from 'lucide-react';
import AppLayout from '@/Layouts/AppLayout';
import { ApiKeyList } from '@/components/api-keys/ApiKeyList';
import { ApiKeySecret } from '@/components/api-keys/ApiKeySecret';
import { CreateApiKeyDialog } from '@/components/api-keys/CreateApiKeyDialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { ApiKeyArea, ApiKeyOptions, ApiKeyRow, PageProps } from '@/types';

type Props = PageProps<{
    apiKeys: ApiKeyRow[];
    options: ApiKeyOptions;
    areas: ApiKeyArea[];
    expiryDays: (number | null)[];
    enabled: boolean;
    rateLimits: { read: number; write: number };
    created: null | { key: ApiKeyRow; secret: string };
    apiBaseUrl: string;
}>;

const DOCS_URL = 'https://docs.brainitive.app/integrations/developer-api';
const REFERENCE_URL = 'https://docs.brainitive.app/api';

/** Profile → API keys (Developer API): personal keys for the Brainitive API /api/v1. */
export default function ApiKeys({ apiKeys, options, areas, expiryDays, enabled, rateLimits, created, apiBaseUrl }: Props) {
    const [creating, setCreating] = useState(false);
    // the secret of the last create / regenerate response; hidden by "Done" (never stored anywhere)
    const [hiddenSecret, setHiddenSecret] = useState<string | null>(null);
    const showSecret = created !== null && created.secret !== hiddenSecret;

    const done = () => {
        if (created) setHiddenSecret(created.secret);
        // replace the history entry so Back doesn't bring the secret back
        router.visit(route('profile.api-keys.index'), { replace: true, preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="API keys" />

            <div className="mx-auto max-w-3xl space-y-6 p-6 md:p-8 lg:p-10">
                <div className="mb-8">
                    <Link
                        href={route('profile.edit')}
                        className="mb-3 inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <ArrowLeft className="size-4" aria-hidden />
                        Profile
                    </Link>
                    <h1 className="text-2xl font-bold tracking-tight">API keys</h1>
                    <p className="mt-1 text-muted-foreground">
                        Use the Brainitive API from your own scripts and apps. A key acts as you, but only where and for what you allow.
                    </p>
                </div>

                {showSecret && created && <ApiKeySecret apiKey={created.key} secret={created.secret} apiBaseUrl={apiBaseUrl} onDone={done} />}

                <Card>
                    <CardHeader>
                        <CardTitle>Developer API</CardTitle>
                        <CardDescription>Send the key as a Bearer token with every request.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-2">
                            <span className="text-muted-foreground">Base URL</span>
                            <code className="break-all rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{apiBaseUrl}</code>
                        </div>
                        <p className="text-muted-foreground">
                            Rate limits per key: {rateLimits.read} reads and {rateLimits.write} writes a minute.
                        </p>
                        <div className="flex flex-wrap gap-x-4 gap-y-2">
                            <a href={DOCS_URL} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1.5 font-medium text-primary hover:underline">
                                <BookOpen className="size-4" aria-hidden />
                                Getting started
                                <ExternalLink className="size-3" aria-hidden />
                            </a>
                            <a
                                href={REFERENCE_URL}
                                target="_blank"
                                rel="noopener noreferrer"
                                className="inline-flex items-center gap-1.5 font-medium text-primary hover:underline"
                            >
                                API reference
                                <ExternalLink className="size-3" aria-hidden />
                            </a>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-col gap-3 space-y-0 sm:flex-row sm:items-start sm:justify-between">
                        <div className="space-y-1.5">
                            <CardTitle>Your keys</CardTitle>
                            <CardDescription>Revoke a key you no longer use. Keys stop working when they expire.</CardDescription>
                        </div>
                        {enabled && (
                            <Button onClick={() => setCreating(true)} data-testid="create-api-key" className="shrink-0">
                                <Plus className="size-4" aria-hidden />
                                Create API key
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!enabled && (
                            <p className="rounded-md border bg-muted px-3 py-2 text-sm text-muted-foreground" role="status">
                                API keys are turned off on this server by its administrator.
                            </p>
                        )}
                        <ApiKeyList apiKeys={apiKeys} areas={areas} />
                    </CardContent>
                </Card>
            </div>

            {enabled && <CreateApiKeyDialog open={creating} onOpenChange={setCreating} options={options} areas={areas} expiryDays={expiryDays} />}
        </AppLayout>
    );
}
