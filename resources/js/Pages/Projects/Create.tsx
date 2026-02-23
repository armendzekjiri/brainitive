import AppLayout from '@/Layouts/AppLayout';
import { Head, useForm, Link } from '@inertiajs/react';
import { PageProps, Workspace } from '@/types';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

interface Props extends PageProps {
    workspace: Workspace;
}

export default function ProjectsCreate({ auth, workspace }: Props) {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        key: '',
        description: '',
    });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('projects.store', workspace.slug));
    };

    // Auto-generate key from name
    const handleNameChange = (value: string) => {
        setData('name', value);
        if (!data.key || data.key === generateKey(data.name)) {
            setData('key', generateKey(value));
        }
    };

    const generateKey = (name: string) => {
        return name
            .replace(/[^a-zA-Z]/g, '')
            .toUpperCase()
            .substring(0, 4);
    };

    return (
        <AppLayout>
            <Head title="Create Project" />

            <div className="p-6">
                <div className="max-w-2xl mx-auto">
                    <div className="mb-6">
                        <h1 className="text-2xl font-bold tracking-tight">Create Project</h1>
                        <p className="text-muted-foreground">
                            Add a new project to {workspace.name}
                        </p>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Project Details</CardTitle>
                            <CardDescription>
                                Create a new project to organize your work into boards and tasks.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-6">
                                <div className="space-y-2">
                                    <Label htmlFor="name">Project Name</Label>
                                    <Input
                                        id="name"
                                        type="text"
                                        value={data.name}
                                        onChange={(e) => handleNameChange(e.target.value)}
                                        placeholder="My Project"
                                        required
                                    />
                                    {errors.name && (
                                        <p className="text-sm text-destructive">{errors.name}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="key">Project Key</Label>
                                    <Input
                                        id="key"
                                        type="text"
                                        value={data.key}
                                        onChange={(e) => setData('key', e.target.value.toUpperCase().replace(/[^A-Z]/g, '').substring(0, 10))}
                                        placeholder="PROJ"
                                        maxLength={10}
                                        required
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        This key will be used for issue IDs (e.g., {data.key || 'PROJ'}-1, {data.key || 'PROJ'}-2)
                                    </p>
                                    {errors.key && (
                                        <p className="text-sm text-destructive">{errors.key}</p>
                                    )}
                                </div>

                                <div className="space-y-2">
                                    <Label htmlFor="description">Description (optional)</Label>
                                    <Textarea
                                        id="description"
                                        value={data.description}
                                        onChange={(e) => setData('description', e.target.value)}
                                        placeholder="A brief description of this project..."
                                        rows={3}
                                    />
                                    {errors.description && (
                                        <p className="text-sm text-destructive">{errors.description}</p>
                                    )}
                                </div>

                                <div className="flex justify-end gap-3">
                                    <Link href={route('workspaces.show', workspace.slug)}>
                                        <Button type="button" variant="outline">
                                            Cancel
                                        </Button>
                                    </Link>
                                    <Button type="submit" disabled={processing}>
                                        {processing ? 'Creating...' : 'Create Project'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
