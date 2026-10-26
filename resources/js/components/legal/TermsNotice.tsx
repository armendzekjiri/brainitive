import { cn } from '@/lib/utils';

/**
 * Sign-up notice (BRAI-156): creating an account accepts the Terms of Use (stored as
 * users.terms_version); the Privacy Policy is information, not something to "agree" to.
 * No checkbox, nothing pre-ticked.
 */
export function TermsNotice({ action = 'creating an account', className }: { action?: string; className?: string }) {
    return (
        <p className={cn('text-xs leading-relaxed text-muted-foreground', className)} data-testid="terms-notice">
            By {action} you agree to the{' '}
            <a href={route('terms')} target="_blank" rel="noopener" className="underline underline-offset-2 hover:text-foreground">
                Terms of Use
            </a>
            . How we handle your data is explained in the{' '}
            <a href={route('privacy')} target="_blank" rel="noopener" className="underline underline-offset-2 hover:text-foreground">
                Privacy Policy
            </a>
            .
        </p>
    );
}
