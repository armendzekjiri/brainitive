import InputError from '@/Components/InputError';
import { User } from '@/types';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Link, useForm, usePage, router } from '@inertiajs/react';
import { FormEventHandler, useRef } from 'react';
import { Camera } from 'lucide-react';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
}: {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}) {
    const user = usePage().props.auth.user as User;
    const fileInputRef = useRef<HTMLInputElement>(null);

    const { data, setData, patch, errors, processing, recentlySuccessful } =
        useForm({
            name: user.name,
            email: user.email,
            bio: user.bio ?? '',
            phone: user.phone ?? '',
            avatar: null as File | null,
        });

    const avatarPreview = data.avatar
        ? URL.createObjectURL(data.avatar)
        : user.avatar_display ?? null;

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        if (data.avatar) {
            const formData = new FormData();
            const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
            if (token) formData.append('_token', token);
            formData.append('_method', 'PATCH');
            formData.append('name', data.name);
            formData.append('email', data.email);
            formData.append('bio', data.bio);
            formData.append('phone', data.phone);
            formData.append('avatar', data.avatar);
            router.post(route('profile.update'), formData, {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {},
            });
            return;
        }
        patch(route('profile.update'), {
            preserveScroll: true,
        });
    };

    return (
        <section className={className}>
            <form onSubmit={submit} className="space-y-6">
                {/* Profile picture */}
                <div className="flex items-center gap-6">
                    <div className="relative">
                        <Avatar className="h-24 w-24">
                            {avatarPreview ? (
                                <AvatarImage src={avatarPreview} alt="" />
                            ) : null}
                            <AvatarFallback className="text-2xl bg-primary/10 text-primary">
                                {(user.name || 'U').charAt(0).toUpperCase()}
                            </AvatarFallback>
                        </Avatar>
                        <button
                            type="button"
                            onClick={() => fileInputRef.current?.click()}
                            className="absolute bottom-0 right-0 flex h-8 w-8 items-center justify-center rounded-full border-2 border-background bg-primary text-primary-foreground shadow hover:bg-primary/90"
                        >
                            <Camera className="size-4" />
                        </button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept="image/jpeg,image/png,image/jpg"
                            className="hidden"
                            onChange={(e) => setData('avatar', e.target.files?.[0] ?? null)}
                        />
                    </div>
                    <div>
                        <Label className="text-base font-medium">Profile picture</Label>
                        <p className="text-sm text-muted-foreground mt-0.5">
                            JPG or PNG. Max 2MB.
                        </p>
                        {data.avatar && (
                            <p className="text-sm text-primary mt-1">
                                New photo selected
                            </p>
                        )}
                        <InputError className="mt-1" message={errors.avatar} />
                    </div>
                </div>

                <div className="space-y-2">
                    <Label htmlFor="name">Name</Label>
                    <Input
                        id="name"
                        value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required
                        autoComplete="name"
                    />
                    <InputError message={errors.name} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="email">Email</Label>
                    <Input
                        id="email"
                        type="email"
                        value={data.email}
                        onChange={(e) => setData('email', e.target.value)}
                        required
                        autoComplete="username"
                    />
                    <InputError message={errors.email} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="phone">Phone (optional)</Label>
                    <Input
                        id="phone"
                        type="tel"
                        value={data.phone}
                        onChange={(e) => setData('phone', e.target.value)}
                        autoComplete="tel"
                        placeholder="+1 234 567 8900"
                    />
                    <InputError message={errors.phone} />
                </div>

                <div className="space-y-2">
                    <Label htmlFor="bio">Bio (optional)</Label>
                    <Textarea
                        id="bio"
                        value={data.bio}
                        onChange={(e) => setData('bio', e.target.value)}
                        placeholder="A short bio about you"
                        className="min-h-[100px] resize-none"
                        maxLength={1000}
                    />
                    <p className="text-xs text-muted-foreground">
                        {data.bio.length}/1000
                    </p>
                    <InputError message={errors.bio} />
                </div>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div className="rounded-lg border border-primary/30 bg-primary/5 dark:border-primary/20 dark:bg-primary/10 p-4">
                        <p className="text-sm text-primary dark:text-primary">
                            Your email address is unverified.
                            <Link
                                href={route('verification.send')}
                                method="post"
                                as="button"
                                className="ml-1 underline hover:no-underline"
                            >
                                Click here to re-send the verification email.
                            </Link>
                        </p>
                        {status === 'verification-link-sent' && (
                            <p className="mt-2 text-sm font-medium text-brand-green">
                                A new verification link has been sent to your email address.
                            </p>
                        )}
                    </div>
                )}

                <div className="flex items-center gap-4">
                    <Button type="submit" disabled={processing}>
                        {processing ? 'Saving…' : 'Save'}
                    </Button>
                    {recentlySuccessful && (
                        <p className="text-sm text-muted-foreground">Saved.</p>
                    )}
                </div>
            </form>
        </section>
    );
}
