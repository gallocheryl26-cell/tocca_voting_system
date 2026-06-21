(function () {
  const nativeFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    init = Object.assign({}, init);
    const url = typeof input === 'string' ? input : (input && input.url) || '';
    const isRelative = url && !/^https?:\/\//i.test(url);
    const isSameOrigin = isRelative || (typeof url === 'string' && url.startsWith(location.origin));
    if (isRelative || isSameOrigin) {
      init.credentials = init.credentials || 'same-origin';
    }
    return nativeFetch(input, init);
  };
})();

/*!
    * Start Bootstrap - SB Admin v7.0.7 (https://startbootstrap.com/template/sb-admin)
    * Copyright 2013-2023 Start Bootstrap
    * Licensed under MIT (https://github.com/StartBootstrap/startbootstrap-sb-admin/blob/master/LICENSE)
    */
    // 
// Scripts
// 
(function () {
  // Ensure script runs after Bootstrap is available
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSidebar);
  } else {
    initSidebar();
  }

  function initSidebar() {
    try {
      clearLegacySidebarHideState();
      syncSidebarCollapses();
      setupSidebarToggle();
      setupBreadcrumbSidebarLinks();
    } catch (err) {
      console.error('Sidebar enhancement error:', err);
    }
  }

  /** Remove old session flag that closed menus after submodule clicks. */
  function clearLegacySidebarHideState() {
    try {
      sessionStorage.removeItem('sb|sidebar-force-hide');
    } catch (e) {
      // ignore
    }
  }

  function normalizePath(path) {
    if (!path) return '';
    const cleaned = path.split('?')[0].split('#')[0];
    return cleaned.split('/').filter(Boolean).pop() || cleaned;
  }

  /**
   * Keep sidebar sections aligned with server-rendered state (PHP adds .show on the
   * group for the current page). Never force-close the section that contains .active.
   */
  function syncSidebarCollapses() {
    const sidenav = document.getElementById('sidenavAccordion');
    if (!sidenav) return;

    const current = normalizePath(window.location.pathname);
    const links = sidenav.querySelectorAll('a.nav-link[href]');

    // Reset â€” only one leaf link should be active (avoids double highlights)
    links.forEach(link => link.classList.remove('active'));

    links.forEach(link => {
      if (link.hasAttribute('data-bs-toggle')) return;
      const href = link.getAttribute('href');
      if (!href || href === '#' || href.startsWith('../')) return;
      const target = normalizePath(href);
      if (target && target === current) {
        link.classList.add('active');
      }
    });

    const collapses = sidenav.querySelectorAll('.collapse[id]');
    collapses.forEach(collapseEl => {
      registerCollapseStateListener(collapseEl);
      const trigger = sidenav.querySelector(`[data-bs-target="#${collapseEl.id}"]`);
      const hasActiveChild = !!collapseEl.querySelector('.sb-sidenav-menu-nested .nav-link.active');
      const serverOpen = collapseEl.classList.contains('show');

      if (hasActiveChild || serverOpen) {
        showCollapse(collapseEl, trigger);
      } else {
        forceHideCollapse(collapseEl, trigger);
      }
    });
  }

  function setupBreadcrumbSidebarLinks() {
    const links = document.querySelectorAll('.breadcrumb-sidebar-link[data-sidebar-target]');
    if (!links.length) return;

    links.forEach(link => {
      link.addEventListener('click', event => {
        const targetId = link.getAttribute('data-sidebar-target');
        if (!targetId) {
          return;
        }

        event.preventDefault();

        const collapseEl = document.getElementById(targetId);
        if (!collapseEl) {
          return;
        }

        registerCollapseStateListener(collapseEl);

        const trigger = document.querySelector(`[data-bs-target="#${targetId}"]`);
        showCollapse(collapseEl, trigger);

        if (trigger && typeof trigger.focus === 'function') {
          try {
            trigger.focus({ preventScroll: true });
          } catch (err) {
            trigger.focus();
          }
        }

        if (typeof collapseEl.scrollIntoView === 'function') {
          collapseEl.scrollIntoView({ block: 'nearest' });
        }
      });
    });
  }

  function showCollapse(element, trigger) {
    element.classList.add('show');

    if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
      const collapseInstance = bootstrap.Collapse.getOrCreateInstance(element, { toggle: false });
      collapseInstance.show();
    }

    if (trigger) {
      trigger.classList.remove('collapsed');
      trigger.setAttribute('aria-expanded', 'true');
    }
  }

  function forceHideCollapse(element, trigger) {
    element.classList.remove('show');

    if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
      const collapseInstance = bootstrap.Collapse.getOrCreateInstance(element, { toggle: false });
      collapseInstance.hide();
    }

    if (trigger) {
      trigger.classList.add('collapsed');
      trigger.setAttribute('aria-expanded', 'false');
    }
  }

  function registerCollapseStateListener(element) {
    if (!element || element.dataset.sbCollapseListener === 'true') return;

    element.addEventListener('shown.bs.collapse', () => {
      const trigger = document.querySelector(`[data-bs-target="#${element.id}"]`);
      if (trigger) {
        trigger.classList.remove('collapsed');
        trigger.setAttribute('aria-expanded', 'true');
      }
    });

    element.addEventListener('hidden.bs.collapse', () => {
      const trigger = document.querySelector(`[data-bs-target="#${element.id}"]`);
      if (trigger) {
        trigger.classList.add('collapsed');
        trigger.setAttribute('aria-expanded', 'false');
      }
    });

    element.dataset.sbCollapseListener = 'true';
  }

  function setupSidebarToggle() {
    const toggleButton = document.getElementById('sidebarToggle');
    const body = document.body;

    if (!toggleButton || !body) return;

    toggleButton.addEventListener('click', event => {
      event.preventDefault();
      body.classList.toggle('sb-sidenav-toggled');
      try {
        localStorage.setItem('sb|sidebar-toggle', body.classList.contains('sb-sidenav-toggled'));
      } catch (e) {
        // Ignore storage errors
      }
    });

    try {
      const isToggled = localStorage.getItem('sb|sidebar-toggle') === 'true';
      body.classList.toggle('sb-sidenav-toggled', isToggled);
    } catch (e) {
      // Ignore storage errors
    }
  }
})();
function updateSidebarTextColor() {
    const sidenav = document.getElementById('sidenavAccordion');
    if (!sidenav) return;
    const getLuminance = (rgb) => {
        const [r, g, b] = rgb.match(/\d+/g).map(Number).map(v => v / 255);
        const [R, G, B] = [r, g, b].map(c =>
            c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4)
        );
        return 0.2126 * R + 0.7152 * G + 0.0722 * B;
    };
    const bg = getComputedStyle(sidenav).backgroundColor;
    const textColor = getLuminance(bg) > 0.5 ? '#000000' : '#ffffff';
    document.documentElement.style.setProperty('--sidebar-text', textColor);
}

window.updateSidebarTextColor = updateSidebarTextColor;

document.addEventListener('DOMContentLoaded', updateSidebarTextColor);

(function initTopnavDropdowns() {
  'use strict';

  function bindTopnavDropdowns() {
    if (typeof bootstrap === 'undefined' || !bootstrap.Dropdown) {
      return;
    }

    document.querySelectorAll('.sb-topnav [data-bs-toggle="dropdown"]').forEach(function (toggleEl) {
      bootstrap.Dropdown.getOrCreateInstance(toggleEl, { autoClose: true });

      if (toggleEl.dataset.toccaDropdownBound === '1') {
        return;
      }

      toggleEl.dataset.toccaDropdownBound = '1';
      toggleEl.addEventListener('click', function (event) {
        event.preventDefault();
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bindTopnavDropdowns);
  } else {
    bindTopnavDropdowns();
  }
})();
