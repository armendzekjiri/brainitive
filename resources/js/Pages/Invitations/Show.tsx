import PrimaryButton from '@/components/PrimaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { Mail, UserPlus, LogIn, UserCircle2 } from 'lucide-react';

interface InvitationData {
    token: string;
    email: string;
    role: string;
    expires_at: string;
}

interface WorkspaceData {
    id: number;
    name: string;
    slug: string;
}

interface InviterData {
    name: string;
}

interface Props {
    invitation: InvitationData;
    workspace: WorkspaceData;
    inviter: InviterData;
    canAccept: boolean;
    auth: { user: { email: string } | null };
}

export default function InvitationsShow({
    invitation,
    workspace,
    inviter,
    canAccept,
}: Props) {
    const form = useForm({});

    const acceptUrl = route('invitations.accept', invitation.token);

    return (
        <GuestLayout>
            <Head title={`Invitation to ${workspace.name}`} />

            <div className="w-full max-w-md space-y-6">
                <div className="text-center">
                    <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-[#0E83DC]/10">
                        <Mail className="size-7 text-[#0E83DC]" />
                    </div>
                    <h1 className="text-xl font-semibold text-foreground">
                        You're invited
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        <strong>{inviter.name}</strong> has invited you to join{' '}
                        <strong>{workspace.name}</strong>
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        You'll join as{' '}
                        <span className="rounded-full border border-[#C0C5C0]/60 bg-[#0E83DC]/5 px-2 py-0.5 text-xs font-medium text-[#0E83DC]">
                            {invitation.role}
                        </span>
                    </p>
                </div>

                {canAccept ? (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(acceptUrl);
                        }}
                        className="space-y-4"
                    >
                        <PrimaryButton
                            type="submit"
                            className="w-full gap-2"
                            disabled={form.processing}
                        >
                            <UserPlus className="size-4" />
                            {form.processing ? 'Accepting…' : 'Accept invitation'}
                        </PrimaryButton>
                    </form>
                ) : (
                    <div className="space-y-3 rounded-xl border border-border bg-muted/30 p-4">
                        <p className="text-center text-sm text-foreground">
                            Sign up or log in with <strong>{invitation.email}</strong> to accept this invitation.
                        </p>
                        <div className="flex flex-col gap-2 sm:flex-row sm:justify-center">
                            <Link
                                href={route('register')}
                                className="inline-flex items-center justify-center gap-2 rounded-xl bg-[#0E83DC] px-4 py-2.5 text-sm font-semibold text-white shadow-lg shadow-[#0E83DC]/20 transition hover:bg-[#0b6fbf]"
                            >
                                <UserCircle2 className="size-4" />
                                Sign up
                            </Link>
                            <Link
                                href={route('login')}
                                className="inline-flex items-center justify-center gap-2 rounded-xl border-2 border-border bg-background/60 px-4 py-2.5 text-sm font-semibold text-foreground backdrop-blur-sm transition hover:bg-card hover:border-border/70"
                            >
                                <LogIn className="size-4" />
                                Log in
                            </Link>
                        </div>
                        <p className="text-center text-xs text-muted-foreground">
                            After signing in, open this link again to accept.
                        </p>
                    </div>
                )}

                <p className="text-center text-xs text-muted-foreground">
                    This invitation is valid for 30 days. Sign up to join the workspace.
                </p>
            </div>
        </GuestLayout>
    );
}
