// Reads the newest entry of CHANGELOG in resources/js/components/HelpModal.tsx — the app
// version by convention (docs/ai/WORKFLOW.md) — and prints it for the release workflow.
//
//   node .github/scripts/changelog-release.mjs [notes-file]
//
// Prints `version=1.15.0`, `tag=v1.15.0`, `title=…` lines (GITHUB_OUTPUT format) and, when
// a notes file is given, writes the entry's items there as Markdown release notes.
import { readFileSync, writeFileSync } from 'node:fs';

const source = readFileSync(new URL('../../resources/js/components/HelpModal.tsx', import.meta.url), 'utf8');

const start = source.indexOf('const CHANGELOG = [');
if (start === -1) fail('CHANGELOG not found in HelpModal.tsx');

// The first entry: from the first `{` after the array start to the matching `}`.
const open = source.indexOf('{', start);
let depth = 0;
let end = open;
for (; end < source.length; end++) {
    if (source[end] === '{') depth++;
    if (source[end] === '}' && --depth === 0) break;
}
const entry = source.slice(open, end + 1);

const string = (key) => entry.match(new RegExp(`${key}:\\s*'((?:[^'\\\\]|\\\\.)*)'`))?.[1]?.replace(/\\'/g, "'");

const tag = string('version');
if (!tag || !/^v\d+\.\d+\.\d+$/.test(tag)) fail(`Newest CHANGELOG version "${tag}" is not vMAJOR.MINOR.PATCH`);

const title = string('title') ?? tag;
const itemsBlock = entry.match(/items:\s*\[([\s\S]*?)\]/)?.[1] ?? '';
const items = [...itemsBlock.matchAll(/'((?:[^'\\]|\\.)*)'/g)].map((m) => m[1].replace(/\\'/g, "'"));

console.log(`version=${tag.slice(1)}`);
console.log(`tag=${tag}`);
console.log(`title=${title}`);

const notesFile = process.argv[2];
if (notesFile) {
    const image = `ghcr.io/${process.env.GITHUB_REPOSITORY_OWNER ?? 'armendzekjiri'}/brainitive`;
    writeFileSync(
        notesFile,
        [
            `## What's new`,
            '',
            ...items.map((item) => `- ${item}`),
            '',
            '## Docker',
            '',
            '```bash',
            `docker pull ${image}:${tag.slice(1)}`,
            '```',
            '',
        ].join('\n'),
    );
}

function fail(message) {
    console.error(`::error::${message}`);
    process.exit(1);
}
