import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm, router } from '@inertiajs/react';
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
} from 'lucide-react';
import { useState, FormEvent, ChangeEvent } from 'react';
import { Link } from '@inertiajs/react';

interface Props extends PageProps {
    workspace: Workspace & {
        users: User[];
        owner: User;
    };
    userRole: string;
    timezones: string[];
    industries: Record<string, string>;
    companySizes: Record<string, string>;
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

export default function WorkspacesEdit({
    workspace,
    userRole,
    timezones,
    industries,
    companySizes,
}: Props) {
    const [logoPreview, setLogoPreview] = useState<string | null>(
        workspace.logo_display ?? (workspace.logo ? `/storage/${workspace.logo}` : null)
    );
    const [headerPreview, setHeaderPreview] = useState<string | null>(
        workspace.header_image_display ?? (workspace.header_image ? `/storage/${workspace.header_image}` : null)
    );
    const [inviteDialogOpen, setInviteDialogOpen] = useState(false);

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
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        post(route('workspaces.update', workspace.slug), {
            forceFormData: true,
        });
    };

    const handleInviteMember = (e: FormEvent) => {
        e.preventDefault();
        inviteForm.post(route('workspaces.members.invite', workspace.slug), {
            onSuccess: () => {
                setInviteDialogOpen(false);
                inviteForm.reset();
            },
        });
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

                    {/* Upload header button */}
                    <label className="absolute bottom-4 right-4 cursor-pointer">
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
                    <Tabs defaultValue="general" className="pb-16">
                        <TabsList className="mb-8 h-12 p-1">
                            <TabsTrigger value="general" className="gap-2 px-4 h-10">
                                <Settings className="size-4" />
                                General
                            </TabsTrigger>
                            <TabsTrigger value="branding" className="gap-2 px-4 h-10">
                                <Palette className="size-4" />
                                Branding
                            </TabsTrigger>
                            <TabsTrigger value="members" className="gap-2 px-4 h-10">
                                <Users className="size-4" />
                                Members
                            </TabsTrigger>
                            <TabsTrigger value="danger" className="gap-2 px-4 h-10 text-destructive data-[state=active]:text-destructive">
                                <Shield className="size-4" />
                                Danger Zone
                            </TabsTrigger>
                        </TabsList>

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
                                                <Select
                                                    value={data.timezone}
                                                    onValueChange={(value) => setData('timezone', value)}
                                                >
                                                    <SelectTrigger className="h-11">
                                                        <SelectValue placeholder="Select timezone" />
                                                    </SelectTrigger>
                                                    <SelectContent className="max-h-[300px]">
                                                        {timezones.slice(0, 100).map((tz) => (
                                                            <SelectItem key={tz} value={tz}>
                                                                {tz}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
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
                                        <Dialog open={inviteDialogOpen} onOpenChange={setInviteDialogOpen}>
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
                                                        Invite Team Member
                                                    </DialogTitle>
                                                    <DialogDescription>
                                                        Enter the email address of the person you want to invite.
                                                    </DialogDescription>
                                                </DialogHeader>
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
                                                        <Button type="submit" disabled={inviteForm.processing} className="gap-2">
                                                            {inviteForm.processing ? 'Sending...' : (
                                                                <>
                                                                    <UserPlus className="size-4" />
                                                                    Send Invite
                                                                </>
                                                            )}
                                                        </Button>
                                                    </div>
                                                </form>
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

                                        {workspace.users.length <= 1 && (
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
                    </Tabs>
                </div>
            </div>
        </AppLayout>
    );
}
