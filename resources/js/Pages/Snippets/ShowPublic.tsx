import { Head, Link } from '@inertiajs/react';
import { PageProps, SnippetCollection, Snippet } from '@/types';
import { Copy, Globe } from 'lucide-react';
import { useMemo } from 'react';
import { toast } from 'sonner';
import hljs from 'highlight.js';
import 'highlight.js/styles/github-dark-dimmed.css';

const CODE_FONT = "'JetBrains Mono', 'Fira Code', 'Cascadia Code', ui-monospace, monospace";
const APP_FONT = "'Inter', 'Hanken Grotesk', -apple-system, BlinkMacSystemFont, sans-serif";

function CollectionIcon({ icon, className = '' }: { icon?: string | null; className?: string }) {
    const src = icon ?? '📋';
    const isUrl = src.startsWith('http') || src.startsWith('/');
    if (isUrl) return <img src={src} alt="" className={`rounded object-contain ${className}`} />;
    return <span className={className}>{src}</span>;
}

const LANGUAGES: Record<string, string> = {
    plaintext: 'Plain text', javascript: 'JavaScript', typescript: 'TypeScript',
    tsx: 'TSX / JSX', python: 'Python', php: 'PHP', bash: 'Bash / Shell',
    sql: 'SQL', html: 'HTML', css: 'CSS', json: 'JSON', yaml: 'YAML',
    markdown: 'Markdown', rust: 'Rust', go: 'Go', java: 'Java',
    c: 'C', cpp: 'C++', ruby: 'Ruby', swift: 'Swift', kotlin: 'Kotlin',
};

function CodeBlock({ code, language }: { code: string; language: string }) {
    const lines = useMemo(() => {
        let html: string;
        if (language !== 'plaintext' && hljs.getLanguage(language)) {
            html = hljs.highlight(code, { language }).value;
        } else {
            html = code.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
        }
        const rows = html.split('\n');
        if (rows.at(-1) === '') rows.pop();
        return rows;
    }, [code, language]);

    return (
        <div className="overflow-auto bg-[#22272e] text-sm rounded-b-xl" style={{ fontFamily: CODE_FONT }}>
            <table className="w-full border-collapse" style={{ lineHeight: '1.6' }}>
                <tbody>
                    {lines.map((line, i) => (
                        <tr key={i} className="hover:bg-muted/30">
                            <td
                                className="select-none text-right pr-3 pl-4 w-px whitespace-nowrap border-r border-white/10 align-top"
                                style={{ color: '#636e7b', fontSize: '0.75rem', paddingTop: '2px', paddingBottom: '2px' }}
                            >
                                {i + 1}
                            </td>
                            <td
                                className="pl-4 pr-4 whitespace-pre align-top"
                                style={{ color: '#adbac7', paddingTop: '2px', paddingBottom: '2px' }}
                                dangerouslySetInnerHTML={{ __html: line || '\u00a0' }}
                            />
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

interface Props extends PageProps {
    collection: SnippetCollection & { snippets?: Snippet[] };
    snippet?: Snippet;
    isPublic: boolean;
    singleMode?: boolean;
}

export default function SnippetsShowPublic({ collection, snippet, isPublic, singleMode = false }: Props) {
    const snippets = singleMode
        ? (snippet ? [snippet] : [])
        : (collection.snippets ?? []);

    const copy = (content: string) =>
        navigator.clipboard.writeText(content).then(() => toast.success('Copied to clipboard!'));

    const pageTitle = singleMode ? (snippet?.title ?? 'Snippet') : collection.name;

    return (
        <>
            <Head title={`${pageTitle} · Brainitive`} />

            <div
                className="min-h-screen"
                style={{ background: '#0d1117', color: '#e6edf3', fontFamily: APP_FONT }}
            >
                {/* ── Header ──────────────────────────────────── */}
                <header
                    className="sticky top-0 z-20 backdrop-blur-sm"
                    style={{ borderBottom: '1px solid #30363d', background: 'rgba(22,27,34,0.95)' }}
                >
                    <div className="max-w-3xl mx-auto px-4 sm:px-6 h-14 flex items-center justify-between gap-4">
                        <Link href="/" className="flex items-center gap-2.5 shrink-0">
                            <img
                                src="/images/logo-dark.png"
                                alt="Brainitive"
                                className="h-7 w-auto object-contain"
                            />
                        </Link>
                        <span
                            className="flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full"
                            style={{ border: '1px solid #30363d', color: '#58a6ff', background: 'rgba(88,166,255,0.08)' }}
                        >
                            <Globe className="size-3" />
                            Public share
                        </span>
                    </div>
                </header>

                {/* ── Hero ──────────────────────────────────────── */}
                <div style={{ borderBottom: '1px solid #30363d', background: 'linear-gradient(to bottom, rgba(88,166,255,0.06), transparent)' }}>
                    <div className="max-w-3xl mx-auto px-4 sm:px-6 py-10">
                        {singleMode ? (
                            <div>
                                <div className="flex items-center gap-2 mb-3 text-xs" style={{ color: '#8b949e' }}>
                                    <Link
                                        href={`/share/snippet-collections/${collection.uuid}`}
                                        className="hover:text-white transition-colors flex items-center gap-1"
                                    >
                                        <CollectionIcon icon={collection.icon} className="size-4 text-sm" />
                                        {collection.name}
                                    </Link>
                                    <span className="opacity-40">›</span>
                                    <span>snippet</span>
                                </div>
                                <h1 className="text-2xl sm:text-3xl font-bold tracking-tight">{snippet?.title}</h1>
                                {snippet?.description && (
                                    <p className="mt-2 text-sm" style={{ color: '#8b949e' }}>{snippet.description}</p>
                                )}
                                {snippet && (
                                    <div className="mt-3">
                                        <span
                                            className="inline-flex items-center text-xs font-mono px-2 py-0.5 rounded"
                                            style={{ background: '#21262d', color: '#8b949e', border: '1px solid #30363d' }}
                                        >
                                            {LANGUAGES[snippet.language] ?? snippet.language}
                                        </span>
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="flex items-start gap-4">
                                <CollectionIcon icon={collection.icon} className="text-4xl leading-none mt-1 shrink-0 size-12" />
                                <div className="min-w-0">
                                    <h1 className="text-2xl sm:text-3xl font-bold tracking-tight">{collection.name}</h1>
                                    {collection.description && (
                                        <p className="mt-2 text-sm max-w-xl" style={{ color: '#8b949e' }}>{collection.description}</p>
                                    )}
                                    <p className="mt-3 text-xs" style={{ color: '#8b949e' }}>
                                        {snippets.length} snippet{snippets.length !== 1 ? 's' : ''}
                                    </p>
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                {/* ── Snippets ──────────────────────────────────── */}
                <main className="max-w-3xl mx-auto px-4 sm:px-6 py-8 space-y-4">
                    {snippets.length === 0 && (
                        <p className="text-sm py-16 text-center" style={{ color: '#8b949e' }}>
                            No snippets in this collection.
                        </p>
                    )}

                    {snippets.map((s) => {
                        const lang = LANGUAGES[s.language] ?? s.language;
                        return (
                            <div
                                key={s.id}
                                className="rounded-xl overflow-hidden"
                                style={{ border: '1px solid #30363d', boxShadow: '0 1px 3px rgba(0,0,0,0.4)' }}
                            >
                                {/* Snippet header */}
                                <div
                                    className="flex items-center justify-between gap-3 px-4 py-3"
                                    style={{ background: '#161b22', borderBottom: '1px solid #30363d' }}
                                >
                                    <div className="flex items-center gap-3 min-w-0">
                                        <p className="font-semibold text-sm truncate">{s.title}</p>
                                        <span
                                            className="hidden sm:inline-flex items-center text-xs font-mono px-2 py-0.5 rounded shrink-0"
                                            style={{ background: '#21262d', color: '#8b949e', border: '1px solid #30363d' }}
                                        >
                                            {lang}
                                        </span>
                                    </div>
                                    <button
                                        className="flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-md transition-colors shrink-0"
                                        style={{ background: '#21262d', border: '1px solid #30363d', color: '#cdd9e5' }}
                                        onClick={() => copy(s.content ?? '')}
                                    >
                                        <Copy className="size-3" />
                                        Copy
                                    </button>
                                </div>

                                {/* Description */}
                                {s.description && (
                                    <p
                                        className="text-xs px-4 py-2"
                                        style={{ background: '#161b22', borderBottom: '1px solid #30363d', color: '#8b949e' }}
                                    >
                                        {s.description}
                                    </p>
                                )}

                                {/* Code block with line numbers */}
                                <CodeBlock code={s.content ?? ''} language={s.language} />

                                {/* Mobile lang badge */}
                                <div
                                    className="sm:hidden flex items-center gap-2 px-4 py-2"
                                    style={{ background: '#22272e', borderTop: '1px solid rgba(255,255,255,0.06)' }}
                                >
                                    <span
                                        className="inline-flex items-center text-xs font-mono px-2 py-0.5 rounded"
                                        style={{ background: '#21262d', color: '#8b949e', border: '1px solid #30363d' }}
                                    >
                                        {lang}
                                    </span>
                                </div>
                            </div>
                        );
                    })}
                </main>

                {/* ── Footer ──────────────────────────────────── */}
                <footer className="mt-12 py-8" style={{ borderTop: '1px solid #30363d' }}>
                    <div className="max-w-3xl mx-auto px-4 sm:px-6 flex flex-col sm:flex-row items-center justify-between gap-3">
                        <Link href="/">
                            <img
                                src="/images/logo-dark.png"
                                alt="Brainitive"
                                className="h-[22px] w-auto object-contain transition-opacity"
                                style={{ opacity: 0.6 }}
                                onMouseEnter={e => (e.currentTarget.style.opacity = '1')}
                                onMouseLeave={e => (e.currentTarget.style.opacity = '0.6')}
                            />
                        </Link>
                        <p className="text-xs" style={{ color: '#8b949e' }}>
                            Shared via{' '}
                            <Link href="/" className="font-semibold hover:text-white transition-colors" style={{ color: '#e6edf3' }}>
                                Brainitive
                            </Link>
                            {' '}— your team's second brain
                        </p>
                    </div>
                </footer>
            </div>
        </>
    );
}
