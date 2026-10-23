import type { BrowserContext } from '@playwright/test';

/**
 * A visible mouse cursor + click ripple drawn into every page (E2E_SHOW_CURSOR=1, set by
 * `npm run e2e:live` / `e2e:ui`): Playwright moves the real mouse but, unlike Cypress, doesn't
 * draw it. Pure overlay (pointer-events: none) — it never changes what the tests click.
 */
export async function showCursor(context: BrowserContext): Promise<void> {
    await context.addInitScript(() => {
        const install = () => {
            if (document.getElementById('__e2e_cursor')) return;
            const style = document.createElement('style');
            style.textContent = `
                #__e2e_cursor { position: fixed; top: 0; left: 0; width: 22px; height: 22px; margin: -11px 0 0 -11px;
                    border-radius: 50%; background: rgba(239, 68, 68, .35); border: 2px solid #ef4444;
                    pointer-events: none; z-index: 2147483647; transition: transform .08s ease-out, background .1s; }
                #__e2e_cursor.down { transform: scale(.7); background: rgba(239, 68, 68, .8); }
                .__e2e_ripple { position: fixed; width: 44px; height: 44px; margin: -22px 0 0 -22px; border-radius: 50%;
                    border: 3px solid #ef4444; pointer-events: none; z-index: 2147483646; animation: __e2e_ripple .5s ease-out forwards; }
                @keyframes __e2e_ripple { from { transform: scale(.3); opacity: 1 } to { transform: scale(1.4); opacity: 0 } }`;
            document.documentElement.appendChild(style);
            const cursor = document.createElement('div');
            cursor.id = '__e2e_cursor';
            document.documentElement.appendChild(cursor);
            const move = (e: MouseEvent) => {
                cursor.style.left = `${e.clientX}px`;
                cursor.style.top = `${e.clientY}px`;
            };
            window.addEventListener('mousemove', move, true);
            window.addEventListener('mousedown', (e) => {
                move(e);
                cursor.classList.add('down');
                const ripple = document.createElement('div');
                ripple.className = '__e2e_ripple';
                ripple.style.left = `${e.clientX}px`;
                ripple.style.top = `${e.clientY}px`;
                document.documentElement.appendChild(ripple);
                setTimeout(() => ripple.remove(), 600);
            }, true);
            window.addEventListener('mouseup', () => cursor.classList.remove('down'), true);
        };
        if (document.documentElement) install();
        else document.addEventListener('DOMContentLoaded', install);
    });
}
