// nomination_chart.js
(() => {
  const eventId = document.getElementById('currentEventId')?.value || '';
  const days = 30;

  const lineEl       = document.getElementById('nomsTrend');
  const donutEl      = document.getElementById('nomsStatus');
  const emptyLineEl  = document.getElementById('nomsTrendEmpty');
  const emptyDonutEl = document.getElementById('nomsStatusEmpty');

  const rootEl = document.documentElement;

  function getThemePalette() {
    const bodyStyles = window.getComputedStyle(document.body);
    const bodyColor = bodyStyles?.color?.trim();
    const bsBodyColor = bodyStyles?.getPropertyValue('--bs-body-color')?.trim();
    const isDarkMode = rootEl.classList.contains('dark-mode') || rootEl.getAttribute('data-bs-theme') === 'dark';

    const fallbackTextColor = isDarkMode ? '#e5e7eb' : '#1f2937';
    const textColor = bodyColor || bsBodyColor || fallbackTextColor;

    const fallbackGridColor = isDarkMode
      ? 'rgba(148, 163, 184, 0.25)'
      : 'rgba(71, 85, 105, 0.15)';
    const computedGridColor = bodyStyles?.getPropertyValue('--bs-border-color-translucent')?.trim();
    const gridColor = computedGridColor || fallbackGridColor;

    const tooltipBgColor = isDarkMode ? 'rgba(15, 23, 42, 0.92)' : 'rgba(255, 255, 255, 0.96)';
    const tooltipBorderColor = isDarkMode ? 'rgba(148, 163, 184, 0.35)' : 'rgba(100, 116, 139, 0.35)';

    const accentBorder = isDarkMode ? '#38bdf8' : '#1d4ed8';
    const accentFill = isDarkMode ? 'rgba(56, 189, 248, 0.25)' : 'rgba(59, 130, 246, 0.2)';
    const accentBorderWidth = isDarkMode ? 3 : 2;

    return { textColor, gridColor, tooltipBgColor, tooltipBorderColor, accentBorder, accentFill, accentBorderWidth };
  }

  if (!lineEl || !donutEl) return;

  let lineChart = null;
  let donutChart = null;

  function dateKey(d) {
    const yy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yy}-${mm}-${dd}`;
  }

  function buildLastNDays(n) {
    const labels = [];
    const today = new Date();
    for (let i = n - 1; i >= 0; i--) {
      const d = new Date(today);
      d.setDate(d.getDate() - i);
      labels.push(dateKey(d));
    }
    return labels;
  }

  async function load() {
    try {
      const params = new URLSearchParams();
      if (eventId) params.set('event_id', eventId);
      params.set('days', String(days));

      const res = await fetch(`nominations_metrics.php?${params.toString()}`, { cache: 'no-store' });
      const j = await res.json();
      if (j.status !== 'success') throw new Error(j.message || 'Failed');

      /* ---------------- LINE: Last N Days ---------------- */
      const rawLabels = buildLastNDays(j.window_days || days);
      const look = Object.create(null);
      (j.daily || []).forEach(r => { look[r.date] = (r.count || 0); });

      const series = rawLabels.map(k => look[k] || 0);
      const labels = rawLabels.map(k => k.slice(5)); // "MM-DD"

      if (emptyLineEl) emptyLineEl.style.display = series.reduce((a, b) => a + b, 0) === 0 ? 'block' : 'none';

      const palette = getThemePalette();

      if (lineChart) lineChart.destroy();
      lineChart = new Chart(lineEl.getContext('2d'), {
        type: 'line',
        data: {
          labels,
          datasets: [{
            label: 'Registration',
            data: series,
            tension: 0.3,
            fill: true,
            pointRadius: 2,
            backgroundColor: palette.accentFill,
            borderColor: palette.accentBorder,
            borderWidth: palette.accentBorderWidth
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          scales: {
            x: {
              ticks: { color: palette.textColor },
              grid: { color: palette.gridColor }
            },
            y: {
              beginAtZero: true,
              ticks: { precision: 0, color: palette.textColor },
              grid: { color: palette.gridColor }
            }
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              backgroundColor: palette.tooltipBgColor,
              titleColor: palette.textColor,
              bodyColor: palette.textColor,
              borderColor: palette.tooltipBorderColor,
              borderWidth: 1,
              callbacks: {
                label: ctx => ` ${ctx.parsed.y} registration${ctx.parsed.y === 1 ? '' : 's'}`
              }
            }
          }
        }
      });

      /* ---------------- DOUGHNUT: Status Breakdown ---------------- */
      const raw = j.status_counts || {};

      // Normalize alternate backend keys to a single "in_review" value
      const inReviewValue =
        raw.in_review ?? raw.under_review ?? raw.review ?? raw.reviewing ?? raw.inreview ?? 0;

      // Order must match labels, values, and colors
      const labels2 = ['Pending', 'In Review', 'Needs Info', 'Approved', 'Rejected'];
      const values2 = [
        (raw.pending || 0),
        Number(inReviewValue) || 0,
        (raw.needs_info || 0),
        (raw.approved || 0),
        (raw.rejected || 0)
      ];

      const total2 = values2.reduce((a, b) => a + b, 0);
      if (emptyDonutEl) emptyDonutEl.style.display = total2 === 0 ? 'block' : 'none';

      // 🎨 Colors matched to your badges:
      // In Review = #1E88E5 (blue)
      // Approved  = #2E7D32 (dark green)
      // Pending   = #FBBF24 (yellow)
      // (kept Needs Info gray, Rejected red)
      const colors = [
        '#FBBF24', // Pending (yellow)
        '#1E88E5', // In Review (blue)
        '#9E9E9E', // Needs Info (gray)
        '#2E7D32', // Approved (dark green)
        '#EF4444'  // Rejected (red)
      ];

      const legendLabelsWithCounts = labels2.map((lbl, i) => `${lbl} (${values2[i]})`);

      // Center total plugin
      const centerTotalPlugin = {
        id: 'centerTotal',
        afterDatasetsDraw(chart, _args, opts) {
          const { ctx } = chart;
          const meta = chart.getDatasetMeta(0);
          if (!meta?.data?.length) return;

          // Get center of doughnut
          const { x, y } = meta.data[0];

          ctx.save();
          ctx.textAlign = 'center';
          ctx.textBaseline = 'middle';
          ctx.fillStyle = opts.color || '#2c3e50';

          if (opts.subText) {
            ctx.font = `${opts.subFontSize || 11}px ${opts.fontFamily || 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif'}`;
            ctx.fillText(opts.subText, x, y - (opts.mainFontSize || 24) * 0.6);
          }

          ctx.font = `bold ${opts.mainFontSize || 26}px ${opts.fontFamily || 'system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif'}`;
          ctx.fillText(String(opts.mainText ?? ''), x, y + (opts.yOffset || 2));
          ctx.restore();
        }
      };

      if (donutChart) donutChart.destroy();
      donutChart = new Chart(donutEl.getContext('2d'), {
        type: 'doughnut',
        data: {
          labels: legendLabelsWithCounts,
          datasets: [{
            data: values2,
            backgroundColor: colors,
            borderColor: '#ffffff',
            borderWidth: 2
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          cutout: '60%',
          plugins: {
            legend: {
              position: 'bottom',
              align: 'center',
              labels: {
                boxWidth: 12,
                padding: 12,
                color: palette.textColor
              }
            },
            tooltip: {
              backgroundColor: palette.tooltipBgColor,
              titleColor: palette.textColor,
              bodyColor: palette.textColor,
              borderColor: palette.tooltipBorderColor,
              borderWidth: 1,
              callbacks: {
                label: ctx => ` ${labels2[ctx.dataIndex]}: ${ctx.parsed}`
              }
            },
            centerTotal: {
              mainText: total2,
              subText: 'Total',
              mainFontSize: 26,
              subFontSize: 11,
              color: palette.textColor,
              yOffset: 2
            }
          }
        },
        plugins: [centerTotalPlugin]
      });

    } catch (e) {
      console.error('Registration charts error:', e);
      if (emptyLineEl)  emptyLineEl.style.display  = 'block';
      if (emptyDonutEl) emptyDonutEl.style.display = 'block';
    }
  }

  function applyThemeToCharts() {
    if (!lineChart && !donutChart) return;

    const palette = getThemePalette();

    if (lineChart) {
      const opts = lineChart.options;
      if (opts?.scales?.x?.ticks) opts.scales.x.ticks.color = palette.textColor;
      if (opts?.scales?.x?.grid)  opts.scales.x.grid.color  = palette.gridColor;
      if (opts?.scales?.y?.ticks) opts.scales.y.ticks.color = palette.textColor;
      if (opts?.scales?.y?.grid)  opts.scales.y.grid.color  = palette.gridColor;

      const tooltip = opts?.plugins?.tooltip;
      if (tooltip) {
        tooltip.backgroundColor = palette.tooltipBgColor;
        tooltip.titleColor = palette.textColor;
        tooltip.bodyColor = palette.textColor;
        tooltip.borderColor = palette.tooltipBorderColor;
      }

      const dataset = lineChart.data?.datasets?.[0];
      if (dataset) {
        dataset.borderColor = palette.accentBorder;
        dataset.backgroundColor = palette.accentFill;
        dataset.borderWidth = palette.accentBorderWidth;
      }

      lineChart.update('none');
    }

    if (donutChart) {
      const opts = donutChart.options;
      const legendLabels = opts?.plugins?.legend?.labels;
      if (legendLabels) { 
        legendLabels.color = palette.textColor;
      }

      const tooltip = opts?.plugins?.tooltip;
      if (tooltip) {
        tooltip.backgroundColor = palette.tooltipBgColor;
        tooltip.titleColor = palette.textColor;
        tooltip.bodyColor = palette.textColor;
        tooltip.borderColor = palette.tooltipBorderColor;
      }

      if (opts?.plugins?.centerTotal) {
        opts.plugins.centerTotal.color = palette.textColor;
      }

      donutChart.update('none');
    }
  }

  const observer = new MutationObserver(() => applyThemeToCharts());
  observer.observe(rootEl, { attributes: true, attributeFilter: ['class', 'data-bs-theme'] });

  async function loadNominationDashboardStats() {
    const eventId = document.getElementById('currentEventId')?.value || '';
    const params = new URLSearchParams();
    if (eventId) params.set('event_id', eventId);
    params.set('days', '30');
    try {
      const res = await fetch(`nominations_metrics.php?${params.toString()}`, { cache: 'no-store' });
      const j = await res.json();
      if (j.status !== 'success') return;
      const raw = j.status_counts || {};
      const inReview =
        Number(raw.in_review ?? raw.under_review ?? raw.review ?? raw.reviewing ?? raw.inreview ?? 0) || 0;
      const set = (id, val) => {
        const el = document.getElementById(id);
        if (el) el.textContent = Number(val || 0).toLocaleString();
      };
      set('nomTotal', j.total ?? 0);
      set('nomPending', raw.pending ?? 0);
      set('nomInReview', inReview);
      set('nomApproved', raw.approved ?? 0);
    } catch (e) {
      console.error('Registration dashboard stats error:', e);
    }
  }

  function toccaDashboardLoadNominations() {
    loadNominationDashboardStats();
    load().then(() => applyThemeToCharts());
  }

  window.toccaDashboardLoadNominations = toccaDashboardLoadNominations;

  document.addEventListener('DOMContentLoaded', () => {
    if (document.documentElement.dataset.dashboardPhase === 'nominations_open') {
      toccaDashboardLoadNominations();
    }
  });
})();