import { usePage } from '@inertiajs/react';
import { LifeBuoy } from 'lucide-react';
import { ContactEmail, LegalLayout, LegalSection } from '@/components/legal/LegalLayout';
import { DOCS_URL } from '@/lib/docs';
import type { LegalPageData } from '@/types';

/*
 * Support (mobile app help): the public support URL of the Brainitive app in the App Store and on
 * Google Play — contact + a short FAQ. Same shell as the legal pages (LegalLayout + legal footer).
 * Brainitive Cloud names its contact address; a self-hosted install names its own operator (never
 * Brainitive Cloud's) and points problems with the app itself to the public issue tracker.
 * The mobile app's own facts live in the mobile repo (docs/store/); keep the FAQ in step with them.
 */

const TOC = [
    { id: 'contact', title: 'Contact' },
    { id: 'sign-in', title: 'Signing in to the mobile app' },
    { id: 'self-hosted', title: 'Using your own Brainitive server' },
    { id: 'notifications', title: 'Notifications' },
    { id: 'delete-account', title: 'Deleting your account' },
    { id: 'web-only', title: 'Billing and administration' },
    { id: 'bugs', title: 'Reporting a bug' },
];

export default function Support({ legal }: { legal: LegalPageData }) {
    const githubRepo = (usePage().props as { githubRepo?: { url: string } }).githubRepo;
    const issuesUrl = githubRepo?.url ? `${githubRepo.url.replace(/\/$/, '')}/issues` : null;
    const n = (id: string) => TOC.findIndex((t) => t.id === id) + 1;
    const web = legal.cloud ? 'www.brainitive.app' : (legal.host ?? 'the web address of your server');

    return (
        <LegalLayout
            title="Support"
            icon={LifeBuoy}
            legal={legal}
            toc={TOC}
            intro={
                <p>
                    Help with the {legal.app_name} mobile app for iPhone and Android and with your {legal.app_name} account. The app is a
                    companion to the web app: your tasks, issues, docs, calendar and inbox on the go. Everything else is on the web.
                </p>
            }
        >
            <LegalSection id="contact" num={n('contact')} title="Contact">
                {legal.cloud ? (
                    <p data-testid="support-contact">
                        Write to us at <ContactEmail legal={legal} /> — questions, problems, feedback and requests about your data.
                    </p>
                ) : (
                    <>
                        <p data-testid="support-contact">
                            This installation{legal.host ? <> at <strong>{legal.host}</strong></> : null} is run by{' '}
                            {legal.operator.name ? <strong>{legal.operator.name}</strong> : 'its operator'}. For questions about your account and your
                            data, please contact them{legal.operator.email ? <> at <ContactEmail legal={legal} /></> : ' directly'}.
                        </p>
                        {issuesUrl && (
                            <p>
                                Problems with the {legal.app_name} software or the mobile app itself can be reported in the{' '}
                                <a href={issuesUrl} rel="noopener noreferrer">
                                    public issue tracker
                                </a>
                                .
                            </p>
                        )}
                    </>
                )}
                <p>
                    Guides for every feature are in the <a href={DOCS_URL}>{legal.app_name} documentation</a>.
                </p>
            </LegalSection>

            <LegalSection id="sign-in" num={n('sign-in')} title="Signing in to the mobile app">
                <p>
                    Tap <strong>Sign in</strong>. The app opens the sign-in page of your server in your phone’s browser, where you sign in with
                    Apple, Google or your e-mail address and password (or another method your server offers). Afterwards the browser hands you back
                    to the app. The app never sees your password.
                </p>
                <p>
                    No account yet? Create one on the same page. If the browser does not return to the app, close it and tap <strong>Sign in</strong>{' '}
                    again; make sure the app is up to date.
                </p>
            </LegalSection>

            <LegalSection id="self-hosted" num={n('self-hosted')} title="Using your own Brainitive server">
                <p>
                    The app works with Brainitive Cloud and with self-hosted Brainitive servers. On the sign-in screen, enter your server’s address
                    (for example <code>https://brainitive.example.com</code>) instead of using Brainitive Cloud, then sign in as usual.
                </p>
                <ul>
                    <li>The server must run Brainitive <strong>v1.49 or newer</strong>; older versions don’t offer the app’s sign-in.</li>
                    <li>It must be reachable from your phone over HTTPS.</li>
                    <li>You can add several servers and switch between them; each keeps its own sign-in.</li>
                </ul>
                <p>Your data then goes only to that server. Its operator, not us, is responsible for it.</p>
            </LegalSection>

            <LegalSection id="notifications" num={n('notifications')} title="Notifications">
                <p>
                    In the app, open <strong>Account → Notifications</strong> and turn notifications on, then allow them when your phone asks. You
                    receive push notifications for the events you have chosen in your notification settings (mentions, assignments, reminders and
                    so on).
                </p>
                <p>
                    Not getting any? Check that notifications for {legal.app_name} are allowed in your phone’s settings and that Focus / Do Not
                    Disturb is off. You can turn them off again in the same places at any time. On a self-hosted server, push must also be enabled
                    by its operator.
                </p>
            </LegalSection>

            <LegalSection id="delete-account" num={n('delete-account')} title="Deleting your account">
                <p>
                    In the app, open <strong>Account → Delete account</strong>. This opens the web page where you confirm the deletion. On the web,
                    go to <strong>Profile settings → Delete account</strong>{' '}
                    {legal.cloud ? (
                        <>
                            (<a href="/profile">{web}/profile</a>)
                        </>
                    ) : null}
                    .
                </p>
                <p>
                    Deleting your account removes your account, your personal settings, connections and tokens, and the workspaces only you belong
                    to. If you own a workspace that other people use, transfer it to someone else or delete it first. Details are in our{' '}
                    <a href={`${legal.privacy}#retention`}>Privacy Policy</a>. Signing out of the app or uninstalling it does <em>not</em> delete
                    your account.
                </p>
            </LegalSection>

            <LegalSection id="web-only" num={n('web-only')} title="Billing and administration">
                <p>
                    Plans and billing, workspace settings, members and roles, integrations and instance administration are only available in the
                    web app at <strong>{web}</strong>. The mobile app does not sell anything and has no in-app purchases.
                </p>
            </LegalSection>

            <LegalSection id="bugs" num={n('bugs')} title="Reporting a bug">
                <p>
                    {legal.cloud ? (
                        <>
                            Send an e-mail to <ContactEmail legal={legal} />
                        </>
                    ) : issuesUrl ? (
                        <>
                            Open an issue in the{' '}
                            <a href={issuesUrl} rel="noopener noreferrer">
                                public issue tracker
                            </a>{' '}
                            (it is public — don’t include private data), or contact this installation’s operator
                        </>
                    ) : (
                        <>Contact this installation’s operator</>
                    )}{' '}
                    and tell us:
                </p>
                <ul>
                    <li>what you did, what you expected and what happened instead,</li>
                    <li>your phone model, its system version (iOS / Android) and the app version,</li>
                    <li>a screenshot or screen recording, if you can.</li>
                </ul>
                {legal.cloud && issuesUrl && (
                    <p>
                        You can also open an issue in our{' '}
                        <a href={issuesUrl} rel="noopener noreferrer">
                            public issue tracker
                        </a>{' '}
                        (it is public — don’t include private data).
                    </p>
                )}
            </LegalSection>
        </LegalLayout>
    );
}
