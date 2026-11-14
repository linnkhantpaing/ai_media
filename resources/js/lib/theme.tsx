import { createContext, useContext, useEffect, useState, type PropsWithChildren } from 'react';

type Theme = 'dark' | 'light';
const ThemeContext = createContext<{ theme: Theme; toggle: () => void }>({ theme: 'dark', toggle: () => {} });

export function ThemeProvider({ children }: PropsWithChildren) {
    const [theme, setTheme] = useState<Theme>(() => document.documentElement.dataset.theme === 'light' ? 'light' : 'dark');
    useEffect(() => {
        document.documentElement.dataset.theme = theme;
        document.documentElement.style.colorScheme = theme;
        document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme === 'dark' ? '#0B0D14' : '#F6F7FB');
        try { localStorage.setItem('ai-media.theme', theme); } catch { /* Private browser storage may be unavailable. */ }
    }, [theme]);
    useEffect(() => {
        const sync = (event: StorageEvent) => {
            if (event.key === 'ai-media.theme' && (event.newValue === 'dark' || event.newValue === 'light')) setTheme(event.newValue);
        };
        window.addEventListener('storage', sync);
        return () => window.removeEventListener('storage', sync);
    }, []);
    return <ThemeContext value={{ theme, toggle: () => setTheme((value) => value === 'dark' ? 'light' : 'dark') }}>{children}</ThemeContext>;
}
export const useTheme = () => useContext(ThemeContext);