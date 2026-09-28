import { useCallback, useEffect, useState } from 'react';

export type ThemePreference = 'light' | 'dark';

const STORAGE_KEY = 'tucano:theme';

/** Reads the visitor's saved choice. Private browsing or a blocked storage: no crash, no theme. */
function storedPreference(): ThemePreference | null {
  try {
    const value = window.localStorage.getItem(STORAGE_KEY);
    return value === 'light' || value === 'dark' ? value : null;
  } catch {
    return null;
  }
}

function storePreference(preference: ThemePreference): void {
  try {
    window.localStorage.setItem(STORAGE_KEY, preference);
  } catch {
    // The toggle still works for this visit; it just will not be remembered.
  }
}

function systemPreference(): ThemePreference {
  return window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
}

export type ThemeState = {
  readonly theme: ThemePreference;
  readonly toggleTheme: () => void;
};

/**
 * Light by default, dark when the OS asks for it or the visitor picked it before. The choice
 * is mirrored to `<html data-theme>`, which is what `src/styles/tokens.css` switches on.
 */
export function useTheme(): ThemeState {
  const [theme, setTheme] = useState<ThemePreference>(
    () => storedPreference() ?? systemPreference(),
  );

  useEffect(() => {
    document.documentElement.dataset.theme = theme;
  }, [theme]);

  // Follows the OS live, but only until the visitor makes an explicit choice of their own.
  useEffect(() => {
    if (storedPreference() !== null) {
      return;
    }
    const media = window.matchMedia('(prefers-color-scheme: dark)');
    const onChange = () => setTheme(media.matches ? 'dark' : 'light');
    media.addEventListener('change', onChange);
    return () => media.removeEventListener('change', onChange);
  }, []);

  const toggleTheme = useCallback(() => {
    setTheme((current) => {
      const next = current === 'light' ? 'dark' : 'light';
      storePreference(next);
      return next;
    });
  }, []);

  return { theme, toggleTheme };
}
