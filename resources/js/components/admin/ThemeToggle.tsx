import { Moon, Sun } from 'lucide-react';
import { useAdminTheme } from '../../hooks/useAdminTheme';

export default function ThemeToggle({ className = '' }: { className?: string }) {
  const { isDark, toggleTheme } = useAdminTheme();

  return (
    <button
      type="button"
      className={`theme-toggle ${className}`.trim()}
      aria-label={isDark ? 'Switch to light theme' : 'Switch to dark theme'}
      aria-pressed={isDark}
      onClick={toggleTheme}
    >
      {isDark ? <Sun size={16} aria-hidden="true" /> : <Moon size={16} aria-hidden="true" />}
      {isDark ? 'Light' : 'Dark'}
    </button>
  );
}
