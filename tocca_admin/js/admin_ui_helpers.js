/**
 * Shared TOCCA admin UI helpers — badges, action bars, escape.
 */
(function (global) {
  'use strict';

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  const BADGE_CLASS = {
    active: 'text-bg-primary',
    inactive: 'text-bg-secondary',
    archived: 'text-bg-dark',
    pending: 'text-bg-warning',
    approved: 'text-bg-success',
    rejected: 'text-bg-danger',
    info: 'text-bg-info',
    secondary: 'text-bg-secondary',
  };

  function statusBadge(kind, label) {
    const cls = BADGE_CLASS[kind] || BADGE_CLASS.secondary;
    const text = escapeHtml(label || kind);
    return `<span class="badge badge-status rounded-pill ${cls}">${text}</span>`;
  }

  function actionBar(innerHtml) {
    return `<div class="admin-table-actions" role="group">${innerHtml}</div>`;
  }

  /** @param {string} classNames - e.g. "btn-success activate-event-btn" */
  function btn(classNames, label, attrs) {
    const attrStr = attrs ? ` ${attrs}` : '';
    return `<button type="button" class="btn btn-sm ${classNames}"${attrStr}>${escapeHtml(label)}</button>`;
  }

  global.ToccaAdminUI = {
    escapeHtml,
    statusBadge,
    actionBar,
    btn,
    BADGE_CLASS,
  };
})(typeof window !== 'undefined' ? window : global);
