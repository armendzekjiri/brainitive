import type { PageProps } from '@/types';
import { usePage } from '@inertiajs/react';
import { Star } from 'lucide-react';
import { forwardRef, type AnchorHTMLAttributes } from 'react';

const FALLBACK_URL = 'https://github.com/armendzekjiri/brainitive';

export function GitHubMark({ className = '' }: { className?: string }) {
    return (
        <svg viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" className={className}>
            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.013 8.013 0 0016 8c0-4.42-3.58-8-8-8z" />
        </svg>
    );
}

/** 1234 → "1.2k" */
export function formatStars(stars: number): string {
    if (stars < 1000) return String(stars);
    const k = stars / 1000;
    return `${k >= 10 ? Math.round(k) : k.toFixed(1).replace(/\.0$/, '')}k`;
}

/** The repository link + cached star count shared by every page (HandleInertiaRequests → GithubStars). */
export function useGitHubRepo(): { url: string; stars: number | null } {
    const github = usePage<PageProps>().props.githubRepo;
    return { url: github?.url ?? FALLBACK_URL, stars: github?.stars ?? null };
}

/**
 * "⭐ Star · 1.2k" — opens the repository on github.com, where Star is one click (v1.42.0).
 * GitHub can't be starred from another site without a token that writes to all of the visitor's
 * public repositories, so we link instead of asking for that.
 *
 * - `pill`: GitHub-style split button (homepage top bar, open-source card)
 * - `badge`: small rounded badge (homepage hero)
 * - `link`: plain text link with the count (footers, menus)
 */
type Props = Omit<AnchorHTMLAttributes<HTMLAnchorElement>, 'href'> & { variant?: 'pill' | 'badge' | 'link' };

/** Extra props and the ref reach the <a>, so it also works inside `DropdownMenuItem asChild`. */
const GitHubStarButton = forwardRef<HTMLAnchorElement, Props>(function GitHubStarButton({ variant = 'pill', className = '', ...rest }, ref) {
    const { url, stars } = useGitHubRepo();
    const count = stars !== null ? formatStars(stars) : null;
    const label = count ? `Star Brainitive on GitHub (${stars} stars)` : 'Star Brainitive on GitHub';

    if (variant === 'link') {
        return (
            <a ref={ref} {...rest} href={url} target="_blank" rel="noopener" aria-label={label} data-testid="github-star" className={`inline-flex items-center gap-1.5 ${className}`}>
                <GitHubMark className="h-3.5 w-3.5" />
                <span>Star on GitHub</span>
                {count && (
                    <span className="inline-flex items-center gap-0.5 tabular-nums opacity-80">
                        <Star className="h-3 w-3 fill-current" aria-hidden />
                        {count}
                    </span>
                )}
            </a>
        );
    }

    if (variant === 'badge') {
        return (
            <a
                ref={ref}
                {...rest}
                href={url}
                target="_blank"
                rel="noopener"
                aria-label={label}
                data-testid="github-star"
                className={`inline-flex items-center gap-2 rounded-full border border-border/70 bg-card/70 backdrop-blur-sm px-3.5 py-1.5 text-xs font-semibold text-[#485E72] dark:text-slate-200 shadow-sm hover:border-amber-400/60 hover:text-amber-600 dark:hover:text-amber-300 transition-colors ${className}`}
            >
                <GitHubMark className="h-3.5 w-3.5" />
                Star us on GitHub
                <span className="inline-flex items-center gap-1 tabular-nums">
                    <Star className="h-3.5 w-3.5 fill-amber-400 text-amber-400" aria-hidden />
                    {count ?? ''}
                </span>
            </a>
        );
    }

    return (
        <a
            ref={ref}
            {...rest}
            href={url}
            target="_blank"
            rel="noopener"
            aria-label={label}
            data-testid="github-star"
            className={`group inline-flex items-stretch overflow-hidden rounded-lg border border-border/80 bg-card text-xs font-semibold text-[#485E72] dark:text-slate-100 shadow-sm transition-colors hover:border-amber-400/60 ${className}`}
        >
            <span className="inline-flex items-center gap-1.5 px-2.5 py-1.5 group-hover:text-amber-600 dark:group-hover:text-amber-300">
                <GitHubMark className="h-3.5 w-3.5" />
                <Star className="h-3.5 w-3.5 transition-colors group-hover:fill-amber-400 group-hover:text-amber-400" aria-hidden />
                Star
            </span>
            {count && <span className="inline-flex items-center border-l border-border/80 bg-muted/60 px-2.5 tabular-nums">{count}</span>}
        </a>
    );
});

export default GitHubStarButton;
