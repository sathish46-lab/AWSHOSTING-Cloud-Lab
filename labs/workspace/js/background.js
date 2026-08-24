/**
 * Wrapped with IIFE Error Boundary
 */
try {
  (function() {
    "use strict";


window.saveBgModalTab = function(tabId) {
    const fd = new FormData();
    fd.append('preference_id', 'bg_dialog_tab');
    fd.append('value', tabId);
    fetch('/api/user/preference_save', { method: 'POST', body: fd }).catch(() => {});
};

var TomBG = {
  // Theme configuration is now loaded securely from the server via API
  themes: {},

  // Current picker target: 'background' or 'accent'
  pickerTarget: 'background',

  init: function () {
    var _this = this;

    // Use preloaded themes injected by PHP to prevent FOUC / slow visual blur load
    _this.themes = window.TOM_THEMES || {};
    
    // Apply background instantly from localStorage
    var saved = localStorage.getItem("tom-labs-bg-mode") || "spiderman";
    var modeToUse = window.FORCED_BG_MODE || saved;
    _this.apply(modeToUse);

    // Watch for theme changes (Light/Dark mode toggle) to update colors instantly
    var _this = this;
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        if (mutation.attributeName === "data-coreui-theme") {
          var currentMode =
            window.FORCED_BG_MODE ||
            localStorage.getItem("tom-labs-bg-mode") ||
            "spiderman";
          _this.apply(currentMode);
        }
      });
    });

    observer.observe(document.documentElement, {
      attributes: true,
      attributeFilter: ["data-coreui-theme"]
    });

    this.updateCustomSlotsUI();
    this.initAccent();
    this.initScenery();
    this.initModalLoader();

    // Handle editing state when modal opens/closes
    var modalEl = document.getElementById("plainColorModal");
    if (modalEl) {
      modalEl.addEventListener("show.bs.modal", function () {
        // Default to Slot 0 if no specific slot was picked via the wand icon
        if (_this.currentEditingSlot === null) _this.currentEditingSlot = 0;
      });
      modalEl.addEventListener("hidden.bs.modal", function () {
        _this.currentEditingSlot = null;
      });
    }

    // Restore theme state if dropdown is closed without selecting
    var themeToggle = document.querySelector('.theme-selector-btn');
    if (themeToggle) {
      themeToggle.addEventListener('hidden.coreui.dropdown', function () {
        if (window.TomVisuals) window.TomVisuals.syncUI();
      });
    }
  },

  initModalLoader: function () {
    var bgModal = document.getElementById('bgSelectModal');
    if (bgModal) {
      bgModal.addEventListener('show.coreui.modal', function () {
        var contentDiv = document.getElementById('bgSelectModalContent');
        if (!contentDiv || contentDiv.getAttribute('data-loaded') === 'true') return;

        fetch('/api/user/change_bg')
          .then(function(res) { return res.text(); })
          .then(function(html) {
            contentDiv.innerHTML = html;
            contentDiv.setAttribute('data-loaded', 'true');
            if (typeof TomBG.updateCustomSlotsUI === 'function') {
              TomBG.updateCustomSlotsUI();
            }
            var currentMode = localStorage.getItem('tom-labs-bg-mode') || 'spiderman';
            document.querySelectorAll('.theme-template-card').forEach(function(card) {
              if (card.getAttribute('data-mode') === currentMode) {
                card.style.borderColor = 'var(--cui-primary)';
                card.style.boxShadow = '0 0 12px rgba(var(--cui-primary-rgb), 0.35)';
                var src = card.getAttribute('data-bg-src');
                if (src) card.style.backgroundImage = "linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('" + src + "')";
              }
            });
            if ('IntersectionObserver' in window) {
              var observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                  if (entry.isIntersecting) {
                    var card = entry.target;
                    var src = card.getAttribute('data-bg-src');
                    if (src && !card.style.backgroundImage.includes(src)) {
                      card.style.backgroundImage = "linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('" + src + "')";
                    }
                    observer.unobserve(card);
                  }
                });
              }, { rootMargin: '200px' });
              document.querySelectorAll('.theme-template-card').forEach(function(card) {
                if (card.getAttribute('data-mode') !== currentMode) observer.observe(card);
              });
            } else {
              document.querySelectorAll('.theme-template-card').forEach(function(card) {
                var src = card.getAttribute('data-bg-src');
                if (src) card.style.backgroundImage = "linear-gradient(rgba(0,0,0,0.4), rgba(0,0,0,0.4)), url('" + src + "')";
              });
            }
          })
          .catch(function(err) {
            console.error("Failed to load background modal", err);
            contentDiv.innerHTML = '<div class="p-4 text-danger text-center">Failed to load backgrounds. Please try again.</div>';
          });
      });
    }

    var plainModal = document.getElementById('plainColorModal');
    if (plainModal) {
      plainModal.addEventListener('show.coreui.modal', function () {
        var contentDiv = document.getElementById('plainColorModalContent');
        if (!contentDiv) return;
        
        if (contentDiv.getAttribute('data-loaded') === 'true') {
          setTimeout(function() {
            var target = TomBG.pickerTarget === 'accent'
              ? (localStorage.getItem('tom-labs-accent-color') || '#8b91f9')
              : (localStorage.getItem('tom-labs-plain-color') || '#010d12');
            TomBG.syncPickers(target);
          }, 50);
          return;
        }

        fetch('/api/user/plain_theme')
          .then(function(res) { return res.text(); })
          .then(function(html) {
            contentDiv.innerHTML = html;
            contentDiv.setAttribute('data-loaded', 'true');
            
            TomBG.initCustomPicker();
            TomBG.initWheel();
            
            var bgSphere = document.getElementById('designer-sphere-bg');
            var accentSphere = document.getElementById('designer-sphere-accent');
            var previewSphere = document.getElementById('designer-sphere-preview');
            if (bgSphere) { bgSphere.style.cursor = 'pointer'; bgSphere.addEventListener('click', function() { TomBG.switchPickerTarget('background'); }); }
            if (accentSphere) { accentSphere.style.cursor = 'pointer'; accentSphere.addEventListener('click', function() { TomBG.switchPickerTarget('accent'); }); }
            if (previewSphere) { previewSphere.style.cursor = 'pointer'; previewSphere.addEventListener('click', function() { TomBG.switchPickerTarget(TomBG.pickerTarget === 'background' ? 'accent' : 'background'); }); }

            setTimeout(function() {
              var target = TomBG.pickerTarget === 'accent'
                ? (localStorage.getItem('tom-labs-accent-color') || '#8b91f9')
                : (localStorage.getItem('tom-labs-plain-color') || '#010d12');
              TomBG.syncPickers(target);
              TomBG.updateDesignerPreviews();
              TomBG.updateContrastScores();
            }, 50);
          })
          .catch(function(err) {
            console.error("Failed to load plain theme modal", err);
            contentDiv.innerHTML = '<div class="p-4 text-danger text-center">Failed to load designer. Please try again.</div>';
          });
      });
    }
  },

  currentEditingSlot: null,

  // ========================================================================
  // Accent Color System
  // ========================================================================
  initAccent: function () {
    var savedAccent = localStorage.getItem('tom-labs-accent-color');
    if (!savedAccent) {
      // Default accent if none saved
      savedAccent = '#8b91f9';
      localStorage.setItem('tom-labs-accent-color', savedAccent);
    }
    this.updateDesignerPreviews();
    this.updateContrastScores();
  },

  switchPickerTarget: function (target) {
    this.pickerTarget = target;
    var bgBtn = document.getElementById('picker-target-bg');
    var accentBtn = document.getElementById('picker-target-accent');
    if (bgBtn) bgBtn.classList.toggle('active', target === 'background');
    if (accentBtn) accentBtn.classList.toggle('active', target === 'accent');

    // Sync pickers to the currently-editing color
    var color = target === 'accent'
      ? (localStorage.getItem('tom-labs-accent-color') || '#8b91f9')
      : (localStorage.getItem('tom-labs-plain-color') || '#010d12');
    this.syncPickers(color);

    var preview = document.getElementById('hex-preview');
    if (preview) {
      preview.innerText = color.toUpperCase();
      preview.style.color = color;
    }
  },

  setAccentColor: function (hex) {
    hex = this.toHex(hex);
    localStorage.setItem('tom-labs-accent-color', hex);

    // Apply accent as --cui-primary immediately
    var pRGB = this.hexToRgbValues(hex);
    var themeStyle = document.getElementById('tom-theme-vars');
    if (themeStyle) {
      var current = themeStyle.innerHTML;
      // Replace or append accent vars
      current = current.replace(/--cui-primary:\s*[^;]+!important;/g, '--cui-primary: ' + hex + ' !important;');
      current = current.replace(/--cui-primary-rgb:\s*[^;]+!important;/g, '--cui-primary-rgb: ' + pRGB + ' !important;');
      themeStyle.innerHTML = current;
    }

    this.updateDesignerPreviews();
    this.updateContrastScores();
    this.syncToServer();
  },

  // WCAG contrast ratio calculation
  getRelativeLuminance: function (hex) {
    var rgb = hex.match(/[A-Za-z0-9]{2}/g).map(function (x) {
      var c = parseInt(x, 16) / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
  },

  getContrastRatio: function (hex1, hex2) {
    var l1 = this.getRelativeLuminance(hex1);
    var l2 = this.getRelativeLuminance(hex2);
    var lighter = Math.max(l1, l2);
    var darker = Math.min(l1, l2);
    return (lighter + 0.05) / (darker + 0.05);
  },

  updateContrastScores: function () {
    var bg = localStorage.getItem('tom-labs-plain-color') || '#010d12';
    var accent = localStorage.getItem('tom-labs-accent-color') || '#8b91f9';

    var bgLum = this.getRelativeLuminance(bg);
    var isDarkBg = bgLum < 0.5;

    var textRatio = isDarkBg ? this.getContrastRatio(bg, '#ffffff') : this.getContrastRatio(bg, '#000000');
    var accentRatio = this.getContrastRatio(bg, accent);

    var scores = [
      { id: 'contrast-text', ratio: textRatio, label: isDarkBg ? 'White on bg' : 'Black on bg' },
      { id: 'contrast-accent', ratio: accentRatio, label: 'Accent on bg' }
    ];

    scores.forEach(function (s) {
      var valEl = document.getElementById(s.id + '-val');
      var barEl = document.getElementById(s.id + '-bar');
      var badgeEl = document.getElementById(s.id + '-badge');
      var labelEl = document.getElementById(s.id + '-label');
      
      if (labelEl) labelEl.textContent = s.label;
      if (!valEl) return;

      var ratio = Math.round(s.ratio * 10) / 10;
      valEl.textContent = ratio.toFixed(1);

      var pct = Math.min(100, (ratio / 21) * 100);
      if (barEl) barEl.style.width = pct + '%';

      if (badgeEl) {
        if (ratio >= 7) {
          badgeEl.textContent = 'AAA';
          badgeEl.className = 'contrast-badge badge-aaa';
        } else if (ratio >= 4.5) {
          badgeEl.textContent = 'AA';
          badgeEl.className = 'contrast-badge badge-aa';
        } else {
          badgeEl.textContent = 'Fail';
          badgeEl.className = 'contrast-badge badge-fail';
        }
      }
    });

    // Update overall score header (uses the weakest link)
    var minRatio = Math.min(textRatio, accentRatio);
    var overallScore = document.getElementById('contrast-overall-score');
    var overallLabel = document.getElementById('contrast-overall-label');
    var stars = document.getElementById('contrast-stars');

    if (overallScore && overallLabel && stars) {
      overallScore.textContent = minRatio.toFixed(1);
      
      var starCount = 1;
      if (minRatio >= 7) {
        overallLabel.textContent = 'Excellent';
        overallLabel.className = 'fw-semibold text-success';
        starCount = 5;
      } else if (minRatio >= 4.5) {
        overallLabel.textContent = 'Good';
        overallLabel.className = 'fw-semibold text-success';
        starCount = 4;
      } else if (minRatio >= 3.0) {
        overallLabel.textContent = 'Poor';
        overallLabel.className = 'fw-semibold text-warning';
        starCount = 2;
      } else {
        overallLabel.textContent = 'Terrible';
        overallLabel.className = 'fw-semibold text-danger';
        starCount = 1;
      }

      var starHtml = '';
      for (var i = 0; i < 5; i++) {
        if (i < starCount) {
          starHtml += `<i class='bx bxs-star' style="font-size: 0.7rem; color: #fbbf24;"></i>`;
        } else {
          starHtml += `<i class='bx bx-star' style="font-size: 0.7rem; color: rgba(255,255,255,0.2);"></i>`;
        }
      }
      stars.innerHTML = starHtml;
    }
  },

  enhanceAccent: function () {
    var bg = localStorage.getItem('tom-labs-plain-color') || '#010d12';
    var accent = localStorage.getItem('tom-labs-accent-color') || '#8b91f9';
    var ratio = this.getContrastRatio(bg, accent);

    if (ratio >= 4.5) {
      if (typeof TomNotify !== 'undefined') {
        TomNotify.show('Accent already passes AA contrast!', 'Enhance', 'success', 3000);
      }
      return;
    }

    // Determine if we need to lighten or darken accent
    var bgLum = this.getRelativeLuminance(bg);
    var step = bgLum > 0.5 ? -5 : 5;
    var enhanced = accent;
    var maxIter = 60;

    for (var i = 0; i < maxIter; i++) {
      enhanced = this.adjustColor(enhanced, step);
      var newRatio = this.getContrastRatio(bg, enhanced);
      if (newRatio >= 4.5) break;
    }

    this.setAccentColor(enhanced);
    this.syncPickers(enhanced);
    this.updateContrastScores(bg, enhanced);

    var preview = document.getElementById('hex-preview');
    if (preview && this.pickerTarget === 'accent') {
      preview.innerText = enhanced.toUpperCase();
      preview.style.color = enhanced;
    }

    if (typeof TomNotify !== 'undefined') {
      var finalRatio = this.getContrastRatio(bg, enhanced);
      TomNotify.show('Accent enhanced to ' + (Math.round(finalRatio * 10) / 10) + ':1 contrast ratio', 'Enhance', 'success', 3000);
    }
  },

  updateDesignerPreviews: function () {
    var bg = localStorage.getItem('tom-labs-plain-color') || '#010d12';
    var accent = localStorage.getItem('tom-labs-accent-color') || '#8b91f9';

    // Background sphere
    var bgSphere = document.getElementById('designer-sphere-bg');
    if (bgSphere) bgSphere.style.background = 'radial-gradient(circle at 35% 30%, ' + this.adjustColor(bg, 25) + ', ' + bg + ' 70%)';

    // Accent sphere
    var accentSphere = document.getElementById('designer-sphere-accent');
    if (accentSphere) accentSphere.style.background = 'radial-gradient(circle at 35% 30%, ' + this.adjustColor(accent, 25) + ', ' + accent + ' 70%)';

    // Preview combined sphere
    var previewSphere = document.getElementById('designer-sphere-preview');
    var previewDot = document.getElementById('designer-sphere-preview-dot');
    if (previewSphere) previewSphere.style.background = 'radial-gradient(circle at 35% 30%, ' + this.adjustColor(bg, 20) + ', ' + bg + ' 70%)';
    if (previewDot) previewDot.style.background = accent;
  },

  // Save custom theme with both bg + accent
  saveCustomTheme: function (index) {
    var bg = localStorage.getItem('tom-labs-plain-color') || '#010d12';
    var accent = localStorage.getItem('tom-labs-accent-color') || '#8b91f9';
    var theme = JSON.stringify({ bg: bg, accent: accent });
    localStorage.setItem('tom-labs-custom-theme-' + index, theme);
    // Also keep legacy single-color for backward compat
    localStorage.setItem('tom-labs-custom-color-' + index, bg);
    this.updateCustomSlotsUI();
    this.syncToServer();

    if (typeof TomNotify !== 'undefined') {
      TomNotify.show('Custom theme saved to slot ' + (index + 1), 'Saved', 'success', 3000);
    }
  },

  applyCustomTheme: function (index) {
    var themeStr = localStorage.getItem('tom-labs-custom-theme-' + index);
    if (themeStr) {
      try {
        var theme = JSON.parse(themeStr);
        this.setPlainColor(theme.bg);
        this.setAccentColor(theme.accent);
        this.setMode('plain');
        return;
      } catch (e) {}
    }
    // Fallback to legacy single color
    var slotColor = localStorage.getItem('tom-labs-custom-color-' + index);
    if (slotColor) {
      this.setPlainColor(slotColor);
      this.setMode('plain');
    }
  },

  deleteCustomTheme: function (index) {
    // We want to delete the slot at `index`, but since custom slots are populated 0..N,
    // we should shift the higher index themes down by 1 so there are no empty gaps.
    var maxSlots = 10;
    for (var i = index; i < maxSlots - 1; i++) {
      var nextTheme = localStorage.getItem('tom-labs-custom-theme-' + (i + 1));
      var nextColor = localStorage.getItem('tom-labs-custom-color-' + (i + 1));
      
      if (nextTheme) {
        localStorage.setItem('tom-labs-custom-theme-' + i, nextTheme);
      } else {
        localStorage.removeItem('tom-labs-custom-theme-' + i);
      }
      
      if (nextColor) {
        localStorage.setItem('tom-labs-custom-color-' + i, nextColor);
      } else {
        localStorage.removeItem('tom-labs-custom-color-' + i);
      }
    }
    
    // Clear the very last slot
    localStorage.removeItem('tom-labs-custom-theme-' + (maxSlots - 1));
    localStorage.removeItem('tom-labs-custom-color-' + (maxSlots - 1));
    
    this.updateCustomSlotsUI();
    this.syncToServer();
    
    if (typeof TomNotify !== 'undefined') {
      TomNotify.show('Custom theme deleted', 'Deleted', 'warning', 3000);
    }
  },

  openDesignerForSlot: function (index) {
    this.currentEditingSlot = index;
    // Load existing theme data
    var themeStr = localStorage.getItem('tom-labs-custom-theme-' + index);
    if (themeStr) {
      try {
        var theme = JSON.parse(themeStr);
        this.setPlainColor(theme.bg);
        this.setAccentColor(theme.accent);
      } catch (e) {
        var slotColor = localStorage.getItem('tom-labs-custom-color-' + index) || '#ffffff';
        this.setPlainColor(slotColor);
      }
    } else {
      var slotColor = localStorage.getItem('tom-labs-custom-color-' + index) || '#ffffff';
      this.setPlainColor(slotColor);
    }
    this.switchPickerTarget('background');
    var mEl = document.getElementById('plainColorModal');
    var m = coreui.Modal.getInstance(mEl) || new coreui.Modal(mEl);
    m.show();
  },

  applySlot: function (index) {
    var themeStr = localStorage.getItem('tom-labs-custom-theme-' + index);
    if (themeStr) {
      this.applyCustomTheme(index);
    } else {
      var slotColor = localStorage.getItem('tom-labs-custom-color-' + index);
      if (slotColor) {
        this.setPlainColor(slotColor);
      } else {
        this.openDesignerForSlot(index);
      }
    }
  },

  initWheel: function () {
    var wheel = document.getElementById("color-wheel");
    if (!wheel) return;

    var isDragging = false;

    var updateFromWheel = function (e) {
      var rect = wheel.getBoundingClientRect();
      var centerX = rect.width / 2;
      var centerY = rect.height / 2;
      var clientX = e.clientX || (e.touches && e.touches[0].clientX);
      var clientY = e.clientY || (e.touches && e.touches[0].clientY);
      var x = clientX - rect.left - centerX;
      var y = clientY - rect.top - centerY;

      // Calculate Angle (Hue)
      var angle = Math.atan2(y, x) * (180 / Math.PI) + 90;
      if (angle < 0) angle += 360;

      // Calculate Distance (Saturation)
      var dist = Math.sqrt(x * x + y * y);
      var radius = rect.width / 2;
      var s = Math.min(100, (dist / radius) * 100);
      var v = document.getElementById("brightness-slider") ? document.getElementById("brightness-slider").value : 100;

      var hex = TomBG.hsvToHex(angle, s, v);
      TomBG.setPlainColor(hex);

      // Update wheel cursor position
      var cursor = document.getElementById("wheel-cursor");
      if (cursor) {
        cursor.style.left = (centerX + x) + "px";
        cursor.style.top = (centerY + y) + "px";
      }
    };

    wheel.addEventListener("mousedown", function (e) {
      isDragging = true;
      updateFromWheel(e);
    });

    window.addEventListener("mousemove", function (e) {
      if (isDragging) updateFromWheel(e);
    });

    window.addEventListener("mouseup", function () {
      isDragging = false;
    });
  },

  updateBrightness: function (val) {
    var valBright = document.getElementById("val-bright");
    if (valBright) valBright.innerText = val + "%";
    var currentHex = localStorage.getItem("tom-labs-plain-color") || "#010d12";
    var hsv = this.hexToHsv(currentHex);
    var newHex = this.hsvToHex(hsv.h, hsv.s, val);
    this.setPlainColor(newHex);
  },

  hexToHsv: function (hex) {
    var rgb = hex.match(/[A-Za-z0-9]{2}/g).map(function (x) { return parseInt(x, 16) / 255; });
    var max = Math.max.apply(Math, rgb), min = Math.min.apply(Math, rgb);
    var d = max - min;
    var h, s = max === 0 ? 0 : d / max, v = max;
    if (max === min) h = 0;
    else {
      switch (max) {
        case rgb[0]: h = (rgb[1] - rgb[2]) / d + (rgb[1] < rgb[2] ? 6 : 0); break;
        case rgb[1]: h = (rgb[2] - rgb[0]) / d + 2; break;
        case rgb[2]: h = (rgb[0] - rgb[1]) / d + 4; break;
      }
      h /= 6;
    }
    return { h: h * 360, s: s * 100, v: v * 100 };
  },

  initCustomPicker: function () {
    var specMap = document.getElementById("spectrum-map");
    if (!specMap) return;

    var isDragging = false;

    var updateFromMap = function (e) {
      var rect = specMap.getBoundingClientRect();
      var clientX = e.clientX || (e.touches && e.touches[0].clientX);
      var clientY = e.clientY || (e.touches && e.touches[0].clientY);
      var x = Math.max(0, Math.min(rect.width, clientX - rect.left));
      var y = Math.max(0, Math.min(rect.height, clientY - rect.top));

      var h = (x / rect.width) * 360;
      var s, v;

      if (y < rect.height / 2) {
        s = (y / (rect.height / 2)) * 100;
        v = 100;
      } else {
        s = 100;
        v = 100 - ((y - rect.height / 2) / (rect.height / 2)) * 100;
      }

      var hex = TomBG.hsvToHex(h, s, v);
      TomBG.setPlainColor(hex);

      var cursor = document.getElementById("spectrum-cursor");
      if (cursor) {
        cursor.style.left = x + "px";
        cursor.style.top = y + "px";
      }
    };

    specMap.addEventListener("mousedown", function (e) {
      isDragging = true;
      updateFromMap(e);
    });

    window.addEventListener("mousemove", function (e) {
      if (isDragging) updateFromMap(e);
    });

    window.addEventListener("mouseup", function () {
      isDragging = false;
    });

    // Palettes
    document.querySelectorAll(".palette-item").forEach(function (p) {
      p.onclick = function () { TomBG.setPlainColor(p.style.backgroundColor); };
    });

    // Pencils
    document.querySelectorAll(".pencil-item").forEach(function (p) {
      p.onclick = function () { TomBG.setPlainColor(p.getAttribute("data-color")); };
    });
  },

  hsvToHex: function (h, s, v) {
    s /= 100;
    v /= 100;
    var i = Math.floor(h / 60);
    var f = h / 60 - i;
    var p = v * (1 - s);
    var q = v * (1 - f * s);
    var t = v * (1 - (1 - f) * s);
    var r, g, b;
    switch (i % 6) {
      case 0: r = v, g = t, b = p; break;
      case 1: r = q, g = v, b = p; break;
      case 2: r = p, g = v, b = t; break;
      case 3: r = p, g = q, b = v; break;
      case 4: r = t, g = p, b = v; break;
      case 5: r = v, g = p, b = q; break;
    }
    var toHex = function (x) {
      var hex = Math.round(x * 255).toString(16);
      return hex.length === 1 ? "0" + hex : hex;
    };
    return "#" + toHex(r) + toHex(g) + toHex(b);
  },

  switchPickerTab: function (tabId) {
    // Update buttons
    var tabs = document.querySelectorAll(".designer-tab");
    tabs.forEach(function (t) {
      var title = t.getAttribute("title").toLowerCase();
      if (title === tabId) {
        t.classList.add("active");
      } else {
        t.classList.remove("active");
      }
    });

    // Update content
    var modes = document.querySelectorAll(".picker-mode");
    modes.forEach(function (m) {
      m.classList.add("d-none");
      m.classList.remove("active");
    });

    var activeMode = document.getElementById("picker-" + tabId);
    if (activeMode) {
      activeMode.classList.remove("d-none");
      activeMode.classList.add("active");
    }

    // Toggle global brightness slider for visual modes
    var brightCont = document.getElementById("global-brightness-cont");
    if (brightCont) {
      if (tabId === "wheel" || tabId === "spectrum") {
        brightCont.classList.remove("d-none");
      } else {
        brightCont.classList.add("d-none");
      }
    }
  },

  updateFromSliders: function () {
    var r = document.querySelector(".rgb-slider.r").value;
    var g = document.querySelector(".rgb-slider.g").value;
    var b = document.querySelector(".rgb-slider.b").value;

    var valR = document.getElementById("val-r");
    var valG = document.getElementById("val-g");
    var valB = document.getElementById("val-b");
    if (valR) valR.innerText = r;
    if (valG) valG.innerText = g;
    if (valB) valB.innerText = b;

    var toHex = function (c) {
      var hex = parseInt(c).toString(16);
      return hex.length === 1 ? "0" + hex : hex;
    };
    var hex = "#" + toHex(r) + toHex(g) + toHex(b);
    this.setPlainColor(hex);
  },

  syncPickers: function (hex) {
    var hsv = this.hexToHsv(hex);
    var rgb = hex.match(/[A-Za-z0-9]{2}/g).map(function (v) { return parseInt(v, 16); });

    // 1. Sync Sliders
    var rSlider = document.querySelector(".rgb-slider.r");
    if (rSlider) {
      rSlider.value = rgb[0];
      document.querySelector(".rgb-slider.g").value = rgb[1];
      document.querySelector(".rgb-slider.b").value = rgb[2];
      var valR = document.getElementById("val-r");
      var valG = document.getElementById("val-g");
      var valB = document.getElementById("val-b");
      if (valR) valR.innerText = rgb[0];
      if (valG) valG.innerText = rgb[1];
      if (valB) valB.innerText = rgb[2];
    }

    // 2. Sync Spectrum Cursor
    var specMap = document.getElementById("spectrum-map");
    var specCursor = document.getElementById("spectrum-cursor");
    if (specMap && specCursor) {
      var rect = specMap.getBoundingClientRect();
      var x = (hsv.h / 360) * rect.width;
      var y;
      if (hsv.v === 100) {
        y = (hsv.s / 100) * (rect.height / 2);
      } else {
        y = rect.height / 2 + (1 - hsv.v / 100) * (rect.height / 2);
      }
      specCursor.style.left = x + "px";
      specCursor.style.top = y + "px";
    }

    // 3. Sync Wheel Cursor
    var wheel = document.getElementById("color-wheel");
    var wheelCursor = document.getElementById("wheel-cursor");
    if (wheel && wheelCursor) {
      var rect = wheel.getBoundingClientRect();
      var centerX = rect.width / 2;
      var centerY = rect.height / 2;
      var angle = (hsv.h - 90) * (Math.PI / 180);
      var radius = (hsv.s / 100) * (rect.width / 2);
      var x = centerX + radius * Math.cos(angle);
      var y = centerY + radius * Math.sin(angle);
      wheelCursor.style.left = x + "px";
      wheelCursor.style.top = y + "px";
    }

    // 4. Sync Brightness Slider
    var bSlider = document.getElementById("brightness-slider");
    if (bSlider) {
      bSlider.value = Math.round(hsv.v);
      var valBright = document.getElementById("val-bright");
      if (valBright) valBright.innerText = Math.round(hsv.v) + "%";
    }
  },

  setPlainColor: function (color) {
    var hex = this.toHex(color);

    // If pickerTarget is 'accent', route to accent color instead
    if (this.pickerTarget === 'accent') {
      this.setAccentColor(hex);
      // Sync preview
      var preview = document.getElementById('hex-preview');
      if (preview) {
        preview.innerText = hex.toUpperCase();
        preview.style.color = hex;
      }
      this.syncPickers(hex);
      return;
    }

    // Default to Slot 0 if no slot is active, ensuring the Designer always has a target
    var targetSlot = (this.currentEditingSlot !== null) ? this.currentEditingSlot : 0;

    // Save to the target slot (Real-time update)
    this.saveCustomColor(targetSlot, hex);

    // Always update main theme and apply immediately for real-time feedback
    localStorage.setItem("tom-labs-plain-color", hex);
    localStorage.setItem("tom-labs-bg-mode", "plain");
    this.apply("plain");
    this.syncToServer();

    // Sync preview
    var preview = document.getElementById("hex-preview");
    if (preview) {
      preview.innerText = hex.toUpperCase();
      preview.style.color = hex;
    }

    // Update active tab icon color ONLY
    var activeTab = document.querySelector(".designer-tab.active i");
    if (activeTab) {
      activeTab.style.color = hex;
    }

    // Reset other icons to default opacity
    document.querySelectorAll(".designer-tab:not(.active) i").forEach(function (i) { i.style.color = ""; });

    // Update designer previews + contrast
    this.updateDesignerPreviews();
    this.updateContrastScores();

    // Global Sync all other pickers
    this.syncPickers(hex);
  },

  apply: function (mode) {
    var scene = document.getElementById("scene");
    if (!scene) return;

    var root = document.documentElement;
    var isLight = root.getAttribute("data-coreui-theme") === "light";
    var sc = document.querySelector(".scenery-container");

    // Dynamically toggle bg-mode classes on the root HTML element
    var classesToRemove = [];
    for (var i = 0; i < root.classList.length; i++) {
      var cls = root.classList[i];
      if (cls && cls.indexOf("bg-mode-") === 0) {
        classesToRemove.push(cls);
      }
    }
    classesToRemove.forEach(function (c) { root.classList.remove(c); });
    root.classList.add("bg-mode-" + mode);

    var cssVars = "";
    var setVar = function (name, value) {
      cssVars += name + ": " + value + "; ";
    };

    if (mode === "plain") {
      var color = localStorage.getItem("tom-labs-plain-color") || "#0b1e36";
      if (sc) sc.style.display = "none";
      scene.style.display = "none";
      document.body.style.setProperty('background-color', 'var(--cui-body-bg)', 'important');
      root.classList.add("mode-plain");

      var safeColor = isLight ? this.ensureLightness(color, 0.95) : this.ensureDarkness(color, 0.2);

      if (isLight) {
        var baseLight = this.ensureLightness(color, 0.98);
        setVar("--c1", "#ffffff");
        setVar("--c2", baseLight);
        setVar("--c3", this.adjustColor(baseLight, -2));
        setVar("--c4", this.adjustColor(baseLight, -5));
        setVar("--c5", "#ffffff");
        setVar("--c6", baseLight);
        setVar("--c7", this.adjustColor(baseLight, -3));
        setVar("--snow-color", "rgba(0,0,0,0.05)");
      } else {
        setVar("--c1", this.adjustColor(safeColor, -10));
        setVar("--c2", safeColor);
        setVar("--c3", this.adjustColor(safeColor, 8));
        setVar("--c4", this.adjustColor(safeColor, 15));
        setVar("--c5", this.adjustColor(safeColor, 5));
        setVar("--c6", safeColor);
        setVar("--c7", this.adjustColor(safeColor, -5));
        setVar("--snow-color", "#ffffff");
      }

      // Use stored accent color instead of auto-deriving from background
      var savedAccent = localStorage.getItem('tom-labs-accent-color');
      var primaryColor = savedAccent || this.adjustColor(color, isLight ? -40 : 40);
      var pRGB = this.hexToRgbValues(primaryColor);

      setVar("--glass-bg", isLight ? "#ffffff" : this.hexToRgba(safeColor, 0.88));
      setVar("--glass-bg-solid", isLight ? "#ffffff" : this.hexToRgba(safeColor, 0.96));
      setVar("--cui-card-bg", isLight ? "#ffffff" : this.hexToRgba(this.adjustColor(safeColor, 6), 0.65));
      setVar("--cui-card-bg-solid", isLight ? "#ffffff" : this.hexToRgba(this.adjustColor(safeColor, 5), 0.98));
      setVar("--cui-body-bg", isLight ? safeColor : this.adjustColor(safeColor, 3));
      setVar("--cui-primary", primaryColor);
      setVar("--cui-primary-rgb", pRGB);
      setVar("--cui-sidebar-bg", isLight ? "#ffffff" : this.hexToRgba(safeColor, 0.98));
      setVar("--cui-header-bg", isLight ? "#ffffff" : this.hexToRgba(safeColor, 0.92));

      if (typeof TomParallax !== "undefined") TomParallax.destroy();
    } else {
      scene.style.display = "block";
      document.body.style.setProperty('background', 'transparent', 'important');
      if (sc) {
        sc.style.display = "block";
        sc.style.opacity = "0";
      }
      root.classList.remove("mode-plain");

      var theme = this.themes[mode] || this.themes["spiderman"];
      var assets = theme.assets || theme;
      var themeColor = theme.color || "#0b1e36";

      if (themeColor) {
        var safeColor = isLight ? this.ensureLightness(themeColor, 0.8) : this.ensureDarkness(themeColor, 0.15);
        var primaryColor = theme.primary || this.adjustColor(themeColor, isLight ? -40 : 40);
        var pRGB = this.hexToRgbValues(primaryColor);

        setVar("--glass-bg", isLight ? "rgba(255, 255, 255, 0.79)" : this.hexToRgba(safeColor, 0.85));
        setVar("--glass-bg-solid", isLight ? this.hexToRgba(this.ensureLightness(themeColor, 0.92), 0.94) : this.hexToRgba(safeColor, 0.94));
        setVar("--cui-card-bg", isLight ? "rgba(255, 255, 255, 0.7)" : this.hexToRgba(safeColor, 0.85));
        setVar("--cui-card-bg-solid", isLight ? this.hexToRgba(this.ensureLightness(themeColor, 0.96), 0.94) : this.hexToRgba(safeColor, 0.95));
        setVar("--cui-body-bg", safeColor);
        setVar("--cui-primary", primaryColor);
        setVar("--cui-primary-rgb", pRGB);
        setVar("--cui-sidebar-bg", isLight ? "rgba(255, 255, 255, 0.6)" : this.hexToRgba(safeColor, 0.95));
        setVar("--cui-header-bg", isLight ? "rgba(255, 255, 255, 0.4)" : this.hexToRgba(safeColor, 0.85));

        setVar("--c1", isLight ? "#ffffff" : this.adjustColor(safeColor, -5));
        setVar("--c2", isLight ? "#f8f9fa" : safeColor);
        setVar("--c3", isLight ? "#ffffff" : this.adjustColor(safeColor, 5));
        setVar("--c4", isLight ? "#f0f2f5" : this.adjustColor(safeColor, 10));
      }

      var layers = scene.querySelectorAll(".bg-cover");
      layers.forEach(function (layer, index) {
        if (assets[index]) {
          layer.style.backgroundImage = "url('" + assets[index] + "')";
          layer.style.display = "block";
        } else {
          layer.style.backgroundImage = "none";
          layer.style.display = "none";
        }
      });

      if (typeof TomParallax !== "undefined") TomParallax.init();
    }

    // Inject variables into a clean style tag to keep HTML tag simple like SNA
    var themeStyle = document.getElementById("tom-theme-vars");
    if (!themeStyle) {
      themeStyle = document.createElement("style");
      themeStyle.id = "tom-theme-vars";
      document.head.appendChild(themeStyle);
    }
    themeStyle.innerHTML = `html[data-coreui-theme] { ${cssVars} }`;

    // Remove all style attributes from root to keep it clean like SNA
    this.updateActiveSwatchUI();
    root.removeAttribute("style");
  },

  adjustColor: function (hex, percent) {
    var num = parseInt(hex.replace("#", ""), 16),
      amt = Math.round(2.55 * percent),
      R = (num >> 16) + amt,
      B = (num >> 8 & 0x00ff) + amt,
      G = (num & 0x0000ff) + amt;
    return (
      "#" +
      (
        0x1000000 +
        (R < 255 ? (R < 1 ? 0 : R) : 255) * 0x10000 +
        (B < 255 ? (B < 1 ? 0 : B) : 255) * 0x100 +
        (G < 255 ? (G < 1 ? 0 : G) : 255)
      )
        .toString(16)
        .slice(1)
    );
  },

  hexToRgba: function (hex, opacity) {
    var rgb = this.hexToRgbValues(hex);
    return "rgba(" + rgb + ", " + opacity + ")";
  },

  hexToRgbValues: function (hex) {
    var num = parseInt(hex.replace("#", ""), 16),
      R = (num >> 16) & 0xff,
      G = (num >> 8) & 0xff,
      B = num & 0xff;
    return R + ", " + G + ", " + B;
  },

  ensureDarkness: function (hex, maxLuminance) {
    var rgbVal = this.hexToRgbValues(hex);
    var rgb = rgbVal.split(",").map(Number);
    var luminance = (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;

    if (luminance > maxLuminance) {
      var factor = maxLuminance / luminance;
      var r = Math.round(rgb[0] * factor);
      var g = Math.round(rgb[1] * factor);
      var b = Math.round(rgb[2] * factor);
      var toHexStr = function (c) {
        var h = c.toString(16);
        return h.length === 1 ? "0" + h : h;
      };
      return "#" + toHexStr(r) + toHexStr(g) + toHexStr(b);
    }
    return hex;
  },

  ensureLightness: function (hex, minLuminance) {
    var rgbVal = this.hexToRgbValues(hex);
    var rgb = rgbVal.split(",").map(Number);
    var luminance = (0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2]) / 255;

    if (luminance < minLuminance) {
      var factor = (1 - minLuminance) / (1 - luminance);
      var r = Math.round(255 - (255 - rgb[0]) * factor);
      var g = Math.round(255 - (255 - rgb[1]) * factor);
      var b = Math.round(255 - (255 - rgb[2]) * factor);
      var toHexStr = function (c) {
        var h = c.toString(16);
        return h.length === 1 ? "0" + h : h;
      };
      return "#" + toHexStr(r) + toHexStr(g) + toHexStr(b);
    }
    return hex;
  },

  extractColorFromImage: function (imageSrc, callback) {
    var img = new Image();
    img.crossOrigin = "anonymous";
    img.onload = function () {
      try {
        var canvas = document.createElement("canvas");
        var ctx = canvas.getContext("2d");
        var size = 50;
        canvas.width = size;
        canvas.height = size;
        ctx.drawImage(img, 0, 0, size, size);
        var data = ctx.getImageData(0, 0, size, size).data;
        var r = 0, g = 0, b = 0, count = 0;
        for (var i = 0; i < data.length; i += 16) {
          r += data[i];
          g += data[i + 1];
          b += data[i + 2];
          count++;
        }
        r = Math.round(r / count);
        g = Math.round(g / count);
        b = Math.round(b / count);
        var hex = "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
        callback(hex);
      } catch (e) {
        console.error("CORS or Canvas Error in color extraction:", e);
        callback("#010d12");
      }
    };
    img.onerror = function () {
      callback("#010d12");
    };
    img.src = imageSrc;
  },

  saveCustomColor: function (index, color) {
    var hex = this.toHex(color);
    localStorage.setItem("tom-labs-custom-color-" + index, hex);
    this.updateCustomSlotsUI();
    this.syncToServer();
  },

  setMode: function (mode) {
    // Auto-switch theme (light/dark) to match the visible background grid
    // This ensures that selecting a dark wallpaper also switches to dark theme and vice versa
    var visibleGrid = document.querySelector('.theme-bg-grid[style*="display: grid"], .theme-bg-grid:not(.d-none):not([style*="display: none"])');
    if (visibleGrid) {
      var gridTheme = visibleGrid.getAttribute('data-theme');
      var currentTheme = localStorage.getItem('tom-labs-theme') || 'dark';
      if (gridTheme && gridTheme !== currentTheme) {
        // Switch the full theme (colors, sidebar, header, icon) to match the grid
        if (typeof window.changeTheme === 'function') {
          window.changeTheme(gridTheme);
        }
      }
    }

    localStorage.setItem("tom-labs-bg-mode", mode);
    this.apply(mode);

    if (mode !== "plain") {
      // Force instant save and full page reload for image backgrounds
      var plainColor = localStorage.getItem("tom-labs-plain-color");
      var accentColor = localStorage.getItem("tom-labs-accent-color");
      var customSlots = [];
      var customThemes = [];
      for (var i = 0; i < 10; i++) {
        customSlots.push(localStorage.getItem("tom-labs-custom-color-" + i));
        customThemes.push(localStorage.getItem("tom-labs-custom-theme-" + i));
      }
      fetch('/api/account/theme_save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          mode: mode, plainColor: plainColor, accentColor: accentColor, customSlots: customSlots, customThemes: customThemes
        })
      }).finally(function() {
        window.location.reload();
      });
      return;
    }

    this.syncToServer();

    // Update thumbnails UI for the new Mega Dropdown
    document.querySelectorAll('.theme-bg-item').forEach(function (item) {
      item.classList.toggle('active', item.getAttribute('data-mode') === mode);
    });
  },

  updateCustomSlotsUI: function () {
    var container = document.getElementById('dynamic-custom-slots');
    if (!container) return;

    // Clear existing dynamically added slots
    var existingSlots = container.querySelectorAll('.dynamic-slot');
    existingSlots.forEach(function(slot) { slot.remove(); });

    var slotCount = 0;
    var maxSlots = 10;
    var html = '';

    for (var i = 0; i < maxSlots; i++) {
      var themeStr = localStorage.getItem('tom-labs-custom-theme-' + i);
      var colorStr = localStorage.getItem("tom-labs-custom-color-" + i);
      
      if (themeStr || colorStr) {
        slotCount++;
        var bg = "rgba(255,255,255,0.05)";
        var accent = "";
        var hasAccent = false;

        if (themeStr) {
          try {
            var theme = JSON.parse(themeStr);
            bg = theme.bg;
            accent = theme.accent;
            hasAccent = true;
          } catch(e){}
        } else if (colorStr) {
          bg = colorStr;
        }

        var gradient = 'radial-gradient(circle at 35% 30%, ' + this.adjustColor(bg, 25) + ', ' + bg + ' 70%)';

        html += '<div class="text-center dynamic-slot position-relative swatch-sphere-wrap swatch-item" style="width: 72px;" data-bg="' + bg + '" data-accent="' + accent + '" onmouseenter="this.querySelector(\'.action-btns\').style.opacity=\'1\'" onmouseleave="this.querySelector(\'.action-btns\').style.opacity=\'0\'">' +
              '<button class="swatch-circle pointer" title="Custom ' + (i+1) + '" onclick="TomBG.applyCustomTheme(' + i + ')" style="background: transparent; border: none; padding: 0; cursor: pointer; outline: none; position: relative;">' +
                  '<svg width="52" height="52" viewBox="0 0 48 48" style="filter: drop-shadow(0 4px 6px rgba(0,0,0,0.1));">' +
                      '<clipPath id="top-custom-' + i + '">' +
                          '<path d="M24 4 A20 20 0 0 1 44 24 L4 24 A20 20 0 0 1 24 4Z"></path>' +
                      '</clipPath>' +
                      '<clipPath id="bot-custom-' + i + '">' +
                          '<path d="M4 24 L44 24 A20 20 0 0 1 24 44 A20 20 0 0 1 4 24Z"></path>' +
                      '</clipPath>' +
                      '<circle cx="24" cy="24" r="22" fill="' + bg + '" clip-path="url(#top-custom-' + i + ')"></circle>' +
                      '<circle cx="24" cy="24" r="22" fill="' + (hasAccent ? '#ffffff' : '#f0f0f0') + '" clip-path="url(#bot-custom-' + i + ')"></circle>' +
                      '<circle cx="24" cy="24" r="22" fill="none" stroke="' + (hasAccent ? accent : bg) + '" stroke-width="2"></circle>' +
                      '<circle cx="24" cy="24" r="6" fill="' + (hasAccent ? accent : bg) + '"></circle>' +
                  '</svg>' +
                  '<div class="active-badge position-absolute shadow-sm" style="top: -2px; right: -2px; width: 16px; height: 16px; background: #22c55e; border-radius: 50%; color: white; display: none; align-items: center; justify-content: center; font-size: 11px; border: 2px solid var(--cui-body-bg); z-index: 2;">' +
                      '<i class=\'bx bx-check fw-bold\'></i>' +
                  '</div>' +
              '</button>' +
              '<span class="d-block text-body-secondary" style="font-size: 0.65rem; font-weight: 500; line-height: 1.2;">Custom ' + (i + 1) + '</span>' +
              '<div class="action-btns position-absolute w-100 d-flex justify-content-between" style="top: -5px; padding: 0 2px; opacity: 0; transition: opacity 0.2s ease; pointer-events: none; z-index: 5;">' +
                  '<button class="btn btn-sm btn-success rounded-circle shadow-sm d-flex align-items-center justify-content-center p-0 action-btn" ' +
                          'onclick="TomBG.openDesignerForSlot(' + i + '); event.stopPropagation();" ' +
                          'style="width: 20px; height: 20px; border: 1px solid rgba(255,255,255,0.5); pointer-events: auto; background-color: #22c55e;" title="Edit">' +
                      '<i class=\'bx bxs-pencil\' style="font-size: 10px; color: #fff;"></i>' +
                  '</button>' +
                  '<button class="btn btn-sm btn-danger rounded-circle shadow-sm d-flex align-items-center justify-content-center p-0 action-btn" ' +
                          'onclick="TomBG.deleteCustomTheme(' + i + '); event.stopPropagation();" ' +
                          'style="width: 20px; height: 20px; border: 1px solid rgba(255,255,255,0.5); pointer-events: auto; background-color: #ef4444;" title="Delete">' +
                      '<i class=\'bx bx-x\' style="font-size: 14px; color: #fff;"></i>' +
                  '</button>' +
              '</div>' +
          '</div>';
      }
    }

    // Insert before the 'Create New' button
    var createNewBtn = container.querySelector('.create-new-slot');
    if (createNewBtn) {
      createNewBtn.insertAdjacentHTML('beforebegin', html);
      // Hide Create New button if we reached the max limit
      createNewBtn.style.display = slotCount >= maxSlots ? 'none' : 'block';
      
      // Update onclick to use the next available slot index
      var nextIndex = -1;
      for (var i = 0; i < maxSlots; i++) {
        if (!localStorage.getItem('tom-labs-custom-theme-' + i) && !localStorage.getItem('tom-labs-custom-color-' + i)) {
          nextIndex = i;
          break;
        }
      }
      
      if (nextIndex !== -1) {
        createNewBtn.setAttribute('onclick', "TomBG.openDesignerForSlot(" + nextIndex + "); var m = coreui.Modal.getInstance(document.getElementById('bgSelectModal')); if(m)m.hide();");
      }
    }
    
    var myThemesBadge = document.getElementById('my-themes-count-badge');
    if (myThemesBadge) {
      myThemesBadge.textContent = slotCount + '/' + maxSlots;
    }
    
    this.updateActiveSwatchUI();
  },

  updateActiveSwatchUI: function () {
    var currentBg = localStorage.getItem('tom-labs-plain-color') || '#010d12';
    var currentAccent = localStorage.getItem('tom-labs-accent-color') || '#8b91f9';
    var mode = localStorage.getItem('tom-labs-bg-mode') || 'spiderman';
    
    document.querySelectorAll('.swatch-item').forEach(function(item) {
      var badge = item.querySelector('.active-badge');
      if (!badge) return;
      
      var itemBg = item.getAttribute('data-bg');
      var itemAccent = item.getAttribute('data-accent');
      
      var bgMatches = itemBg && itemBg.toLowerCase() === currentBg.toLowerCase();
      var accentMatches = (!itemAccent) || (itemAccent.toLowerCase() === currentAccent.toLowerCase());
      
      if (mode === 'plain' && bgMatches && accentMatches) {
        badge.style.display = 'flex';
      } else {
        badge.style.display = 'none';
      }
    });
  },

  // Set both background + accent from a swatch preset
  applySwatchPreset: function (bg, accent) {
    localStorage.setItem('tom-labs-accent-color', accent);
    this.setPlainColor(bg);
    this.setAccentColor(accent);
    this.setMode('plain');
  },

  toHex: function (color) {
    if (!color) return "#010d12";
    if (color.indexOf("#") === 0) return color;
    var rgb = color.match(/\d+/g);
    if (!rgb || rgb.length < 3) return "#010d12";
    var r = parseInt(rgb[0]),
      g = parseInt(rgb[1]),
      b = parseInt(rgb[2]);
    return "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
  },

  initScenery: function () {
    if (document.querySelector(".scenery-container")) return;
    var container = document.createElement("div");
    container.className = "scenery-container";
    container.innerHTML = `
      <div class="scenery-snow"></div>
      <div class="scenery-mountains"></div>
      <div class="scenery-house"></div>
      <div class="scenery-orb-1"></div>
      <div class="scenery-orb-2"></div>
    `;
    document.body.appendChild(container);
  },

  _syncTimer: null,
  syncToServer: function () {
    var mode = localStorage.getItem("tom-labs-bg-mode");
    var plainColor = localStorage.getItem("tom-labs-plain-color");
    var accentColor = localStorage.getItem("tom-labs-accent-color");
    var customSlots = [];
    var customThemes = [];
    for (var i = 0; i < 10; i++) {
      customSlots.push(localStorage.getItem("tom-labs-custom-color-" + i));
      customThemes.push(localStorage.getItem("tom-labs-custom-theme-" + i));
    }

    if (this._syncTimer) clearTimeout(this._syncTimer);
    this._syncTimer = setTimeout(function () {
      fetch('/api/account/theme_save', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          mode: mode,
          plainColor: plainColor,
          accentColor: accentColor,
          customSlots: customSlots,
          customThemes: customThemes
        })
      }).catch(function (err) { console.error("Theme sync failed:", err); });
    }, 500);
  }
};

window.onPageLoad( function () { TomBG.init(); });

/**
 * Theme & Visuals Controller (Migrated from _master.php for Grunt Workflow)
 */

window.updateThemeIcon = function (theme) {
  var iconMap = {
    'light': 'bx-sun',
    'dark': 'bx-moon',
    'auto': 'bx-circle-half'
  };
  var iconElement = document.getElementById('currentThemeIcon');
  if (iconElement) {
    iconElement.classList.remove('bx-sun', 'bx-moon', 'bx-circle-half', 'bx-circle');
    iconElement.classList.add(iconMap[theme] || 'bx-circle');
  }
};

window.changeTheme = function (themeName) {
  var themeToApply = themeName;
  if (themeName === 'auto') {
    themeToApply = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  }

  document.documentElement.setAttribute('data-coreui-theme', themeToApply);
  localStorage.setItem('tom-labs-theme', themeName);
  window.updateThemeIcon(themeName);

  document.querySelectorAll('.mode-item').forEach(function (btn) {
    btn.classList.toggle('active', btn.getAttribute('data-coreui-value') === themeName);
  });

  if (window.TomVisuals) {
    window.TomVisuals.switchBGTheme(themeName);
  }
  window.dispatchEvent(new Event('themeChanged'));
};

window.TomVisuals = {
  showRecommendation: function () {
    var gpuInfo = window.TomGPU ? window.TomGPU.detect() : { webgl: false, highPerf: false, vendor: 'Unknown', renderer: 'Unknown' };
    var webglEl = document.getElementById('gpuModalWebGL');
    var perfEl = document.getElementById('gpuModalHighPerf');
    var vendorEl = document.getElementById('gpuModalVendor');
    var rendererEl = document.getElementById('gpuModalRenderer');
    if (webglEl) {
      webglEl.textContent = gpuInfo.webgl ? 'Yes' : 'No';
      webglEl.className = 'py-2 px-3 fw-semibold ' + (gpuInfo.webgl ? 'text-success' : 'text-danger');
    }
    if (perfEl) {
      perfEl.textContent = gpuInfo.highPerf ? 'Yes' : 'No';
      perfEl.className = 'py-2 px-3 fw-semibold ' + (gpuInfo.highPerf ? 'text-success' : 'text-danger');
    }
    if (vendorEl) vendorEl.textContent = gpuInfo.vendor;
    if (rendererEl) rendererEl.textContent = gpuInfo.renderer;

    var modalEl = document.getElementById('visualsRecommendationModal');
    if (modalEl && typeof coreui !== 'undefined') {
      var modal = coreui.Modal.getOrCreateInstance(modalEl);
      modal.show();
    }
  },
  toggleBlur: function (enable) {
    if (enable) {
      if (document.body) {
        document.body.classList.add('hwa-enabled');
        document.body.classList.remove('hwa-disabled');
      }
      if (window.TomGPU && !window.TomGPU.isCapable()) {
        var gpuInfo = window.TomGPU.detect();
        var reason = !gpuInfo.webgl 
          ? 'Your browser does not support WebGL.' 
          : 'Your GPU (' + gpuInfo.renderer + ') is not high-performance.';
        if (typeof window.TomGPU.startUnsupportedCountdown === 'function') {
          window.TomGPU.startUnsupportedCountdown(reason);
        }
      }
    } else {
      if (window._gpuWarningTimer) {
        clearInterval(window._gpuWarningTimer);
        window._gpuWarningTimer = null;
      }
      if (document.body) {
        document.body.classList.remove('hwa-enabled');
        document.body.classList.add('hwa-disabled');
      }
      var toggleEl = document.getElementById('visualBlurToggle');
      if (toggleEl) toggleEl.checked = false;
    }
    
    // Save to Database
    var data = new FormData();
    data.append('preference_id', 'visual_blur');
    data.append('value', enable ? 'true' : 'false');
    fetch('/api/user/preference_save', {
      method: 'POST',
      body: data
    }).catch(console.error);
  },
  switchBGTheme: function (theme) {
    var darkGrid = document.getElementById('bg-grid-dark');
    var lightGrid = document.getElementById('bg-grid-light');
    if (!darkGrid || !lightGrid) return;
    
    var themeToPreview = theme;
    if (theme === 'auto') {
      themeToPreview = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    
    if (themeToPreview === 'light') {
      darkGrid.style.display = 'none';
      darkGrid.classList.add('d-none');
      lightGrid.style.display = 'grid';
      lightGrid.classList.remove('d-none');
    } else {
      lightGrid.style.display = 'none';
      lightGrid.classList.add('d-none');
      darkGrid.style.display = 'grid';
      darkGrid.classList.remove('d-none');
    }
  },
  syncUI: function () {
    var blurToggle = document.getElementById('visualBlurToggle');
    if (blurToggle) {
      blurToggle.checked = document.body && document.body.classList.contains('hwa-enabled');
    }
    var savedTheme = document.documentElement.getAttribute('data-coreui-theme') || 'dark';
    this.switchBGTheme(savedTheme);
    window.updateThemeIcon(savedTheme);
  }
};


    

    // --- Explicit Window Exports for Inline HTML ---
    window.TomBG = TomBG;

  })();
} catch (e) {
  console.error("[Fatal Error in background.js]", e);
}
