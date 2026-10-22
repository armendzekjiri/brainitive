/** The snippet languages the app knows (keep in sync with resources/js/Pages/Snippets/Show.tsx). */
export const LANGUAGES = [
    { value: 'plaintext', label: 'Plain text' },
    { value: 'javascript', label: 'JavaScript' },
    { value: 'typescript', label: 'TypeScript' },
    { value: 'tsx', label: 'TSX / JSX' },
    { value: 'python', label: 'Python' },
    { value: 'php', label: 'PHP' },
    { value: 'bash', label: 'Bash / Shell' },
    { value: 'sql', label: 'SQL' },
    { value: 'html', label: 'HTML' },
    { value: 'css', label: 'CSS' },
    { value: 'json', label: 'JSON' },
    { value: 'yaml', label: 'YAML' },
    { value: 'markdown', label: 'Markdown' },
    { value: 'rust', label: 'Rust' },
    { value: 'go', label: 'Go' },
    { value: 'java', label: 'Java' },
    { value: 'c', label: 'C' },
    { value: 'cpp', label: 'C++' },
    { value: 'ruby', label: 'Ruby' },
    { value: 'swift', label: 'Swift' },
    { value: 'kotlin', label: 'Kotlin' },
] as const;

const ALIASES: Record<string, string> = {
    js: 'javascript', jsx: 'tsx', mjs: 'javascript', node: 'javascript',
    ts: 'typescript',
    py: 'python', python3: 'python',
    sh: 'bash', shell: 'bash', zsh: 'bash', console: 'bash', terminal: 'bash', shellsession: 'bash',
    yml: 'yaml', md: 'markdown', htm: 'html', xml: 'html', vue: 'html',
    scss: 'css', sass: 'css', less: 'css',
    rs: 'rust', golang: 'go', rb: 'ruby', kt: 'kotlin', 'c++': 'cpp', cc: 'cpp', h: 'c',
    postgresql: 'sql', mysql: 'sql', psql: 'sql', blade: 'php',
};

/** Map a page's code-block class (or a guess from the content) to an app language. */
export function detectLanguage(hint: string, code: string): string {
    const known = new Set<string>(LANGUAGES.map((l) => l.value));
    const name = hint.toLowerCase();
    if (known.has(name)) return name;
    if (ALIASES[name]) return ALIASES[name];

    const text = code.trim();
    if (/^<\?php/.test(text)) return 'php';
    if (/^\s*[{[][\s\S]*[}\]]\s*$/.test(text) && /"\w+"\s*:/.test(text)) return 'json';
    if (/^(\$ |sudo |npm |npx |composer |php artisan |git |docker |cd |curl |brew |apt )/m.test(text)) return 'bash';
    if (/^\s*(SELECT|INSERT|UPDATE|DELETE|CREATE TABLE|ALTER TABLE)\b/i.test(text)) return 'sql';
    if (/^\s*(def |import \w+$|from \w+ import )/m.test(text)) return 'python';
    if (/^\s*(const|let|import|export|function)\b/m.test(text) && /:\s*(string|number|boolean)\b|interface \w+/.test(text)) return 'typescript';
    if (/^\s*(const|let|var|import|export|function)\b/m.test(text)) return 'javascript';
    if (/^\s*<[a-z!]/i.test(text)) return 'html';
    return 'plaintext';
}
