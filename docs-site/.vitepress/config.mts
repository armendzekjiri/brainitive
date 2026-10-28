import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { defineConfig, type DefaultTheme } from 'vitepress';

const HOST = 'https://docs.brainitive.app';
const ROOT = join(import.meta.dirname, '..');

/** `title:` and `order:` from a page's frontmatter (falls back to its first heading). */
function pageMeta(file: string): { title: string; order: number } {
    const src = readFileSync(file, 'utf8');
    const front = src.match(/^---\n([\s\S]*?)\n---/)?.[1] ?? '';
    const field = (name: string) => front.match(new RegExp(`^${name}:\\s*['"]?(.+?)['"]?\\s*$`, 'm'))?.[1];
    const title = field('sidebarTitle') ?? field('title') ?? src.match(/^#\s+(.+)$/m)?.[1] ?? file;

    return { title, order: Number(field('order') ?? 100) };
}

/**
 * One sidebar group per section folder: every .md file, sorted by its `order:` frontmatter, so a new
 * page shows up by adding the file (no list to keep in sync).
 */
function section(dir: string, text: string): DefaultTheme.SidebarItem {
    const items = readdirSync(join(ROOT, dir))
        .filter((f) => f.endsWith('.md'))
        .map((f) => {
            const meta = pageMeta(join(ROOT, dir, f));
            const slug = f === 'index.md' ? '' : f.replace(/\.md$/, '');
            return { text: meta.title, link: `/${dir}/${slug}`, order: f === 'index.md' ? -1 : meta.order };
        })
        .sort((a, b) => a.order - b.order || a.text.localeCompare(b.text))
        .map(({ text, link }) => ({ text, link }));

    return { text, items };
}

const SECTIONS: [string, string][] = [
    ['guide', 'Using Brainitive'],
    ['self-hosting', 'Self-hosting'],
    ['integrations', 'Integrations'],
    ['admin', 'Instance admin'],
];

export default defineConfig({
    lang: 'en',
    title: 'Brainitive Docs',
    titleTemplate: ':title · Brainitive Docs',
    description: 'How to use, self-host and configure Brainitive — boards, docs, whiteboards, helpdesk, booking pages and more.',
    cleanUrls: true,
    lastUpdated: true,
    sitemap: { hostname: HOST },
    head: [
        ['link', { rel: 'icon', href: '/favicon.ico', sizes: 'any' }],
        ['link', { rel: 'icon', type: 'image/png', href: '/favicon-32x32.png', sizes: '32x32' }],
        ['link', { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' }],
        ['meta', { name: 'theme-color', content: '#0e83dd' }],
        ['meta', { property: 'og:site_name', content: 'Brainitive Docs' }],
        ['meta', { property: 'og:type', content: 'website' }],
        ['meta', { property: 'og:image', content: `${HOST}/og-image.png` }],
        ['meta', { name: 'twitter:card', content: 'summary_large_image' }],
    ],
    // per page: canonical URL + og:title / og:description / og:url (SEO)
    transformPageData(pageData) {
        const path = pageData.relativePath.replace(/(^|\/)index\.md$/, '$1').replace(/\.md$/, '');
        const url = `${HOST}/${path}`;
        const title = pageData.frontmatter.title ?? pageData.title;
        const description = pageData.frontmatter.description ?? pageData.description;
        pageData.frontmatter.head ??= [];
        pageData.frontmatter.head.push(
            ['link', { rel: 'canonical', href: url }],
            ['meta', { property: 'og:url', content: url }],
            ['meta', { property: 'og:title', content: title ? `${title} · Brainitive Docs` : 'Brainitive Docs' }],
            ...(description ? [['meta', { property: 'og:description', content: description }] as [string, Record<string, string>]] : []),
        );
    },
    themeConfig: {
        logo: { light: '/logo.png', dark: '/logo-dark.png', alt: 'Brainitive' },
        siteTitle: 'Docs',
        nav: [
            { text: 'Guide', link: '/guide/', activeMatch: '^/guide/' },
            { text: 'Self-hosting', link: '/self-hosting/', activeMatch: '^/self-hosting/' },
            { text: 'Integrations', link: '/integrations/', activeMatch: '^/integrations/' },
            { text: 'Admin', link: '/admin/', activeMatch: '^/admin/' },
            { text: 'Open Brainitive', link: 'https://www.brainitive.app' },
        ],
        sidebar: Object.fromEntries(SECTIONS.map(([dir]) => [`/${dir}/`, SECTIONS.map(([d, text]) => ({ ...section(d, text), collapsed: d !== dir }))])),
        search: { provider: 'local' },
        outline: { level: [2, 3], label: 'On this page' },
        editLink: {
            pattern: 'https://github.com/armendzekjiri/brainitive/edit/main/docs-site/:path',
            text: 'Edit this page on GitHub',
        },
        socialLinks: [{ icon: 'github', link: 'https://github.com/armendzekjiri/brainitive' }],
        footer: {
            message:
                '<a href="https://www.brainitive.app/privacy">Privacy</a> · <a href="https://www.brainitive.app/imprint">Imprint</a> · <a href="https://brainitive.app/support/security-issue">Report a security issue</a>',
            copyright: 'Brainitive — open source, AGPL-3.0',
        },
    },
});
