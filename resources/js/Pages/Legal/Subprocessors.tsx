import { Server } from 'lucide-react';
import type { ReactNode } from 'react';
import { ContactEmail, LegalLayout, LegalSection, LegalTable, ToConfirm } from '@/components/legal/LegalLayout';
import type { LegalPageData } from '@/types';

/*
 * Sub-processors of Brainitive Cloud (BRAI-156): Annex 3 of the DPA. Locations and transfer safeguards
 * are only stated where the operator confirmed them (config/legal.php providers.*); otherwise they show
 * as "to be confirmed". Every new processor → update this list, the privacy policy and
 * docs/legal/data-inventory.md (docs/legal/README.md). DRAFT, to be confirmed by a lawyer.
 */

export default function Subprocessors({ legal }: { legal: LegalPageData }) {
    const p = legal.providers;
    const at = (value: string | null, what: string): ReactNode => value ?? <ToConfirm>{what}</ToConfirm>;

    return (
        <LegalLayout
            title="Sub-processors"
            icon={Server}
            legal={legal}
            intro={
                <p>
                    These companies process personal data for Brainitive Cloud on our behalf. Customers who use Brainitive as processor under our{' '}
                    {legal.dpa ? <a href={legal.dpa}>Data Processing Agreement</a> : 'Data Processing Agreement'} authorise them in general; we
                    announce changes to this list at least 30 days in advance (by e-mail to workspace owners or in the app), and you may object
                    for an important data protection reason.
                </p>
            }
        >
            <LegalSection id="always" num={1} title="Always used">
                <LegalTable
                    head={['Sub-processor', 'Purpose', 'Location', 'Transfer safeguard']}
                    rows={[
                        [
                            p.hosting ?? 'OVHcloud',
                            'Servers: application, database, file storage; backups on a separate server; our self-hosted analytics (Umami, only with the visitor’s consent)',
                            at(p.hosting_location, 'EU data centre'),
                            p.hosting_location ? 'Processing in the location named' : <ToConfirm>safeguard</ToConfirm>,
                        ],
                        [p.email ?? 'MXroute', 'Sending e-mails (notifications, invitations, sign-in and support e-mails)', at(p.email_location, 'location'), <ToConfirm key="mx">safeguard</ToConfirm>],
                    ]}
                />
            </LegalSection>

            <LegalSection id="optional" num={2} title="Only if you use the integration">
                <p>
                    These services receive data only when a user or workspace connects them. Where they act for you rather than for us (for example
                    the AI service your workspace chooses), they are your own processors or controllers, not ours.
                </p>
                <LegalTable
                    head={['Service', 'Purpose', 'When', 'Location / safeguard']}
                    rows={[
                        ['Google (Google Ireland Ltd. / Google LLC)', 'Sign-in with Google, Google Calendar & Tasks, Google Meet; push notifications in Chrome-based browsers', 'Only if you use the integration or push', 'EU / USA — EU-U.S. Data Privacy Framework or Standard Contractual Clauses'],
                        ['Microsoft (Microsoft Ireland Operations Ltd. / Microsoft Corp.)', 'Sign-in with Microsoft, Outlook calendar, Teams meetings; push notifications in Edge / Windows', 'Only if you use the integration or push', 'EU / USA — EU-U.S. Data Privacy Framework or Standard Contractual Clauses'],
                        ['GitHub, Inc.', 'Sign-in with GitHub, GitHub App / webhooks for repositories', 'Only if you use the integration', 'USA — EU-U.S. Data Privacy Framework or Standard Contractual Clauses'],
                        ['Apple, Mozilla', 'Push notifications in Safari / Firefox', 'Only if you enable push', 'Push service of your browser; contents end-to-end encrypted'],
                        ['AI service chosen by the workspace (e.g. OpenAI, Azure OpenAI, a self-hosted model)', 'AI features', 'Only if your workspace adds its own key', 'Chosen and contracted by the workspace'],
                    ]}
                />
                <p className="text-xs">
                    Locations and safeguards of these services are taken from their published information; check their current status before you
                    rely on it.
                </p>
            </LegalSection>

            <LegalSection id="contact" num={3} title="Questions">
                <p>
                    Questions about our sub-processors: <ContactEmail legal={legal} />.
                </p>
            </LegalSection>
        </LegalLayout>
    );
}
