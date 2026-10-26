import { FileSignature } from 'lucide-react';
import { ContactEmail, LegalLayout, LegalSection, LegalTable, ToConfirm, operatorName } from '@/components/legal/LegalLayout';
import type { LegalPageData } from '@/types';

/*
 * Data Processing Agreement (Art. 28 GDPR) for Brainitive Cloud customers (BRAI-156). Annex 2 (TOMs) is
 * derived from measures that exist in the code and deployment docs — never add a measure that isn't
 * implemented. DRAFT template, to be confirmed by a qualified lawyer (docs/legal/README.md).
 */

const TOC = [
    { id: 'parties', title: 'Parties and conclusion' },
    { id: 'subject', title: 'Subject matter, duration, nature and purpose' },
    { id: 'instructions', title: 'Instructions' },
    { id: 'confidentiality', title: 'Confidentiality' },
    { id: 'security', title: 'Security of processing' },
    { id: 'subprocessors', title: 'Sub-processors' },
    { id: 'assistance', title: 'Assistance' },
    { id: 'breach', title: 'Personal data breaches' },
    { id: 'transfers', title: 'Transfers to third countries' },
    { id: 'end', title: 'Deletion and return' },
    { id: 'audits', title: 'Information and audits' },
    { id: 'final', title: 'Liability and final provisions' },
    { id: 'annex-1', title: 'Annex 1 — Details of the processing' },
    { id: 'annex-2', title: 'Annex 2 — Technical and organisational measures' },
    { id: 'annex-3', title: 'Annex 3 — Sub-processors' },
];

export default function Dpa({ legal }: { legal: LegalPageData }) {
    const n = (id: string) => TOC.findIndex((t) => t.id === id) + 1;
    const we = operatorName(legal);

    return (
        <LegalLayout
            title="Data Processing Agreement"
            icon={FileSignature}
            legal={legal}
            toc={TOC}
            intro={
                <p>
                    This agreement under Art. 28 (3) GDPR applies when you use Brainitive Cloud to process personal data for which you are the
                    controller — for example the data of your team, your clients, your support contacts, your leads or the visitors of your
                    portals. You can save or print it; if you need a signed copy, write to <ContactEmail legal={legal} />.
                </p>
            }
        >
            <LegalSection id="parties" num={n('parties')} title="Parties and conclusion">
                <p>
                    This agreement is concluded between the customer who owns a workspace on Brainitive Cloud (“Controller”) and{' '}
                    <strong>{we}</strong> (“Processor”). It becomes part of the contract under our <a href={legal.terms}>Terms of Use</a> when the
                    Controller uses the service for personal data it is responsible for, and applies for as long as the Processor processes such
                    data. In data protection matters it takes precedence over the Terms of Use.
                </p>
            </LegalSection>

            <LegalSection id="subject" num={n('subject')} title="Subject matter, duration, nature and purpose">
                <p>
                    The Processor provides the hosted workspace Brainitive Cloud. Processing comprises storing, organising, displaying,
                    searching, transmitting (e-mails, push notifications, webhooks and integrations set up by the Controller), backing up and
                    deleting the Controller’s data, solely to provide the service. Details are in Annex 1. The agreement lasts as long as the
                    contract under the Terms of Use, and beyond that until the data has been deleted (section {n('end')}).
                </p>
            </LegalSection>

            <LegalSection id="instructions" num={n('instructions')} title="Instructions">
                <p>
                    The Processor processes personal data only on documented instructions of the Controller, unless required by EU or member
                    state law (in that case it informs the Controller beforehand, unless the law forbids it). The Terms of Use, this agreement and
                    the Controller’s settings and actions in the service (for example inviting members, publishing pages, connecting integrations
                    or deleting content) are the Controller’s documented instructions. Further instructions are given in text form to{' '}
                    <ContactEmail legal={legal} />. The Processor informs the Controller without delay if it considers an instruction to infringe
                    data protection law.
                </p>
            </LegalSection>

            <LegalSection id="confidentiality" num={n('confidentiality')} title="Confidentiality">
                <p>
                    The Processor ensures that everyone authorised to process the personal data is committed to confidentiality or is under an
                    appropriate statutory obligation of confidentiality, and does not use the data for its own purposes.
                </p>
            </LegalSection>

            <LegalSection id="security" num={n('security')} title="Security of processing">
                <p>
                    The Processor takes the technical and organisational measures in Annex 2 (Art. 32 GDPR). It may adapt them to technical
                    progress as long as the level of protection is not reduced.
                </p>
            </LegalSection>

            <LegalSection id="subprocessors" num={n('subprocessors')} title="Sub-processors">
                <p>
                    The Controller gives general authorisation for the sub-processors in Annex 3. The Processor informs the Controller at least 30
                    days before adding or replacing a sub-processor (by e-mail to workspace owners or in the app). The Controller may object for
                    an important data protection reason within that period; if no solution is found, the Controller may end the contract before
                    the change takes effect. The Processor imposes the same data protection obligations on every sub-processor by contract and
                    remains responsible for them.
                </p>
            </LegalSection>

            <LegalSection id="assistance" num={n('assistance')} title="Assistance">
                <p>
                    Taking into account the nature of the processing, the Processor assists the Controller with appropriate measures in
                    responding to requests of data subjects (Art. 12–23 GDPR) — most of which the Controller can handle itself in the service
                    (editing, exporting and deleting content) — and in meeting the obligations under Art. 32–36 GDPR (security, breach
                    notifications, data protection impact assessments, prior consultation). If a data subject contacts the Processor directly,
                    it forwards the request to the Controller where the Controller can be identified.
                </p>
            </LegalSection>

            <LegalSection id="breach" num={n('breach')} title="Personal data breaches">
                <p>
                    The Processor notifies the Controller without undue delay after becoming aware of a personal data breach affecting the
                    Controller’s data, with the information available at that time (nature of the breach, categories and approximate number of
                    data subjects and records, likely consequences, measures taken), and provides further information as it becomes available.
                </p>
            </LegalSection>

            <LegalSection id="transfers" num={n('transfers')} title="Transfers to third countries">
                <p>
                    The Processor transfers personal data to a country outside the EU/EEA only if the requirements of Chapter V GDPR are met —
                    an adequacy decision or appropriate safeguards such as the EU Standard Contractual Clauses. Transfers caused by integrations
                    the Controller chooses (for example an AI service or a calendar provider) are instructions of the Controller.
                </p>
            </LegalSection>

            <LegalSection id="end" num={n('end')} title="Deletion and return">
                <p>
                    Until the end of the contract the Controller can export its projects and delete its data at any time. When the contract ends
                    (the workspace or account is deleted), the Processor deletes the Controller’s data, unless EU or member state law requires
                    storage. Copies in backups are deleted when the backups are overwritten in their regular cycle ({legal.retention.backups ?? <ToConfirm>backup retention period</ToConfirm>}).
                </p>
            </LegalSection>

            <LegalSection id="audits" num={n('audits')} title="Information and audits">
                <p>
                    The Processor makes available all information necessary to demonstrate compliance with Art. 28 GDPR and allows for and
                    contributes to audits, including inspections, by the Controller or an auditor it mandates who is bound to confidentiality.
                    Audits are announced at least 30 days in advance, take place during normal business hours, without disrupting operations
                    and, unless there is a concrete reason, at most once a year. Each party bears its own costs.
                </p>
            </LegalSection>

            <LegalSection id="final" num={n('final')} title="Liability and final provisions">
                <p>
                    Liability is governed by Art. 82 GDPR and otherwise by the Terms of Use. Changes require text form. If a provision is invalid,
                    the rest remains valid.
                </p>
            </LegalSection>

            <LegalSection id="annex-1" num={n('annex-1')} title="Annex 1 — Details of the processing">
                <LegalTable
                    head={['Item', 'Details']}
                    rows={[
                        ['Data subjects', 'Members and guests of the Controller’s workspaces; the Controller’s clients, contacts and leads; people who use the Controller’s support pages and widget, forms, feedback and client portals, shared links and calendar invitations; people mentioned in the Controller’s content'],
                        ['Categories of data', 'Account and contact data (name, e-mail address, optional phone, photo); content (documents, issues, comments, files, whiteboards, plans, messages, form answers, votes); time entries and billing data (client names, addresses, VAT IDs, bank details entered for invoices); calendar and meeting data (titles, times, attendees); usage and technical data (session IP address and browser, activity history, push subscriptions)'],
                        ['Special categories', 'Not intended. The Controller decides what it stores and is responsible if it stores special categories (Art. 9 GDPR).'],
                        ['Nature of processing', 'Hosting, storage, display, search, real-time collaboration, transmission by e-mail, push, webhooks and integrations set up by the Controller, backups, deletion'],
                        ['Purpose', 'Providing Brainitive Cloud under the Terms of Use'],
                        ['Duration', 'For the term of the contract and until deletion under section ' + n('end')],
                    ]}
                />
            </LegalSection>

            <LegalSection id="annex-2" num={n('annex-2')} title="Annex 2 — Technical and organisational measures">
                <ul>
                    <li>
                        <strong>Encryption:</strong> all connections use TLS. Passwords are stored as salted bcrypt hashes. Access and refresh tokens
                        of integrations, app passwords, AI keys, mailbox passwords, webhook and GitHub secrets are encrypted in the database with the
                        application key. Guest sign-in links are stored only as hashes; calendar feed links as a hash plus an encrypted copy.
                    </li>
                    <li>
                        <strong>Access control (application):</strong> every request is authorised by explicit policies per workspace and project
                        role; instance administrators manage accounts but have no access to workspace content. Tokens for AI connectors and the
                        browser extension carry limited scopes (the extension token can only save content and search titles). Sign-in attempts are
                        rate-limited; users can sign out of all devices; e-mail addresses are verified.
                    </li>
                    <li>
                        <strong>Input protection:</strong> CSRF protection, server-side sanitising of rich text, an allowlist of file types for
                        uploads with random file names, and checks against requests to internal addresses for user-supplied URLs (SSRF protection).
                    </li>
                    <li>
                        <strong>Access control (systems):</strong> the servers are administered only by the operator via SSH; deployments run through
                        an automated pipeline. <ToConfirm>key-only SSH and multi-factor authentication for the hosting account</ToConfirm>
                    </li>
                    <li>
                        <strong>Separation:</strong> workspaces are separated logically by authorisation checks on every query; the staging system
                        uses its own database.
                    </li>
                    <li>
                        <strong>Availability:</strong> backups are stored on a separate server of {legal.providers.backup ?? 'OVHcloud'}.{' '}
                        <ToConfirm>backup frequency, retention and restore tests</ToConfirm>
                    </li>
                    <li>
                        <strong>Logging:</strong> actions of instance administrators are recorded in an audit log; changes to issues are recorded in
                        their history; web server logs are kept for {legal.retention.server_logs ?? <ToConfirm>period</ToConfirm>}.
                    </li>
                    <li>
                        <strong>Deletion and data minimisation:</strong> automatic deletion of expired sign-in links, tokens, notifications,
                        technical deliveries and statistics (see the storage periods in the <a href={legal.privacy}>Privacy Policy</a>); form
                        statistics without IP addresses; no third-party analytics or advertising.
                    </li>
                    <li>
                        <strong>Development and incident handling:</strong> changes pass an automated test suite before release; vulnerabilities
                        can be reported at brainitive.app/support/security-issue.
                    </li>
                </ul>
            </LegalSection>

            <LegalSection id="annex-3" num={n('annex-3')} title="Annex 3 — Sub-processors">
                <p>
                    The current list is on our {legal.subprocessors ? <a href={legal.subprocessors}>sub-processors page</a> : 'sub-processors page'}.
                </p>
            </LegalSection>
        </LegalLayout>
    );
}
