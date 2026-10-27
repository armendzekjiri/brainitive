import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm, router, usePage } from '@inertiajs/react';
import { PageProps, Workspace, User } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Separator } from '@/components/ui/separator';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TimezonePicker } from '@/components/TimezonePicker';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import {
    Settings,
    Upload,
    Globe,
    Building2,
    Users,
    Palette,
    Shield,
    Trash2,
    ImageIcon,
    Link2,
    Twitter,
    Linkedin,
    Github,
    ArrowLeft,
    Mail,
    UserPlus,
    Bot,
    Loader2,
    Check,
    AlertCircle,
    Eye,
    EyeOff,
    Sparkles,
    Webhook as WebhookIcon,
    Scale,
} from 'lucide-react';
import { WorkspaceLegalSettings } from '@/components/legal/WorkspaceLegalSettings';
import { useState, useEffect, FormEvent, ChangeEvent } from 'react';
import { Link } from '@inertiajs/react';
import { toast } from 'sonner';
import axios from 'axios';

interface PendingInvitation {
    id: number;
    email: string;
    role: string;
    expires_at: string;
    created_at: string;
    inviter: { id: number; name: string } | null;
}

interface Props extends PageProps {
    workspace: Workspace & {
        users: User[];
        owner: User;
    };
    pendingInvitations?: PendingInvitation[];
    userRole: string;
    timezones: string[];
    industries: Record<string, string>;
    companySizes: Record<string, string>;
    flash?: {
        success?: string;
        error?: string;
        invite_result?: 'invitation_sent' | 'member_added';
        invite_confirm_required?: boolean;
        invite_email?: string;
        invite_role?: string;
    };
}

// Generate consistent colors for workspace icons
function getWorkspaceColor(name: string): string {
    const colors = [
        'bg-brand-blue',
        'bg-brand-slate',
        'bg-brand-green',
        'bg-brand-blue',
        'bg-brand-slate',
        'bg-brand-green',
        'bg-brand-blue',
        'bg-brand-slate',
    ];
    return colors[name.charCodeAt(0) % colors.length];
}

/**
 * AI Settings Tab — configure endpoint, API key, and model for AI features.
 */
function AiSettingsTab({ workspace }: { workspace: Workspace }) {
    const [aiEndpoint, setAiEndpoint] = useState('');
    const [aiApiKey, setAiApiKey] = useState('');
    const [aiModel, setAiModel] = useState('');
    const [models, setModels] = useState<string[]>([]);
    const [showKey, setShowKey] = useState(false);
    const [loadingModels, setLoadingModels] = useState(false);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState<{ type: 'success' | 'error'; text: string } | null>(null);
    const [modelsError, setModelsError] = useState<string | null>(null);
    const [hasExistingKey, setHasExistingKey] = useState(false);

    // Load current settings on mount
    useEffect(() => {
        window.axios.get(route('ai.settings', workspace.slug))
            .then((res: any) => {
                const d = res.data;
                setAiEndpoint(d.ai_endpoint || '');
                setAiModel(d.ai_model || '');
                setHasExistingKey(!!d.ai_api_key);
            })
            .catch(() => {});
    }, [workspace.slug]);

    const handleFetchModels = async () => {
        setLoadingModels(true);
        setModelsError(null);
        setModels([]);
        try {
            const payload: Record<string, string> = {};
            if (aiEndpoint) payload.ai_endpoint = aiEndpoint;
            if (aiApiKey) payload.ai_api_key = aiApiKey;

            const res = await window.axios.post(route('ai.models', workspace.slug), payload);
            if (res.data.success && res.data.models?.length) {
                setModels(res.data.models);
                // Auto-select first model if none selected
                if (!aiModel && res.data.models.length > 0) {
                    setAiModel(res.data.models[0]);
                }
            } else {
                setModelsError(res.data.message || 'No models found.');
            }
        } catch (err: any) {
            setModelsError(err.response?.data?.message || 'Failed to fetch models.');
        } finally {
            setLoadingModels(false);
        }
    };

    const handleSave = async () => {
        setSaving(true);
        setMessage(null);
        try {
            const payload: Record<string, string | null> = {
                ai_endpoint: aiEndpoint || null,
                ai_model: aiModel || null,
            };
            // Only send API key if user typed a new one
            if (aiApiKey) {
                payload.ai_api_key = aiApiKey;
            }

            const res = await window.axios.post(route('ai.settings.save', workspace.slug), payload);
            setMessage({ type: 'success', text: res.data.message || 'Settings saved.' });
            if (aiApiKey) {
                setHasExistingKey(true);
                setAiApiKey('');
            }
        } catch (err: any) {
            setMessage({ type: 'error', text: err.response?.data?.message || 'Failed to save settings.' });
        } finally {
            setSaving(false);
        }
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-xl flex items-center gap-3">
                    <Sparkles className="size-5 text-primary" />
                    AI Configuration
                </CardTitle>
                <CardDescription>
                    Connect an OpenAI-compatible AI provider to enable AI-powered features like content rewriting in item descriptions.
                    Provide your API endpoint and key, then select a model.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-6">
                {/* Endpoint */}
                <div className="space-y-2">
                    <Label htmlFor="ai_endpoint" className="text-base font-medium">
                        API Endpoint
                    </Label>
                    <Input
                        id="ai_endpoint"
                        value={aiEndpoint}
                        onChange={(e) => setAiEndpoint(e.target.value)}
                        placeholder="https://api.openai.com"
                        className="h-11"
                    />
                    <p className="text-xs text-muted-foreground">
                        The base URL of your OpenAI-compatible API provider (e.g. OpenAI, Ollama, OpenRouter, Azure, etc.)
                    </p>
                </div>

                {/* API Key */}
                <div className="space-y-2">
                    <Label htmlFor="ai_api_key" className="text-base font-medium">
                        API Key
                    </Label>
                    <div className="relative">
                        <Input
                            id="ai_api_key"
                            type={showKey ? 'text' : 'password'}
                            value={aiApiKey}
                            onChange={(e) => setAiApiKey(e.target.value)}
                            placeholder={hasExistingKey ? '••••••••  (key saved — enter new key to replace)' : 'sk-...'}
                            className="h-11 pr-10"
                        />
                        <button
                            type="button"
                            className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
                            onClick={() => setShowKey(!showKey)}
                        >
                            {showKey ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                        </button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Your API key is encrypted before storage and never exposed to the browser.
                    </p>
                </div>

                {/* Fetch Models */}
                <div className="space-y-3">
                    <div className="flex items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={handleFetchModels}
                            disabled={loadingModels || (!aiEndpoint)}
                            className="gap-2"
                        >
                            {loadingModels ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Bot className="size-4" />
                            )}
                            {loadingModels ? 'Fetching models...' : 'Fetch Available Models'}
                        </Button>
                        {!aiEndpoint && (
                            <span className="text-xs text-muted-foreground">Enter an endpoint first</span>
                        )}
                    </div>

                    {modelsError && (
                        <div className="flex items-center gap-2 text-sm text-destructive bg-destructive/10 p-3 rounded-lg">
                            <AlertCircle className="size-4 shrink-0" />
                            {modelsError}
                        </div>
                    )}

                    {/* Model Selection */}
                    {models.length > 0 && (
                        <div className="space-y-2">
                            <Label className="text-base font-medium">Select Model</Label>
                            <Select value={aiModel} onValueChange={setAiModel}>
                                <SelectTrigger className="h-11">
                                    <SelectValue placeholder="Choose a model..." />
                                </SelectTrigger>
                                <SelectContent>
                                    {models.map((m) => (
                                        <SelectItem key={m} value={m}>
                                            {m}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {/* Show current model if saved but models not fetched */}
                    {models.length === 0 && aiModel && (
                        <div className="space-y-2">
                            <Label className="text-base font-medium">Current Model</Label>
                            <div className="flex items-center gap-2 p-3 rounded-lg border bg-muted/30">
                                <Check className="size-4 text-green-600" />
                                <span className="text-sm font-mono">{aiModel}</span>
                            </div>
                        </div>
                    )}
                </div>

                <Separator />

                {/* Save */}
                <div className="flex items-center gap-4">
                    <Button
                        type="button"
                        onClick={handleSave}
                        disabled={saving || !aiEndpoint}
                        className="gap-2"
                    >
                        {saving ? (
                            <Loader2 className="size-4 animate-spin" />
                        ) : (
                            <Check className="size-4" />
                        )}
                        {saving ? 'Saving...' : 'Save AI Settings'}
                    </Button>

                    {message && (
                        <div className={`flex items-center gap-2 text-sm ${message.type === 'success' ? 'text-green-600' : 'text-destructive'}`}>
                            {message.type === 'success' ? <Check className="size-4" /> : <AlertCircle className="size-4" />}
                            {message.text}
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

export default function WorkspacesEdit({
    workspace,
    pendingInvitations = [],
    userRole,
    timezones,
    industries,
    companySizes,
}: Props) {
    const { props } = usePage<Props>();

    const [inviteDialogOpen, setInviteDialogOpen] = useState(false);
    const [inviteConfirmPending, setInviteConfirmPending] = useState<{ email: string; role: string } | null>(null);
    const [inviteSending, setInviteSending] = useState(false);
    const [settingsTab, setSettingsTab] = useState<'general' | 'branding' | 'members' | 'ai' | 'legal' | 'danger'>('general');

    useEffect(() => {
        const message = props.flash?.success;
        if (message) {
            toast.success(message);
        }
    }, [props.flash?.success]);

    useEffect(() => {
        const message = props.flash?.error;
        if (message) {
            toast.error(message);
        }
    }, [props.flash?.error]);

    const [logoPreview, setLogoPreview] = useState<string | null>(
        workspace.logo_display ?? (workspace.logo ? `/storage/${workspace.logo}` : null)
    );
    const [headerPreview, setHeaderPreview] = useState<string | null>(
        workspace.header_image_display ?? (workspace.header_image ? `/storage/${workspace.header_image}` : null)
    );

    // Form for workspace settings
    const { data, setData, post, processing, errors } = useForm({
        _method: 'PUT',
        name: workspace.name || '',
        description: workspace.description || '',
        website: workspace.website || '',
        industry: workspace.industry || '',
        company_size: workspace.company_size || '',
        timezone: workspace.timezone || 'UTC',
        language: workspace.language || 'en',
        currency: workspace.currency || 'USD',
        is_public: workspace.is_public || false,
        logo: null as File | null,
        header_image: null as File | null,
        remove_header_image: false,
        social_links: {
            twitter: workspace.social_links?.twitter || '',
            linkedin: workspace.social_links?.linkedin || '',
            github: workspace.social_links?.github || '',
        },
        branding: {
            primary_color: workspace.branding?.primary_color || '#0052CC',
            secondary_color: workspace.branding?.secondary_color || '#6554C0',
        },
    });

    // Form for inviting members
    const inviteForm = useForm({
        email: '',
        role: 'member' as 'admin' | 'editor' | 'member' | 'viewer',
        confirm_send_invitation: false as boolean,
    });


    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(route('workspaces.update', workspace.slug), {
            forceFormData: true,
        });
    };

    const inviteUrl = route('workspaces.members.invite', workspace.slug);

    const handleInviteMember = (e: FormEvent) => {
        e.preventDefault();
        const email = inviteForm.data.email.trim().toLowerCase();
        const role = inviteForm.data.role;
        if (!email) return;
        setInviteSending(true);
        axios
            .post(inviteUrl, { email, role }, {
                headers: {
                    'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                    'X-Requested-With': 'XMLHttpRequest',
                    Accept: 'application/json',
                },
                validateStatus: (status) => status === 200 || status === 302 || status === 409,
                maxRedirects: 0,
            })
            .then((res) => {
                if (res.status === 409 && res.data?.confirm_required) {
                    setInviteConfirmPending({ email: res.data.email, role: res.data.role });
                } else {
                    setInviteDialogOpen(false);
                    setInviteConfirmPending(null);
                    inviteForm.reset();
                    const location = res.headers?.location ?? (res.headers as Record<string, string>)?.['location'];
                    if (res.status === 302 && location) {
                        router.visit(location, { preserveScroll: true });
                    } else {
                        router.reload();
                    }
                }
            })
            .catch((err) => {
                if (err.response?.status === 409 && err.response?.data?.confirm_required) {
                    setInviteConfirmPending({
                        email: err.response.data.email,
                        role: err.response.data.role,
                    });
                } else {
                    toast.error(err.response?.data?.message ?? 'Something went wrong.');
                }
            })
            .finally(() => setInviteSending(false));
    };

    const handleConfirmSendInvitation = (e: FormEvent) => {
        e.preventDefault();
        if (!inviteConfirmPending) return;
        setInviteSending(true);
        axios
            .post(
                inviteUrl,
                {
                    email: inviteConfirmPending.email,
                    role: inviteConfirmPending.role,
                    confirm_send_invitation: true,
                },
                {
                    headers: {
                        'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '',
                        'X-Requested-With': 'XMLHttpRequest',
                        Accept: 'application/json',
                    },
                    validateStatus: (status) => status === 200 || status === 302,
                    maxRedirects: 0,
                }
            )
            .then((res) => {
                setInviteDialogOpen(false);
                setInviteConfirmPending(null);
                inviteForm.reset();
                const location = res.headers?.location ?? (res.headers as Record<string, string>)?.['location'];
                if (res.status === 302 && location) {
                    router.visit(location, { preserveScroll: true });
                } else {
                    router.reload();
                }
            })
            .catch(() => toast.error('Failed to send invitation.'))
            .finally(() => setInviteSending(false));
    };

    const handleUpdateRole = (userId: number, role: string) => {
        router.patch(route('workspaces.members.update', [workspace.slug, userId]), {
            role,
        });
    };

    const handleRemoveMember = (userId: number) => {
        router.delete(route('workspaces.members.remove', [workspace.slug, userId]));
    };

    const handleLogoChange = (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('logo', file);
            setLogoPreview(URL.createObjectURL(file));
        }
    };

    const handleHeaderChange = (e: ChangeEvent<HTMLInputElement>) => {
        const file = e.target.files?.[0];
        if (file) {
            setData('header_image', file);
            setHeaderPreview(URL.createObjectURL(file));
        }
    };

    const handleDeleteWorkspace = () => {
        router.delete(route('workspaces.destroy', workspace.slug));
    };

    const canManage = userRole === 'admin';

    return (
        <AppLayout>
            <Head title={`Settings - ${workspace.name}`} />

            <div className="min-h-full">
                {/* Header with cover image */}
                <div className="relative h-48 bg-gradient-to-r from-brand-blue via-brand-slate to-brand-green">
                    {headerPreview && (
                        <img
                            src={headerPreview}
                            alt="Workspace header"
                            className="absolute inset-0 w-full h-full object-cover"
                        />
                    )}
                    <div className="absolute inset-0 bg-black/20" />

                    {/* Back button */}
                    <div className="absolute top-4 left-4">
                        <Link href={route('workspaces.show', workspace.slug)}>
                            <Button variant="secondary" size="sm" className="gap-2">
                                <ArrowLeft className="size-4" />
                                Back to workspace
                            </Button>
                        </Link>
                    </div>

                    {/* Cover image actions */}
                    <div className="absolute bottom-4 right-4 flex items-center gap-2">
                        {headerPreview && (
                            <Button
                                variant="secondary"
                                size="sm"
                                className="gap-2"
                                type="button"
                                onClick={() => {
                                    setHeaderPreview(null);
                                    setData('remove_header_image', true);
                                    post(route('workspaces.update', workspace.slug), { forceFormData: true });
                                }}
                            >
                                <Trash2 className="size-4" />
                                Remove cover
                            </Button>
                        )}
                        <label className="cursor-pointer">
                            <input
                                type="file"
                                accept="image/*"
                                className="hidden"
                                onChange={handleHeaderChange}
                            />
                            <Button variant="secondary" size="sm" className="gap-2" type="button" asChild>
                                <span>
                                    <ImageIcon className="size-4" />
                                    Change cover
                                </span>
                            </Button>
                        </label>
                    </div>
                </div>

                {/* Profile section */}
                <div className="max-w-5xl mx-auto px-8 -mt-16 relative z-10">
                    <div className="flex items-end gap-6 mb-8">
                        {/* Logo */}
                        <div className="relative group">
                            {logoPreview ? (
                                <Avatar className="size-32 border-4 border-background shadow-xl">
                                    <AvatarImage src={logoPreview} alt={workspace.name} />
                                    <AvatarFallback className={`${getWorkspaceColor(workspace.name)} text-white text-4xl font-bold`}>
                                        {workspace.name.charAt(0).toUpperCase()}
                                    </AvatarFallback>
                                </Avatar>
                            ) : (
                                <div className={`size-32 rounded-full border-4 border-background shadow-xl ${getWorkspaceColor(workspace.name)} flex items-center justify-center`}>
                                    <span className="text-white text-4xl font-bold">
                                        {workspace.name.charAt(0).toUpperCase()}
                                    </span>
                                </div>
                            )}
                            <label className="absolute inset-0 flex items-center justify-center bg-black/50 rounded-full opacity-0 group-hover:opacity-100 transition-opacity cursor-pointer">
                                <input
                                    type="file"
                                    accept="image/*"
                                    className="hidden"
                                    onChange={handleLogoChange}
                                />
                                <Upload className="size-8 text-white" />
                            </label>
                        </div>

                        <div className="pb-2">
                            <h1 className="text-3xl font-bold tracking-tight">{workspace.name}</h1>
                            <p className="text-muted-foreground mt-1">Workspace Settings</p>
                        </div>
                    </div>

                    {/* Tabs */}
                    <Tabs value={settingsTab} onValueChange={(v) => setSettingsTab(v as typeof settingsTab)} orientation="vertical" className="pb-16">
                        <div className="flex flex-col gap-8 lg:flex-row lg:items-start">
                            <aside className="w-full lg:w-56 lg:shrink-0 lg:sticky lg:top-4">
                                <div className="mb-2 px-3 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">Settings</div>
                                <TabsList className="flex h-auto w-full flex-row gap-1 overflow-x-auto bg-transparent p-0 lg:flex-col">
                                    <TabsTrigger value="general" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <Settings className="size-4" />General
                                    </TabsTrigger>
                                    <TabsTrigger value="branding" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <Palette className="size-4" />Branding
                                    </TabsTrigger>
                                    <TabsTrigger value="members" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <Users className="size-4" />Members
                                        <span className="ml-auto rounded-full bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">{workspace.users.length}</span>
                                    </TabsTrigger>
                                    <TabsTrigger value="ai" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <Bot className="size-4" />AI
                                    </TabsTrigger>
                                    <TabsTrigger value="legal" className="w-full justify-start gap-2 px-3 h-9 data-[state=active]:bg-secondary data-[state=active]:text-foreground data-[state=active]:shadow-none">
                                        <Scale className="size-4" />Legal
                                    </TabsTrigger>
                                    {/* Webhooks has its own page (v1.39.0, BRAI-104) */}
                                    <Link href={route('webhooks.index', workspace.slug)} className="inline-flex h-9 w-full shrink-0 items-center justify-start gap-2 whitespace-nowrap rounded-sm px-3 text-sm font-medium text-muted-foreground transition-colors hover:bg-secondary hover:text-foreground">
                                        <WebhookIcon className="size-4" />Webhooks
                                    </Link>
                                    <TabsTrigger value="danger" className="w-full justify-start gap-2 px-3 h-9 text-destructive data-[state=active]:bg-destructive/10 data-[state=active]:text-destructive data-[state=active]:shadow-none">
                                        <Shield className="size-4" />Danger zone
                                    </TabsTrigger>
                                </TabsList>
                            </aside>
                            <div className="min-w-0 flex-1">

                        {/* General Settings */}
                        <TabsContent value="general">
                            <form onSubmit={handleSubmit} className="space-y-8">
                                {/* Basic Info */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-xl flex items-center gap-3">
                                            <Building2 className="size-5 text-primary" />
                                            Basic Information
                                        </CardTitle>
                                        <CardDescription>
                                            Update your workspace's basic details and information.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-6">
                                        <div className="grid gap-6 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="name" className="text-base font-medium">
                                                    Workspace Name *
                                                </Label>
                                                <Input
                                                    id="name"
                                                    value={data.name}
                                                    onChange={(e) => setData('name', e.target.value)}
                                                    className="h-11"
                                                    placeholder="My Workspace"
                                                />
                                                {errors.name && (
                                                    <p className="text-sm text-destructive">{errors.name}</p>
                                                )}
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="website" className="text-base font-medium">
                                                    Website
                                                </Label>
                                                <div className="relative">
                                                    <Globe className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                                                    <Input
                                                        id="website"
                                                        type="url"
                                                        value={data.website}
                                                        onChange={(e) => setData('website', e.target.value)}
                                                        className="h-11 pl-10"
                                                        placeholder="https://example.com"
                                                    />
                                                </div>
                                                {errors.website && (
                                                    <p className="text-sm text-destructive">{errors.website}</p>
                                                )}
                                            </div>
                                        </div>

                                        <div className="space-y-2">
                                            <Label htmlFor="description" className="text-base font-medium">
                                                Description
                                            </Label>
                                            <Textarea
                                                id="description"
                                                value={data.description}
                                                onChange={(e) => setData('description', e.target.value)}
                                                placeholder="Describe your workspace..."
                                                className="min-h-[120px] resize-none"
                                            />
                                            {errors.description && (
                                                <p className="text-sm text-destructive">{errors.description}</p>
                                            )}
                                        </div>

                                        <div className="grid gap-6 sm:grid-cols-2">
                                            <div className="space-y-2">
                                                <Label htmlFor="industry" className="text-base font-medium">
                                                    Industry
                                                </Label>
                                                <Select
                                                    value={data.industry}
                                                    onValueChange={(value) => setData('industry', value)}
                                                >
                                                    <SelectTrigger className="h-11">
                                                        <SelectValue placeholder="Select industry" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {Object.entries(industries).map(([key, label]) => (
                                                            <SelectItem key={key} value={key}>
                                                                {label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="company_size" className="text-base font-medium">
                                                    Company Size
                                                </Label>
                                                <Select
                                                    value={data.company_size}
                                                    onValueChange={(value) => setData('company_size', value)}
                                                >
                                                    <SelectTrigger className="h-11">
                                                        <SelectValue placeholder="Select size" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {Object.entries(companySizes).map(([key, label]) => (
                                                            <SelectItem key={key} value={key}>
                                                                {label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Regional Settings */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-xl flex items-center gap-3">
                                            <Globe className="size-5 text-primary" />
                                            Regional Settings
                                        </CardTitle>
                                        <CardDescription>
                                            Configure your workspace's timezone, language, and currency preferences.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid gap-6 sm:grid-cols-3">
                                            <div className="space-y-2">
                                                <Label htmlFor="timezone" className="text-base font-medium">
                                                    Timezone
                                                </Label>
                                                <TimezonePicker
                                                    id="timezone"
                                                    value={data.timezone}
                                                    timezones={timezones}
                                                    onChange={(value) => setData('timezone', value)}
                                                />
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="language" className="text-base font-medium">
                                                    Language
                                                </Label>
                                                <Select
                                                    value={data.language}
                                                    onValueChange={(value) => setData('language', value)}
                                                >
                                                    <SelectTrigger className="h-11">
                                                        <SelectValue placeholder="Select language" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="en">English</SelectItem>
                                                        <SelectItem value="es">Spanish</SelectItem>
                                                        <SelectItem value="fr">French</SelectItem>
                                                        <SelectItem value="de">German</SelectItem>
                                                        <SelectItem value="ja">Japanese</SelectItem>
                                                        <SelectItem value="zh">Chinese</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                            <div className="space-y-2">
                                                <Label htmlFor="currency" className="text-base font-medium">
                                                    Currency
                                                </Label>
                                                <Select
                                                    value={data.currency}
                                                    onValueChange={(value) => setData('currency', value)}
                                                >
                                                    <SelectTrigger className="h-11">
                                                        <SelectValue placeholder="Select currency" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        <SelectItem value="USD">USD ($)</SelectItem>
                                                        <SelectItem value="EUR">EUR (€)</SelectItem>
                                                        <SelectItem value="GBP">GBP (£)</SelectItem>
                                                        <SelectItem value="JPY">JPY (¥)</SelectItem>
                                                        <SelectItem value="CAD">CAD ($)</SelectItem>
                                                        <SelectItem value="AUD">AUD ($)</SelectItem>
                                                    </SelectContent>
                                                </Select>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Social Links */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-xl flex items-center gap-3">
                                            <Link2 className="size-5 text-primary" />
                                            Social Links
                                        </CardTitle>
                                        <CardDescription>
                                            Add your social media profiles to help team members connect.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent className="space-y-4">
                                        <div className="space-y-2">
                                            <Label className="text-base font-medium">Twitter</Label>
                                            <div className="relative">
                                                <Twitter className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                                                <Input
                                                    value={data.social_links.twitter}
                                                    onChange={(e) =>
                                                        setData('social_links', {
                                                            ...data.social_links,
                                                            twitter: e.target.value,
                                                        })
                                                    }
                                                    className="h-11 pl-10"
                                                    placeholder="https://twitter.com/yourcompany"
                                                />
                                            </div>
                                        </div>
                                        <div className="space-y-2">
                                            <Label className="text-base font-medium">LinkedIn</Label>
                                            <div className="relative">
                                                <Linkedin className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                                                <Input
                                                    value={data.social_links.linkedin}
                                                    onChange={(e) =>
                                                        setData('social_links', {
                                                            ...data.social_links,
                                                            linkedin: e.target.value,
                                                        })
                                                    }
                                                    className="h-11 pl-10"
                                                    placeholder="https://linkedin.com/company/yourcompany"
                                                />
                                            </div>
                                        </div>
                                        <div className="space-y-2">
                                            <Label className="text-base font-medium">GitHub</Label>
                                            <div className="relative">
                                                <Github className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                                                <Input
                                                    value={data.social_links.github}
                                                    onChange={(e) =>
                                                        setData('social_links', {
                                                            ...data.social_links,
                                                            github: e.target.value,
                                                        })
                                                    }
                                                    className="h-11 pl-10"
                                                    placeholder="https://github.com/yourcompany"
                                                />
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                {/* Save Button */}
                                <div className="flex justify-end gap-4">
                                    <Link href={route('workspaces.show', workspace.slug)}>
                                        <Button type="button" variant="outline" size="lg">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" size="lg" disabled={processing}>
                                        {processing ? 'Saving...' : 'Save Changes'}
                                    </Button>
                                </div>
                            </form>
                        </TabsContent>

                        {/* Branding Settings */}
                        <TabsContent value="branding">
                            <form onSubmit={handleSubmit} className="space-y-8">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-xl flex items-center gap-3">
                                            <Palette className="size-5 text-primary" />
                                            Brand Colors
                                        </CardTitle>
                                        <CardDescription>
                                            Customize your workspace's appearance with your brand colors.
                                        </CardDescription>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="grid gap-6 sm:grid-cols-2">
                                            <div className="space-y-3">
                                                <Label className="text-base font-medium">Primary Color</Label>
                                                <div className="flex gap-3">
                                                    <div
                                                        className="size-12 rounded-lg border-2 shadow-inner cursor-pointer"
                                                        style={{ backgroundColor: data.branding.primary_color }}
                                                    />
                                                    <Input
                                                        type="color"
                                                        value={data.branding.primary_color}
                                                        onChange={(e) =>
                                                            setData('branding', {
                                                                ...data.branding,
                                                                primary_color: e.target.value,
                                                            })
                                                        }
                                                        className="h-12 w-24 p-1 cursor-pointer"
                                                    />
                                                    <Input
                                                        value={data.branding.primary_color}
                                                        onChange={(e) =>
                                                            setData('branding', {
                                                                ...data.branding,
                                                                primary_color: e.target.value,
                                                            })
                                                        }
                                                        className="h-12 flex-1"
                                                        placeholder="#0052CC"
                                                    />
                                                </div>
                                            </div>
                                            <div className="space-y-3">
                                                <Label className="text-base font-medium">Secondary Color</Label>
                                                <div className="flex gap-3">
                                                    <div
                                                        className="size-12 rounded-lg border-2 shadow-inner cursor-pointer"
                                                        style={{ backgroundColor: data.branding.secondary_color }}
                                                    />
                                                    <Input
                                                        type="color"
                                                        value={data.branding.secondary_color}
                                                        onChange={(e) =>
                                                            setData('branding', {
                                                                ...data.branding,
                                                                secondary_color: e.target.value,
                                                            })
                                                        }
                                                        className="h-12 w-24 p-1 cursor-pointer"
                                                    />
                                                    <Input
                                                        value={data.branding.secondary_color}
                                                        onChange={(e) =>
                                                            setData('branding', {
                                                                ...data.branding,
                                                                secondary_color: e.target.value,
                                                            })
                                                        }
                                                        className="h-12 flex-1"
                                                        placeholder="#6554C0"
                                                    />
                                                </div>
                                            </div>
                                        </div>

                                        <Separator className="my-8" />

                                        {/* Preview */}
                                        <div className="space-y-3">
                                            <Label className="text-base font-medium">Preview</Label>
                                            <div className="p-6 rounded-xl border-2 border-dashed bg-muted/30">
                                                <div className="flex items-center gap-4 mb-4">
                                                    <div
                                                        className="size-12 rounded-xl flex items-center justify-center text-white font-bold text-lg"
                                                        style={{ backgroundColor: data.branding.primary_color }}
                                                    >
                                                        {workspace.name.charAt(0).toUpperCase()}
                                                    </div>
                                                    <div>
                                                        <h3 className="text-lg font-semibold">{workspace.name}</h3>
                                                        <p className="text-sm text-muted-foreground">Your workspace preview</p>
                                                    </div>
                                                </div>
                                                <div className="flex gap-3">
                                                    <Button
                                                        type="button"
                                                        style={{ backgroundColor: data.branding.primary_color }}
                                                        className="text-white"
                                                    >
                                                        Primary Button
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        style={{
                                                            borderColor: data.branding.secondary_color,
                                                            color: data.branding.secondary_color,
                                                        }}
                                                    >
                                                        Secondary Button
                                                    </Button>
                                                </div>
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                <div className="flex justify-end gap-4">
                                    <Link href={route('workspaces.show', workspace.slug)}>
                                        <Button type="button" variant="outline" size="lg">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" size="lg" disabled={processing}>
                                        {processing ? 'Saving...' : 'Save Changes'}
                                    </Button>
                                </div>
                            </form>
                        </TabsContent>

                        {/* Members */}
                        <TabsContent value="members">
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <div>
                                            <CardTitle className="text-xl flex items-center gap-3">
                                                <Users className="size-5 text-primary" />
                                                Team Members
                                            </CardTitle>
                                            <CardDescription>
                                                Manage who has access to this workspace.
                                            </CardDescription>
                                        </div>
                                        <Dialog
                                            open={inviteDialogOpen}
                                            onOpenChange={(open) => {
                                                setInviteDialogOpen(open);
                                                if (!open) setInviteConfirmPending(null);
                                            }}
                                        >
                                            <DialogTrigger asChild>
                                                <Button className="gap-2">
                                                    <UserPlus className="size-4" />
                                                    Invite Members
                                                </Button>
                                            </DialogTrigger>
                                            <DialogContent className="sm:max-w-md">
                                                <DialogHeader>
                                                    <DialogTitle className="text-xl flex items-center gap-3">
                                                        <Mail className="size-5 text-primary" />
                                                        {inviteConfirmPending ? 'Send invitation?' : 'Invite Team Member'}
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        {inviteConfirmPending ? (
                                                            <>
                                                                <strong>{inviteConfirmPending.email}</strong> is not on our platform. Send them an invitation to sign up? Click &quot;Send invitation&quot; below to send the email and add them as pending — they&apos;ll join this workspace when they register within 30 days.
                                                            </>
                                                        ) : (
                                                            'Enter the email address of the person you want to invite.'
                                                        )}
                                                    </DialogDescription>
                                                </DialogHeader>
                                                {inviteConfirmPending ? (
                                                    <form onSubmit={handleConfirmSendInvitation} className="space-y-5 mt-4">
                                                        <div className="rounded-lg border bg-muted/30 p-3 text-sm">
                                                            <p className="font-medium text-foreground">{inviteConfirmPending.email}</p>
                                                            <p className="text-muted-foreground">Role: {inviteConfirmPending.role}</p>
                                                        </div>
                                                        <div className="flex justify-end gap-3 pt-2">
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                onClick={() => setInviteConfirmPending(null)}
                                                            >
                                                                Cancel
                                                            </Button>
                                                            <Button type="submit" disabled={inviteSending} className="gap-2">
                                                                {inviteSending ? 'Sending...' : (
                                                                    <>
                                                                        <UserPlus className="size-4" />
                                                                        Send invitation
                                                                    </>
                                                                )}
                                                            </Button>
                                                        </div>
                                                    </form>
                                                ) : (
                                                    <form onSubmit={handleInviteMember} className="space-y-5 mt-4">
                                                        <div className="space-y-2">
                                                            <Label htmlFor="invite-email" className="text-base font-medium">
                                                                Email Address
                                                            </Label>
                                                            <div className="relative">
                                                                <Mail className="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-muted-foreground" />
                                                                <Input
                                                                    id="invite-email"
                                                                    type="email"
                                                                    value={inviteForm.data.email}
                                                                    onChange={(e) => inviteForm.setData('email', e.target.value)}
                                                                    className="h-11 pl-10"
                                                                    placeholder="colleague@example.com"
                                                                    required
                                                                />
                                                            </div>
                                                            {inviteForm.errors.email && (
                                                                <p className="text-sm text-destructive">{inviteForm.errors.email}</p>
                                                            )}
                                                        </div>
                                                        <div className="space-y-2">
                                                            <Label htmlFor="invite-role" className="text-base font-medium">
                                                                Role
                                                            </Label>
                                                            <Select
                                                                value={inviteForm.data.role}
                                                                onValueChange={(value: 'admin' | 'editor' | 'member' | 'viewer') =>
                                                                    inviteForm.setData('role', value)
                                                                }
                                                            >
                                                                <SelectTrigger className="h-11">
                                                                    <SelectValue placeholder="Select role" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="admin">
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium">Admin</span>
                                                                            <span className="text-xs text-muted-foreground">Full access to all settings</span>
                                                                        </div>
                                                                    </SelectItem>
                                                                    <SelectItem value="editor">
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium">Editor</span>
                                                                            <span className="text-xs text-muted-foreground">Can create and edit content</span>
                                                                        </div>
                                                                    </SelectItem>
                                                                    <SelectItem value="member">
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium">Member</span>
                                                                            <span className="text-xs text-muted-foreground">Standard team member access</span>
                                                                        </div>
                                                                    </SelectItem>
                                                                    <SelectItem value="viewer">
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium">Viewer</span>
                                                                            <span className="text-xs text-muted-foreground">Read-only access</span>
                                                                        </div>
                                                                    </SelectItem>
                                                                </SelectContent>
                                                            </Select>
                                                        </div>
                                                        <div className="flex justify-end gap-3 pt-2">
                                                            <Button
                                                                type="button"
                                                                variant="outline"
                                                                onClick={() => setInviteDialogOpen(false)}
                                                            >
                                                                Cancel
                                                            </Button>
                                                            <Button type="submit" disabled={inviteSending} className="gap-2">
                                                                {inviteSending ? 'Sending...' : (
                                                                    <>
                                                                        <UserPlus className="size-4" />
                                                                        Send Invite
                                                                    </>
                                                                )}
                                                            </Button>
                                                        </div>
                                                    </form>
                                                )}
                                            </DialogContent>
                                        </Dialog>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    <div className="space-y-4">
                                        {/* Owner */}
                                        <div className="flex items-center justify-between p-4 rounded-lg bg-muted/50">
                                            <div className="flex items-center gap-4">
                                                <Avatar className="size-12">
                                                    <AvatarFallback className="bg-gradient-to-br from-brand-blue to-brand-slate text-white font-semibold text-lg">
                                                        {workspace.owner.name.charAt(0).toUpperCase()}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div>
                                                    <p className="font-semibold">{workspace.owner.name}</p>
                                                    <p className="text-sm text-muted-foreground">{workspace.owner.email}</p>
                                                </div>
                                            </div>
                                            <Badge variant="secondary" className="text-sm">
                                                Owner
                                            </Badge>
                                        </div>

                                        {/* Other members */}
                                        {workspace.users
                                            .filter((u) => u.id !== workspace.owner.id)
                                            .map((user) => (
                                                <div
                                                    key={user.id}
                                                    className="flex items-center justify-between p-4 rounded-lg hover:bg-muted/30 transition-colors"
                                                >
                                                    <div className="flex items-center gap-4">
                                                        <Avatar className="size-12">
                                                            <AvatarFallback className="bg-gradient-to-br from-green-500 to-teal-600 text-white font-semibold text-lg">
                                                                {user.name.charAt(0).toUpperCase()}
                                                            </AvatarFallback>
                                                        </Avatar>
                                                        <div>
                                                            <p className="font-semibold">{user.name}</p>
                                                            <p className="text-sm text-muted-foreground">{user.email}</p>
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-3">
                                                        <Select
                                                            defaultValue={user.pivot?.role || 'member'}
                                                            onValueChange={(value) => handleUpdateRole(user.id, value)}
                                                        >
                                                            <SelectTrigger className="w-32 h-9">
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="admin">Admin</SelectItem>
                                                                <SelectItem value="editor">Editor</SelectItem>
                                                                <SelectItem value="member">Member</SelectItem>
                                                                <SelectItem value="viewer">Viewer</SelectItem>
                                                            </SelectContent>
                                                        </Select>
                                                        {canManage && (
                                                            <AlertDialog>
                                                                <AlertDialogTrigger asChild>
                                                                    <Button variant="ghost" size="icon" className="text-destructive hover:text-destructive">
                                                                        <Trash2 className="size-4" />
                                                                    </Button>
                                                                </AlertDialogTrigger>
                                                                <AlertDialogContent>
                                                                    <AlertDialogHeader>
                                                                        <AlertDialogTitle>Remove member?</AlertDialogTitle>
                                                                        <AlertDialogDescription>
                                                                            Are you sure you want to remove <strong>{user.name}</strong> from this workspace?
                                                                            They will lose access to all projects and boards.
                                                                        </AlertDialogDescription>
                                                                    </AlertDialogHeader>
                                                                    <AlertDialogFooter>
                                                                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                        <AlertDialogAction
                                                                            onClick={() => handleRemoveMember(user.id)}
                                                                            className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                                        >
                                                                            Remove
                                                                        </AlertDialogAction>
                                                                    </AlertDialogFooter>
                                                                </AlertDialogContent>
                                                            </AlertDialog>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}

                                        {/* Pending invitations */}
                                        {canManage && pendingInvitations.length > 0 && (
                                            <div className="space-y-3 pt-6 border-t">
                                                <h4 className="text-sm font-medium text-muted-foreground flex items-center gap-2">
                                                    <Mail className="size-4" />
                                                    Pending invitations
                                                </h4>
                                                {pendingInvitations.map((inv) => (
                                                    <div
                                                        key={inv.id}
                                                        className="flex items-center justify-between p-4 rounded-lg bg-muted/30 border border-dashed"
                                                    >
                                                        <div className="flex items-center gap-4">
                                                            <div className="size-12 rounded-full bg-muted flex items-center justify-center">
                                                                <Mail className="size-5 text-muted-foreground" />
                                                            </div>
                                                            <div>
                                                                <p className="font-medium">{inv.email}</p>
                                                                <p className="text-sm text-muted-foreground">
                                                                    {inv.role} · Invited {new Date(inv.created_at).toLocaleDateString()} · Expires {new Date(inv.expires_at).toLocaleDateString()}
                                                                    {inv.inviter && ` · by ${inv.inviter.name}`}
                                                                </p>
                                                            </div>
                                                        </div>
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <Button variant="outline" size="sm" className="text-destructive hover:text-destructive border-destructive/30">
                                                                    Cancel invite
                                                                </Button>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>Cancel invitation?</AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        The invitation sent to <strong>{inv.email}</strong> will be revoked. They will no longer be able to join this workspace using that link.
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Keep</AlertDialogCancel>
                                                                    <AlertDialogAction
                                                                        onClick={() => router.delete(route('workspaces.invitations.destroy', [workspace.slug, inv.id]))}
                                                                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                                    >
                                                                        Cancel invitation
                                                                    </AlertDialogAction>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    </div>
                                                ))}
                                            </div>
                                        )}

                                        {workspace.users.length <= 1 && pendingInvitations.length === 0 && (
                                            <div className="text-center py-12">
                                                <div className="size-16 rounded-full bg-muted mx-auto mb-4 flex items-center justify-center">
                                                    <Users className="size-8 text-muted-foreground" />
                                                </div>
                                                <h3 className="text-lg font-semibold mb-2">No team members yet</h3>
                                                <p className="text-muted-foreground mb-6 max-w-sm mx-auto">
                                                    Invite your team members to collaborate on this workspace.
                                                </p>
                                                <Button className="gap-2" onClick={() => setInviteDialogOpen(true)}>
                                                    <UserPlus className="size-4" />
                                                    Invite Members
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>

                        {/* AI Settings */}
                        <TabsContent value="ai">
                            <AiSettingsTab workspace={workspace} />
                        </TabsContent>

                        {/* Legal: who runs the public pages (BRAI-156) */}
                        <TabsContent value="legal">
                            <WorkspaceLegalSettings workspace={workspace} />
                        </TabsContent>

                        {/* Danger Zone */}
                        <TabsContent value="danger">
                            <Card className="border-destructive/50">
                                <CardHeader>
                                    <CardTitle className="text-xl flex items-center gap-3 text-destructive">
                                        <Shield className="size-5" />
                                        Danger Zone
                                    </CardTitle>
                                    <CardDescription>
                                        Irreversible and destructive actions. Please proceed with caution.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="space-y-6">
                                    <div className="flex items-center justify-between p-6 rounded-lg border border-destructive/30 bg-destructive/5">
                                        <div>
                                            <h4 className="font-semibold text-lg">Delete this workspace</h4>
                                            <p className="text-sm text-muted-foreground mt-1">
                                                Once you delete a workspace, there is no going back. All projects,
                                                boards, and data will be permanently deleted.
                                            </p>
                                        </div>
                                        <AlertDialog>
                                            <AlertDialogTrigger asChild>
                                                <Button variant="destructive" className="gap-2 shrink-0">
                                                    <Trash2 className="size-4" />
                                                    Delete Workspace
                                                </Button>
                                            </AlertDialogTrigger>
                                            <AlertDialogContent>
                                                <AlertDialogHeader>
                                                    <AlertDialogTitle className="text-xl">
                                                        Are you absolutely sure?
                                                    </AlertDialogTitle>
                                                    <AlertDialogDescription className="text-base">
                                                        This action cannot be undone. This will permanently delete the
                                                        workspace <strong>{workspace.name}</strong> and remove all
                                                        associated projects, boards, and data.
                                                    </AlertDialogDescription>
                                                </AlertDialogHeader>
                                                <AlertDialogFooter>
                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                    <AlertDialogAction
                                                        onClick={handleDeleteWorkspace}
                                                        className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                                                    >
                                                        Delete Workspace
                                                    </AlertDialogAction>
                                                </AlertDialogFooter>
                                            </AlertDialogContent>
                                        </AlertDialog>
                                    </div>
                                </CardContent>
                            </Card>
                        </TabsContent>
                            </div>
                        </div>
                    </Tabs>
                </div>
            </div>
        </AppLayout>
    );
}
