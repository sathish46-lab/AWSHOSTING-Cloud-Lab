/**
 * Wrapped with IIFE Error Boundary
 */
try {
  (function() {
    "use strict";


/**
 * ============================================================================
 * TOM LABS - MESSAGE QUEUE CLIENT (MQ)
 * ============================================================================
 * Handles all WebSocket connections, STOMP messaging, and real-time updates
 *
 * Dependencies: stomp.js
 * Used by: lab-dashboard.js
 *
 * @version 1.0.5
 * @author Sathish46 - Tom Labs
 * ============================================================================
 */

/**
 * Hardened Socket Factory with Subscription Tracking and Delayed Binding
 */
const TomSocketClient = function () {
  this.client = null;
  this.isConnected = false;
  this.isConnecting = false;
  this.isSubscribed = false;
  this.subRetryCount = 0;

  /**
   * Establish WebSocket connection to RabbitMQ
   * @param {string} exchange - RabbitMQ exchange name
   * @param {function} callback - Message handler function
   * @param {object} ui - UI elements (optional, e.g., status dot)
   */
  this.connect = function (exchange, callback, ui = null, onSubscribed = null) {
    if (this.isConnected && this.isSubscribed) {
      if (typeof onSubscribed === 'function') onSubscribed();
      return;
    }
    if (this.isConnecting) {
      // If already connecting, poll briefly for subscription readiness
      if (typeof onSubscribed === 'function') {
        const checkReady = setInterval(() => {
          if (this.isSubscribed) {
            clearInterval(checkReady);
            onSubscribed();
          } else if (!this.isConnecting && !this.isConnected) {
            clearInterval(checkReady);
          }
        }, 100);
      }
      return;
    }
    this.isConnecting = true;
    try {
      // Read MQ domain from server-injected config (env.json → PHP → window.TOM_CONFIG)
      // Each environment (dev/prod) has its own mq_domain in env.json
      let mqDomain = (window.TOM_CONFIG && window.TOM_CONFIG.mq_domain) ? window.TOM_CONFIG.mq_domain : null;
      
      if (!mqDomain) {
        // Fallback: dynamically resolve if TOM_CONFIG is not available
        const currentHost = window.location.hostname;
        if (currentHost.includes("dev.tomweb.in")) {
          mqDomain = "mq.dev.tomweb.in";
        } else if (currentHost === "localhost" || currentHost === "127.0.0.1") {
          mqDomain = "localhost:15674"; 
        } else {
          mqDomain = "mq.tomweb.in";
        }
      }
      
      const wsProtocol = window.location.protocol === "https:" ? "wss" : "ws";
      const port = window.location.port ? ":" + window.location.port : "";
      const wsUrl = `${wsProtocol}://${mqDomain}${port}/ws`;
      
      console.log(`[MQ] Connecting to WebSocket: ${wsUrl}`);
      const ws = new WebSocket(wsUrl);
      this.client = Stomp.over(ws);
      this.client.debug = null; // Set to console.log for debugging

      this.client.connect(
        (window.MQ_CREDENTIALS && window.MQ_CREDENTIALS.user) || "guest",
        (window.MQ_CREDENTIALS && window.MQ_CREDENTIALS.pass) || "",
        () => {
          this.isConnecting = false;
          this.isConnected = true;
          if (ui && ui.dot) ui.dot.style.color = "#a6e3a1"; // Green

          console.log(`[✓] Socket connected to exchange: ${exchange}`);

          // Start the subscription attempt
          this.safeSubscribe(exchange, callback, ui, onSubscribed);
        },
        (err) => {
          this.isConnecting = false;
          this.isConnected = false;
          this.isSubscribed = false;
          if (ui && ui.dot) ui.dot.style.color = "#f38ba8"; // Red

          console.warn("Socket connection failed, retrying in 2s...", err);
          setTimeout(() => this.connect(exchange, callback, ui, onSubscribed), 2000);
        },
        "/",
      );
    } catch (e) {
      this.isConnecting = false;
      console.error("Socket Error:", e);
    }
  };

  /**
   * Gracefully disconnect the WebSocket
   */
  this.disconnect = function () {
    if (this.client && this.isConnected) {
      this.client.disconnect(() => {
        console.log("Socket gracefully disconnected to save resources.");
      });
      this.isConnected = false;
      this.isSubscribed = false;
      this.isConnecting = false;
    }
  };

  /**
   * Attempts to subscribe to an exchange, with retries if the exchange isn't ready
   * @param {string} exchange - Exchange name
   * @param {function} callback - Message handler
   * @param {object} ui - UI elements
   * @param {function} onSubscribed - Optional callback once subscribed
   */
  this.safeSubscribe = function (exchange, callback, ui, onSubscribed = null) {
    const isTopic = exchange.startsWith('/') || exchange.startsWith('logs.') || exchange.includes('_stream.');
    const delayMs = isTopic ? 10 : 1000;

    setTimeout(() => {
      if (!this.isConnected) return;

      try {
        let destination;
        if (exchange.startsWith('/')) {
          destination = exchange;
        } else if (exchange.includes('_stream.') || exchange.startsWith('logs.')) {
          destination = `/topic/${exchange}`;
        } else {
          destination = `/exchange/${exchange}/#`;
        }

        this.client.subscribe(destination, (m) => {
          if (m && m.body) {
            let parsed = m.body;
            try {
              if (typeof m.body === 'string' && (m.body.startsWith('{') || m.body.startsWith('['))) {
                parsed = JSON.parse(m.body);
              }
            } catch (e) {}
            callback(parsed);
          }
        });
        this.isSubscribed = true;
        this.subRetryCount = 0;
        console.log(`[✓] Subscribed to ${destination}`);
        if (typeof onSubscribed === 'function') {
          onSubscribed();
        }
      } catch (e) {
        console.warn(
          `[!] Subscription failed for ${exchange}. Worker might not be ready. Retrying...`,
        );
        if (this.subRetryCount < 10) {
          this.subRetryCount++;
          this.safeSubscribe(exchange, callback, ui, onSubscribed);
        }
      }
    }, delayMs);
  };

  /**
   * Check if socket is currently connected
   * @returns {boolean}
   */
  this.isActive = function () {
    return this.isConnected && this.isSubscribed;
  };

  /**
   * Reconnect to the exchange
   * @param {string} exchange
   * @param {function} callback
   * @param {object} ui
   */
  this.reconnect = function (exchange, callback, ui = null) {
    if (this.isConnected) {
      console.log("Socket already connected. Skipping reconnection.");
      return;
    }
    this.connect(exchange, callback, ui);
  };
};

/**
 * Lightweight Native WebSocket Client for Overview Stats
 */
const NativeSocketClient = function () {
  this.ws = null;
  this.isConnected = false;
  this.reconnectTimer = null;

  this.connect = function (path, callback, ui = null) {
    try {
      // Strictly use the domain injected by the PHP backend via env.json
      let mqDomain = (window.TOM_CONFIG && window.TOM_CONFIG.mq_domain) ? window.TOM_CONFIG.mq_domain : "mq.tomweb.in";
      
      if (!mqDomain) {
          console.error("[MQ Native] CRITICAL: mq_domain not provided by backend config!");
          return;
      }

      const wsProtocol = window.location.protocol === "https:" ? "wss" : "ws";
      const port = window.location.port ? ":" + window.location.port : "";
      
      // If the backend injected a full localhost string (e.g. localhost:15674), don't append the window port
      const finalDomain = mqDomain.includes(":") ? mqDomain : `${mqDomain}${port}`;
      const wsUrl = `${wsProtocol}://${finalDomain}${path}`;

      console.log(`[MQ Native] Connecting to WebSocket: ${wsUrl}`);
      this.ws = new WebSocket(wsUrl);

      this.ws.onopen = () => {
        this.isConnected = true;
        if (ui && ui.dot) ui.dot.style.color = "#a6e3a1"; // Green
        console.log(`[✓] Native Socket connected to ${path}`);
      };

      this.ws.onmessage = (event) => {
        try {
          const data = JSON.parse(event.data);
          callback(data);
        } catch (e) {
          console.error("Native Socket JSON parse error:", e);
        }
      };

      this.ws.onclose = () => {
        this.isConnected = false;
        if (ui && ui.dot) ui.dot.style.color = "#f38ba8"; // Red
        console.warn(`[!] Native Socket closed. Reconnecting in 2s...`);
        clearTimeout(this.reconnectTimer);
        this.reconnectTimer = setTimeout(() => this.connect(path, callback, ui), 2000);
      };

      this.ws.onerror = (err) => {
        console.error("Native Socket Error:", err);
      };

    } catch (e) {
      console.error("Native Socket Error:", e);
    }
  };

  this.disconnect = function () {
    if (this.ws && this.isConnected) {
      clearTimeout(this.reconnectTimer);
      // Remove onclose handler so it doesn't auto-reconnect when manually disconnected
      this.ws.onclose = null;
      this.ws.close();
      this.isConnected = false;
      console.log("Native Socket gracefully disconnected to save resources.");
    }
  };
};

/**
 * Global Socket Instances
 * - OverviewSocket: System-wide stats (Native WebSocket to stats-daemon)
 * - LogSocket: Instance-specific deployment logs (STOMP via RabbitMQ)
 */
const OverviewSocket = new NativeSocketClient();
const LogSocket = new TomSocketClient();

/**
 * ============================================================================
 * ACTIVITY TRACKER - Resource Optimization
 * ============================================================================
 * Disconnects sockets when user is idle or tab is hidden
 */
const ActivityTracker = {
  idleTimer: null,
  idleTimeout: 300000, // 5 Minutes (in milliseconds)
  isProcessing: false, // Flag to prevent disconnection during critical operations

  /**
   * Initialize activity tracking
   */
  init: function () {
    // 1. Listen for Tab Switching
    document.addEventListener("visibilitychange", () => {
      if (document.hidden) {
        console.log("Tab hidden: Optimization active.");
        this.stopAllSockets();
      } else {
        console.log("Tab focused: Reconnecting...");
        this.reconnectSockets();
      }
    });

    // Flag to track if we disconnected due to being idle
    this.isIdleDisconnected = false;

    // 2. Listen for Mouse/Keyboard Activity
    const resetTimer = () => {
      clearTimeout(this.idleTimer);
      
      // Wake up if we were previously idle
      if (this.isIdleDisconnected) {
        console.log("Activity detected: Reconnecting sockets...");
        this.reconnectSockets();
        this.isIdleDisconnected = false;
      }

      this.idleTimer = setTimeout(() => {
        console.warn("User idle: Disconnecting sockets.");
        this.stopAllSockets();
        this.isIdleDisconnected = true;
      }, this.idleTimeout);
    };

    window.onload = resetTimer;
    document.onmousemove = resetTimer;
    document.onkeypress = resetTimer;
    document.onscroll = resetTimer;
    document.onclick = resetTimer;
  },

  /**
   * Stop all socket connections (called when idle or tab hidden)
   */
  stopAllSockets: function () {
    // PREVENT: If we are deploying or stopping, DO NOT close the socket
    if (this.isProcessing) {
      console.log(
        "[Optimization Bypass] System is busy with logs. Keeping socket alive.",
      );
      return;
    }

    OverviewSocket.disconnect();
    LogSocket.disconnect();

    console.log("[Optimization] All sockets disconnected to save resources.");
  },

  /**
   * Reconnect sockets when user returns
   */
  reconnectSockets: function () {
    // This will be called by the Dashboard to re-init sockets
    if (
      typeof Dashboard !== "undefined" &&
      typeof Dashboard.initSockets === "function"
    ) {
      Dashboard.initSockets();
    }
  },

  /**
   * Set processing flag to prevent socket closure during critical operations
   * @param {boolean} processing
   */
  setProcessing: function (processing) {
    this.isProcessing = processing;
    console.log(
      `[ActivityTracker] Processing mode: ${processing ? "ON" : "OFF"}`,
    );
  },
};

/**
 * ============================================================================
 * MQ UTILITIES - Helper Functions
 * ============================================================================
 */
const MQUtils = {
  /**
   * Update MQ status indicator in UI
   * @param {string} status - 'connected', 'disconnected', 'connecting'
   */
  updateStatusIndicator: function (status) {
    const dot = document.getElementById("mq-status-dot");
    if (!dot) return;

    const colors = {
      connected: "#a6e3a1", // Green
      disconnected: "#f38ba8", // Red
      connecting: "#f9e2af", // Yellow
    };

    dot.style.color = colors[status] || colors.disconnected;
  },

  /**
   * Log MQ events to console (debug mode)
   * @param {string} message
   * @param {string} type - 'info', 'warn', 'error'
   */
  log: function (message, type = "info") {
    const prefix = "[MQ]";
    switch (type) {
      case "warn":
        console.warn(prefix, message);
        break;
      case "error":
        console.error(prefix, message);
        break;
      default:
        console.log(prefix, message);
    }
  },

  /**
   * Parse incoming message (handle both JSON and plain text)
   * @param {*} data
   * @returns {object|string}
   */
  parseMessage: function (data) {
    if (typeof data === "string") {
      try {
        return JSON.parse(data);
      } catch (e) {
        return data;
      }
    }
    return data;
  },
};

/**
 * Export for use in other modules (if using ES6 modules)
 * For legacy script tags, these are already global
 */
if (typeof module !== "undefined" && module.exports) {
  module.exports = {
    TomSocketClient,
    OverviewSocket,
    LogSocket,
    ActivityTracker,
    MQUtils,
  };
}


    

    // --- Explicit Window Exports for Inline HTML ---
    window.ActivityTracker = ActivityTracker;
    window.LogSocket = LogSocket;
    window.MQUtils = MQUtils;
    window.NativeSocketClient = NativeSocketClient;
    window.TomSocketClient = TomSocketClient;
    window.OverviewSocket = OverviewSocket;

  })();
} catch (e) {
  console.error("[Fatal Error in mq.js]", e);
}
