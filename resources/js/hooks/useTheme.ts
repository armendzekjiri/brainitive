import { useCallback, useEffect, useState } from 'react';

export type Theme = 'light' | 'dark' | 'system';
export type ResolvedTheme = 'light' | 'dark';

const STORAGE_KEY = 'brainitive-theme';

function prefersDark(): boolean {
    if (typeof window === 'undefined' || !window.matchMedia) return false;
    return window.matchMedia('(prefers-color-scheme: dark)').matches;
}

function resolveTheme(theme: Theme): ResolvedTheme {
    if (theme === 'system') return prefersDark() ? 'dark' : 'light';
    return theme;
}

function applyResolved(resolved: ResolvedTheme) {
    const root = document.documentElement;
    if (resolved === 'dark') {
        root.classList.add('dark');
        root.classList.remove('light');
    } else {
        root.classList.remove('dark');
        root.classList.add('light');
    }
    root.style.colorScheme = resolved;
}

function readStoredTheme(): Theme {
    try {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored === 'dark' || stored === 'light' || stored === 'system') return stored;
    } catch {
        // localStorage not available
    }
    return 'system';
}

export function useTheme() {
    const [theme, setThemeState] = useState<Theme>(() => {
        if (typeof window === 'undefined') return 'system';
        return readStoredTheme();
    });
    const [resolvedTheme, setResolvedTheme] = useState<ResolvedTheme>(() => {
        if (typeof window === 'undefined') return 'light';
        return resolveTheme(readStoredTheme());
    });

    useEffect(() => {
        const stored = readStoredTheme();
        setThemeState(stored);
        const resolved = resolveTheme(stored);
        setResolvedTheme(resolved);
        applyResolved(resolved);
    }, []);

    useEffect(() => {
        if (theme !== 'system' || typeof window === 'undefined' || !window.matchMedia) return;
        const mql = window.matchMedia('(prefers-color-scheme: dark)');
        const handler = () => {
            const next: ResolvedTheme = mql.matches ? 'dark' : 'light';
            setResolvedTheme(next);
            applyResolved(next);
        };
        mql.addEventListener('change', handler);
        return () => mql.removeEventListener('change', handler);
    }, [theme]);

    useEffect(() => {
        if (typeof window === 'undefined') return;
        const handler = (e: StorageEvent) => {
            if (e.key !== STORAGE_KEY) return;
            const next = readStoredTheme();
            setThemeState(next);
            const resolved = resolveTheme(next);
            setResolvedTheme(resolved);
            applyResolved(resolved);
        };
        window.addEventListener('storage', handler);
        return () => window.removeEventListener('storage', handler);
    }, []);

    const setTheme = useCallback((next: Theme) => {
        setThemeState(next);
        const resolved = resolveTheme(next);
        setResolvedTheme(resolved);
        applyResolved(resolved);
        try {
            localStorage.setItem(STORAGE_KEY, next);
        } catch {
            // ignore
        }
    }, []);

    const toggleTheme = useCallback(() => {
        const current = readStoredTheme();
        const currentResolved = resolveTheme(current);
        const next: Theme = currentResolved === 'dark' ? 'light' : 'dark';
        setTheme(next);
    }, [setTheme]);

    return { theme, resolvedTheme, setTheme, toggleTheme };
}
