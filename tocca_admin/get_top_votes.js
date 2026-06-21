let topVotesChart = null;
let chartThemeObserver = null;

function isDarkModeEnabled() {
  const root = document.documentElement;
  return root.classList.contains('dark-mode') || root.getAttribute('data-bs-theme') === 'dark';
}

function getChartTheme() {
  const dark = isDarkModeEnabled();
  return {
    textColor: dark ? '#f1f5f9' : '#1f2937',
    gridColor: dark ? 'rgba(148, 163, 184, 0.2)' : 'rgba(148, 163, 184, 0.35)'
  };
}

function applyChartTheme(chart) {
  if (!chart) return;
  const { textColor, gridColor } = getChartTheme();
  const opts = chart.options || {};

  if (opts.plugins?.title) {
    opts.plugins.title.color = textColor;
  }
  if (opts.plugins?.legend?.labels) {
    opts.plugins.legend.labels.color = textColor;
  }

  if (opts.scales?.x) {
    opts.scales.x.ticks = Object.assign({}, opts.scales.x.ticks, { color: textColor });
    opts.scales.x.grid = Object.assign({}, opts.scales.x.grid, { color: gridColor });
  }

  if (opts.scales?.y) {
    opts.scales.y.ticks = Object.assign({}, opts.scales.y.ticks, { color: textColor });
    opts.scales.y.grid = Object.assign({}, opts.scales.y.grid, { color: gridColor });
  }
}

function ensureThemeObserver() {
  if (chartThemeObserver) return;
  const root = document.documentElement;
  chartThemeObserver = new MutationObserver(() => {
    if (!topVotesChart) {
      return;
    }
    applyChartTheme(topVotesChart);
    topVotesChart.update('none');
  });
  chartThemeObserver.observe(root, { attributes: true, attributeFilter: ['class', 'data-bs-theme'] });
}

function loadCategoryChartTotalVotes() {
  const eventId = document.getElementById("currentEventId")?.value;
  const chartCanvas = document.getElementById("categoryChart");
  if (!eventId || !chartCanvas) return;

  fetch(`get_top_votes.php?event_id=${eventId}`)
    .then(res => res.json())
    .then(data => {
      const noDataEl = document.getElementById("noChartData");

      if (data.status === "success" && Array.isArray(data.results) && data.results.length > 0) {
        const labels = data.results.map(r => r.category_name ?? "");
        const voteCounts = data.results.map(r => Number(r.total_votes) || 0);

        const ctx = chartCanvas.getContext("2d");
        if (topVotesChart) topVotesChart.destroy();

        const { textColor, gridColor } = getChartTheme();

        topVotesChart = new Chart(ctx, {
          type: "bar",
          data: {
            labels,
            datasets: [{
              label: "Total Votes per Category",
              data: voteCounts,
              borderWidth: 1
            }]
          },
          options: {
            responsive: true,
            maintainAspectRatio: false,   // <-- respect CSS height
            animation: false,
            layout: { padding: { top: 8, right: 8, bottom: 8, left: 8 } },
            plugins: {
              title: { display: true, text: "Total Votes by Category", color: textColor },
              legend: { display: false },
              tooltip: {
                callbacks: {
                  label: (ctx) => {
                    const v = Number(ctx.raw) || 0;
                    return `${v.toLocaleString()} vote${v === 1 ? "" : "s"}`;
                  }
                }
              }
            },
            scales: {
              y: {
                beginAtZero: true,
                ticks: {
                  callback: (v) => Math.trunc(v).toLocaleString(),
                  color: textColor
                },
                grid: { drawBorder: false, color: gridColor }
              },
              x: {
                ticks: {
                  autoSkip: true,
                  maxTicksLimit: 10,
                  maxRotation: 0,
                  minRotation: 0,
                  color: textColor
                },
                grid: { display: false, color: gridColor }
              }
            }
          }
        });

        applyChartTheme(topVotesChart);
        ensureThemeObserver();

        topVotesChart.update('none');

        chartCanvas.style.display = "block";
        if (noDataEl) noDataEl.style.display = "none";

        // Update dashboard stats if provided
        if (data.dashboard) {
          document.getElementById("totalVotes").textContent        = (data.dashboard.total_votes ?? 0).toLocaleString();
          document.getElementById("registeredVoters").textContent  = (data.dashboard.registered ?? 0).toLocaleString();
          document.getElementById("voted").textContent             = (data.dashboard.voted ?? 0).toLocaleString();
          document.getElementById("notVoted").textContent          = (data.dashboard.not_voted ?? 0).toLocaleString();
        }

      } else {
        if (topVotesChart) { topVotesChart.destroy(); topVotesChart = null; }
        chartCanvas.style.display = "none";
        if (noDataEl) noDataEl.style.display = "block";
      }
    })
    .catch(err => {
      console.error("Chart error:", err);
      if (topVotesChart) { topVotesChart.destroy(); topVotesChart = null; }
      chartCanvas.style.display = "none";
      const noDataEl = document.getElementById("noChartData");
      if (noDataEl) noDataEl.style.display = "block";
    });
}

// Leading business list display
function loadTopVoteResults() {
  const eventId = document.getElementById("currentEventId")?.value;
  if (!eventId) return;

  fetch("get_top_votes.php?event_id=" + eventId)
    .then(res => res.json())
    .then(data => {
      const list = document.getElementById("voteResultList");
      list.innerHTML = "";

      if (data.status === "success" && Array.isArray(data.results)) {
        if (data.results.length === 0) {
          list.innerHTML = `<li class="list-group-item text-muted">No results found.</li>`;
        } else {
          data.results.forEach(item => {
            const li = document.createElement("li");
            li.className = "list-group-item d-flex justify-content-between align-items-center";
            li.innerHTML = `
              <span class="fw-semibold">${item.category_name}</span>
              <span class="text-end" style="max-width: 60%; word-wrap: break-word;">
                <strong>${item.choice_name}</strong><br>
                <small>(${(Number(item.vote_count)||0).toLocaleString()} votes)</small>
              </span>
            `;
            list.appendChild(li);
          });
        }
      } else {
        list.innerHTML = `<li class="list-group-item text-muted">No results found.</li>`;
      }
    })
    .catch(err => {
      console.error("Error loading vote results:", err);
    });
}

function toccaDashboardLoadVoting() {
  loadCategoryChartTotalVotes();
  loadTopVoteResults();
}

window.toccaDashboardLoadVoting = toccaDashboardLoadVoting;

document.addEventListener("DOMContentLoaded", () => {
  const phase = document.documentElement.dataset.dashboardPhase;
  if (phase === "voting_open") {
    toccaDashboardLoadVoting();
  }
});
