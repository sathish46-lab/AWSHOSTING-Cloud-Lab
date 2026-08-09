/**
 * ui-init.js — Core UI Initialization & TomNotify System
 * Loaded as a separate standalone script for professional modular loading.
 */

// Initialize all tooltips globally with body container to fix positioning issues
// window.onPageLoad is provided by htmx-bridge.js (canonical definition)

/**
 * Glass Blur Double-Buffer for HTMX Swaps
 * 
 * Problem: backdrop-filter: blur(20px) is GPU paint-time work.
 * When HTMX swaps new cards into #main-content, the browser renders
 * them unblurred on frame 1, then composites blur on frame 2+.
 * This causes a visible "pop-in" flash.
 *
 * Solution: Hide #main-content (opacity 0) during the swap, let the
 * GPU composite blur layers invisibly across 2 animation frames,
 * then smoothly reveal the content with blur already applied.
 */
(function() {
    var isGlassMode = function() {
        return document.body && document.body.classList.contains('hwa-enabled');
    };

    // Before HTMX swaps content: hide #main-content instantly
    document.addEventListener('htmx:beforeSwap', function() {
        if (!isGlassMode()) return;
        var mc = document.getElementById('main-content');
        if (mc) {
            mc.classList.add('glass-swap-pending');
            mc.classList.remove('glass-swap-reveal');
        }
    });

    // After swap settles: wait 2 rAF frames for GPU to composite blur, then reveal
    document.addEventListener('htmx:afterSettle', function() {
        var mc = document.getElementById('main-content');
        if (!mc || !mc.classList.contains('glass-swap-pending')) return;

        // Double requestAnimationFrame = wait for 2 paint frames
        // Frame 1: browser layouts the new DOM
        // Frame 2: GPU composites backdrop-filter blur
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                mc.classList.remove('glass-swap-pending');
                mc.classList.add('glass-swap-reveal');
                // Clean up the reveal class after transition ends
                setTimeout(function() {
                    mc.classList.remove('glass-swap-reveal');
                }, 100);
            });
        });
    });
})();

window.onPageLoad(function() {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-coreui-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        if (typeof coreui !== 'undefined' && coreui.Tooltip) {
            return new coreui.Tooltip(tooltipTriggerEl, {
                container: 'body',
                trigger: 'hover'
            });
        }
    });

    // Smart Header Positioning:
    // If main content exceeds viewport (scrollable), switch header to static so it scrolls naturally
    function updateHeaderScrollMode() {
        var header = document.querySelector('.header');
        if (!header) return;
        var isScrollable = document.documentElement.scrollHeight > (window.innerHeight + 15);
        if (isScrollable) {
            header.style.setProperty('position', 'static', 'important');
        } else {
            header.style.setProperty('position', 'sticky', 'important');
            header.style.setProperty('top', '0', 'important');
        }
    }
    updateHeaderScrollMode();
    window.addEventListener('resize', updateHeaderScrollMode);
    setTimeout(updateHeaderScrollMode, 250);
    setTimeout(updateHeaderScrollMode, 800);

    if (window.headerObserver) window.headerObserver.disconnect();
    var mainContent = document.getElementById('main-content');
    if (mainContent && window.MutationObserver) {
        window.headerObserver = new MutationObserver(function() {
            updateHeaderScrollMode();
        });
        window.headerObserver.observe(mainContent, { childList: true, subtree: true, attributes: true });
    }

    // Silent Activity Tracker (Throttled & Deduplicated)
    var currentPath = window.location.pathname;
    var now = Date.now();
    if (window._lastTrackedPath !== currentPath || (now - (window._lastTrackedTime || 0)) > 30000) {
        window._lastTrackedPath = currentPath;
        window._lastTrackedTime = now;
        try {
            fetch('/api/dashboard/track_activity', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'page=' + encodeURIComponent(currentPath)
            }).catch(function(){});
        } catch(e) {}
    }
});

/**
 * TomNotify - Premium Stackable Notification System
 */
window.TomNotify = {
    show: function(message, title = 'Notification', type = 'success', duration = 5000) {
        const container = document.getElementById('notification-container');
        if (!container) return;

        const iconMap = {
            success: 'bxs-check-circle text-success',
            error: 'bxs-error-circle text-danger',
            warning: 'bxs-warning text-warning',
            info: 'bxs-info-circle text-info'
        };

        const toastId = 'toast-' + Date.now() + Math.floor(Math.random() * 1000);
        const icon = iconMap[type] || iconMap.success;
        const progressColor = type === 'error' ? '#e74c3c' : (type === 'warning' ? '#f1c40f' : 'var(--cui-primary, #8b91f9)');

        const html = `
            <div id="${toastId}" class="toast fade border-0 rounded-4 overflow-hidden shadow-lg mb-3" role="alert" aria-live="assertive" aria-atomic="true" data-coreui-autohide="true" data-coreui-delay="${duration}"
                style="background: var(--glass-bg, rgba(11, 30, 54, 0.88)); min-width: 320px; pointer-events: auto;">
                <div class="toast-header border-0 bg-transparent pt-3 px-3 d-flex align-items-center">
                    <strong class="me-auto d-flex align-items-center gap-2 fs-6 text-body-emphasis">
                        <i class="bx ${icon}"></i> 
                        <span class="ls-tight">${title}</span>
                    </strong>
                    <small class="text-body-secondary fw-light" style="font-size: 10px;">now</small>
                    <button type="button" class="btn-close ms-3 mb-1" style="font-size: 9px; filter: var(--cui-btn-close-white-filter, none);" data-coreui-dismiss="toast"></button>
                </div>
                <div class="toast-body text-body px-3 pb-3 pt-1">
                    <span class="small" style="line-height: 1.5; opacity: 0.85;">${message}</span>
                </div>
                <div class="toast-progress-container" style="height: 3px; background: rgba(var(--cui-emphasis-color-rgb, 255,255,255), 0.05); width: 100%;">
                    <div class="toast-progress-bar" style="height: 100%; width: 100%; background: ${progressColor}; transition: width ${duration}ms linear;"></div>
                </div>
            </div>
        `;

        container.insertAdjacentHTML('afterbegin', html);
        const toastEl = document.getElementById(toastId);
        if (typeof coreui !== 'undefined' && coreui.Toast) {
            const toast = new coreui.Toast(toastEl, { autohide: true, delay: duration });
            toast.show();

            setTimeout(() => {
                const progressBar = toastEl.querySelector('.toast-progress-bar');
                if (progressBar) progressBar.style.width = '0%';
            }, 50);

            toastEl.addEventListener('hidden.coreui.toast', () => {
                toastEl.remove();
            });
            
            setTimeout(() => {
                if (document.getElementById(toastId)) {
                    toast.hide();
                    setTimeout(() => {
                        if (document.getElementById(toastId)) document.getElementById(toastId).remove();
                    }, 500);
                }
            }, duration + 1000);
        }
    }
};

window.showToast = (msg) => TomNotify.show(msg, "System", "info");
// copyToClipboard removed — use window.copyText() from clipboard.js instead

window.onPageLoad(function() {
    if (window.TomVisuals && typeof window.TomVisuals.syncUI === 'function') window.TomVisuals.syncUI();

    if (!document.querySelector('.stable-app-view') && !document.querySelector('.split-panel-view')) {
        document.body.style.removeProperty('overflow');
        document.body.style.removeProperty('height');
        const mainWrapper = document.querySelector('.wrapper');
        if (mainWrapper) {
            mainWrapper.style.removeProperty('overflow');
            mainWrapper.style.removeProperty('height');
        }
    }

    document.body.addEventListener("tomNotify", function(evt) {
        if (evt.detail && window.TomNotify) {
            window.TomNotify.show(evt.detail.message, evt.detail.title || "Notification", evt.detail.type || "warning", 6000);
        }
    });
});

/**
 * Global HTMX Top Progress Bar Animation Driver
 */
(function() {
    function resetProgress() {
        const bar = document.getElementById('htmx-top-progress');
        if (bar) {
            bar.classList.remove('htmx-running', 'htmx-complete', 'htmx-fade');
            bar.removeAttribute('style');
        }
    }

    function startProgress() {
        const bar = document.getElementById('htmx-top-progress');
        if (bar) {
            bar.removeAttribute('style');
            bar.classList.remove('htmx-complete', 'htmx-fade');
            void bar.offsetWidth;
            bar.classList.add('htmx-running');
        }
    }

    function finishProgress() {
        const bar = document.getElementById('htmx-top-progress');
        if (bar && bar.classList.contains('htmx-running')) {
            bar.classList.remove('htmx-running');
            bar.classList.add('htmx-complete');
            setTimeout(() => {
                if (bar.classList.contains('htmx-complete')) {
                    bar.classList.remove('htmx-complete');
                    bar.classList.add('htmx-fade');
                    setTimeout(() => {
                        if (bar.classList.contains('htmx-fade')) {
                            bar.classList.remove('htmx-fade');
                            bar.removeAttribute('style');
                        }
                    }, 300);
                }
            }, 250);
        } else if (bar) {
            resetProgress();
        }
    }

    // Start & Finish progress on normal HTMX requests (forward navigation)
    document.addEventListener('htmx:beforeRequest', startProgress);
    document.addEventListener('htmx:afterRequest', finishProgress);

    // Start & Finish progress on browser Back / Forward navigation (backpage history restore)
    window.addEventListener('popstate', function() {
        startProgress();
    });
    document.addEventListener('htmx:historyRestore', function() {
        setTimeout(finishProgress, 50);
    });

    // Clean up on errors / abort / timeout
    document.addEventListener('htmx:sendError', resetProgress);
    document.addEventListener('htmx:responseError', finishProgress);
    document.addEventListener('htmx:abort', resetProgress);
    document.addEventListener('htmx:timeout', resetProgress);
})();

/**
 * TomGPU — GPU Hardware Detection Utility
 * Detects WebGL support, GPU vendor, renderer, and performance tier.
 * Used by TomVisuals to auto-disable blur on unsupported devices.
 */
window.TomGPU = (function() {
    var _cached = null;

    function detect() {
        if (_cached) return _cached;

        var result = {
            webgl: false,
            highPerf: false,
            vendor: 'Unknown',
            renderer: 'Unknown'
        };

        try {
            var canvas = document.createElement('canvas');
            var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
            
            if (!gl) {
                _cached = result;
                return result;
            }

            result.webgl = true;

            // Try to get real GPU info via debug extension
            var debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
            if (debugInfo) {
                result.vendor = gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL) || 'Unknown';
                result.renderer = gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL) || 'Unknown';
            } else {
                result.vendor = gl.getParameter(gl.VENDOR) || 'Unknown';
                result.renderer = gl.getParameter(gl.RENDERER) || 'Unknown';
            }

            // Check if GPU is high performance (not software-rendered)
            var lowPerfIndicators = [
                'swiftshader', 'llvmpipe', 'mesa', 'software',
                'microsoft basic', 'vmware', 'virtualbox', 'chromium',
                'google swiftshader'
            ];
            var rendererLower = result.renderer.toLowerCase();
            var isLowPerf = lowPerfIndicators.some(function(indicator) {
                return rendererLower.indexOf(indicator) !== -1;
            });
            result.highPerf = !isLowPerf;

            // Clean up WebGL context
            var ext = gl.getExtension('WEBGL_lose_context');
            if (ext) ext.loseContext();

        } catch (e) {
            // WebGL detection failed — treat as unsupported
        }

        _cached = result;
        return result;
    }

    return {
        detect: detect,
        isCapable: function() {
            var info = this.detect();
            return info.webgl && info.highPerf;
        },
        checkAndWarn: function() {
            if (!document.body || !document.body.classList.contains('hwa-enabled')) return;
            var gpuInfo = this.detect();
            if (!gpuInfo.webgl || !gpuInfo.highPerf) {
                var reason = !gpuInfo.webgl 
                    ? 'Your browser does not support WebGL.' 
                    : 'Your GPU (' + gpuInfo.renderer + ') is not high-performance.';
                this.startUnsupportedCountdown(reason);
            }
        },
        startUnsupportedCountdown: function(reasonText) {
            if (window._gpuWarningTimer) clearInterval(window._gpuWarningTimer);
            var count = 0;
            var maxWarnings = 3;
            var reason = reasonText || "High Performance GPU not detected.";

            function triggerWarning() {
                count++;
                if (!document.body || !document.body.classList.contains('hwa-enabled')) {
                    if (window._gpuWarningTimer) clearInterval(window._gpuWarningTimer);
                    window._gpuWarningTimer = null;
                    return;
                }
                if (count < maxWarnings) {
                    if (window.TomNotify) {
                        TomNotify.show(
                            reason + ' Warning ' + count + ' of ' + maxWarnings + '. Visual Blur will automatically turn off after 3 warnings.',
                            'Performance Warning (' + count + '/' + maxWarnings + ')',
                            'warning',
                            4500
                        );
                    }
                } else {
                    if (window.TomNotify) {
                        TomNotify.show(
                            reason + ' Warning 3 of 3. Automatically turning off Visual Blur to protect device performance.',
                            'Visual Blur Disabled (3/3)',
                            'warning',
                            6000
                        );
                    }
                    if (window._gpuWarningTimer) clearInterval(window._gpuWarningTimer);
                    window._gpuWarningTimer = null;
                    if (window.TomVisuals && typeof window.TomVisuals.toggleBlur === 'function') {
                        window.TomVisuals.toggleBlur(false);
                    } else {
                        if (document.body) {
                            document.body.classList.remove('hwa-enabled');
                            document.body.classList.add('hwa-disabled');
                        }
                        var toggle = document.getElementById('visualBlurToggle');
                        if (toggle) toggle.checked = false;
                    }
                }
            }

            triggerWarning();
            window._gpuWarningTimer = setInterval(triggerWarning, 5000);
        }
    };
})();

/**
 * Auto-detect GPU capability on page load.
 * Automatically initiates the 3-warning countdown if visual blur is active on an unsupported device.
 */
window.addEventListener('DOMContentLoaded', function() {
    if (window.TomGPU && typeof window.TomGPU.checkAndWarn === 'function') {
        window.TomGPU.checkAndWarn();
    }
});

// =========================================================================
// Global Rate Limit (429) Handler
// Intercepts HTMX responses to show a friendly TomNotify toast.
// =========================================================================
(function() {
    var rateLimitShown = false;
    var RATE_LIMIT_COOLDOWN = 5000;

    function showRateLimitToast(data) {
        if (rateLimitShown) return;
        rateLimitShown = true;
        setTimeout(function() { rateLimitShown = false; }, RATE_LIMIT_COOLDOWN);

        var retry = data.retry_after || 30;
        var msg = data.error || 'Too many requests. Please slow down.';
        if (window.TomNotify) {
            window.TomNotify.show(
                msg + ' Wait ' + retry + 's.',
                'Rate Limited',
                'warning',
                Math.min(retry * 1000, 8000)
            );
        } else {
            alert('Rate Limited: ' + msg);
        }
    }

    // Expose globally so device.js / other modules can call it
    window.showRateLimitToast = showRateLimitToast;

    // 1. HTMX global 429 response handler — prevents swap + shows toast
    document.addEventListener('htmx:beforeSwap', function(evt) {
        var xhr = evt.detail && evt.detail.xhr;
        if (!xhr || xhr.status !== 429) return;

        evt.preventDefault();

        var data = {};
        try { data = JSON.parse(xhr.responseText); } catch(e) {}
        showRateLimitToast(data);
    });

    // 2. Global fetch() 429 interceptor — catches ALL fetch-based API calls
    var originalFetch = window.fetch;
    window.fetch = function() {
        return originalFetch.apply(this, arguments).then(function(response) {
            if (response.status === 429) {
                var clone = response.clone();
                clone.json().then(function(data) {
                    showRateLimitToast(data);
                }).catch(function() {
                    showRateLimitToast({ error: 'Too many requests. Please slow down.', retry_after: 30 });
                });
            }
            return response;
        });
    };
})();
