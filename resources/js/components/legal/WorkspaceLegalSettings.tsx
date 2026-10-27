import { useForm } from '@inertiajs/react';
import { Scale } from 'lucide-react';
import type { FormEvent } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Workspace, WorkspaceLegalFields } from '@/types';

/**
 * Workspace settings → Legal (BRAI-156): who runs the workspace's public pages (support pages, forms,
 * portals, share links) and where its privacy policy and imprint are. Shown on those pages
 * (OperatorNotice); the workspace is the controller of its visitors' data, Brainitive its processor.
 */
export function WorkspaceLegalSettings({ workspace }: { workspace: Workspace & WorkspaceLegalFields }) {
    const form = useForm({
        legal_controller_name: workspace.legal_controller_name ?? '',
        legal_privacy_url: workspace.legal_privacy_url ?? '',
        legal_imprint_url: workspace.legal_imprint_url ?? '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.patch(route('workspaces.legal.update', workspace.slug), {
            preserveScroll: true,
            onSuccess: () => toast.success('Legal details saved.'),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-3 text-xl">
                    <Scale className="size-5 text-primary" />
                    Legal
                </CardTitle>
                <CardDescription>
                    Your support pages, forms, portals and shared links show who runs them. For the data visitors enter there, your organisation
                    is the controller under the GDPR and Brainitive processes it on your behalf. Add your own privacy policy and imprint so they
                    are linked on those pages.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="space-y-5" data-testid="workspace-legal-form">
                    <div className="space-y-1.5">
                        <Label htmlFor="legal_controller_name">Legal name of your organisation</Label>
                        <Input
                            id="legal_controller_name"
                            value={form.data.legal_controller_name}
                            onChange={(e) => form.setData('legal_controller_name', e.target.value)}
                            placeholder={workspace.name}
                            maxLength={200}
                        />
                        <p className="text-xs text-muted-foreground">Shown as “This page is operated by …”. Empty = the workspace name.</p>
                        {form.errors.legal_controller_name && <p className="text-sm text-destructive">{form.errors.legal_controller_name}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="legal_privacy_url">Privacy policy URL</Label>
                        <Input
                            id="legal_privacy_url"
                            type="url"
                            inputMode="url"
                            value={form.data.legal_privacy_url}
                            onChange={(e) => form.setData('legal_privacy_url', e.target.value)}
                            placeholder="https://example.com/privacy"
                            maxLength={2048}
                        />
                        {form.errors.legal_privacy_url && <p className="text-sm text-destructive">{form.errors.legal_privacy_url}</p>}
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="legal_imprint_url">Imprint URL</Label>
                        <Input
                            id="legal_imprint_url"
                            type="url"
                            inputMode="url"
                            value={form.data.legal_imprint_url}
                            onChange={(e) => form.setData('legal_imprint_url', e.target.value)}
                            placeholder="https://example.com/imprint"
                            maxLength={2048}
                        />
                        <p className="text-xs text-muted-foreground">Required for businesses in Germany and Austria (§ 5 DDG, § 25 MedienG).</p>
                        {form.errors.legal_imprint_url && <p className="text-sm text-destructive">{form.errors.legal_imprint_url}</p>}
                    </div>
                    <Button type="submit" disabled={form.processing}>
                        Save legal details
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
