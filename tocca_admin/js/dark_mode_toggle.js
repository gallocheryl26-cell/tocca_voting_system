(function () {
  const STORAGE_DARK = 'darkMode';
  const STORAGE_THEME = 'theme';
  const root = document.documentElement;
  let darkModeEnabled = false;
  let switchEl = null;
  let fabButton = null;

  const MOON_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 12.79A9 9 0 0 1 11.21 3 7 7 0 1 0 21 12.79Z" /></svg>';
  const SUN_ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 18a6 6 0 1 1 0-12 6 6 0 0 1 0 12Zm0-16h2v3h-2V2Zm0 19h2v3h-2v-3ZM2 11h3v2H2v-2Zm19 0h3v2h-3v-2ZM4.22 4.22l2.12 2.12-1.41 1.41-2.12-2.12 1.41-1.41Zm14.9 14.9 2.12 2.12-1.41 1.41-2.12-2.12 1.41-1.41ZM4.22 19.78l1.41-1.41 2.12 2.12-1.41 1.41-2.12-2.12Zm14.9-14.9 1.41-1.41 2.12 2.12-1.41 1.41-2.12-2.12Z" /></svg>';

  function readStoredPreference() {
    try {
      const storedTheme = localStorage.getItem(STORAGE_THEME);
      if (storedTheme === 'dark') return true;
      if (storedTheme === 'light') return false;
      const legacy = localStorage.getItem(STORAGE_DARK);
      if (legacy === 'true') return true;
      if (legacy === 'false') return false;
    } catch (err) {
      console.warn('Unable to read dark mode preference from storage.', err);
    }
    return root.classList.contains('dark-mode') || root.getAttribute('data-bs-theme') === 'dark';
  }

  function persistPreference(isDark) {
    try {
      localStorage.setItem(STORAGE_DARK, isDark ? 'true' : 'false');
      localStorage.setItem(STORAGE_THEME, isDark ? 'dark' : 'light');
    } catch (err) {
      console.warn('Unable to persist dark mode preference.', err);
    }
  }

  function updateSwitch(isDark) {
    if (switchEl) {
      switchEl.checked = isDark;
    }
  }

  function updateFabAppearance(isDark) {
    if (!fabButton) {
      return;
    }

    fabButton.classList.toggle('ig-dark-mode-toggle--active', isDark);
    fabButton.setAttribute('aria-pressed', String(isDark));

    const label = isDark ? 'Disable dark mode' : 'Enable dark mode';
    fabButton.setAttribute('aria-label', label);

    const srSpan = fabButton.querySelector('.visually-hidden');
    if (srSpan) {
      srSpan.textContent = label;
    }

    const tooltip = fabButton.querySelector('.ig-dark-mode-toggle__tooltip');
    if (tooltip) {
      tooltip.textContent = label;
    }

    const iconWrap = fabButton.querySelector('.ig-dark-mode-toggle__icon');
    if (iconWrap) {
      iconWrap.innerHTML = isDark ? SUN_ICON : MOON_ICON;
    }
  }

  function applyTheme(isDark, { persist = true } = {}) {
    darkModeEnabled = Boolean(isDark);
    root.classList.toggle('dark-mode', darkModeEnabled);
    root.setAttribute('data-bs-theme', darkModeEnabled ? 'dark' : 'light');
    updateSwitch(darkModeEnabled);
    updateFabAppearance(darkModeEnabled);
    if (typeof window.updateSidebarTextColor === 'function') {
      try {
        window.updateSidebarTextColor();
      } catch (err) {
        console.warn('Unable to refresh sidebar text color for dark mode.', err);
      }
    }
    if (persist) {
      persistPreference(darkModeEnabled);
    }
  }

  function toggleDarkMode() {
    applyTheme(!darkModeEnabled);
  }

  function injectStyles() {
    if (document.getElementById('ig-dark-mode-toggle-styles')) {
      return;
    }
    const style = document.createElement('style');
    style.id = 'ig-dark-mode-toggle-styles';
    style.textContent = `
      .ig-dark-mode-toggle {
        position: fixed;
        bottom: 1.75rem;
        right: 1.75rem;
        width: 3.75rem;
        height: 3.75rem;
        border-radius: 50%;
        border: none;
        padding: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        background-color: #0f172a;
        color: #f8fafc;
        cursor: pointer;
        box-shadow: 0 12px 25px rgba(0, 0, 0, 0.25);
        transition: transform 150ms ease, box-shadow 150ms ease;
        z-index: 1080;
      }
      .ig-dark-mode-toggle:hover {
        transform: translateY(-2px);
        box-shadow: 0 16px 32px rgba(0, 0, 0, 0.28);
      }
      .ig-dark-mode-toggle:focus-visible {
        outline: 3px solid rgba(79, 91, 213, 0.35);
        outline-offset: 4px;
      }
      .ig-dark-mode-toggle__icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.35rem;
        height: 2.35rem;
        border-radius: 50%;
        pointer-events: none;
        line-height: 0;
      }
      .ig-dark-mode-toggle__icon svg {
        width: 100%;
        height: 100%;
        fill: currentColor;
        pointer-events: none;
        display: block;
      }
      .ig-dark-mode-toggle__tooltip {
        position: absolute;
        right: calc(100% + 0.5rem);
        top: 50%;
        transform: translateY(-50%);
        background-color: rgba(15, 23, 42, 0.9);
        color: #f8fafc;
        padding: 0.35rem 0.6rem;
        border-radius: 999px;
        font-size: 0.85rem;
        font-weight: 600;
        letter-spacing: 0.01em;
        white-space: nowrap;
        opacity: 0;
        visibility: hidden;
        transition: opacity 120ms ease, visibility 120ms ease;
        pointer-events: none;
      }
      .ig-dark-mode-toggle--active .ig-dark-mode-toggle__tooltip {
        background-color: rgba(248, 250, 252, 0.95);
        color: #0f172a;
      }
      .ig-dark-mode-toggle:hover .ig-dark-mode-toggle__tooltip,
      .ig-dark-mode-toggle:focus-visible .ig-dark-mode-toggle__tooltip {
        opacity: 1;
        visibility: visible;
      }
      .ig-dark-mode-toggle--active {
        background-color: #f8fafc;
        color: #0f172a;
        box-shadow: 0 12px 25px rgba(15, 23, 42, 0.45);
      }
      @media (max-width: 767.98px) {
        .ig-dark-mode-toggle {
          width: 3.25rem;
          height: 3.25rem;
          bottom: 1.25rem;
          right: 1.25rem;
        }
        .ig-dark-mode-toggle__icon {
          width: 2rem;
          height: 2rem;
        }
      }
    `;
    document.head.appendChild(style);
  }

  function createFloatingButton() {
    if (fabButton || !document.body) {
      return;
    }
    fabButton = document.createElement('button');
    fabButton.type = 'button';
    fabButton.className = 'ig-dark-mode-toggle';
    fabButton.innerHTML = `
      <span class="visually-hidden">Enable dark mode</span>
      <span class="ig-dark-mode-toggle__tooltip" aria-hidden="true">Enable dark mode</span>
      <span class="ig-dark-mode-toggle__icon" aria-hidden="true">${MOON_ICON}</span>
    `;
    fabButton.addEventListener('click', toggleDarkMode);
    document.body.appendChild(fabButton);
    updateFabAppearance(darkModeEnabled);
  }

  function setupSwitch() {
    switchEl = document.querySelector('#darkModeSwitch');
    if (switchEl) {
      switchEl.checked = darkModeEnabled;
      switchEl.addEventListener('change', function (event) {
        applyTheme(event.target.checked);
      });
    }
  }

  function init() {
    injectStyles();
    applyTheme(readStoredPreference(), { persist: false });
    setupSwitch();
    createFloatingButton();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
