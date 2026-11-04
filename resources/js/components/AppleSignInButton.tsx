/**
 * "Sign in with Apple" (BRAI-449), drawn per Apple's Human Interface Guidelines: black with a white
 * logo and title on light backgrounds, white with a black logo and title on dark ones; logo and
 * title share one colour. A plain link — the flow is a full-page redirect to Apple.
 */
export default function AppleSignInButton({ size = 'md' }: { size?: 'md' | 'lg' }) {
    const sizing =
        size === 'lg'
            ? 'gap-3 rounded-2xl px-7 py-4 text-base font-bold shadow-lg shadow-black/5 hover:scale-[1.02]'
            : 'w-full gap-2 rounded-md px-4 py-2 text-sm font-medium shadow-sm';

    return (
        <a
            href={route('login.apple')}
            className={`inline-flex items-center justify-center bg-black text-white transition hover:bg-black/85 focus:outline-none focus:ring-2 focus:ring-primary focus:ring-offset-2 dark:bg-white dark:text-black dark:hover:bg-white/90 dark:focus:ring-offset-card ${sizing}`}
        >
            <svg className={size === 'lg' ? 'h-6 w-6 shrink-0' : 'h-5 w-5 shrink-0'} viewBox="0 0 24 24" fill="currentColor" aria-hidden>
                <path d="M16.365 1.43c0 1.14-.493 2.27-1.177 3.08-.744.9-1.99 1.57-2.987 1.57-.12 0-.23-.02-.3-.03-.01-.06-.04-.22-.04-.39 0-1.15.572-2.27 1.206-2.98.804-.94 2.142-1.64 3.248-1.68.03.13.05.28.05.43zm4.565 15.71c-.03.07-.463 1.58-1.518 3.12-.945 1.34-1.94 2.71-3.43 2.71-1.517 0-1.9-.88-3.63-.88-1.698 0-2.302.91-3.67.91-1.377 0-2.332-1.26-3.428-2.8-1.287-1.82-2.323-4.63-2.323-7.28 0-4.28 2.797-6.55 5.552-6.55 1.448 0 2.675.95 3.6.95.865 0 2.222-1.01 3.902-1.01.613 0 2.886.06 4.374 2.19-.13.09-2.383 1.37-2.383 4.19 0 3.26 2.854 4.42 2.955 4.45z" />
            </svg>
            Sign in with Apple
        </a>
    );
}
