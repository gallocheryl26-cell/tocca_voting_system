(function () {
  try {
    const root = document.documentElement;
    if (!root) {
      return;
    }
    const storedTheme = localStorage.getItem('theme');
    const legacyDark = localStorage.getItem('darkMode');
    const isDark = storedTheme != null ? storedTheme === 'dark' : legacyDark === 'true';
    root.classList.toggle('dark-mode', Boolean(isDark));
    root.setAttribute('data-bs-theme', isDark ? 'dark' : 'light');
    if (isDark) {
      localStorage.setItem('darkMode', 'true');
      localStorage.setItem('theme', 'dark');
    } else if (storedTheme != null || legacyDark != null) {
      localStorage.setItem('darkMode', 'false');
      localStorage.setItem('theme', 'light');
    }
  } catch (err) {
    // Ignore storage access issues.
  }
})();
