import { Shield } from 'lucide-react';
import type { ReactNode } from 'react';
import { ContactEmail, LegalLayout, LegalSection, LegalTable, SelfHostedNotice, ToConfirm, operatorName } from '@/components/legal/LegalLayout';
import type { LegalPageData } from '@/types';

/*
 * Privacy Policy of Brainitive Cloud (BRAI-156). Written from docs/legal/data-inventory.md — every
 * statement must be backed by the code or by the operator's facts (config/legal.php). DRAFT: to be
 * confirmed by a qualified lawyer before it is relied on (docs/legal/README.md). Self-hosted installs
 * see an installation notice instead (their operator is the controller, not us).
 */

const TOC = [
    { id: 'controller', title: 'Who is responsible' },
    { id: 'summary', title: 'Summary' },
    { id: 'roles', title: 'Our role: controller or processor' },
    { id: 'website', title: 'Visiting our website' },
    { id: 'analytics', title: 'Visit statistics (Umami, only with your consent)' },
    { id: 'account', title: 'Your account and sign-in' },
    { id: 'content', title: 'Workspaces and content' },
    { id: 'payments', title: 'Paid plans and payments (Paddle)' },
    { id: 'email', title: 'E-mails and notifications' },
    { id: 'integrations', title: 'Integrations you connect' },
    { id: 'ai', title: 'AI features and AI connectors' },
    { id: 'public-pages', title: 'Customers’ public pages' },
    { id: 'extension', title: 'Browser extension' },
    { id: 'device', title: 'Cookies and storage on your device' },
    { id: 'recipients', title: 'Recipients and processors' },
    { id: 'transfers', title: 'Transfers outside the EU/EEA' },
    { id: 'retention', title: 'How long we keep data' },
    { id: 'security', title: 'Security' },
    { id: 'rights', title: 'Your rights' },
    { id: 'obligation', title: 'Do you have to provide data?' },
    { id: 'children', title: 'Children' },
    { id: 'changes', title: 'Changes to this policy' },
];

export default function Privacy({ legal }: { legal: LegalPageData }) {
    if (!legal.cloud) {
        return (
            <LegalLayout title="Privacy notice" icon={Shield} legal={legal}>
                <SelfHostedNotice legal={legal} document="privacy policy" externalUrl={legal.external.privacy_url} />
                <LegalSection id="software" title="What the software stores">
                    <p>
                        {legal.app_name} stores the account details you enter (name, e-mail address, a hashed password), the content you create in
                        workspaces, technical session data (a session cookie and, in the session table, your IP address and browser) and — if you
                        use them — connected calendars, browser notifications and integrations. Which services this installation connects to, and
                        how long it keeps data, is decided by its operator.
                    </p>
                </LegalSection>
            </LegalLayout>
        );
    }

    const n = (id: string) => TOC.findIndex((t) => t.id === id) + 1;
    const op = legal.operator;
    const p = legal.providers;
    const keep = (value: string | null, what: string): ReactNode => value ?? <ToConfirm>{what}</ToConfirm>;

    return (
        <LegalLayout
            title="Privacy Policy"
            icon={Shield}
            legal={legal}
            toc={TOC}
            intro={
                <p>
                    This policy explains which personal data {legal.app_name} processes when you visit www.brainitive.app, use Brainitive Cloud,
                    or use a page that one of our customers runs on Brainitive (such as a support page or a form). It follows the EU General Data
                    Protection Regulation (GDPR) and the German Telecommunications Digital Services Data Protection Act (TDDDG).
                </p>
            }
        >
            <LegalSection id="controller" num={n('controller')} title="Who is responsible">
                <p>
                    The controller for the processing described here is <strong>{operatorName(legal)}</strong>
                    {op.description ? <>. {op.description}</> : '.'}
                </p>
                {op.address && <p className="whitespace-pre-line">{op.address}</p>}
                <p>
                    Contact for all privacy questions and requests: <ContactEmail legal={legal} />.
                    {legal.imprint && (
                        <>
                            {' '}
                            See also our <a href={legal.imprint}>imprint</a>.
                        </>
                    )}
                </p>
                <p>
                    {op.dpo ? (
                        <>Data protection officer: {op.dpo}</>
                    ) : (
                        <>We have not appointed a data protection officer; please send privacy requests to the address above.</>
                    )}
                </p>
                {op.eu_representative && <p>Representative in the EU (Art. 27 GDPR): {op.eu_representative}</p>}
            </LegalSection>

            <LegalSection id="summary" num={n('summary')} title="Summary">
                <ul>
                    <li>We process your data to provide Brainitive: your account, your workspaces and what you create in them.</li>
                    <li>We do not sell personal data, do not show advertising and do not use advertising or tracking cookies.</li>
                    <li>
                        Our visit statistics (Umami) run on our own server and only if you allow them; they set no cookies. They never run on
                        customers’ pages or on links that contain a secret.
                    </li>
                    <li>
                        Google, Microsoft, GitHub, AI services and browser push services only receive data when you (or your workspace) choose to
                        use the matching feature.
                    </li>
                    <li>
                        Brainitive runs on servers of {p.hosting ?? 'OVHcloud'}; e-mails are delivered through {p.email ?? 'MXroute'}.
                    </li>
                </ul>
            </LegalSection>

            <LegalSection id="roles" num={n('roles')} title="Our role: controller or processor">
                <p>
                    For your account, sign-in, security, our website and the e-mails we send about the service, we are the <strong>controller</strong>.
                </p>
                <p>
                    The content of a workspace — documents, issues, comments, files, and the data of people outside the workspace (for example the
                    contacts of a helpdesk, the leads of a form, the guests of a booking page or the members of a client portal) — is the responsibility of the workspace’s
                    owner, our customer. For this content <strong>the customer is the controller</strong> and we process it on the customer’s
                    behalf as a <strong>processor</strong> (Art. 28 GDPR)
                    {legal.dpa ? (
                        <>
                            {' '}
                            under our <a href={legal.dpa}>Data Processing Agreement</a>
                        </>
                    ) : null}
                    . If you have questions about such data, please contact the organisation that runs the workspace or page first; we support
                    them in answering.
                </p>
            </LegalSection>

            <LegalSection id="website" num={n('website')} title="Visiting our website">
                <p>
                    When you open a page, your browser sends technical data that the server needs to deliver it: IP address, date and time, the
                    requested address, the referring page, browser and operating system. Our web server records this in access logs to keep the
                    service secure and to find errors. Legal basis: Art. 6 (1) (f) GDPR — our legitimate interest in a secure, working service.
                    Storage period: {keep(legal.retention.server_logs, 'server log retention period')}.
                </p>
                <p>
                    Fonts, scripts and styles are served from our own servers; we do not load Google Fonts or other third-party content when you
                    view our pages. Videos, Figma files or posts from X that someone embeds in a document only load after you click{' '}
                    <em>Load content</em> on them.
                </p>
            </LegalSection>

            <LegalSection id="analytics" num={n('analytics')} title="Visit statistics (Umami, only with your consent)">
                <p>
                    On www.brainitive.app we would like to measure how often pages are used with <strong>Umami</strong>, an open-source analytics
                    tool that we run ourselves on a server of {p.hosting ?? 'OVHcloud'} (stats.novabrainhub.de). No analytics company receives the
                    data. <strong>It runs only if you allow it.</strong> We ask once with a small notice (“Allow” or “No thanks”); until you
                    answer, and if you say no, the Umami script is not loaded and your browser sends nothing to it.
                </p>
                <ul>
                    <li>Umami sets no cookies. We store only your answer in your browser (see the <a href={legal.cookies}>Cookies page</a>).</li>
                    <li>
                        With your consent it records the page address (without query strings or page titles), the referring page, browser,
                        operating system, device type, screen size, language, and the country or region derived from your IP address.
                    </li>
                    <li>
                        Umami does not store your IP address. By its design it uses the IP address and browser data only briefly, to derive the
                        location and an anonymous visit identifier with a salt that changes regularly, so visits cannot be linked over longer
                        periods or across websites.
                    </li>
                    <li>
                        It never runs on customers’ public pages (support pages, forms, portals, shared links) or on pages whose address contains a
                        secret (password reset, invitations, calendar feeds), even if you allowed it.
                    </li>
                    <li>
                        If your browser sends <em>Global Privacy Control</em> or <em>Do Not Track</em>, we treat that as “no” and do not ask.
                    </li>
                </ul>
                <p>
                    Legal basis: your consent, Art. 6 (1) (a) GDPR, and for reading information from your browser § 25 (1) TDDDG. Statistics are
                    kept for {keep(legal.retention.analytics, 'analytics retention period')}.
                </p>
                <p>
                    You can withdraw your consent at any time with effect for the future — on our <a href={legal.cookies}>Cookies page</a>, where
                    you can also change your answer. Counting stops immediately; the lawfulness of the counting before the withdrawal is not
                    affected (Art. 7 (3) GDPR).
                </p>
            </LegalSection>

            <LegalSection id="account" num={n('account')} title="Your account and sign-in">
                <p>
                    <strong>Registration:</strong> name, e-mail address and password (stored only as a salted hash). We confirm your e-mail address
                    with a link and record when you accepted which version of the Terms of Use. Your time zone is taken from your browser so that
                    reminders arrive at the right time. Profile details (photo, bio, phone) are optional.
                </p>
                <p>
                    <strong>Sign-in with Google, Microsoft or GitHub:</strong> if you choose it, the provider confirms your identity and sends us
                    your name, e-mail address and a user ID (Google: <em>openid, profile, email</em>; Microsoft: <em>openid, profile, email,
                    User.Read</em>, read from the sign-in token only; GitHub: <em>read:user, user:email</em>). We store only that ID, your name and
                    e-mail address — no access tokens of these providers. The providers’ own privacy policies apply on their side.
                </p>
                <p>
                    <strong>Security data:</strong> for each signed-in session we keep your IP address, browser and last activity, so that you stay
                    signed in and can sign out other devices; we also store when you last signed in. Sign-in attempts are rate-limited by e-mail
                    and IP address for a short time. Requests of AI connectors (see section {n('ai')}) are currently logged with IP address and
                    browser for troubleshooting. Actions of instance administrators are logged with their IP address.
                </p>
                <p>
                    Legal basis: Art. 6 (1) (b) GDPR (the contract with you — providing your account) and Art. 6 (1) (f) GDPR (protecting accounts
                    and the service against misuse).
                </p>
            </LegalSection>

            <LegalSection id="content" num={n('content')} title="Workspaces and content">
                <p>
                    We store what you and your team create: workspaces, projects, boards and issues, documents and whiteboards, comments, files,
                    snippets, bookmarks, captures, plans, time entries and billing documents (with the client names, addresses and VAT IDs you
                    enter), and a search index built from this content. Members of a workspace see the content according to their role; the
                    history of an issue records who changed what. When several people edit a document or whiteboard at the same time, their
                    names and cursors are shared with each other.
                </p>
                <p>For bookmarks, our server fetches the bookmarked page once to show its title, description and icon.</p>
                <p>
                    <strong>Offline mode</strong> (optional) keeps copies of the projects you choose on your device and removes them when you sign
                    out.
                </p>
                <p>
                    Legal basis: Art. 6 (1) (b) GDPR towards the account holder; for content that is our customers’ responsibility we act as their
                    processor (section {n('roles')}).
                </p>
            </LegalSection>

            <LegalSection id="payments" num={n('payments')} title="Paid plans and payments (Paddle)">
                <p>
                    Paid plans are sold by our reseller <strong>Paddle.com Market Ltd</strong> (Judd House, 18–29 Mora Street, London EC1V 8BT,
                    United Kingdom), the merchant of record. When a workspace admin clicks “Upgrade”, Paddle’s checkout opens on our page; its
                    script is only loaded after that click. Paddle collects the payment details (card or other method, billing address, VAT
                    number if given) itself and processes them as an independent controller under its own privacy notice (paddle.com/legal/privacy).
                    We never see card numbers.
                </p>
                <p>
                    From Paddle we receive what we need to run the plan: a customer number, name, e-mail address, country, the plan, the number
                    of members paid for, the subscription status and payment dates, and the invoices. Legal basis: performance of the contract
                    (Art. 6(1)(b) GDPR) and our legal obligations to keep business records (Art. 6(1)(c) GDPR). Workspaces on the Free plan send
                    nothing to Paddle.
                </p>
            </LegalSection>

            <LegalSection id="email" num={n('email')} title="E-mails and notifications">
                <p>
                    We send e-mails that belong to the service: address confirmation, password reset, invitations, the notifications and digests
                    you have switched on, and reminders. You choose which notifications you receive, and through which channel, in your profile. We
                    send no newsletters or advertising. E-mails are delivered by our e-mail provider <strong>{p.email ?? 'MXroute'}</strong>.
                </p>
                <p>
                    <strong>Browser push notifications</strong> (optional): if you allow them, your browser gives us an address at its push service
                    (Google, Mozilla, Apple or Microsoft, depending on the browser) and keys to encrypt messages. We store this address, the keys, a
                    short description of the browser and device, and when messages last succeeded. Message contents are encrypted for your browser
                    as the Web Push standard requires; the push service only sees that a message is sent. You can switch push off per device at
                    any time.
                </p>
                <p>Legal basis: Art. 6 (1) (b) GDPR — the e-mails and notifications are part of the service you use.</p>
            </LegalSection>

            <LegalSection id="integrations" num={n('integrations')} title="Integrations you connect">
                <p>These only happen when you connect them, and you can disconnect them at any time:</p>
                <ul>
                    <li>
                        <strong>Google Calendar and Google Tasks</strong> and <strong>Outlook / Microsoft 365</strong>: we read and write your events
                        and tasks so they appear in Brainitive. Meetings you create with Google Meet or Microsoft Teams are created in your
                        calendar, and the provider e-mails the invitations to the attendees you add.
                    </li>
                    <li>
                        <strong>iCloud / CalDAV and calendar links (ICS):</strong> our server signs in with the app password you enter, or downloads
                        the link you enter.
                    </li>
                    <li>
                        <strong>Booking pages:</strong> to show when you’re free, our server reads the start and end of the events in the
                        calendars you pick for a page (never their titles for the visitor — the page only shows free times), plus your issues and
                        tasks with a time if you choose. A booking is written to the calendar you pick, with the guest invited.
                    </li>
                    <li>
                        <strong>Calendar feed:</strong> a private link that lets your calendar app read your Brainitive events. We count how often it
                        is fetched, not who fetches it.
                    </li>
                    <li>
                        <strong>GitHub</strong> (GitHub App or webhooks): repository names, branches, commits and pull requests (titles, authors,
                        links) that mention an issue key are shown on the issue. Raw deliveries are deleted after 14 days.
                    </li>
                    <li>
                        <strong>Webhooks</strong> that a workspace admin sets up send event data to the address the admin chooses.
                    </li>
                </ul>
                <p>
                    Access tokens, app passwords and secrets of these integrations are stored encrypted. Legal basis: Art. 6 (1) (b) GDPR — you
                    asked us to connect the service.
                </p>
            </LegalSection>

            <LegalSection id="ai" num={n('ai')} title="AI features and AI connectors">
                <p>
                    Brainitive has <strong>no AI provider of its own</strong>. AI writing help and summaries only work when a workspace admin adds an
                    AI service (for example OpenAI, an Azure deployment or a self-hosted model) with the workspace’s own key. Then the text you
                    select — or, for project reports and meeting notes, the relevant titles, notes and dates — is sent to that service when you use
                    the feature. The workspace chooses the service and is responsible for it; the key is stored encrypted.
                </p>
                <p>
                    <strong>AI connectors (MCP):</strong> you can allow an AI assistant such as Claude or ChatGPT to read and change your workspace.
                    The assistant then receives the content it asks for, within your own permissions, and its provider processes it under its own
                    terms. You can revoke access in your profile at any time.
                </p>
            </LegalSection>

            <LegalSection id="public-pages" num={n('public-pages')} title="Customers’ public pages">
                <p>
                    Our customers can publish support pages and a support widget, forms, feedback and client portals, shared documents, plans and
                    changelogs. Each of these pages says which organisation runs it. That organisation is the controller for what you enter there
                    (for example your name, e-mail address, message, attachments, form answers or votes); we host the page as its processor. The
                    organisation’s own privacy policy is linked on the page when it has provided one.
                </p>
                <p>
                    <strong>Guest sign-in for portals:</strong> to vote or comment in a portal you can sign in with a one-time link sent to your
                    e-mail address. We keep the e-mail address and optional name of this guest identity so it works across the portals shared with
                    you. The one-time links expire after 30 minutes. Legal basis: Art. 6 (1) (b) GDPR.
                </p>
                <p>
                    Forms count how many visitors view, start and complete them with a random number that only exists while the page is open — no
                    cookie, nothing stored on your device, no IP address.
                </p>
            </LegalSection>

            <LegalSection id="extension" num={n('extension')} title="Browser extension">
                <p>
                    The optional Brainitive browser extension (Chrome, Edge, Firefox) reads a web page only when you use it on that page — the
                    toolbar button, the right-click menu or a keyboard shortcut — and sends what you choose to capture (the page address and title,
                    selected text, a clipped article or a screenshot) only to the Brainitive server you signed in to. It does not track your
                    browsing, has no analytics and shares nothing with third parties. Its sign-in token is stored on your device, can only save
                    content and search titles, and is removed when you sign out.
                </p>
            </LegalSection>

            <LegalSection id="device" num={n('device')} title="Cookies and storage on your device">
                <p>
                    We only use cookies and browser storage that are strictly necessary for the service you asked for (§ 25 (2) no. 2 TDDDG): the
                    session and security cookies, “remember me”, and preferences such as your theme or sidebar layout. We use no optional cookies;
                    the only optional thing — our visit statistics — runs only with your consent (section {n('analytics')}), and your answer is
                    remembered in your browser. The complete list is on our <a href={legal.cookies}>Cookies page</a>.
                </p>
            </LegalSection>

            <LegalSection id="recipients" num={n('recipients')} title="Recipients and processors">
                <p>Personal data is only passed on as described in this policy:</p>
                <LegalTable
                    head={['Recipient', 'Purpose', 'When']}
                    rows={[
                        [p.hosting ?? 'OVHcloud', 'Servers for the application, database and files; backups on a separate server; our analytics', 'Always'],
                        [p.email ?? 'MXroute', 'Delivering e-mails', 'For e-mails we send'],
                        ['Google, Microsoft, GitHub', 'Sign-in, calendars, tasks, meetings, repositories', 'Only if you use the integration'],
                        ['The push service of your browser (Google, Mozilla, Apple, Microsoft)', 'Delivering push notifications', 'Only if you enable push'],
                        ['The AI service chosen by your workspace', 'AI features', 'Only if your workspace enables them'],
                        ['Paddle.com Market Ltd (United Kingdom, merchant of record)', 'Selling and billing paid plans', 'Only for workspaces on a paid plan'],
                        ['Members of your workspace', 'Collaboration', 'According to their role'],
                        ['Authorities and courts', 'Legal obligations', 'Only where we are legally obliged'],
                    ]}
                />
                <p>
                    Our processors are bound by data processing agreements (Art. 28 GDPR). The full list with locations and safeguards is on our{' '}
                    {legal.subprocessors ? <a href={legal.subprocessors}>sub-processors page</a> : 'sub-processors page'}.
                </p>
            </LegalSection>

            <LegalSection id="transfers" num={n('transfers')} title="Transfers outside the EU/EEA">
                <p>
                    Where a recipient processes data in a country outside the EU/EEA — in particular the US providers of integrations you choose
                    (Google, Microsoft, GitHub) and of browser push services — the transfer is based on an adequacy decision of the European
                    Commission (for the USA: the EU-U.S. Data Privacy Framework, for certified companies) or on the EU Standard Contractual Clauses
                    (Art. 45, 46 GDPR). Paddle (paid plans) is in the United Kingdom, for which an adequacy decision applies. The safeguard for
                    each of our processors is listed on the sub-processors page.
                </p>
            </LegalSection>

            <LegalSection id="retention" num={n('retention')} title="How long we keep data">
                <LegalTable
                    head={['Data', 'Storage period']}
                    rows={[
                        ['Account and profile', 'Until you delete your account'],
                        ['Billing records of paid plans (plan, payments, invoices)', '10 years after the end of the year of the payment (commercial and tax law)'],
                        ['Workspace content', 'Until it, its workspace or the account is deleted (deleted projects, documents and issues are first kept marked as deleted)'],
                        ['Session (cookie and session record)', '2 hours of inactivity; “remember me” up to about 400 days or until you sign out'],
                        ['Password reset links', '60 minutes, then deleted daily'],
                        ['Guest sign-in links', '30 minutes; deleted one day after they expire'],
                        ['Access tokens of AI connectors, the CLI and the extension', '30 days (renewal: 60 days); expired and revoked tokens are deleted'],
                        ['Notifications in your inbox', 'Archived ones after 90 days, all after 180 days'],
                        ['Push subscriptions', 'Until you switch push off; 30 days after the push service reports them invalid'],
                        ['Form statistics (view / start / step)', '91 days'],
                        ['Technical log of incoming support e-mails', '180 days'],
                        ['GitHub deliveries / outgoing webhook deliveries', '14 days / 30 days'],
                        ['Project exports / imports', 'Export file 24 hours; uploaded import files 7 days'],
                        ['Failed background jobs', '30 days'],
                        ['Web server logs', keep(legal.retention.server_logs, 'period')],
                        ['Backups', keep(legal.retention.backups, 'period')],
                        ['Analytics statistics (Umami)', keep(legal.retention.analytics, 'period')],
                    ]}
                />
                <p>
                    Where the law requires us to keep data longer (for example tax records, once paid plans exist), we keep it for that period and
                    restrict its use.
                </p>
                <p>
                    <strong>Deleting your account</strong> (Profile → Delete account) removes your account, your personal settings, connections
                    and tokens, and the workspaces only you belong to. If you own a workspace other people use, transfer or delete it first. What
                    you created in other people’s workspaces stays there and is handed over to the workspace owner, because it is part of their
                    work; your comments are deleted. Backups are overwritten in their normal cycle.
                </p>
            </LegalSection>

            <LegalSection id="security" num={n('security')} title="Security">
                <p>
                    Connections are encrypted with TLS. Passwords are stored as salted hashes; access tokens of integrations, app passwords, AI
                    keys and webhook secrets are stored encrypted. Access to workspaces is checked on every request according to the member’s role,
                    sign-in attempts are rate-limited, and instance administrators have no access to workspace content. You can sign out of all
                    devices from your profile. Please report vulnerabilities at{' '}
                    <a href="https://www.brainitive.app/support/security-issue">brainitive.app/support/security-issue</a>.
                </p>
            </LegalSection>

            <LegalSection id="rights" num={n('rights')} title="Your rights">
                <p>Under the GDPR you have the right to:</p>
                <ul>
                    <li>access your data and receive a copy (Art. 15),</li>
                    <li>have incorrect data corrected (Art. 16),</li>
                    <li>have your data deleted (Art. 17) and its processing restricted (Art. 18),</li>
                    <li>receive the data you provided in a common machine-readable format (Art. 20),</li>
                    <li>
                        <strong>object</strong> to processing based on our legitimate interests, on grounds relating to your particular situation
                        (Art. 21),
                    </li>
                    <li>withdraw a consent at any time with effect for the future (Art. 7 (3)),</li>
                    <li>
                        lodge a complaint with a data protection supervisory authority (Art. 77), in particular in the EU member state where you live
                        or work
                        {op.supervisory_authority ? <>; the authority responsible for us is {op.supervisory_authority}</> : null}.
                    </li>
                </ul>
                <p>
                    You can change or delete most data yourself in your profile and workspace settings, and export projects.{' '}
                    <strong>Download my data</strong> (Profile → Your data) gives you a copy of the data about your account at any time, as a
                    ZIP of machine-readable JSON files with an explanation: your profile and settings, the content you created in the workspaces
                    you belong to, your time entries, notifications, devices, connected calendars and apps, and the files you uploaded — never
                    passwords, tokens or other people’s data. We prepare it in the background and send you a link that works for 24 hours and
                    only when you are signed in; then the file is deleted (the record that you asked for it after 30 days). You can request a new
                    copy once an hour. For anything else, write to <ContactEmail legal={legal} />. We answer within one month (Art. 12 (3) GDPR).
                    For content a workspace or a customer’s page is responsible for — including content in workspaces you no longer belong to —
                    we pass your request on to that organisation and support it.
                </p>
                <p>We make no decisions based solely on automated processing, including profiling (Art. 22 GDPR).</p>
            </LegalSection>

            <LegalSection id="obligation" num={n('obligation')} title="Do you have to provide data?">
                <p>
                    You are not legally obliged to provide data. Without a name, e-mail address and password we cannot create an account; without
                    the technical data your browser sends, we cannot show you our pages.
                </p>
            </LegalSection>

            <LegalSection id="children" num={n('children')} title="Children">
                <p>Brainitive is not intended for children. You must be at least 16 years old to create an account.</p>
            </LegalSection>

            <LegalSection id="changes" num={n('changes')} title="Changes to this policy">
                <p>
                    We update this policy when our service or the law changes; the version and date at the top show the current state. We inform
                    registered users about significant changes by e-mail or in the app before they take effect.
                </p>
            </LegalSection>
        </LegalLayout>
    );
}
