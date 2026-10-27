import type { PublicOperatorData } from '@/types';
import { cn } from '@/lib/utils';
import type { CSSProperties } from 'react';

/**
 * "Who runs this page" on a customer's public page (BRAI-156): support pages, ticket portal, lead
 * forms, feedback / client portals, share links. The workspace is the controller of what visitors
 * enter; Brainitive's own privacy policy is linked only for the platform part.
 */
export function OperatorNotice({
    operator,
    className,
    style,
    linkClassName,
}: {
    operator: PublicOperatorData | null | undefined;
    className?: string;
    style?: CSSProperties;
    linkClassName?: string;
}) {
    if (!operator) return null;
    const link = cn('underline underline-offset-2 hover:opacity-80', linkClassName);

    return (
        <div className={cn('text-xs leading-relaxed text-muted-foreground', className)} style={style} data-testid="operator-notice">
            {operator.named ? (
                <p>
                    This page is operated by <span className="font-medium" data-testid="operator-name">{operator.name}</span> using{' '}
                    {operator.platform_name}. {operator.name} is responsible for the information you share here.
                </p>
            ) : (
                <p>
                    This page is shared by the organisation that sent you the link, using {operator.platform_name}. That organisation is
                    responsible for its content and for the information you share here.
                </p>
            )}
            <p className="mt-1 flex flex-wrap items-center justify-center gap-x-3 gap-y-1">
                {operator.privacy_url && (
                    <a href={operator.privacy_url} target="_blank" rel="noopener noreferrer nofollow" className={link} data-testid="operator-privacy">
                        Privacy policy
                    </a>
                )}
                {operator.imprint_url && (
                    <a href={operator.imprint_url} target="_blank" rel="noopener noreferrer nofollow" className={link} data-testid="operator-imprint">
                        Imprint
                    </a>
                )}
                <a href={operator.platform_privacy_url} target="_blank" rel="noopener" className={link} data-testid="operator-platform-privacy">
                    {operator.platform_name} platform privacy
                </a>
            </p>
        </div>
    );
}

/** One line under a submit button: what happens with the data, and whose policy applies. */
export function SubmitPrivacyLine({
    operator,
    className,
    style,
    linkClassName,
}: {
    operator: PublicOperatorData | null | undefined;
    className?: string;
    style?: CSSProperties;
    linkClassName?: string;
}) {
    if (!operator) return null;
    const link = cn('underline underline-offset-2 hover:opacity-80', linkClassName);

    return (
        <p className={cn('text-xs leading-relaxed text-muted-foreground', className)} style={style} data-testid="submit-privacy-line">
            {operator.named ? operator.name : 'The organisation running this page'} uses what you send here to answer your request.{' '}
            {operator.privacy_url ? (
                <a href={operator.privacy_url} target="_blank" rel="noopener noreferrer nofollow" className={link}>
                    Privacy policy
                </a>
            ) : (
                <a href={operator.platform_privacy_url} target="_blank" rel="noopener" className={link}>
                    How {operator.platform_name} hosts this form
                </a>
            )}
        </p>
    );
}
