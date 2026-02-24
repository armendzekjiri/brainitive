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
                    <div className="flex size-14 items-center justify-center rounded-2xl bg-violet-100 dark:bg-violet-500/20">
                        <Mail className="size-7 text-violet-600 dark:text-violet-400" strokeWidth={2} />
                    </div>
                </div>

                <div className="text-center">
                    <h1 className="text-xl font-semibold tracking-tight text-gray-900 dark:text-white">
                        Verify your email address
                    </h1>
                    <p className="mt-2 text-sm text-gray-600 dark:text-gray-400 leading-relaxed">
                        Thanks for signing up! We’ve sent a verification link to your email.
                        Click the link in that email to activate your account and get started.
                    </p>
                </div>

                {status === 'verification-link-sent' && (
                    <div className="rounded-lg border border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/50 px-4 py-3 text-sm font-medium text-emerald-800 dark:text-emerald-200">
                        A new verification link has been sent to your email address.
                    </div>
                )}

                <form onSubmit={submit} className="space-y-4">
                    <PrimaryButton
                        type="submit"
                        disabled={processing}
                        className="w-full justify-center gap-2 rounded-xl bg-violet-600 px-4 py-3 text-sm font-semibold text-white shadow-sm hover:bg-violet-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-violet-600 disabled:opacity-50"
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
                            className="inline-flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-white focus:outline-none focus:ring-2 focus:ring-violet-500 focus:ring-offset-2 rounded-md"
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
