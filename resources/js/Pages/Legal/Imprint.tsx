import { Info } from 'lucide-react';
import type { ReactNode } from 'react';
import { LegalLayout, LegalSection } from '@/components/legal/LegalLayout';
import type { LegalPageData } from '@/types';

/*
 * Imprint (§ 5 DDG, § 18 (2) MStV) from config/legal.php (BRAI-156). Only rows with a value are shown;
 * on self-hosted installs only the operator's own data, never Brainitive's. Brainitive Cloud currently
 * publishes no name or address by the operator's decision — an accepted open risk documented in
 * docs/legal/README.md. DRAFT, to be confirmed by a lawyer.
 */

function Row({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="grid gap-0.5 sm:grid-cols-[12rem_1fr] sm:gap-4">
            <dt className="font-medium text-foreground">{label}</dt>
            <dd className="whitespace-pre-line">{children}</dd>
        </div>
    );
}

export default function Imprint({ legal }: { legal: LegalPageData }) {
    const op = legal.operator;
    const provider = op.name ?? (legal.cloud ? `${legal.app_name} is run by an individual (sole proprietor).` : null);

    return (
        <LegalLayout title="Imprint" icon={Info} legal={legal}>
            <LegalSection id="provider" title="Service provider">
                <dl className="space-y-3" data-testid="imprint-details">
                    {provider && <Row label="Provider">{op.name && op.description ? `${op.name}\n${op.description}` : provider}</Row>}
                    {op.address && <Row label="Address">{op.address}</Row>}
                    {op.representative && <Row label="Represented by">{op.representative}</Row>}
                    {op.email && (
                        <Row label="E-mail">
                            <a href={`mailto:${op.email}`}>{op.email}</a>
                        </Row>
                    )}
                    {op.phone && <Row label="Phone">{op.phone}</Row>}
                    {op.register && <Row label="Register entry">{op.register}</Row>}
                    {op.vat_id && <Row label="VAT ID (§ 27a UStG)">{op.vat_id}</Row>}
                    {op.content_responsible && <Row label="Responsible for content (§ 18 (2) MStV)">{op.content_responsible}</Row>}
                </dl>
            </LegalSection>

            {legal.cloud && (
                <LegalSection id="disputes" title="Consumer dispute resolution">
                    <p>
                        We are neither willing nor obliged to take part in dispute resolution proceedings before a consumer arbitration board
                        (§ 36 VSBG).
                    </p>
                </LegalSection>
            )}

            {!legal.cloud && (
                <LegalSection id="software" title="Software">
                    <p>
                        This installation runs the open-source software {legal.app_name}. Its developers do not operate this installation and are
                        not responsible for its content.
                    </p>
                </LegalSection>
            )}
        </LegalLayout>
    );
}
