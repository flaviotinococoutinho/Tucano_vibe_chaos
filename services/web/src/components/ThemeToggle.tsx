import type { ReactElement } from 'react';
import { useTheme } from '../theme/index.ts';
import './ThemeToggle.css';

function SunIcon(): ReactElement {
  return (
    <svg
      viewBox="0 0 24 24"
      width="20"
      height="20"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      aria-hidden="true"
    >
      <circle cx="12" cy="12" r="4" />
      <path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1" />
    </svg>
  );
}

function MoonIcon(): ReactElement {
  return (
    <svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true">
      <path d="M20 14.5A8.5 8.5 0 0 1 9.5 4a8.5 8.5 0 1 0 10.5 10.5Z" />
    </svg>
  );
}

/** Persisted in localStorage (try/catch wrapped, see theme/useTheme.ts); OS preference otherwise. */
export function ThemeToggle(): ReactElement {
  const { theme, toggleTheme } = useTheme();
  const isDark = theme === 'dark';

  return (
    <button type="button" className="theme-toggle" onClick={toggleTheme} aria-pressed={isDark}>
      {isDark ? <SunIcon /> : <MoonIcon />}
      <span className="visually-hidden">
        {isDark ? 'Mudar para o tema claro' : 'Mudar para o tema escuro'}
      </span>
    </button>
  );
}
