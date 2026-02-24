import PrimaryButton from '@/Components/PrimaryButton';
import GuestLayout from '@/Layouts/GuestLayout';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';
import { Mail, ArrowRight, LogOut } from 'lucide-react';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title="Verify your email" />

            <div className="space-y-6">
                <div className="flex justify-center">
                    <div className="flex size-14 items-center justify-center rounded-2xl bg-primary/10 dark:bg-primary/20">
                        <Mail className="size-7 text-primary" strokeWidth={2} />
                    </div>
                </div>

                <div className="text-center">
                    <h1 className="text-xl font-semibold tracking-tight text-foreground">
                        Verify your email address
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground leading-relaxed">
                        Thanks for signing up! We’ve sent a verification link to your email.
                        Click the link in that email to activate your account and get started.
                    </p>
                </div>

                {status === 'verification-link-sent' && (
                    <div className="rounded-lg border border-brand-green/30 bg-brand-green/10 dark:border-brand-green/30 dark:bg-brand-green/10 px-4 py-3 text-sm font-medium text-brand-green dark:text-brand-green">
                        A new verification link has been sent to your email address.
                    </div>
                )}

                <form onSubmit={submit} className="space-y-4">
                    <PrimaryButton
                        type="submit"
                        disabled={processing}
                        className="w-full justify-center gap-2 rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-primary/90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50"
                    >
                        {processing ? (
                            'Sending…'
                        ) : (
                            <>
                                Resend verification email
                                <ArrowRight className="size-4" />
                            </>
                        )}
                    </PrimaryButton>

                    <p className="text-center">
                        <Link
                            href={route('logout')}
                            method="post"
                            as="button"
                            className="inline-flex items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 rounded-md"
                        >
                            <LogOut className="size-4" />
                            Log out
                        </Link>
                    </p>
                </form>
            </div>
        </GuestLayout>
    );
}
