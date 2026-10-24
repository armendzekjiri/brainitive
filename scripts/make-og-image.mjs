// Renders resources/og/og-image.html to public/images/og-image.png (1200×630), the social preview
// image used by the Open Graph / X meta tags in resources/views/app.blade.php (v1.39.0, BRAI-109).
// Usage: node scripts/make-og-image.mjs
import { chromium } from '@playwright/test';
import { fileURLToPath, pathToFileURL } from 'node:url';
import path from 'node:path';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 630 }, deviceScaleFactor: 1 });
await page.goto(pathToFileURL(path.join(root, 'resources/og/og-image.html')).href);
await page.waitForLoadState('networkidle');
await page.screenshot({ path: path.join(root, 'public/images/og-image.png'), type: 'png' });
await browser.close();
console.log('public/images/og-image.png written');
