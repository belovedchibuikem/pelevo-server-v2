import { createContext, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';

export type AdminTheme = 'light' | 'dark';

const storageKey = 'pelevo.admin.theme';
const ThemeContext = createContext<{
  theme: AdminTheme;
  isDark: boolean;
  toggleTheme: () => void;
} | null>(null);

export function readAdminTheme(): AdminTheme {
  try {
    return localStorage.getItem(storageKey) === 'dark' ? 'dark' : 'light';
  } catch {
    return 'light';
  }
}

export function applyAdminTheme(theme: AdminTheme): void {
  document.documentElement.dataset.theme = theme;
  document.documentElement.style.colorScheme = theme;
}

export function AdminThemeProvider({ children }: { children: ReactNode }) {
  const [theme, setTheme] = useState<AdminTheme>(() => readAdminTheme());

  useEffect(() => {
    applyAdminTheme(theme);
    try {
      localStorage.setItem(storageKey, theme);
    } catch {
      /* Theme still applies for this session if storage is blocked. */
    }
  }, [theme]);

  const value = useMemo(
    () => ({
      theme,
      isDark: theme === 'dark',
      toggleTheme: () => setTheme((current) => (current === 'dark' ? 'light' : 'dark')),
    }),
    [theme],
  );

  return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useAdminTheme() {
  const context = useContext(ThemeContext);
  if (!context) {
    throw new Error('useAdminTheme must be used within AdminThemeProvider');
  }
  return context;
}
