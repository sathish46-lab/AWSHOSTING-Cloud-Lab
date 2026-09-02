/**
 * Wrapped with IIFE Error Boundary
 */
try {
  (function() {
    "use strict";


/**
 * ============================================================================
 * TOM LABS - LAB DASHBOARD CONTROLLER
 * ============================================================================
 * Handles all lab-specific operations: deployment, monitoring, UI updates
 *
 * Dependencies:
 * - mq-client.js (OverviewSocket, LogSocket, ActivityTracker)
 * - chart.js
 * - coreui.js
 *
 * ============================================================================
 */

const Dashboard = {
  /* ========================================================================
   * STATE MANAGEMENT
   * ====================================================================== */
  isProcessing: false,
  statsInterval: null,
  charts: {},
  historyLimit: 20,
  isFirstLoad: true,

  /* ========================================================================
   * INITIALIZATION
   * ====================================================================== */

  /**
   * Main initialization - called on page load
   */
  init: function () {
    console.log("[Dashboard] Initializing...");

    // 1. Initialize Charts
    this.initCharts();

    // 2. Initial Terminal Setup
    this.resetTerminal();
    this.appendCommand("");

    // 3. Start Services
    this.initSockets();
    this.startStatsPolling();

    // 4. Initialize Optimization Tracker
    if (typeof ActivityTracker !== "undefined") {
      ActivityTracker.init();
    }

    console.log("[Dashboard] Initialization complete.");
  },

  /* ========================================================================
   * SOCKET MANAGEMENT
   * ====================================================================== */

  /**
   * Toggle button loading state
   * @param {HTMLElement} btn 
   * @param {boolean} show 
   */
  escapeHtml: function (str) {
    const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
    return String(str).replace(/[&<>"']/g, c => map[c]);
  },

  toggleLoading: function (btn, show) {
    if (!btn) return;

    if (show) {
      const type = btn.getAttribute('data-coreui-spinner-type') || 'border';
      const spinner = `<span class="spinner-${type} spinner-${type}-sm me-2" role="status" aria-hidden="true"></span>`;

      if (!btn.dataset.originalContent) {
        btn.dataset.originalContent = btn.innerHTML;
      }

      btn.classList.add('disabled');
      // Fix: Only show Spinner + Text (Hide original Icon)
      btn.innerHTML = spinner + btn.textContent.trim();
    } else {
      if (btn.dataset.originalContent) {
        btn.innerHTML = btn.dataset.originalContent;
      }
      btn.classList.remove('disabled');
    }
  },

  /**
   * Initialize Socket Connections (Overview and Instance Logs)
   */
  initSockets: function () {
    // console.log("[Dashboard] Initializing sockets...");

    // 1. Connect to Global Sidebar Stats (Overview)
    try {
      if (!OverviewSocket.isConnected && document.getElementById("sidebar-stats-container") && !document.getElementById("session-expired-overlay")) {
        OverviewSocket.connect("/stats-ws", (data) =>
          this.updateSidebar(data),
        );
      }
    } catch (e) {
      console.error("[Dashboard] OverviewSocket connection failed:", e);
    }

    // 2. Connect to Instance Logs (Only if session exists and not expired)
    try {
      const dot = document.getElementById("mq-status-dot");
      if (window.SESSION_HASH && !document.getElementById("session-expired-overlay")) {
        if (!LogSocket.isConnected) {
          // Fresh connect — pass dot reference
          const ui = dot ? { dot: dot } : null;
          LogSocket.connect(
            `logs.${window.SESSION_HASH}`,
            (data) => this.appendLog(data),
            ui,
          );
        } else if (dot) {
          // Already connected — just update the dot color to green
          dot.style.color = "#a6e3a1";
        }
      }
    } catch (e) {
      console.error("[Dashboard] LogSocket connection failed:", e);
    }
  },

  /* ========================================================================
   * CHART MANAGEMENT
   * ====================================================================== */

  /**
   * Initialize all monitoring charts
   */
  initCharts: function () {
    // Destroy any existing charts first (prevents "Canvas is already in use" on HTMX swap)
    if (this.charts) {
      Object.keys(this.charts).forEach(id => {
        if (this.charts[id]) {
          this.charts[id].destroy();
          delete this.charts[id];
        }
      });
    }

    const create = (id, color, type = "line", extraOptions = {}) => {
      const ctx = document.getElementById(id);
      if (!ctx) return;

      const isBar = type === "bar";
      const limit = extraOptions.limit || this.historyLimit;

      const datasets = extraOptions.datasets || [
        {
          data: new Array(limit).fill(0),
          borderColor: color,
          backgroundColor: isBar ? color : "transparent",
          borderWidth: isBar ? 0 : 2,
          pointRadius: 0,
          tension: extraOptions.tension !== undefined ? extraOptions.tension : 0.4,
          fill: extraOptions.fill || false,
          barThickness: extraOptions.barThickness || 'flex',
          borderRadius: 2
        },
      ];

      this.charts[id] = new Chart(ctx, {
        type: type,
        data: {
          labels: new Array(limit).fill(""),
          datasets: datasets,
        },
        options: {
          maintainAspectRatio: false,
          animation: {
            duration: 300,
            easing: 'linear'
          },
          plugins: {
            legend: { display: false },
            tooltip: { enabled: false }
          },
          scales: {
            x: { display: false },
            y: {
              display: false,
              suggestedMin: 0
            }
          },
          ...extraOptions.options
        },
      });
      this.charts[id].limit = limit;
    };

    // SNA Colors
    const colors = {
      cyan: "#00e5ff",
      green: "#00ff88",
      yellow: "#ffcc00",
      red: "#ff4444",
      blue: "#3d5afe"
    };

    // Initialize all charts with SNA-style configurations
    // Net IO: Download (Purple) & Upload (Cyan)
    create("chart-net-io", "#8b91f9", "line", {
      tension: 0.4,
      limit: 30,
      datasets: [
        { label: 'Download', borderColor: "#8b91f9", data: [], borderWidth: 2, pointRadius: 0, tension: 0.4 },
        { label: 'Upload', borderColor: "#50c7f6", data: [], borderWidth: 2, pointRadius: 0, tension: 0.4 }
      ]
    });

    // Block IO: Read (Yellow) & Write (Green)
    create("chart-block-io", "#f2b90d", "line", {
      tension: 0.4,
      limit: 30,
      datasets: [
        { label: 'Read', borderColor: "#f2b90d", data: [], borderWidth: 2, pointRadius: 0, tension: 0.4 },
        { label: 'Write', borderColor: "#55b16e", data: [], borderWidth: 2, pointRadius: 0, tension: 0.4 }
      ]
    });

    // Averages use varying densities and wide bars
    create("chart-avg-1", colors.blue, "bar", { barThickness: 15, limit: 6 });
    create("chart-avg-5", colors.yellow, "bar", { barThickness: 4, limit: 15 });
    create("chart-avg-15", colors.green, "bar", { barThickness: 4, limit: 15 });

    // History uses jagged line charts
    create("chart-peak-cpu", colors.cyan, "line", { tension: 0.4, limit: 30 });
    create("chart-max-pid", colors.red, "line", {
      tension: 0.4,
      limit: 30,
      options: {
        scales: {
          y: {
            display: false,
            suggestedMin: 0,
            suggestedMax: 150 // Better context for standard PID counts (~80-100)
          }
        }
      }
    });
    create("chart-high-mem", colors.yellow, "line", { tension: 0.4, limit: 30 });
  },

  /**
   * Push new data point to a chart
   * @param {string} id - Chart ID
   * @param {number} value - Data value
   */
  pushChartData: function (id, value) {
    const chart = this.charts[id];
    if (!chart) return;

    const limit = chart.limit || this.historyLimit;

    // Handle multi-dataset vs single dataset
    if (Array.isArray(value)) {
      value.forEach((v, i) => {
        if (chart.data.datasets[i]) {
          const d = chart.data.datasets[i].data;
          d.push(v);
          if (d.length > limit) d.shift();
        }
      });
    } else {
      const d = chart.data.datasets[0].data;
      d.push(value);
      if (d.length > limit) d.shift();
    }

    chart.update("none");
  },

  /* ========================================================================
   * UI UPDATES - SIDEBAR
   * ====================================================================== */

  /**
   * Update sidebar stats from MQ data
   * @param {object} data - System stats from MQ
   */
  updateSidebar: function (data) {
    // Remove loading state on first update
    const container = document.getElementById("sidebar-stats-container");
    if (container && container.classList.contains("loading-state")) {
      container.classList.remove("loading-state");
      container.querySelectorAll(".progress-bar").forEach((el) => {
        el.classList.remove("placeholder-shimmer");
        el.style.width = "0%";
      });
    }

    // Update CPU bars
    if (data.cpu) {
      data.cpu.forEach((val, i) => {
        const bar = document.querySelector(`.cpu-${i}`);
        if (bar) bar.style.width = val + "%";
      });

      const avg = (
        data.cpu.reduce((a, b) => a + b, 0) / data.cpu.length
      ).toFixed(2);
      document.getElementById("sidebar-cpu-val").innerText = avg + "%";
    }

    // Update Load Average
    if (data.loadavg) {
      document.getElementById("sidebar-load-val").innerText =
        `Load Avg: ${data.loadavg[0].toFixed(2)}, ${data.loadavg[1].toFixed(2)}, ${data.loadavg[2].toFixed(2)}`;
    }

    // Update Memory
    if (data.mem) {
      const used = (data.mem.used / 1073741824).toFixed(2);
      const total = (data.mem.total / 1073741824).toFixed(2);
      const avail = (data.mem.available / 1073741824).toFixed(2);
      const perc = (data.mem.used / data.mem.total) * 100;

      document.getElementById("sidebar-mem-bar").style.width = perc + "%";
      document.getElementById("sidebar-mem-details").innerText =
        `${used} GiB / ${total} GiB Avail: ${avail} GiB`;
    }

    // Update Swap
    if (data.swap) {
      const sUsed = (data.swap.used / 1048576).toFixed(2);
      const sTotal = (data.swap.total / 1073741824).toFixed(2);
      const sFree = (data.swap.free / 1073741824).toFixed(2);

      document.getElementById("sidebar-swap-bar").style.width =
        data.swap.percent + "%";
      document.getElementById("sidebar-swap-details").innerText =
        `${sUsed} MiB / ${sTotal} GiB Free: ${sFree} GiB`;
    }
  },

  /* ========================================================================
   * UI UPDATES - INSTANCE STATS
   * ====================================================================== */

  /**
   * Start polling instance stats from API
   */
  startStatsPolling: function () {
    // 1. Only poll if we have a valid session hash (Dashboard Page)
    if (!window.SESSION_HASH) {
      // console.log("[Dashboard] No active session hash found. Stats polling disabled.");
      return;
    }

    const start = () => {
      if (this.statsInterval) clearInterval(this.statsInterval);

      const poll = () => {
        if (document.hidden) return;

        fetch(`/api/labs/stats?hash=${window.SESSION_HASH}`)
          .then((res) => res.json())
          .then((data) => {
            if (data.status === "paused") {
              // Lab is paused — show idle state, stop polling
              this.updateUIIdle();
              if (this.statsInterval) {
                clearInterval(this.statsInterval);
                this.statsInterval = null;
              }
            } else if (data.status === "offline" || data.status === "initializing") {
              this.updateUIIdle();
            } else {
              this.updateUI(data);
            }
          })
          .catch((err) => {
            console.warn("Stats Fetch Error:", err.message);
            this.updateUIIdle();
          });
      };

      poll(); // Initial call
      this.statsInterval = setInterval(poll, 5000); // Standard 5s polling to save resources
    };

    const stop = () => {
      if (this.statsInterval) {
        clearInterval(this.statsInterval);
        this.statsInterval = null;
      }
    };

    // 2. Handle Visibility & HTMX Navigation Changes to save resources
    document.addEventListener("visibilitychange", () => {
      if (document.hidden) {
        stop();
      } else {
        start();
      }
    });

    document.addEventListener("htmx:beforeSwap", () => {
      stop();
    });

    // Start initially if visible
    if (!document.hidden) {
      start();
    }
  },

  /**
   * Update UI with live stats
   * @param {object} data - Stats from API
   */
  updateUI: function (data) {
    const safeSetText = (id, text) => { const el = document.getElementById(id); if (el) el.innerText = text; };
    const safeSetWidth = (id, width) => { const el = document.getElementById(id); if (el) el.style.width = width; };

    // Update text stats
    const pidContainer = document.getElementById("stat-pid-container");
    if (pidContainer) pidContainer.style.display = "block";
    
    safeSetText("stat-cpu-usage", data.CPUPerc);
    safeSetWidth("stat-cpu-bar", data.CPUPerc);
    safeSetText("stat-pid-count", data.PIDs);
    safeSetText("stat-cpu-throttled", data.CPUThrottled || "0%");
    safeSetText("stat-mem-perc", data.MemPerc);
    safeSetWidth("stat-mem-bar", data.MemPerc);
    safeSetText("stat-mem-info", data.MemUsage);

    safeSetText("stat-load-1", parseFloat(data.Load1).toFixed(4));
    safeSetText("stat-load-5", parseFloat(data.Load5).toFixed(4));
    safeSetText("stat-load-15", parseFloat(data.Load15).toFixed(4));

    safeSetText("stat-peak-cpu", data.PeakCPU);
    safeSetText("stat-max-pid", data.MaxPID);
    safeSetText("stat-high-mem", data.HighMem);
    safeSetText("stat-net-io", data.NetIO);
    safeSetText("stat-block-io", data.BlockIO);

    // Update charts (first load vs. incremental)
    if (this.isFirstLoad && data.cpu_h) {
      const mapping = {
        "chart-peak-cpu": data.cpu_h,
        "chart-high-mem": data.mem_h,
        "chart-net-io": data.net_h,
        "chart-block-io": data.block_h,
        "chart-max-pid": data.pids_h,
        "chart-avg-1": data.l1_h,
        "chart-avg-5": data.l5_h,
        "chart-avg-15": data.l15_h,
      };

      for (let id in mapping) {
        if (this.charts[id])
          this.charts[id].data.datasets[0].data = mapping[id];
      }

      Object.values(this.charts).forEach((c) => c.update());
      this.isFirstLoad = false;
    } else {
      // Incremental updates
      // Parse composite strings for IO charts (e.g., "1.46kB / 358B")
      const parseIO = (str) => {
        if (!str) return [0, 0];
        const parts = str.split(' / ').map(p => parseFloat(p) || 0);
        return parts.length === 2 ? parts : [parts[0] || 0, 0];
      };

      this.pushChartData("chart-net-io", parseIO(data.NetIO));
      this.pushChartData("chart-block-io", parseIO(data.BlockIO));

      this.pushChartData("chart-peak-cpu", parseFloat(data.CPUPerc));
      this.pushChartData("chart-high-mem", parseFloat(data.MemPerc));
      this.pushChartData("chart-max-pid", parseInt(data.PIDs));

      this.pushChartData("chart-avg-1", data.Load1);
      this.pushChartData("chart-avg-5", data.Load5);
      this.pushChartData("chart-avg-15", data.Load15);
    }

    // Update status badge
    const badgeArea = document.getElementById("badge-area");
    if (badgeArea && !badgeArea.innerHTML.includes("Running")) {
      badgeArea.innerHTML = `<span class="badge text-bg-success border-0 px-2 py-1 small pulse">Running</span>`;
    }
  },

  /**
   * Reset UI to idle/offline state
   */
  updateUIIdle: function () {
    const resets = {
      "stat-cpu-usage": "0.00%",
      "stat-cpu-throttled": "0%",
      "stat-mem-perc": "0.00%",
      "stat-load-1": "0.0000",
      "stat-load-5": "0.0000",
      "stat-load-15": "0.0000",
      "stat-peak-cpu": "0.00%",
      "stat-net-io": "0B / 0B",
      "stat-block-io": "0B / 0B",
      "stat-max-pid": "0",
      "stat-high-mem": "0.00 MB",
    };

    for (let id in resets) {
      const el = document.getElementById(id);
      if (el) el.innerText = resets[id];
    }

    ["stat-cpu-bar", "stat-mem-bar"].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.style.width = "0%";
    });

    const badgeArea = document.getElementById("badge-area");
    if (badgeArea) {
      badgeArea.innerHTML = `<span class="badge text-bg-danger border-0 px-2 py-1 small">Offline</span>`;
    }

    const pidContainer = document.getElementById("stat-pid-container");
    if (pidContainer) pidContainer.style.display = "none";

    // Reset charts
    Object.values(this.charts).forEach((chart) => {
      chart.data.datasets.forEach((dataset) => {
        dataset.data = new Array(this.historyLimit).fill(null);
      });
      chart.update();
    });
  },

  /* ========================================================================
   * TERMINAL / LOG MANAGEMENT
   * ====================================================================== */

  /**
   * Clear terminal display
   */
  resetTerminal: function () {
    const container = document.getElementById("live-logs-container");
    if (container) container.innerHTML = "";
  },

  /**
   * Append a command prompt line to terminal
   * @param {string} cmd - Command text
   */
  appendCommand: function (cmd) {
    const container = document.getElementById("live-logs-container");
    if (!container) return;

    const user = window.LAB_USER || "tom";
    const host = "Tomlabs";
    const div = document.createElement("div");
    div.className = "log-entry term-cmd-entry";
    div.innerHTML = `<span class="term-user">${Dashboard.escapeHtml(user)}</span>@<span class="term-host" style="color:#FFA500;">${Dashboard.escapeHtml(host)}</span> <span class="term-symbol">$</span> <span class="text-white">${Dashboard.escapeHtml(cmd)}</span>`;
    container.appendChild(div);
  },

  /**
   * Append a log message to terminal (called from MQ)
   * @param {object|string} data - Log data from MQ
   */
  appendLog: function (data) {
    const container = document.getElementById("live-logs-container");
    if (!container) return;

    // Handle structured progress from backend: {progress: pct, label: label}
    if (data && typeof data.progress === 'number') {
      if (typeof DeployProgress !== 'undefined') {
        const status = data.progress >= 100 ? 'completed' : 'running';
        DeployProgress.smoothUpdate(data.progress, data.label, status);
        if (data.progress >= 100) {
          DeployProgress.active = false;
          setTimeout(() => window.location.reload(), 2000);
        }
      }
      return; // Progress messages don't go into the log panel
    }

    // Extract message
    let msg = data.log || data.message || data;

    // Parse for deploy progress (fallback: log-pattern matching)
    if (typeof DeployProgress !== 'undefined') {
      DeployProgress.parseLog(msg);
    }

    // Clean up message formatting
    msg = msg.replace(/^\[\d{2}:\d{2}:\d{2}\]\s*/, "");
    msg = msg.replace(/^(\[\*\]\s*)+/, "[*] ");
    msg = msg.replace(/^(\[!\]\s*)+/, "[!] ");

    // Duplicate detection - skip if last log is identical
    const lastEntry = container.lastElementChild;
    if (lastEntry && lastEntry.innerText === msg) return;

    // Create log entry
    const div = document.createElement("div");
    div.className = "log-entry";

    // Color coding
    if (msg.startsWith("[✓]")) div.style.color = "#a6e3a1";
    if (msg.startsWith("[!]")) {
        div.style.color = "#f38ba8";
        // Show TomNotify for critical background errors
        if (msg.includes("Lab is not running")) {
            if (window.TomNotify) TomNotify.show("Lab is not running. Please start it first.", "Error", "warning", 5000);
        }
    }

    div.innerText = msg;
    container.appendChild(div);

    // Auto-scroll to bottom
    const viewport = document.getElementById("terminal-viewport");
    if (viewport) viewport.scrollTop = viewport.scrollHeight;

    // Check for completion messages
    const lower = msg.toLowerCase();
    if (
      msg.includes("[*] reload") ||
      (msg.includes("[✓]") &&
        (lower.includes("deployment complete") ||
          lower.includes("successfully") ||
          lower.includes("graceful shutdown") ||
          lower.includes("now offline") ||
          lower.includes("complete")))
    ) {
      // Sequence finished
      this.isProcessing = false;

      // Update ActivityTracker
      if (typeof ActivityTracker !== "undefined") {
        ActivityTracker.setProcessing(false);
      }

      // Increased delay to 4s to ensure DB writes are fully committed/visible to PHP
      setTimeout(() => {
        if (typeof htmx !== 'undefined') {
          htmx.ajax('GET', location.href, '#main-content');
        } else {
          location.reload();
        }
      }, 4000);
    }
  },
};

/* ============================================================================
 * ON-DEMAND DATA FETCHING (security: no credentials in page source)
 * ========================================================================== */
const LabData = {
  _root: null,

  getRoot() {
    if (!this._root) this._root = document.getElementById('lab-data-root');
    return this._root;
  },

  getDomainUsage() {
    const el = this.getRoot();
    if (!el) return {};
    try { return JSON.parse(el.dataset.domainUsage || '{}'); } catch (e) { return {}; }
  },

  getConfig() {
    const el = this.getRoot();
    if (!el) return null;
    try { return JSON.parse(el.dataset.labConfig || 'null'); } catch (e) { return null; }
  }
};

/* ============================================================================
 * LAB TYPE CONFIGURATION
 * Adding a new lab type = add ONE entry here. No more if/else chains.
 * ========================================================================== */
const LAB_FIELD_CONFIG = {
  minio:   { minio: 'block', n8n: 'none', vsc: 'none', gui: 'none', expose: 'none', domainSel: 'none', proxies: 'none' },
  n8n:     { minio: 'none', n8n: 'block', vsc: 'none', gui: 'none', expose: 'none', domainSel: 'none', proxies: 'none' },
  gui_essentials: { minio: 'none', n8n: 'none', vsc: 'none', gui: 'flex', expose: 'none', domainSel: 'none', proxies: 'none' },
  // default covers essentials, docker, kali, zephyr, etc.
  default: { minio: 'none', n8n: 'none', vsc: 'flex', gui: 'none', expose: 'flex', proxies: 'block' },
};

const LAB_FORM_CONFIG = {
  minio:   ['minio_console_domain', 'minio_api_domain'],
  n8n:     ['n8n_domain'],
  gui_essentials: ['gui_domain_selector'],
  default: [],
};

const LAB_ACTION_CONFIG = {
  minio:   { launch: 'minioModal' },
  n8n:     { launch: 'n8n_url' },
  default: { launch: 'codeInfoModal' },
};

function getLabFieldConfig(type) {
  return LAB_FIELD_CONFIG[type] || LAB_FIELD_CONFIG.default;
}

function getLabFormFields(type) {
  return LAB_FORM_CONFIG[type] || LAB_FORM_CONFIG.default;
}

function setLabFieldVisibility(type) {
  const cfg = getLabFieldConfig(type);
  const ids = {
    minio: 'minio_domain_wrapper',
    n8n: 'n8n_domain_wrapper',
    vsc: 'vsc_domain_wrapper',
    gui: 'gui_domain_wrapper',
    expose: 'expose_web_wrapper',
    proxies: 'http_proxies_wrapper',
  };
  for (const [key, display] of Object.entries(cfg)) {
    const el = document.getElementById(ids[key]);
    if (el) el.style.display = display;
  }
  // Domain selection visibility depends on expose_web toggle
  const wrapper = document.getElementById('domain_selection_wrapper');
  if (wrapper) {
    const exposeToggle = document.getElementById('expose_web_toggle');
    const isExposed = exposeToggle ? exposeToggle.value === 'true' : false;
    wrapper.style.display = isExposed ? 'flex' : 'none';
  }
}

/* ============================================================================
 * LAB ACTION HANDLERS
 * ========================================================================== */
/**
 * handleDeploy — fetches modal HTML on-demand via API (security: no credentials in page source)
 */
async function handleDeploy(btn, labType) {
  if (Dashboard.isProcessing) return;

  Dashboard.toggleLoading(btn, true);

  try {
    const type = labType || window.LAB_TYPE || "essentials";
    const hash = window.SESSION_HASH;

    // 1. Fetch modal content on-demand
    const response = await fetch(`/api/labs/redeploy?hash=${hash}&_t=${Date.now()}`, {
      credentials: 'same-origin'
    });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const html = await response.text();

    // 2. Remove any previous redeployModal, then inject into body
    const oldModal = document.getElementById('redeployModal');
    if (oldModal) oldModal.remove();
    document.body.insertAdjacentHTML('beforeend', html);

    // 3. Reset dropdown state
    const domainDropdown = document.getElementById("domain_dropdown");
    if (domainDropdown) domainDropdown.style.display = "none";

    const dropdownArrow = document.getElementById("dropdown_arrow");
    if (dropdownArrow) {
      dropdownArrow.classList.remove("bx-chevron-up");
      dropdownArrow.classList.add("bx-chevron-down");
    }

    // 4. Config-driven field visibility
    setLabFieldVisibility(type);

    // 5. Bind confirm button
    document.getElementById("redeploy-confirm-btn").onclick = () => executeRedeploy(type);

    // 6. Show modal
    new coreui.Modal(document.getElementById("redeployModal")).show();

    // 7. Draw chips from existing PHP-checked boxes
    setTimeout(() => {
      updateSelectedDomains();
      updateDomainAvailability();
    }, 200);
  } catch (e) {
    console.error("Deploy Error:", e);
    if (typeof TomNotify !== 'undefined') {
      TomNotify.show("Failed to load deploy form: " + e.message, "Error", "danger", 5000);
    }
  } finally {
    Dashboard.toggleLoading(btn, false);
  }
}

/**
 * Executes the actual POST request
 */
async function executeRedeploy(labType) {
  const modalEl = document.getElementById("redeployModal");
  const type = labType || window.LAB_TYPE || "essentials";
  const modal = coreui.Modal.getInstance(
    document.getElementById("redeployModal"),
  );

  // 1. Collect form data
  const cfg = getLabFieldConfig(type);
  const vscEl = document.getElementById("vsc_domain_selector");
  const guiEl = document.getElementById("gui_domain_selector");
  const vscDomain = (cfg.vsc !== 'none' && vscEl) ? vscEl.value : "";
  const guiDomain = (cfg.gui !== 'none' && guiEl) ? guiEl.value : "";
  const exposeToggleEl = document.getElementById("expose_web_toggle");
  const exposeWeb = exposeToggleEl ? exposeToggleEl.value : "false";
  const checkedDomains = modalEl.querySelectorAll(".domain-selector:checked");
  const domains = Array.from(checkedDomains).map((cb) => cb.value);

  // Collect lab-specific domain fields (config-driven, only visible)
  const labFields = getLabFormFields(type);
  const labFormData = {};
  labFields.forEach(fieldId => {
    const el = document.getElementById(fieldId);
    if (el && el.offsetParent !== null) labFormData[fieldId] = el.value;
  });

  // Collect proxy inputs if present
  const proxyPorts = Array.from(modalEl.querySelectorAll("input[name='deploy_proxy_port[]']")).map(el => el.value);
  const proxyDomains = Array.from(modalEl.querySelectorAll("select[name='deploy_proxy_domain[]']")).map(el => el.value);

  modal.hide();
  Dashboard.isProcessing = true;
  if (typeof ActivityTracker !== "undefined")
    ActivityTracker.setProcessing(true);

  // Add Grow Animation to Deployment Button (matches Stop/Launch behavior)
  const deployBtn = document.getElementById("btn-deploy-action"); // Modal button
  if (deployBtn) {
    deployBtn.classList.add("disabled");
    deployBtn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-2" role="status" aria-hidden="true"></span> Processing';
  }

  // ALSO trigger the main header redeploy button animation
  const headerRedeployBtn = document.querySelector('.btn-lab-deploy');
  if (headerRedeployBtn) {
    Dashboard.toggleLoading(headerRedeployBtn, true);
  }

  try {
    // 2. Log to Terminal (Now shows 'minio' correctly)
    Dashboard.resetTerminal();
    Dashboard.appendCommand(
      `labsctl redeploy ${type} --hash=${window.SESSION_HASH}`,
    );

    // 3. Handshake with PHP API
    const formData = new URLSearchParams();
    formData.append("lab", type);
    formData.append("hash", window.SESSION_HASH);
    formData.append("expose_web", exposeWeb);
    formData.append("code_domain", vscDomain);

    // Append selected IP
    const ipEl = document.getElementById("reallocate_ip_selector");
    if (ipEl) formData.append("internal_ip", ipEl.value);

    // Append lab-specific domain fields
    for (const [fieldId, value] of Object.entries(labFormData)) {
      formData.append(fieldId, value);
    }

    domains.forEach((d) => formData.append("domains[]", d));

    proxyPorts.forEach((port, idx) => {
      if (port && proxyDomains[idx]) {
        formData.append("deploy_proxy_port[]", port);
        formData.append("deploy_proxy_domain[]", proxyDomains[idx]);
      }
    });

    const response = await fetch("/api/labs/deploy", {
      method: "POST",
      body: formData,
    });

    const data = await response.json();

    if (data.status === 'success' && data.hash) {
      // UPDATE GLOBAL HASH
      window.SESSION_HASH = data.hash;

      // RECONNECT SOCKET to ensure we are listening to the active channel
      console.log("[Dashboard] specific socket reconnecting to: " + data.hash);
      LogSocket.disconnect();
      setTimeout(() => {
        LogSocket.connect("logs." + data.hash, (d) => Dashboard.appendLog(d));
      }, 100);
    }

    Dashboard.appendLog("[*] Handshake accepted. Starting stream...");
    
    // Start progress tracking (parses logs from WebSocket)
    DeployProgress.start();
  } catch (e) {
    console.error("Redeploy Error:", e);
    Dashboard.appendLog("[!] Redeploy failed: " + e.message);
    Dashboard.isProcessing = false;
    DeployProgress.hide();
    if (headerRedeployBtn) Dashboard.toggleLoading(headerRedeployBtn, false);
    if (deployBtn) { deployBtn.classList.remove("disabled"); deployBtn.innerHTML = '<i class="bx bx-refresh fs-6 text-dark"></i> <span class="small text-dark">Redeploy</span>'; }
  }
}

/**
 * Deployment Progress Tracker
 * Parses live WebSocket logs to calculate progress percentage
 */
const DeployProgress = {
  active: false,
  progress: 0,
  
  // Deploy step patterns mapped to percentage
  steps: [
    [/Deployment initiated/i, 5, 'Initializing'],
    [/Fetching lab metadata/i, 8, 'Loading metadata'],
    [/Starting deployment for user/i, 10, 'Starting'],
    [/Instance ID:/i, 12, 'Preparing'],
    [/Reusing existing lab IP|Assigned Docker IP/i, 15, 'Assigning IP'],
    [/Checking for conflicting containers/i, 18, 'Checking containers'],
    [/No existing container|Removing existing container/i, 20, 'Cleaning up'],
    [/Storage preserved/i, 25, 'Preserving storage'],
    [/Clearing stale VPN/i, 28, 'Clearing VPN'],
    [/Removing stale WireGuard/i, 30, 'Removing old peer'],
    [/Peer removed/i, 32, 'Peer removed'],
    [/Reusing existing keys|Generating new/i, 35, 'Configuring keys'],
    [/Peer re-registered/i, 38, 'Peer registered'],
    [/Provisioning/i, 40, 'Provisioning'],
    [/Waiting for container/i, 45, 'Starting container'],
    [/Configuring network routing/i, 50, 'Configuring network'],
    [/Routing and firewall configured/i, 55, 'Firewall ready'],
    [/Optimizing Apache/i, 58, 'Configuring Apache'],
    [/Configuring user environment/i, 60, 'Setting up user'],
    [/Syncing ssh/i, 62, 'Syncing SSH'],
    [/Starting user configuration/i, 65, 'Creating user'],
    [/User .* created/i, 68, 'User created'],
    [/System password set/i, 70, 'Password set'],
    [/SSH configured/i, 72, 'SSH ready'],
    [/Bash environment/i, 74, 'Shell ready'],
    [/Configuring WireGuard tunnel/i, 76, 'Setting up VPN'],
    [/WireGuard configured/i, 80, 'VPN ready'],
    [/Configuring persistent storage/i, 82, 'Linking storage'],
    [/Storage links configured/i, 85, 'Storage ready'],
    [/Setting up Code-Server/i, 88, 'Starting Code-Server'],
    [/Code-server started/i, 90, 'Code-Server ready'],
    [/Applying firewall rules/i, 92, 'Applying firewall'],
    [/Firewall rules applied/i, 94, 'Firewall ready'],
    [/Finalizing Traefik/i, 96, 'Configuring proxy'],
    [/Traefik config written/i, 98, 'Proxy configured'],
    [/Deployment Complete|Deploy complete|Access URL:/i, 100, 'Complete'],
  ],
  
  show() {
    const el = document.getElementById('deploy-progress-container');
    if (el) el.classList.remove('d-none');
  },
  
  hide() {
    const el = document.getElementById('deploy-progress-container');
    if (el) el.classList.add('d-none');
  },
  
  update(progress, label, status) {
    this.progress = progress;
    const bar = document.getElementById('deploy-progress-bar');
    const pct = document.getElementById('deploy-progress-percent');
    const lbl = document.getElementById('deploy-progress-label');
    const icon = document.getElementById('deploy-progress-icon');
    
    if (bar) {
      bar.style.width = progress + '%';
      bar.setAttribute('aria-valuenow', progress);
      bar.classList.remove('progress-bar-striped', 'progress-bar-animated', 'bg-success', 'bg-danger');
      if (status === 'running') {
        bar.classList.add('progress-bar-striped', 'progress-bar-animated');
        bar.style.background = 'linear-gradient(90deg, #00d4ff, #00ff88)';
      } else if (status === 'completed') {
        bar.classList.add('bg-success');
        bar.style.background = '';
      } else if (status === 'failed') {
        bar.classList.add('bg-danger');
        bar.style.background = '';
      }
    }
    if (pct) pct.textContent = progress + '%';
    if (lbl) lbl.textContent = label || 'Deploying...';
    if (icon) {
      if (status === 'completed') icon.textContent = '✅';
      else if (status === 'failed') icon.textContent = '❌';
      else icon.textContent = '🚀';
    }
  },
  
  // Called by Dashboard.appendLog for each new log line
  parseLog(line) {
    if (!this.active) return;
    
    for (const [pattern, pct, label] of this.steps) {
      if (pattern.test(line) && pct > this.progress) {
        this.update(pct, label, 'running');
        break;
      }
    }
    
    // Check completion
    if (/Deployment Complete|Deploy complete|Access URL:/i.test(line)) {
      this.update(100, 'Complete', 'completed');
      this.active = false;
      setTimeout(() => window.location.reload(), 2000);
    }
    
    // Check failure
    if (/\[!\].*failed|Error:/i.test(line)) {
      this.update(this.progress, 'Failed', 'failed');
      this.active = false;
    }
  },
  
  // Smooth progress animation — interpolates between current and target
  _animFrame: null,
  _targetPct: 0,
  
  smoothUpdate(targetPct, label, status) {
    this._targetPct = targetPct;
    if (this._animFrame) cancelAnimationFrame(this._animFrame);
    
    const animate = () => {
      const diff = this._targetPct - this.progress;
      if (Math.abs(diff) < 0.5) {
        this.update(this._targetPct, label, status);
        return;
      }
      // Ease towards target (speed proportional to distance)
      this.progress += diff * 0.15;
      this.update(Math.round(this.progress), label, status);
      this._animFrame = requestAnimationFrame(animate);
    };
    animate();
  },
  
  start() {
    this.active = true;
    this.progress = 0;
    this.show();
    this.update(0, 'Starting deployment...', 'running');
  },
  
  stop() {
    this.active = false;
    setTimeout(() => this.hide(), 3000);
  }
};

/**
 * Handle Stop button click - Show Modal
 */
function handleStop() {
  if (Dashboard.isProcessing) return;
  const modalEl = document.getElementById("stopModal");
  if (!modalEl) { console.error("stopModal element not found"); return; }
  let modal = coreui.Modal.getInstance(modalEl);
  if (!modal) modal = new coreui.Modal(modalEl, { backdrop: true, keyboard: true });
  document.getElementById("stop-confirm-btn").onclick = () => executeStop();
  modal.show();
}

/**
 * Execute the actual stop request
 */
async function executeStop() {
  const modalEl = document.getElementById("stopModal");
  const modal = coreui.Modal.getInstance(modalEl);
  const btn = document.getElementById("stop-confirm-btn");
  const headerBtn = document.getElementById("btn-stop-action");
  const type = window.LAB_TYPE || "essentials";

  modal.hide();
  Dashboard.isProcessing = true;
  if (typeof ActivityTracker !== "undefined") {
    ActivityTracker.setProcessing(true);
  }

  // Trigger header button animation too
  if (headerBtn) Dashboard.toggleLoading(headerBtn, true);

  // Update modal button state (if visible)
  if (btn) {
    btn.classList.add("disabled");
    btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-2" role="status" aria-hidden="true"></span> Stopping...';
  }

  Dashboard.resetTerminal();
  Dashboard.appendCommand(`labsctl stop ${type} --hash=${window.SESSION_HASH}`);

  // Wait a bit then stop
  await new Promise((r) => setTimeout(r, 300));
  Dashboard.appendLog("[*] Analyzing active session hooks...");

  try {
    const response = await fetch("/api/labs/stop", {
      method: "POST",
      body: new URLSearchParams({
        lab: type,
        hash: window.SESSION_HASH
      }),
    });

    const data = await response.json();
    if (data.status === 'success') {
      Dashboard.appendLog("[*] Shutdown signal acknowledged. Streaming logs...");
    } else {
      Dashboard.appendLog(`[!] Error: ${data.error || 'Shutdown request failed'}`);
      Dashboard.isProcessing = false;
      if (headerBtn) Dashboard.toggleLoading(headerBtn, false);
    }
  } catch (e) {
    console.error("Stop Error:", e);
    if (headerBtn) Dashboard.toggleLoading(headerBtn, false);
    Dashboard.isProcessing = false;
  }
}

/**
 * Pause Lab - Freeze processes, keep memory, zero CPU
 */
function handlePause() {
  if (Dashboard.isProcessing) return;
  const modalEl = document.getElementById("pauseModal");
  if (!modalEl) {
    // No modal found, execute directly
    executePause();
    return;
  }
  let modal = coreui.Modal.getInstance(modalEl);
  if (!modal) modal = new coreui.Modal(modalEl, { backdrop: true, keyboard: true });
  document.getElementById("pause-confirm-btn").onclick = () => executePause();
  modal.show();
}

/**
 * Execute the actual pause request
 */
async function executePause() {
  const modalEl = document.getElementById("pauseModal");
  const modal = modalEl ? coreui.Modal.getInstance(modalEl) : null;
  const btn = document.getElementById("pause-confirm-btn");
  const headerBtn = document.getElementById("btn-pause-action");
  const type = window.LAB_TYPE || "essentials";

  if (modal) modal.hide();
  Dashboard.isProcessing = true;
  if (typeof ActivityTracker !== "undefined") {
    ActivityTracker.setProcessing(true);
  }

  if (headerBtn) Dashboard.toggleLoading(headerBtn, true);
  if (btn) {
    btn.classList.add("disabled");
    btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-2" role="status" aria-hidden="true"></span> Pausing...';
  }

  Dashboard.resetTerminal();
  Dashboard.appendCommand(`docker pause ${window.SESSION_HASH}`);
  await new Promise((r) => setTimeout(r, 300));
  Dashboard.appendLog("[*] Freezing container processes...");

  try {
    const response = await fetch("/api/labs/pause", {
      method: "POST",
      body: new URLSearchParams({
        lab: type,
      }),
    });

    const data = await response.json();
    if (data.status === 'success') {
      Dashboard.appendLog("[✓] Lab paused. CPU: 0%, Memory: preserved");
      // Reload page after short delay to show paused state
      setTimeout(() => location.reload(), 1500);
    } else {
      Dashboard.appendLog(`[!] Error: ${data.error || 'Pause request failed'}`);
      Dashboard.isProcessing = false;
      if (headerBtn) Dashboard.toggleLoading(headerBtn, false);
    }
  } catch (e) {
    console.error("Pause Error:", e);
    Dashboard.appendLog(`[!] Error: ${e.message}`);
    if (headerBtn) Dashboard.toggleLoading(headerBtn, false);
    Dashboard.isProcessing = false;
  }
}

/**
 * Resume Lab - Unfreeze processes, restore CPU
 */
function handleResume() {
  if (Dashboard.isProcessing) return;
  const modalEl = document.getElementById("resumeModal");
  if (!modalEl) {
    // No modal found, execute directly
    executeResume();
    return;
  }
  let modal = coreui.Modal.getInstance(modalEl);
  if (!modal) modal = new coreui.Modal(modalEl, { backdrop: true, keyboard: true });
  document.getElementById("resume-confirm-btn").onclick = () => executeResume();
  modal.show();
}

/**
 * Execute the actual resume request
 */
async function executeResume() {
  const modalEl = document.getElementById("resumeModal");
  const modal = modalEl ? coreui.Modal.getInstance(modalEl) : null;
  const btn = document.getElementById("resume-confirm-btn");
  const headerBtn = document.getElementById("btn-resume-action");
  const type = window.LAB_TYPE || "essentials";

  if (modal) modal.hide();
  Dashboard.isProcessing = true;
  if (typeof ActivityTracker !== "undefined") {
    ActivityTracker.setProcessing(true);
  }

  if (headerBtn) Dashboard.toggleLoading(headerBtn, true);
  if (btn) {
    btn.classList.add("disabled");
    btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-2" role="status" aria-hidden="true"></span> Resuming...';
  }

  Dashboard.resetTerminal();
  Dashboard.appendCommand(`docker unpause ${window.SESSION_HASH}`);
  await new Promise((r) => setTimeout(r, 300));
  Dashboard.appendLog("[*] Unfreezing container processes...");

  try {
    const response = await fetch("/api/labs/resume", {
      method: "POST",
      body: new URLSearchParams({
        lab: type,
      }),
    });

    const data = await response.json();
    if (data.status === 'success') {
      Dashboard.appendLog("[✓] Lab resumed. All systems restored");
      // Reload page after short delay to show running state
      setTimeout(() => location.reload(), 1500);
    } else {
      Dashboard.appendLog(`[!] Error: ${data.error || 'Resume request failed'}`);
      Dashboard.isProcessing = false;
      if (headerBtn) Dashboard.toggleLoading(headerBtn, false);
    }
  } catch (e) {
    console.error("Resume Error:", e);
    Dashboard.appendLog(`[!] Error: ${e.message}`);
    if (headerBtn) Dashboard.toggleLoading(headerBtn, false);
    Dashboard.isProcessing = false;
  }
}

/**
 * Launch Code-Server IDE
 * @param {Event} event
 */
async function launchCodeIDE(event, targetUrl = null) {
  // 1. Determine Target URL & Mode
  // If we are in MinIO mode, the button might be different?
  // Actually MinIO launch is separate in the modal. 
  // This function is for the main "Code" or "Launch" button on dashboard.

  const type = window.LAB_TYPE || 'essentials';
  const codeBtn = event.target.closest ? event.target.closest('[data-code-url]') : null;
  let url = targetUrl || (codeBtn && codeBtn.dataset.codeUrl) || window.CODE_SERVER_URL;
  let actionName = "Code-Server";
  let ensureAction = "ensure-codeserver";

  // MinIO Handling
  if (type === 'minio') {
    // For MinIO, we probably just want to open the console
    // But we can still "ensure" the container is running if we want.
    // However currently MinIO doesn't have an "idle timeout" feature planned yet.
    // So we just open the URL.
    if (!targetUrl) {
      const labConfig = LabData.getConfig();
      if (labConfig && labConfig.fields) {
        const consoleField = labConfig.fields.find(f => f.label === 'MinIO Console Endpoint');
        if (consoleField) url = consoleField.value;
      }
    }
    actionName = "MinIO Console";
    ensureAction = null; // No auto-start logic for MinIO yet
  }

  const btn = event.target.closest("button");
  const originalText = btn.innerHTML;

  // 3. Auto-Start Logic (Only for Code-Server currently)
  // We move this BEFORE the URL check because we might get the URL from the response.
  // Fast path: ask the backend if code-server is already running. If it is, open the
  // link instantly and skip the (heavier) ensure worker. If not, fall through to the
  // wake-up flow so the user still gets "wait for readiness" feedback.
  if (ensureAction) {
    const formData = new URLSearchParams();
    formData.append("lab", type);
    formData.append("hash", window.SESSION_HASH);

    let alreadyRunning = false;
    try {
      const stResp = await fetch("/api/labs/code_status", {
        method: "POST",
        body: formData
      });
      const stData = await stResp.json();
      if (stData && (stData.running || stData.codeserver_running)) {
        alreadyRunning = true;
        if (stData.url) {
          url = stData.url;
          window.CODE_SERVER_URL = url;
        }
      }
    } catch (e) {
      // Status check failed — treat as not running and fall through to ensure.
      console.error("Code-server status check failed", e);
    }

    if (!alreadyRunning) {
      // Add spinner to button
      btn.classList.add("disabled");
      btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-2" role="status" aria-hidden="true"></span> Waking up...';
      Dashboard.resetTerminal();
      Dashboard.appendCommand(`labsctl ensure code-server --hash=${window.SESSION_HASH}`);
      Dashboard.appendLog(`[*] Ensuring ${actionName} is running...`);

      try {
        // Stream the worker's real logs and only open the editor once code-server is
        // actually ready (worker success marker) — not a blind fixed delay. If the
        // marker is missed we fall back to a safety timeout so the launch never hangs.
        const readyPromise = new Promise((resolveReady) => {
          const origAppend = Dashboard.appendLog;
          let done = false;
          const finish = () => { if (!done) { done = true; Dashboard.appendLog = origAppend; resolveReady(); } };
          const timer = setTimeout(finish, 45000);

          Dashboard.appendLog = function (d) {
            origAppend.call(Dashboard, d);
            const msg = (d && d.log) || (d && d.message) || d || "";
            if (msg && /Code-server started successfully|Code-server is already running/i.test(msg)) {
              clearTimeout(timer);
              finish();
            }
          };
        });

        // Ensure we're listening on the live log channel for this lab
        if (window.LogSocket && !window.LogSocket.isConnected) {
          window.LogSocket.connect(`logs.${window.SESSION_HASH}`, (d) => Dashboard.appendLog(d));
        }

        let response = await fetch("/api/labs/ensure_codeserver", {
          method: "POST",
          body: formData
        });

        let data = await response.json();
        if (data.url) {
          url = data.url; // Use fresh URL from backend if provided
          window.CODE_SERVER_URL = url;
          Dashboard.appendLog(`[*] Backend returned URL: ${url}`);
        }

        // Wait for the worker's success marker (or the 45s safety timeout)
        await readyPromise;

      } catch (e) {
        console.error("Auto-start failed", e);
        Dashboard.appendLog(`[!] Warning: Auto-start trigger failed. Trying saved URL...`);
      }
    }
  }

  if (!url || url === "") {
    Dashboard.appendLog(`[!] Critical: No URL found for ${actionName}.`);
    alert(`${actionName} URL not found. Please Redeploy your lab.`);
    btn.classList.remove("disabled");
    btn.innerHTML = originalText;
    return;
  }

  // 2. Add spinner to button
  btn.classList.add("disabled");
  btn.innerHTML = '<span class="spinner-grow spinner-grow-sm me-2" role="status" aria-hidden="true"></span> Launching...';

  Dashboard.resetTerminal();

  // 3. Show validation logs (Visual feedback)
  Dashboard.appendLog(`[*] Connecting to ${url}...`);
  await new Promise((r) => setTimeout(r, 800));

  // 5. Open window
  const newWin = window.open(url, "_blank");

  if (!newWin || newWin.closed || typeof newWin.closed === "undefined") {
    Dashboard.appendLog("[!] Popup Blocked! User intervention required.");
    alert("Your browser blocked the popup. Please allow popups for this site.");
  } else {
    Dashboard.appendLog(`[✓] ${actionName} Launched.`);
  }

  // Cleanup
  await new Promise((r) => setTimeout(r, 500));
  btn.classList.remove("disabled");
  btn.innerHTML = originalText;

  // Close modal if open
  const modalEl = document.getElementById("vscModal");
  if (modalEl) {
    const modal = coreui.Modal.getInstance(modalEl);
    if (modal) modal.hide();
  }
}

/* ============================================================================
 * DOMAIN SELECTION HELPERS (for Redeploy Modal)
 * ========================================================================== */

// Track dropdown state
let domainDropdownOpen = false;

/**
 * Toggle domain dropdown visibility
 *
 * Focuses the hidden input field when the container is clicked
 */
function focusSearch() {
  document.getElementById("domain_search").focus();
}

/**
 * Shows the dropdown when the input is focused or user starts typing
 */
function showDropdown() {
  const dropdown = document.getElementById("domain_dropdown");
  dropdown.style.display = "block";
  document
    .getElementById("dropdown_arrow")
    .classList.replace("bx-chevron-down", "bx-chevron-up");
}

/**
 * Toggles the dropdown specifically for the arrow click
 *
 * Filter logic: Shows matching domains as you type.
 * If searching, it ensures the list is visible.
 */
function filterDomains() {
  const searchVal = document
    .getElementById("domain_search")
    .value.toLowerCase();
  const dropdown = document.getElementById("domain_dropdown");
  const items = document.querySelectorAll(".domain-item");
  const arrow = document.getElementById("dropdown_arrow");

  if (searchVal.length > 0) {
    dropdown.style.display = "block"; // Show to display results
    arrow.classList.replace("bx-chevron-down", "bx-chevron-up");

    items.forEach((item) => {
      const text = item.innerText.toLowerCase();
      item.style.display = text.includes(searchVal) ? "block" : "none";
    });
  } else {
    // If search is cleared, hide the list unless it was manually expanded
    if (!window.dropdownManuallyExpanded) {
      dropdown.style.display = "none";
      arrow.classList.replace("bx-chevron-up", "bx-chevron-down");
    }
  }
}

/**
 * Dropdown Arrow Toggle Logic
 */
function toggleDomainDropdown(event) {
  if (event) event.stopPropagation();
  const dropdown = document.getElementById("domain_dropdown");
  const arrow = document.getElementById("dropdown_arrow");
  const isHidden = dropdown.style.display === "none" || dropdown.style.display === "";

  if (isHidden) {
    dropdown.style.display = "block";
    arrow.style.transform = "rotate(180deg)";
  } else {
    dropdown.style.display = "none";
    arrow.style.transform = "rotate(0deg)";
  }
}

// Add global state tracker
window.dropdownManuallyExpanded = false;

/**
 * Modified update function to handle placeholder behavior
 */
function updateSelectedDomains() {
  const display = document.getElementById("selected_domains_display");
  const searchInput = document.getElementById("domain_search");
  const checkedBoxes = document.querySelectorAll(".domain-selector:checked");

  display.innerHTML = "";

  if (checkedBoxes.length > 0) {
    searchInput.placeholder = ""; // Hide placeholder if domains are selected
    checkedBoxes.forEach((checkbox) => {
      const chip = document.createElement("span");
      chip.className =
        "border border-secondary border-opacity-25 rounded text-white opacity-75 d-inline-flex align-items-center px-2 py-1";
      chip.style.fontSize = "11px";
      chip.innerHTML = `
                <span>${Dashboard.escapeHtml(checkbox.value)}</span>
                <i class='bx bx-x ms-1 opacity-50 hover-opacity-100 transition-all' style="cursor:pointer; font-size: 14px;" onclick="removeDomainChip('${Dashboard.escapeHtml(checkbox.id)}'); event.stopPropagation();"></i>
            `;
      display.appendChild(chip);
    });
  } else {
    searchInput.placeholder = "Click to select domains...";
  }

  // Update domain availability across all selectors
  updateDomainAvailability();
}

/**
 * Remove a domain chip
 * @param {string} checkboxId - ID of checkbox to uncheck
 */
function removeDomainChip(checkboxId) {
  const checkbox = document.getElementById(checkboxId);
  if (checkbox) {
    checkbox.checked = false;
    updateSelectedDomains();
  }
}

/**
 * Select all domains
 */
function selectAllDomains() {
  const checkboxes = document.querySelectorAll(".domain-selector");
  checkboxes.forEach((cb) => (cb.checked = true));
  updateSelectedDomains();
}

/**
 * Toggle domain section visibility based on "Expose to Web" choice
 */
function toggleDomainSection() {
  const exposeToggle = document.getElementById("expose_web_toggle");
  const isExposed = exposeToggle && exposeToggle.value === "true";
  const wrapper = document.getElementById("domain_selection_wrapper");

  if (isExposed) {
    wrapper.style.display = "flex";
    wrapper.classList.replace("animate__fadeOut", "animate__fadeIn");
  } else {
    wrapper.classList.replace("animate__fadeIn", "animate__fadeOut");

    // Delay display:none to allow animation
    setTimeout(() => {
      const exposeToggleEl = document.getElementById("expose_web_toggle");
      if (exposeToggleEl && exposeToggleEl.value === "false") {
        wrapper.style.display = "none";
      }
    }, 500);

    // Clear selections when hiding
    document
      .querySelectorAll(".domain-selector")
      .forEach((cb) => (cb.checked = false));
    updateSelectedDomains();
  }
}

/**
 * Update domain availability across all selectors
 * Ensures a domain can only be used in ONE place at a time
 * Uses database-backed DOMAIN_USAGE_MAP for cross-lab checking
 */
function updateDomainAvailability() {
  // 1. Use the database-backed usage map (already includes ALL labs)
  const usageMap = LabData.getDomainUsage();

  // Also check currently selected domains in THIS modal (not yet saved to DB)
  const currentSelections = {};

  const vscSelector = document.getElementById("vsc_domain_selector");
  if (vscSelector && vscSelector.offsetParent !== null) {
    const vscDomain = vscSelector.value;
    if (vscDomain && !vscDomain.includes('.tomweb.shop')) {
      currentSelections[vscDomain] = { usage: 'VS Code Web', lab_type: window.LAB_TYPE };
    }
  }

  const minioConsoleSelector = document.getElementById("minio_console_domain");
  if (minioConsoleSelector && minioConsoleSelector.offsetParent !== null) {
    const consoleDomain = minioConsoleSelector.value;
    if (consoleDomain && !consoleDomain.includes('.tomweb.shop')) {
      currentSelections[consoleDomain] = { usage: 'MinIO Console', lab_type: window.LAB_TYPE };
    }
  }

  const minioApiSelector = document.getElementById("minio_api_domain");
  if (minioApiSelector && minioApiSelector.offsetParent !== null) {
    const apiDomain = minioApiSelector.value;
    if (apiDomain && !apiDomain.includes('.tomweb.shop')) {
      currentSelections[apiDomain] = { usage: 'S3 API', lab_type: window.LAB_TYPE };
    }
  }

  const n8nSelector = document.getElementById("n8n_domain_selector");
  if (n8nSelector && n8nSelector.offsetParent !== null) {
    const n8nDomain = n8nSelector.value;
    if (n8nDomain && !n8nDomain.includes('.tomweb.shop')) {
      currentSelections[n8nDomain] = { usage: 'n8n Interface', lab_type: window.LAB_TYPE };
    }
  }

  const checkedDomains = document.querySelectorAll(".domain-selector:checked");
  checkedDomains.forEach(checkbox => {
    currentSelections[checkbox.value] = { usage: 'Public Exposure', lab_type: window.LAB_TYPE };
  });

  // 2-5. Filter all selectors (reusable function replaces 4 copy-paste blocks)
  function filterSelectorOptions(selector, serviceName, allowedShared) {
    if (!selector) return;
    Array.from(selector.options).forEach(option => {
      const domain = option.value;
      if (domain === selector.value) {
        option.disabled = false;
        option.textContent = domain;
        return;
      }
      const dbUsage = usageMap[domain];
      const currentUsage = currentSelections[domain];
      let usageText = '';
      if (dbUsage && dbUsage.usage !== serviceName && !allowedShared.includes(dbUsage.usage)) {
        usageText = ` (Used: ${dbUsage.usage} in ${dbUsage.lab_type} lab)`;
      } else if (currentUsage && currentUsage.usage !== serviceName && !allowedShared.includes(currentUsage.usage)) {
        usageText = ` (Used: ${currentUsage.usage})`;
      }
      option.disabled = (usageText !== '');
      option.textContent = domain + usageText;
    });
  }

  filterSelectorOptions(vscSelector, 'VS Code Web', []);
  filterSelectorOptions(minioConsoleSelector, 'MinIO Console', ['S3 API']);
  filterSelectorOptions(minioApiSelector, 'S3 API', ['MinIO Console']);
  filterSelectorOptions(n8nSelector, 'n8n Interface', []);

  // 6. Filter public exposure domain checkboxes
  const allDomainItems = document.querySelectorAll(".domain-item");
  allDomainItems.forEach(item => {
    const checkbox = item.querySelector(".domain-selector");
    if (!checkbox) return;

    const domain = checkbox.value;
    const isCurrentlyChecked = checkbox.checked;
    const label = item.querySelector(".form-check-label");

    // Determine where the domain is used (check DB first, then current modal)
    const dbUsage = usageMap[domain];
    const currentUsage = currentSelections[domain];

    let usageLabel = '';
    let labInfo = '';
    let isUsedInSelector = false;

    if (dbUsage) {
      usageLabel = `Used: ${dbUsage.usage}`;
      labInfo = dbUsage.lab_type ? ` (${dbUsage.lab_type} lab)` : '';
      isUsedInSelector = true;
    } else if (currentUsage) {
      usageLabel = `Used: ${currentUsage.usage}`;
      isUsedInSelector = true;
    }

    if (isUsedInSelector && !isCurrentlyChecked) {
      // Domain is used elsewhere - show it but disabled with usage label
      item.style.display = '';
      item.style.opacity = '0.5';
      checkbox.disabled = true;

      // Add or update usage badge
      let usageBadge = label.querySelector('.usage-badge');
      if (!usageBadge) {
        usageBadge = document.createElement('span');
        usageBadge.className = 'usage-badge badge bg-info bg-opacity-25 text-info ms-2';
        usageBadge.style.fontSize = '9px';
        usageBadge.style.fontWeight = 'normal';
        label.appendChild(usageBadge);
      }
      usageBadge.textContent = usageLabel + labInfo;
    } else {
      // Domain is available - remove opacity and enable
      item.style.display = '';
      item.style.opacity = '1';
      checkbox.disabled = false;

      // Remove usage badge if it exists
      const existingBadge = label.querySelector('.usage-badge');
      if (existingBadge) {
        existingBadge.remove();
      }
    }
  });
}
/**
 * Professional Launcher
 * Config-driven: add new lab types in LAB_ACTION_CONFIG above
 */
function launchService(btn, type) {
  Dashboard.toggleLoading(btn, true);

  setTimeout(() => {
    const action = LAB_ACTION_CONFIG[type] || LAB_ACTION_CONFIG.default;

    if (action.launch === 'n8n_url') {
      let url = "";
      const labConfig = LabData.getConfig();
      if (labConfig && labConfig.fields) {
        const urlField = labConfig.fields.find(f => f.label === 'Public URL');
        if (urlField) url = urlField.value;
      }
      if (url) {
        window.open(url, '_blank');
      } else {
        alert("n8n URL not found. Please redeploy.");
      }
    } else if (action.launch === 'codeInfoModal') {
      openCodeModal(window.SESSION_HASH, window.LAB_TYPE, 'running');
    } else {
      const modalEl = document.getElementById(action.launch);
      if (!modalEl) return;
      const modal = new coreui.Modal(modalEl);
      modal.show();
    }

    Dashboard.toggleLoading(btn, false);
  }, 100);
}
/* ============================================================================
 * GUI LAUNCH: Opens the noVNC GUI desktop in a new tab
 * ============================================================================
 */
function launchGui(btn) {
  Dashboard.toggleLoading(btn, true);
  setTimeout(() => {
    let guiUrl = "";
    const labConfig = LabData.getConfig();
    if (labConfig && labConfig.fields) {
      const urlField = labConfig.fields.find(f => f.label === 'GUI URL');
      if (urlField) guiUrl = urlField.value;
    }
    if (guiUrl) {
      window.open(guiUrl, '_blank');
    } else {
      alert("GUI URL not found. Please redeploy.");
    }
    Dashboard.toggleLoading(btn, false);
  }, 100);
}

/* ============================================================================
 * UTILITIES: Clipboard Handling
 * ============================================================================
 * All clipboard logic is now in /js/clipboard.js (loaded separately in _master.php).
 * The delegated handler in clipboard.js handles .clipboard[data-clipboard-text] clicks.
 * ============================================================================ */

//  * EVENT LISTENERS
//  * ========================================================================== */

/**
 * Initialize dashboard when DOM is ready
 */
window.onPageLoad( () => {
  Dashboard.init();

  // Close dropdown when clicking outside
  document.addEventListener("click", function (event) {
    const dropdown = document.getElementById("domain_dropdown");
    const display = document.getElementById("selected_domains_display");

    if (dropdown && display && domainDropdownOpen) {
      if (!dropdown.contains(event.target) && !display.contains(event.target)) {
        domainDropdownOpen = false;
        dropdown.style.display = "none";
      }
    }
  });
});

// --- Challenge Labs Search & Filter Logic ---
function initChallengeSearch() {
    const searchContainer = document.getElementById('expandableSearchContainer');
    const searchInput = document.getElementById('challengeSearchInput');
    
    // Only execute on pages that have the challenge search UI
    if (!searchContainer || !searchInput) return;

    const searchBarUI = document.getElementById('searchBarUI');
    const searchLabel = document.getElementById('searchLabel');
    const filterBtn = document.getElementById('filterToggleBtn');
    const closeBtn = document.getElementById('searchCloseBtn');
    const gridContainer = document.getElementById('challengesGrid');
    
    // Parse saved filters from PHP session object on load
    const savedFilters = window.savedChallengeFilters || {};
    if(savedFilters['q']) {
        searchInput.value = savedFilters['q'];
    }
    const setChecks = (paramName, ids) => {
        if(savedFilters[paramName]) {
            const valStr = String(savedFilters[paramName]);
            const vals = valStr.split(',');
            vals.forEach(val => {
                const map = ids[val];
                if(map && document.getElementById(map)) document.getElementById(map).checked = true;
            });
        }
    };
    setChecks('plan', { 'premium': 'filterPremium', 'free': 'filterFree' });
    setChecks('filter', { 'team': 'filterTeam', 'event': 'filterEvent', 'solo': 'filterSolo', 'retired': 'filterRetired' });
    setChecks('sort', { 'new': 'sortNew', 'partial': 'sortPartial', 'completed': 'sortCompleted' });
    setChecks('level', { 'easy': 'levelEasy', 'medium': 'levelMedium', 'hard': 'levelHard' });

    // Check if there's any active filter or search text
    const hasSearchQuery = savedFilters['q'] && savedFilters['q'].trim() !== '';
    const hasAnyFilter = Object.keys(savedFilters).some(k => ['q','plan','filter','sort','level'].includes(k));
    
    // Always show floating close button if any filter/search is active
    if (hasAnyFilter && closeBtn) {
        closeBtn.classList.remove('d-none');
        closeBtn.classList.add('d-flex');
    }

    // Expand search if there's a text query
    if (hasSearchQuery && searchContainer) {
        searchContainer.classList.add('expanded');
        searchLabel.style.display = 'none';
        searchInput.style.display = 'block';
        filterBtn.classList.remove('d-none');
        filterBtn.classList.add('d-flex');
        searchBarUI.style.cursor = 'text';
    }

    if(searchBarUI) {
        searchBarUI.addEventListener('click', function(e) {
            if (e.target.closest('#filterToggleBtn') || e.target.closest('#searchCloseBtn') || e.target.closest('.dropdown-menu')) {
                return;
            }
            if (!searchContainer.classList.contains('expanded')) {
                searchContainer.classList.add('expanded');
                searchLabel.style.display = 'none';
                searchInput.style.display = 'block';
                filterBtn.classList.remove('d-none');
                filterBtn.classList.add('d-flex');
                searchBarUI.style.cursor = 'text';
                searchInput.focus();
            } else {
                // If it is already expanded, clicking anywhere on the bar should focus the input
                searchInput.focus();
            }
        });
    }

    // Collapse ONLY if input is empty. Close badge stays if filters are active.
    document.addEventListener('click', function(e) {
        if (!searchContainer) return;
        if (!searchContainer.contains(e.target) && searchInput.value.trim() === '') {
            searchContainer.classList.remove('expanded');
            searchLabel.style.display = 'block';
            searchInput.style.display = 'none';
            filterBtn.classList.add('d-none');
            filterBtn.classList.remove('d-flex');
            searchBarUI.style.cursor = 'pointer';
        }
    });

    // Close Button logic: clears everything
    if(closeBtn) {
        closeBtn.addEventListener('click', function(e) {
            e.stopPropagation();
            searchInput.value = '';
            document.querySelectorAll('.custom-search-dropdown .form-check-input').forEach(cb => cb.checked = false);
            
            searchContainer.classList.remove('expanded');
            searchLabel.style.display = 'block';
            searchInput.style.display = 'none';
            filterBtn.classList.add('d-none');
            filterBtn.classList.remove('d-flex');
            closeBtn.classList.add('d-none');
            closeBtn.classList.remove('d-flex');
            searchBarUI.style.cursor = 'pointer';
            
            triggerAjaxFetch();
        });
    }

    // Fetch update logic
    let debounceTimer;
    const triggerAjaxFetch = () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => {
            const currentParams = new URLSearchParams(window.location.search);
            
            // Collect new params
            const q = searchInput.value.trim();
            const getChecked = (ids) => Object.entries(ids).filter(([val, id]) => document.getElementById(id).checked).map(([val, id]) => val);
            
            const plans = getChecked({'premium': 'filterPremium', 'free': 'filterFree'});
            const filters = getChecked({'team': 'filterTeam', 'event': 'filterEvent', 'solo': 'filterSolo', 'retired': 'filterRetired'});
            const sorts = getChecked({'new': 'sortNew', 'partial': 'sortPartial', 'completed': 'sortCompleted'});
            const levels = getChecked({'easy': 'levelEasy', 'medium': 'levelMedium', 'hard': 'levelHard'});
            
            if(q) currentParams.set('q', q); else currentParams.delete('q');
            if(plans.length) currentParams.set('plan', plans.join(',')); else currentParams.delete('plan');
            if(filters.length) currentParams.set('filter', filters.join(',')); else currentParams.delete('filter');
            if(sorts.length) currentParams.set('sort', sorts.join(',')); else currentParams.delete('sort');
            if(levels.length) currentParams.set('level', levels.join(',')); else currentParams.delete('level');
            
            const newUrl = window.location.pathname + '?';
            // URL history.pushState is deliberately omitted so the URL in the address bar never changes.

            // Dynamically show/hide close button based on new params
            if (currentParams.toString() !== '' && closeBtn) {
                closeBtn.classList.remove('d-none');
                closeBtn.classList.add('d-flex');
            } else if (closeBtn) {
                closeBtn.classList.add('d-none');
                closeBtn.classList.remove('d-flex');
            }

            // Fetch URL with ajax flag to get only partial HTML
            const fetchUrl = newUrl + (currentParams.toString() ? currentParams.toString() + '&ajax=1' : 'ajax=1');

            // Show loader
            if(gridContainer) {
                gridContainer.style.opacity = '0.4';
                gridContainer.style.pointerEvents = 'none';
            }

            fetch(fetchUrl)
                .then(res => res.text())
                .then(html => {
                    const doc = new DOMParser().parseFromString(html, 'text/html');
                    const newGrid = doc.getElementById('challengesGrid');
                    if(gridContainer && newGrid) {
                        gridContainer.innerHTML = newGrid.innerHTML;
                        gridContainer.style.opacity = '1';
                        gridContainer.style.pointerEvents = 'auto';
                    }
                })
                .catch(err => {
                    console.error('Fetch error:', err);
                    if(gridContainer) {
                        gridContainer.style.opacity = '1';
                        gridContainer.style.pointerEvents = 'auto';
                    }
                });
        }, 400); // 400ms debounce
    };

    // Attach listeners
    if (searchInput) {
        searchInput.addEventListener('input', triggerAjaxFetch);
    }
    const checkboxes = document.querySelectorAll('.custom-search-dropdown .form-check-input');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', triggerAjaxFetch);
    });
}

if (document.readyState === 'loading') {
    window.onPageLoad( initChallengeSearch);
} else {
    initChallengeSearch();
}

function addDeployProxyRow() {
    const list = document.getElementById('deploy-proxy-container');
    if (!list) return;
    const existingRows = list.querySelectorAll('.proxy-row');
    const idx = existingRows.length;
    
    let optionsHtml = '<option value="">Select Domain...</option>';
    if (window.USER_DOMAINS) {
        window.USER_DOMAINS.forEach(d => {
            optionsHtml += `<option value="${Dashboard.escapeHtml(d)}">${Dashboard.escapeHtml(d)}</option>`;
        });
    } else {
        const firstSelect = list.querySelector('.proxy-domain-select');
        if (firstSelect) {
            optionsHtml = firstSelect.innerHTML;
        }
    }
    
    const row = document.createElement('div');
    row.className = 'row align-items-center mb-3 proxy-row';
    row.setAttribute('data-index', idx);
    row.innerHTML = `
        <label class="col-sm-4 small fw-bold text-secondary">Port & Domains</label>
        <div class="col-sm-8">
            <div class="row g-2">
                <div class="col-md-4 col-12 mb-2 mb-md-0">
                    <input type="number" name="deploy_proxy_port[]" class="form-control bg-transparent rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-port text-white" placeholder="Port" min="1" max="65535">
                </div>
                <div class="col-md-6 col-10">
                    <select name="deploy_proxy_domain[]" class="form-select bg-transparent rounded-pill border-secondary border-opacity-25 shadow-none px-3 proxy-domain-select text-white">
                        ${optionsHtml}
                    </select>
                </div>
                <div class="col-md-2 col-2 d-flex justify-content-end">
                    <button type="button" class="btn rounded-circle d-flex align-items-center justify-content-center p-0 btn-remove-proxy border-secondary border-opacity-25 bg-body-tertiary" style="width: 36px; height: 36px; color: #be185d;" onclick="removeProxyRow(this)">
                        <i class='bx bx-trash'></i>
                    </button>
                </div>
            </div>
        </div>
    `;
    list.appendChild(row);
}

// Server Logs Toggle
window.onPageLoad( function() {
    console.log('[DEBUG] Server Logs Toggle: onPageLoad fired');
    const logsBody = document.getElementById('terminal-viewport');
    const toggleBtn = document.getElementById('serverLogsToggleBtn');
    const chevrons = document.querySelectorAll('.server-logs-chevron');
    console.log('[DEBUG] logsBody:', logsBody, 'toggleBtn:', toggleBtn);

    function setMinimizedState(isMinimized) {
        console.log('[DEBUG] setMinimizedState called with:', isMinimized);
        if (isMinimized) {
            logsBody.classList.add('logs-minimized');
            console.log('[DEBUG] Added logs-minimized, height:', getComputedStyle(logsBody).height);
            chevrons.forEach(chevron => {
                chevron.classList.remove('bx-chevron-down');
                chevron.classList.add('bx-chevron-up');
            });
        } else {
            logsBody.classList.remove('logs-minimized');
            console.log('[DEBUG] Removed logs-minimized, height:', getComputedStyle(logsBody).height);
            chevrons.forEach(chevron => {
                chevron.classList.remove('bx-chevron-up');
                chevron.classList.add('bx-chevron-down');
            });
        }
    }

    if (toggleBtn && logsBody) {
        // Retrieve state directly from the HTML injected by PHP
        const isMinimized = toggleBtn.getAttribute('data-minimized') === 'true';
        console.log('[DEBUG] Server Logs: initial isMinimized =', isMinimized);
        
        // Ensure scroll to bottom happens when new logs arrive if minimized
        const observer = new MutationObserver(() => {
            if (toggleBtn.getAttribute('data-minimized') === 'true') {
                logsBody.scrollTop = logsBody.scrollHeight;
            }
        });
        observer.observe(document.getElementById('live-logs-container'), { childList: true, subtree: true });
        
        if (isMinimized) {
            // Already set by PHP classes, just ensure scroll is at bottom initially
            setTimeout(() => { logsBody.scrollTop = logsBody.scrollHeight; }, 100);
        }

        toggleBtn.addEventListener('click', async function(e) {
            // Prevent toggling if clicked on tooltip icon
            if(e.target.closest('.terminal-info-wrapper')) return;

            const willMinimize = !logsBody.classList.contains('logs-minimized');
            console.log('[DEBUG] Server Logs clicked: willMinimize =', willMinimize, 'classList before:', logsBody.className);
            setMinimizedState(willMinimize);
            console.log('[DEBUG] Server Logs clicked: classList after:', logsBody.className);
            toggleBtn.setAttribute('data-minimized', willMinimize ? 'true' : 'false');
            
            // Save state in the database via the API
            const formData = new FormData();
            formData.append('preference_id', 'labs_serverlogs_min');
            formData.append('value', willMinimize ? '1' : '0');
            
            try {
                await fetch('/api/user/preference_save', {
                    method: 'POST',
                    body: formData
                });
            } catch (error) {
                console.error("Failed to save UI preference:", error);
            }
        });
    }
});


    

    // --- Explicit Window Exports for Inline HTML ---
    window.focusSearch = focusSearch;
    window.Dashboard = Dashboard;
    window.showDropdown = showDropdown;
    window.filterDomains = filterDomains;
    window.toggleDomainSection = toggleDomainSection;
    window.executeStop = executeStop;
    window.domainDropdownOpen = domainDropdownOpen;
    window.executeRedeploy = executeRedeploy;
    window.toggleDomainDropdown = toggleDomainDropdown;
    window.launchCodeIDE = launchCodeIDE;
    window.selectAllDomains = selectAllDomains;
    window.launchService = launchService;
    window.launchGui = launchGui;
    window.initChallengeSearch = initChallengeSearch;
    window.updateDomainAvailability = updateDomainAvailability;
    window.addDeployProxyRow = addDeployProxyRow;
    window.updateSelectedDomains = updateSelectedDomains;
    window.handleStop = handleStop;
    window.handlePause = handlePause;
    window.handleResume = handleResume;
    window.executePause = executePause;
    window.executeResume = executeResume;
    window.removeDomainChip = removeDomainChip;
    window.handleDeploy = handleDeploy;

  })();
} catch (e) {
  console.error("[Fatal Error in lab.js]", e);
}
