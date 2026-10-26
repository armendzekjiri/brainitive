import { Scale } from 'lucide-react';
import { ContactEmail, LegalLayout, LegalSection, SelfHostedNotice, operatorName } from '@/components/legal/LegalLayout';
import type { LegalPageData } from '@/types';

/*
 * Terms of Use of Brainitive Cloud (BRAI-156). DRAFT written to a German/EU standard (liability per
 * §§ 276, 307 BGB case law, consumer withdrawal § 312 (1a) BGB, no tacit consent to changes) — to be
 * confirmed by a qualified lawyer before it is relied on (docs/legal/README.md). The accepted version
 * is stored per account (users.terms_version = LegalInfo::DOCUMENTS['terms']['version']).
 */

const TOC = [
    { id: 'scope', title: 'Scope and parties' },
    { id: 'service', title: 'The service' },
    { id: 'plans', title: 'Early access, plans and prices' },
    { id: 'account', title: 'Your account' },
    { id: 'workspaces', title: 'Workspaces and team access' },
    { id: 'acceptable-use', title: 'Acceptable use' },
    { id: 'content', title: 'Your content' },
    { id: 'data-protection', title: 'Data protection and your role' },
    { id: 'ai', title: 'AI features and AI connectors' },
    { id: 'third-party', title: 'Third-party services' },
    { id: 'availability', title: 'Availability and changes to the service' },
    { id: 'liability', title: 'Liability' },
    { id: 'termination', title: 'Term, termination, export and deletion' },
    { id: 'withdrawal', title: 'Right of withdrawal (consumers)' },
    { id: 'changes', title: 'Changes to these terms' },
    { id: 'law', title: 'Governing law and jurisdiction' },
    { id: 'final', title: 'Final provisions' },
];

export default function Terms({ legal }: { legal: LegalPageData }) {
    if (!legal.cloud) {
        return (
            <LegalLayout title="Terms of use" icon={Scale} legal={legal}>
                <SelfHostedNotice legal={legal} document="terms of use" externalUrl={legal.external.terms_url} />
                <LegalSection id="licence" title="The software">
                    <p>
                        {legal.app_name} is open-source software licensed under the GNU Affero General Public License v3.0. The licence covers the
                        software; the use of this installation is governed by its operator.
                    </p>
                </LegalSection>
            </LegalLayout>
        );
    }

    const n = (id: string) => TOC.findIndex((t) => t.id === id) + 1;
    const op = legal.operator;
    const we = operatorName(legal);

    return (
        <LegalLayout
            title="Terms of Use"
            icon={Scale}
            legal={legal}
            toc={TOC}
            intro={
                <p>
                    These terms govern the use of Brainitive Cloud, the hosted service at www.brainitive.app. Please read them before you create an
                    account. You can save or print this page at any time.
                </p>
            }
        >
            <LegalSection id="scope" num={n('scope')} title="Scope and parties">
                <p>
                    The contract is concluded between you and <strong>{we}</strong> (“we”, “us”)
                    {op.address ? <>, {op.address.replace(/\n/g, ', ')}</> : null}. Contact: <ContactEmail legal={legal} />.
                </p>
                <p>
                    These terms apply to every account, whether you use Brainitive privately or for a business, and to every role in a workspace
                    (owner, admin, editor, member, viewer). Terms of business of a customer do not apply unless we agree to them in writing.
                </p>
                <p>
                    By creating an account — with e-mail and password, or by continuing with Google, Microsoft or GitHub for the first time — you
                    accept these terms. We record which version you accepted and when. How we handle personal data is explained in our{' '}
                    <a href={legal.privacy}>Privacy Policy</a>; it is information, not part of the contract.
                </p>
            </LegalSection>

            <LegalSection id="service" num={n('service')} title="The service">
                <p>
                    Brainitive is an online workspace: boards and sprints, documents and wiki, whiteboards, plans, lists and calendar, helpdesk and
                    support pages, forms, feedback and client portals, time tracking and client billing, imports and exports, integrations and AI
                    connectors. The current feature set is described on our website and in the app.
                </p>
                <p>
                    The Brainitive software is open source under the GNU Affero General Public License v3.0. If you run it on your own servers
                    (self-hosting), that licence applies instead of these terms, and we provide no service to you.
                </p>
            </LegalSection>

            <LegalSection id="plans" num={n('plans')} title="Early access, plans and prices">
                <p>
                    Brainitive Cloud is currently in <strong>early access</strong>: it is free of charge, we offer no paid plans and collect no
                    payment details. During early access the commitments planned for paid plans (such as an uptime SLA or a backup restore period)
                    do not apply.
                </p>
                <p>
                    We plan to introduce paid plans after early access (currently planned: Free, Team, Business and Enterprise, as shown on our
                    website). We will announce paid plans at least 30 days before they start. You will only pay if you actively choose a paid plan
                    and agree to its price; a separate order and, where required, separate terms for it will apply.
                </p>
            </LegalSection>

            <LegalSection id="account" num={n('account')} title="Your account">
                <ul>
                    <li>You must be at least 16 years old.</li>
                    <li>Give accurate details and keep your e-mail address up to date — we use it for messages about your account.</li>
                    <li>
                        Keep your password and sign-in links confidential. Tell us at once at <ContactEmail legal={legal} /> if you suspect someone
                        else is using your account; you can also sign out of all devices from your profile.
                    </li>
                    <li>One account is for one person. Accounts may not be shared or sold.</li>
                </ul>
            </LegalSection>

            <LegalSection id="workspaces" num={n('workspaces')} title="Workspaces and team access">
                <p>
                    The person who creates a workspace is its owner. The owner and admins invite members, assign roles and decide who sees what.
                    The owner is responsible for the workspace, including that its members follow these terms. When you invite someone, they get
                    access to the workspace’s content according to their role. If you leave a workspace or delete your account, what you created
                    in someone else’s workspace stays there, because it is part of their work.
                </p>
            </LegalSection>

            <LegalSection id="acceptable-use" num={n('acceptable-use')} title="Acceptable use">
                <p>You may not use Brainitive to:</p>
                <ul>
                    <li>store or share content that is unlawful, infringes the rights of others, or is discriminatory, threatening or abusive;</li>
                    <li>send spam, run phishing or fraud, or collect people’s data without a legal basis (for example through forms or portals);</li>
                    <li>distribute malware, attack or overload our systems, or get around limits, rate limits or access controls;</li>
                    <li>access other people’s accounts or data without permission, or scrape the service;</li>
                    <li>use public pages, links or e-mails sent through Brainitive in a way that misleads recipients about who sends them.</li>
                </ul>
                <p>
                    If we learn of a violation we may remove the content concerned or block access, taking your legitimate interests into account
                    and, where possible, after telling you first. You can object to such a decision by writing to us. Please report illegal content
                    to <ContactEmail legal={legal} />.
                </p>
            </LegalSection>

            <LegalSection id="content" num={n('content')} title="Your content">
                <p>
                    Everything you create or upload remains yours (or your workspace’s). You grant us the simple, non-exclusive, free right to
                    store, copy, process and display your content only as far as necessary to provide the service to you and the people you share
                    it with — for example to keep backups, build the search index, render previews and deliver it to your team. This right ends
                    when the content is deleted, except for copies in backups until they are overwritten. We do not use your content for
                    advertising or to train AI models.
                </p>
                <p>You are responsible for having the rights to what you upload and publish.</p>
            </LegalSection>

            <LegalSection id="data-protection" num={n('data-protection')} title="Data protection and your role">
                <p>
                    When you use Brainitive for others — for example a support page, a support widget, a form, a feedback or client portal, shared
                    links, or when you import or enter other people’s data — <strong>you (or your organisation) are the controller</strong> for
                    those people’s data within the meaning of the GDPR, and we process it on your behalf as processor.
                </p>
                <p>
                    For business customers, our{' '}
                    {legal.dpa ? <a href={legal.dpa}>Data Processing Agreement (Art. 28 GDPR)</a> : 'Data Processing Agreement (Art. 28 GDPR)'}{' '}
                    applies to this processing and is part of the contract. As controller you are responsible in particular for a legal basis,
                    for informing the people concerned (in Workspace settings → Legal you can add your privacy policy and imprint, which are then
                    linked on your public pages), for consents you collect (for example with the consent checkbox of a form) and for answering
                    their requests.
                </p>
            </LegalSection>

            <LegalSection id="ai" num={n('ai')} title="AI features and AI connectors">
                <p>
                    AI features only work with an AI service your workspace connects with its own key; that service is a third-party service of
                    your choice (section {n('third-party')}). AI connectors let an AI assistant you authorise read and change your workspace with
                    your permissions. AI output can be wrong, incomplete or inappropriate: check it before you rely on it or share it. You decide
                    which content you send to an AI service and are responsible for doing so lawfully.
                </p>
            </LegalSection>

            <LegalSection id="third-party" num={n('third-party')} title="Third-party services">
                <p>
                    Integrations (for example Google, Microsoft, GitHub, calendar links, webhooks, AI services) are provided by third parties under
                    their own terms. We are not responsible for their availability or for what they do with data you choose to send them. We may
                    change or end an integration if the third party changes its service.
                </p>
            </LegalSection>

            <LegalSection id="availability" num={n('availability')} title="Availability and changes to the service">
                <p>
                    We work to keep Brainitive available and secure, but during early access we give no availability guarantee. Maintenance,
                    updates and events beyond our control can cause interruptions. We may develop the service further and change features,
                    provided the core purpose of the service is preserved and the change is reasonable for you; we announce significant changes in
                    advance.
                </p>
                <p>Keep your own copies of important data — you can export projects at any time.</p>
            </LegalSection>

            <LegalSection id="liability" num={n('liability')} title="Liability">
                <p>
                    We are liable without limitation for damage caused intentionally or by gross negligence, for injury to life, body or health,
                    under the Product Liability Act and where we have given a guarantee.
                </p>
                <p>
                    For slight negligence we are liable only if we breach an essential contractual obligation (an obligation whose fulfilment makes
                    the proper performance of the contract possible in the first place and on whose fulfilment you may regularly rely), and then
                    only for the foreseeable damage typical for this type of contract. As long as you use the service free of charge, we are not
                    liable for slight negligence, except in the cases of the first paragraph.
                </p>
                <p>
                    These limitations also apply in favour of our representatives and agents. For the loss of data we are liable only for the
                    effort that would have been needed to restore it had you kept reasonable backups.
                </p>
            </LegalSection>

            <LegalSection id="termination" num={n('termination')} title="Term, termination, export and deletion">
                <p>
                    The contract runs for an indefinite period. <strong>You</strong> can end it at any time by deleting your account (Profile →
                    Delete account) or by writing to us. <strong>We</strong> can end it with four weeks’ notice by e-mail, and without notice for
                    good cause, in particular serious or repeated violations of these terms.
                </p>
                <p>
                    Before you leave, you can export your projects. When your account is deleted, your account data and the workspaces only you
                    belong to are deleted immediately; workspaces other people use must be transferred or deleted first. Remaining copies in
                    backups are overwritten in the normal backup cycle. If we end the contract, we will give you a reasonable opportunity to
                    export your data, unless this is unacceptable for us because of a serious violation.
                </p>
            </LegalSection>

            <LegalSection id="withdrawal" num={n('withdrawal')} title="Right of withdrawal (consumers)">
                <p>
                    If you are a consumer, you may withdraw from this contract within 14 days of concluding it without giving reasons. To do so,
                    send us a clear statement (for example an e-mail to <ContactEmail legal={legal} />); sending it before the period ends is
                    enough. Because Brainitive Cloud is free of charge, a withdrawal has no costs for you; we delete your account when you
                    withdraw. Independently of this, you can delete your account at any time.
                </p>
            </LegalSection>

            <LegalSection id="changes" num={n('changes')} title="Changes to these terms">
                <p>
                    We may change these terms for valid reasons, such as new features, changes in the law or case law, or security. We will send
                    you the new version at least 30 days before it takes effect, by e-mail or in the app, and point out what changes. Changes that
                    are not only to your advantage take effect only if you accept them; if you don’t, you can keep using the service under the
                    previous terms until either of us ends the contract under section {n('termination')}. Changes to prices or to the core
                    features of the service always require your explicit agreement.
                </p>
            </LegalSection>

            <LegalSection id="law" num={n('law')} title="Governing law and jurisdiction">
                <p>
                    {op.governing_law ? <>{op.governing_law} applies</> : <>The law of the country in which we are established applies</>}, excluding
                    the UN Convention on Contracts for the International Sale of Goods. If you are a consumer, this choice of law does not deprive
                    you of the protection of the mandatory provisions of the law of the country where you have your habitual residence.
                </p>
                <p>
                    If you are a merchant, a legal entity under public law or a special fund under public law, the place of jurisdiction is{' '}
                    {op.jurisdiction ?? 'our place of business'}.
                </p>
            </LegalSection>

            <LegalSection id="final" num={n('final')} title="Final provisions">
                <p>
                    If a provision of these terms is invalid, the rest remains valid; the statutory provisions apply instead. The contract
                    language is English. We do not keep a separate copy of the contract text for you; the current terms are always available on
                    this page, and earlier versions on request.
                </p>
            </LegalSection>
        </LegalLayout>
    );
}
