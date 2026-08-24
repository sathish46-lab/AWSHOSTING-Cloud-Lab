/**
 * Roadmaps — View Page Client-Side Rendering
 * Fetches data from /api/roadmaps/view.php and renders the two-panel layout.
 */
(function() {
    'use strict';

    var RoadmapView = {
        data: null,
        slug: null,

        init: function() {
            var el = document.getElementById('roadmap-app');
            if (!el) return;
            this.slug = el.getAttribute('data-slug');
            if (!this.slug) return;

            // Use preloaded data for instant header render
            var pre = window.__ROADMAP_PRELOADED;
            if (pre && pre.success && pre.roadmap) {
                this.data = pre;
                this.renderHeader();
            }

            this.load();
            this.initResizer();
            this.restorePaneWidths();
            this.initChat();
        },

        initResizer: function() {
            var self = this;
            if (self._resizerAttached) return;
            self._resizerAttached = true;

            var onMouseDown = function(e) {
                var resizer = e.target.closest('.rm-gutter');
                if (!resizer) return;
                e.preventDefault();
                self.isDragging = true;
                self.currentResizer = resizer;
                self.initialAnchor = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
                var left = document.getElementById('roadmap-panel-left');
                if (left) self.initialWidth = left.offsetWidth;
                document.body.classList.add('user-select-none');
            };

            var onMouseMove = function(e) {
                if (!self.isDragging || !self.currentResizer) return;
                if (self.animFrame) cancelAnimationFrame(self.animFrame);
                self.animFrame = requestAnimationFrame(function() {
                    var clientX = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);
                    if (clientX === undefined) return;
                    var delta = clientX - self.initialAnchor;
                    var newW = self.initialWidth + delta;
                    var wrapper = document.querySelector('.rm-panels');
                    var totalW = wrapper ? wrapper.offsetWidth : window.innerWidth;
                    var minW = 300;
                    var maxW = totalW - 300 - 16;
                    if (newW < minW) newW = minW;
                    if (newW > maxW) newW = maxW;
                    var left = document.getElementById('roadmap-panel-left');
                    var right = document.getElementById('roadmap-panel-right');
                    if (left) {
                        left.style.width = newW + 'px';
                        left.style.flex = 'none';
                    }
                    if (right) {
                        right.style.width = (totalW - newW - 16) + 'px';
                        right.style.flex = 'none';
                    }
                });
            };

            var onMouseUp = function() {
                if (!self.isDragging) return;
                self.isDragging = false;
                if (self.animFrame) { cancelAnimationFrame(self.animFrame); self.animFrame = null; }
                document.body.classList.remove('user-select-none');
                if (self.currentResizer) {
                    self.savePaneWidths();
                    self.currentResizer = null;
                    setTimeout(function() { RoadmapView.drawWires(); }, 50);
                }
            };

            document.addEventListener('mousedown', onMouseDown);
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
            document.addEventListener('touchstart', onMouseDown, { passive: false });
            document.addEventListener('touchmove', onMouseMove, { passive: false });
            document.addEventListener('touchend', onMouseUp);

            // Redraw wires on window resize
            var resizeTimer = null;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimer);
                resizeTimer = setTimeout(function() { RoadmapView.drawWires(); }, 200);
            });
        },

        savePaneWidths: function() {
            var wrapper = document.querySelector('.rm-panels');
            var left = document.getElementById('roadmap-panel-left');
            var right = document.getElementById('roadmap-panel-right');
            if (!wrapper || !left || !right) return;
            var totalW = wrapper.offsetWidth;
            if (totalW <= 0) return;
            var pctLeft = (left.offsetWidth / totalW) * 100;
            var pctRight = (right.offsetWidth / totalW) * 100;
            var sizes = JSON.stringify([Math.round(pctLeft * 10) / 10, Math.round(pctRight * 10) / 10]);
            localStorage.setItem('roadmap-panel-sizes', sizes);
        },

        restorePaneWidths: function() {
            var sizes = null;
            try { sizes = JSON.parse(localStorage.getItem('roadmap-panel-sizes') || 'null'); } catch(e) {}
            if (!sizes || !Array.isArray(sizes) || sizes.length < 2) return;
            var wrapper = document.querySelector('.rm-panels');
            var left = document.getElementById('roadmap-panel-left');
            var right = document.getElementById('roadmap-panel-right');
            if (!wrapper || !left || !right) return;
            var totalW = wrapper.offsetWidth;
            if (totalW <= 0) return;
            var leftW = (sizes[0] / 100) * totalW;
            var rightW = (sizes[1] / 100) * totalW;
            left.style.width = leftW + 'px';
            left.style.flex = 'none';
            right.style.width = rightW + 'px';
            right.style.flex = 'none';
        },

        // ==========================================
        // AI CHAT FUNCTIONALITY
        // ==========================================
        _chatSessionId: null,
        _chatSocket: null,
        _userScrolledUp: false,
        _chatInitialized: false,

        initChat: function() {
            var self = this;
            if (self._chatInitialized) return;
            self._chatInitialized = true;
            self._chatSessionId = 'sess_' + Math.random().toString(36).substr(2, 9);

            var chatInput = document.getElementById('roadmap-chat-input');
            var chatSend = document.getElementById('roadmap-chat-send');
            var chatHistory = document.getElementById('roadmap-chat-history');
            var scrollBtn = document.getElementById('roadmap-chat-scroll-btn');

            if (!chatInput || !chatSend || !chatHistory) return;

            // Auto-resize textarea
            chatInput.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = Math.min(this.scrollHeight, 120) + 'px';
            });

            // Send on Enter
            chatInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    chatSend.click();
                }
            });

            // Send button click
            chatSend.addEventListener('click', function() {
                var query = chatInput.value.trim();
                if (!query) return;

                // Clear input
                chatInput.value = '';
                chatInput.style.height = 'auto';

                // Remove empty state
                var emptyState = chatHistory.querySelector('.d-flex.h-100');
                if (emptyState) emptyState.remove();

                // Append user message
                self.appendChatMessage(query, 'user');

                // Create AI placeholder
                var msgId = 'rm_msg_' + Math.random().toString(36).substr(2, 9);
                self.appendAiPlaceholder(msgId);

                // Ensure socket connection then send
                self.ensureChatSocket(function() {
                    self.sendChatMessage(query, msgId);
                });
            });

            // Scroll-to-bottom button
            if (scrollBtn) {
                chatHistory.addEventListener('scroll', function() {
                    var atBottom = chatHistory.scrollHeight - chatHistory.scrollTop - chatHistory.clientHeight <= 45;
                    if (atBottom) {
                        self._userScrolledUp = false;
                        scrollBtn.style.display = 'none';
                    } else {
                        self._userScrolledUp = true;
                        scrollBtn.style.display = 'flex';
                    }
                });

                scrollBtn.addEventListener('click', function() {
                    self._userScrolledUp = false;
                    chatHistory.scrollTo({ top: chatHistory.scrollHeight, behavior: 'smooth' });
                    setTimeout(function() { scrollBtn.style.display = 'none'; }, 300);
                });
            }
        },

        loadChatHistory: function() {
            var self = this;
            var chatHistory = document.getElementById('roadmap-chat-history');
            if (!chatHistory || !self.data || !self.data.roadmap) return;

            var roadmapId = self.data.roadmap.id;
            fetch('/api/roadmaps/chat_history?roadmap_id=' + encodeURIComponent(roadmapId), { credentials: 'include' })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    if (html.trim()) {
                        chatHistory.innerHTML = html;
                        // Render markdown on loaded AI messages
                        chatHistory.querySelectorAll('.ai-row p[data-raw-md]').forEach(function(p) {
                            self.renderMarkdown(p.getAttribute('data-raw-md'), p);
                        });
                        chatHistory.scrollTop = chatHistory.scrollHeight;
                    }
                })
                .catch(function() {});
        },

        ensureChatSocket: function(onReady) {
            var self = this;
            if (typeof TomSocketClient === 'undefined') {
                if (onReady) onReady();
                return;
            }

            if (self._chatSocket && self._chatSocket.isActive()) {
                if (onReady) onReady();
                return;
            }

            if (self._chatSocket) {
                try { self._chatSocket.disconnect(); } catch(e) {}
            }

            self._chatSocket = new TomSocketClient();
            self._chatSocket.connect('ai_stream.' + self._chatSessionId, function(data) {
                self.handleChatStream(data);
            }, null, function() {
                if (onReady) onReady();
            });
        },

        handleChatStream: function(data) {
            var self = this;
            var chatHistory = document.getElementById('roadmap-chat-history');
            if (!chatHistory) return;

            // Tool execution
            if (data.type === 'tool_execution') {
                var aiContainer = data.message_id ? document.getElementById('ai_msg_' + data.message_id) : chatHistory.querySelector('.current-ai-stream');
                if (aiContainer) {
                    var wrapper = aiContainer.querySelector('.msg-content-wrapper');
                    var badge = document.createElement('div');
                    badge.className = 'tool-badge-wrapper mb-1';
                    badge.innerHTML = '<div style="display:flex;align-items:center;gap:6px;padding:4px 10px;border:1px solid rgba(var(--cui-body-color-rgb),0.15);border-radius:6px;font-size:0.8rem;color:var(--cui-secondary);background:rgba(var(--cui-body-color-rgb),0.03);"><i class="bx bx-check-circle" style="color:#22c55e;"></i>' + RoadmapView.esc(data.tool_name || 'Tool') + '</div>';
                    if (wrapper) wrapper.insertBefore(badge, wrapper.firstChild);
                }
                return;
            }

            var aiContainer = data.message_id ? document.getElementById('ai_msg_' + data.message_id) : chatHistory.querySelector('.current-ai-stream');
            if (!aiContainer) return;

            // Stream end
            if (data.type === 'stream_end') {
                var dots = aiContainer.querySelector('.typing-dots');
                if (dots) dots.remove();

                var bubble = aiContainer.querySelector('.msg-bubble');
                if (bubble) bubble.style.display = 'block';

                var p = aiContainer.querySelector('p');
                if (p && p.dataset.rawMd) {
                    self.renderMarkdown(p.dataset.rawMd, p);

                    // Save AI response to DB
                    fetch('/api/roadmaps/save_chat', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify({
                            roadmap_id: self.data.roadmap.id,
                            content: p.dataset.rawMd,
                            role: 'model',
                            usage: data.usage || null
                        })
                    }).catch(function() {});
                }

                aiContainer.classList.remove('current-ai-stream');

                // Update token stats
                if (data.usage) {
                    self.updateTokenStats(data.usage);
                }

                // Scroll to bottom
                if (!self._userScrolledUp) {
                    chatHistory.scrollTop = chatHistory.scrollHeight;
                }
                return;
            }

            // Text delta
            if (data.type === 'text_delta') {
                var dots = aiContainer.querySelector('.typing-dots');
                if (dots) dots.remove();

                var bubble = aiContainer.querySelector('.msg-bubble');
                if (bubble) bubble.style.display = 'block';

                var p = aiContainer.querySelector('p');
                if (p) {
                    var raw = (p.dataset.rawMd || '') + (data.data || '');
                    p.dataset.rawMd = raw;
                    p.innerHTML = RoadmapView.esc(raw).replace(/\n/g, '<br>');
                }

                if (!self._userScrolledUp) {
                    chatHistory.scrollTop = chatHistory.scrollHeight;
                }
            }
        },

        appendChatMessage: function(text, type) {
            var chatHistory = document.getElementById('roadmap-chat-history');
            if (!chatHistory) return;

            var div = document.createElement('div');
            div.className = 'message-row ' + (type === 'user' ? 'user-row ms-auto' : 'ai-row');

            if (type === 'user') {
                div.innerHTML = '<div class="msg-bubble"><p class="m-0">' + RoadmapView.esc(text) + '</p></div>';
            } else {
                div.innerHTML = '<div class="msg-avatar"><img src="/assets/logo/logo.png" style="width:30px;" alt="AI"></div>' +
                    '<div class="msg-content-wrapper d-flex flex-column" style="max-width:85%;width:100%;">' +
                    '<div class="msg-bubble w-100 ai-transparent-bubble" style="background:transparent!important;border:none!important;box-shadow:none!important;padding:0!important;">' +
                    '<p class="m-0">' + text + '</p></div></div>';
            }

            chatHistory.appendChild(div);
            chatHistory.scrollTop = chatHistory.scrollHeight;
        },

        appendAiPlaceholder: function(msgId) {
            var chatHistory = document.getElementById('roadmap-chat-history');
            if (!chatHistory) return;

            var div = document.createElement('div');
            div.className = 'message-row ai-row current-ai-stream';
            div.id = 'ai_msg_' + msgId;
            div.innerHTML = '<div class="msg-avatar"><img src="/assets/logo/logo.png" style="width:30px;" alt="AI"></div>' +
                '<div class="msg-content-wrapper d-flex flex-column" style="max-width:85%;width:100%;">' +
                '<div class="typing-dots d-flex align-items-center gap-2 mb-1 p-1 text-primary small">' +
                '<i class="bx bx-loader-circle bx-spin fs-5"></i>' +
                '<span class="text-secondary fw-medium">AI is thinking...</span></div>' +
                '<div class="msg-bubble w-100 ai-transparent-bubble" style="background:transparent!important;border:none!important;box-shadow:none!important;padding:0!important;display:none;">' +
                '<p class="m-0"></p></div></div>';

            chatHistory.appendChild(div);
            chatHistory.scrollTop = chatHistory.scrollHeight;
        },

        sendChatMessage: function(query, msgId) {
            var self = this;
            if (!self.data || !self.data.roadmap) return;

            fetch('/api/roadmaps/ask', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    query: query,
                    roadmap_id: self.data.roadmap.id,
                    session_id: self._chatSessionId,
                    message_id: msgId,
                    ai_model: 'gemini'
                })
            })
            .then(function(r) { return r.json(); })
            .catch(function(err) {
                console.error('AI Request Failed:', err);
                var aiContainer = document.getElementById('ai_msg_' + msgId);
                if (aiContainer) {
                    var p = aiContainer.querySelector('p');
                    if (p) p.innerText = 'Sorry, something went wrong.';
                    aiContainer.classList.remove('current-ai-stream');
                    var dots = aiContainer.querySelector('.typing-dots');
                    if (dots) dots.remove();
                    var bubble = aiContainer.querySelector('.msg-bubble');
                    if (bubble) bubble.style.display = 'block';
                }
            });
        },

        updateTokenStats: function(usage) {
            var ctxEl = document.getElementById('rm-token-context');
            var outEl = document.getElementById('rm-token-output');
            var cachedEl = document.getElementById('rm-token-cached');

            var total = (usage.total_tokens || 0);
            var output = (usage.output_tokens || 0);
            var cached = (usage.cached_tokens || 0);

            if (ctxEl) ctxEl.textContent = (total > 0 ? RoadmapView.formatNumber(total) : '0') + '/1M';
            if (outEl) outEl.textContent = output > 0 ? RoadmapView.formatNumber(output) : '0';
            if (cachedEl) cachedEl.textContent = cached > 0 ? RoadmapView.formatNumber(cached) : '0';
        },

        formatNumber: function(n) {
            if (n >= 1000000) return (n / 1000000).toFixed(1) + 'M';
            if (n >= 1000) return (n / 1000).toFixed(1) + 'k';
            return '' + n;
        },

        renderMarkdown: function(md, el) {
            if (!md || !el) return;
            var html = md
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/```(\w*)\n([\s\S]*?)```/g, '<pre><code class="language-$1">$2</code></pre>')
                .replace(/`([^`]+)`/g, '<code>$1</code>')
                .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
                .replace(/\*(.+?)\*/g, '<em>$1</em>')
                .replace(/^### (.+)$/gm, '<h4 class="fw-bold mt-2 mb-1" style="font-size:0.95rem;">$1</h4>')
                .replace(/^## (.+)$/gm, '<h3 class="fw-bold mt-2 mb-1" style="font-size:1.05rem;">$1</h3>')
                .replace(/^# (.+)$/gm, '<h2 class="fw-bold mt-2 mb-1" style="font-size:1.1rem;">$1</h2>')
                .replace(/^- (.+)$/gm, '<li class="ms-2">$1</li>')
                .replace(/^(\d+)\. (.+)$/gm, '<li class="ms-2">$2</li>')
                .replace(/\n/g, '<br>');
            el.innerHTML = html;
        },

        load: function() {
            var self = this;
            var leftPanel = document.getElementById('rm-left-content');
            if (leftPanel) leftPanel.innerHTML = '<div class="text-center py-5"><div class="spinner-border text-primary mb-2" role="status"></div><p class="text-body-secondary small mb-0">Loading roadmap...</p></div>';

            fetch('/api/roadmaps/view?slug=' + encodeURIComponent(this.slug), { credentials: 'include' })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (!json.success || !json.roadmap) {
                        if (leftPanel) leftPanel.innerHTML = '<div class="text-center py-5"><p class="text-danger small">' + (json.error || 'Failed to load roadmap.') + '</p></div>';
                        return;
                    }
                    self.data = json;
                    self.renderHeader();
                    self.renderLeft();
                    self.renderRight();
                    self.loadChatHistory();
                })
                .catch(function(err) {
                    if (leftPanel) leftPanel.innerHTML = '<div class="text-center py-5"><p class="text-danger small">Network error loading roadmap.</p></div>';
                });
        },

        esc: function(s) {
            var d = document.createElement('div');
            d.textContent = s || '';
            return d.innerHTML;
        },

        glyphFor: function(type) {
            switch(type) {
                case 'checkpoint': return '\u2713';
                case 'project': return '\u25A0';
                case 'milestone': return '\u2605';
                default: return '';
            }
        },

        classFor: function(type) {
            switch(type) {
                case 'checkpoint': return 'rm-item-checkpoint';
                case 'project': return 'rm-item-project';
                case 'milestone': return 'rm-item-milestone';
                default: return 'rm-item-topic';
            }
        },

        badgeClass: function(type) {
            switch(type) {
                case 'milestone': return 'badge-soft-primary';
                case 'checkpoint': return 'badge-soft-warning';
                case 'project': return 'badge-soft-success';
                default: return 'badge-soft-secondary';
            }
        },

        isCompleted: function(topicId, itemId) {
            if (!this.data || !this.data.progress_data) return false;
            var arr = this.data.progress_data[topicId];
            if (!arr) return false;
            return arr.indexOf(itemId) !== -1;
        },

        hasEvidence: function(topicId, itemId) {
            if (!this.data || !this.data.evidence_data) return false;
            var ev = this.data.evidence_data[topicId];
            if (!ev) return false;
            return !!ev[itemId];
        },

        openDeclareModal: function(topicId, itemId) {
            var self = this;
            var body = document.getElementById('rm-declare-body');
            if (!body) return;

            body.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-secondary small mt-2">Loading...</p></div>';
            var modal = new coreui.Modal(document.getElementById('rm-declare-modal'));
            modal.show();

            self._declareEvidence = [];

            var url = '/api/roadmaps/declare_form?roadmap_id=' + encodeURIComponent(self.data.roadmap.id)
                + '&topic_id=' + encodeURIComponent(topicId)
                + '&item_id=' + encodeURIComponent(itemId);

            fetch(url, { credentials: 'include' })
                .then(function(r) { return r.text(); })
                .then(function(html) {
                    body.innerHTML = html;
                    self.wireDeclareEvents();
                })
                .catch(function() {
                    body.innerHTML = '<div class="text-center py-4 text-danger">Failed to load declaration form</div>';
                });
        },

        wireDeclareEvents: function() {
            var self = this;

            // File upload
            var dropZone = document.getElementById('rm-declare-drop-zone');
            var fileInput = document.getElementById('rm-declare-file-input');
            if (dropZone && fileInput) {
                dropZone.addEventListener('click', function() { fileInput.click(); });
                dropZone.addEventListener('dragover', function(e) { e.preventDefault(); dropZone.classList.add('dragover'); });
                dropZone.addEventListener('dragleave', function() { dropZone.classList.remove('dragover'); });
                dropZone.addEventListener('drop', function(e) {
                    e.preventDefault();
                    dropZone.classList.remove('dragover');
                    self.handleDeclareFiles(e.dataTransfer.files);
                });
                fileInput.addEventListener('change', function() {
                    self.handleDeclareFiles(this.files);
                    this.value = '';
                });
            }

            // URL add
            var addUrlBtn = document.getElementById('rm-declare-add-url-btn');
            var urlInput = document.getElementById('rm-declare-url-input');
            if (addUrlBtn && urlInput) {
                addUrlBtn.addEventListener('click', function() {
                    var url = urlInput.value.trim();
                    if (!url) return;
                    if (!url.match(/^https?:\/\//)) url = 'https://' + url;
                    self._declareEvidence.push({ type: 'url', value: url });
                    self.renderDeclareEvidence();
                    urlInput.value = '';
                });
            }

            // Submit button (works for both new and re-submit)
            var submitBtn = document.getElementById('rm-declare-submit-btn');
            if (submitBtn) {
                submitBtn.addEventListener('click', function() {
                    var roadmapId = this.getAttribute('data-roadmap');
                    var topicId = this.getAttribute('data-topic');
                    var itemId = this.getAttribute('data-item');
                    self.submitDeclaration(roadmapId, topicId, itemId);
                });
            }
        },

        handleDeclareFiles: function(files) {
            var self = this;
            Array.from(files).forEach(function(file) {
                if (file.type !== 'application/pdf') return;
                if (file.size > 10 * 1024 * 1024) return;
                self._declareEvidence.push({ type: 'file', name: file.name, file: file });
            });
            self.renderDeclareEvidence();
        },

        renderDeclareEvidence: function() {
            var self = this;
            var list = document.getElementById('rm-declare-evidence-list');
            var attestation = document.getElementById('rm-declare-attestation');
            if (!list) return;

            var html = '';
            self._declareEvidence.forEach(function(ev, idx) {
                html += '<div class="d-flex align-items-center gap-2 p-2 rounded" style="background:rgba(255,255,255,0.03);">';
                if (ev.type === 'file') {
                    html += '<i class="bx bx-file text-primary"></i>';
                    html += '<span class="small flex-grow-1 text-truncate">' + self.esc(ev.name) + '</span>';
                } else {
                    html += '<i class="bx bx-link text-info"></i>';
                    html += '<span class="small flex-grow-1 text-truncate">' + self.esc(ev.value) + '</span>';
                }
                html += '<button class="btn btn-sm text-danger p-0" onclick="window.RoadmapView.removeDeclareEvidence(' + idx + ')"><i class="bx bx-x"></i></button>';
                html += '</div>';
            });
            list.innerHTML = html;

            // Show/hide attestation
            if (attestation) {
                if (self._declareEvidence.length > 0) {
                    attestation.classList.remove('d-none');
                } else {
                    attestation.classList.add('d-none');
                }
            }
        },

        removeDeclareEvidence: function(idx) {
            this._declareEvidence.splice(idx, 1);
            this.renderDeclareEvidence();
        },

        submitDeclaration: function(roadmapId, topicId, itemId) {
            var self = this;
            var notes = (document.getElementById('rm-declare-notes') || {}).value || '';
            var agreeCheck = document.getElementById('rm-declare-agree-check');
            var hasEvidence = self._declareEvidence && self._declareEvidence.length > 0;

            if (hasEvidence && agreeCheck && !agreeCheck.checked) {
                alert('Please agree to the self-declaration before submitting with evidence.');
                return;
            }

            var submitBtn = document.getElementById('rm-declare-submit-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Submitting...';
            }

            var fd = new FormData();
            fd.append('roadmap_id', roadmapId);
            fd.append('topic_id', topicId);
            fd.append('item_id', itemId);
            fd.append('notes', notes);
            fd.append('completed', 'true');

            var urls = [];
            (self._declareEvidence || []).forEach(function(ev) {
                if (ev.type === 'url') urls.push(ev.value);
                if (ev.type === 'file') fd.append('evidence_files[]', ev.file);
            });
            fd.append('evidence_urls', JSON.stringify(urls));

            fetch('/api/roadmaps/declare', {
                method: 'POST',
                credentials: 'include',
                body: fd
            })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.result === 'success') {
                    if (!self.data.progress_data[topicId]) self.data.progress_data[topicId] = [];
                    var arr = self.data.progress_data[topicId];
                    if (arr.indexOf(itemId) === -1) arr.push(itemId);

                    // Mark evidence as present
                    if (!self.data.evidence_data) self.data.evidence_data = {};
                    if (!self.data.evidence_data[topicId]) self.data.evidence_data[topicId] = {};
                    self.data.evidence_data[topicId][itemId] = true;

                    self.data.roadmap.progress = json.progress_percentage || 0;
                    self.data.roadmap.checkpoints_completed = json.checkpoints_completed || self.data.roadmap.checkpoints_completed;
                    self.data.roadmap.checkpoints_total = json.checkpoints_total || self.data.roadmap.checkpoints_total;

                    var modal = coreui.Modal.getInstance(document.getElementById('rm-declare-modal'));
                    if (modal) modal.hide();

                    self.renderHeader();
                    self.renderLeft();
                    self.renderRight();
                    self.showToast('Declaration submitted! Progress: ' + json.progress_percentage + '%', 'success');
                } else {
                    alert(json.error || 'Failed to submit declaration');
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = '<i class="bx bx-send me-1"></i> Submit Declaration';
                    }
                }
            })
            .catch(function() {
                alert('Network error');
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="bx bx-send me-1"></i> Submit Declaration';
                }
            });
        },

        showToast: function(msg, type) {
            var toast = document.createElement('div');
            toast.className = 'position-fixed bottom-0 end-0 p-3';
            toast.style.zIndex = '9999';
            toast.innerHTML = '<div class="toast show align-items-center text-bg-' + (type || 'primary') + ' border-0" role="alert"><div class="d-flex"><div class="toast-body">' + msg + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" onclick="this.closest(\'.position-fixed\').remove()"></button></div></div>';
            document.body.appendChild(toast);
            setTimeout(function() { toast.remove(); }, 4000);
        },

        renderHeader: function() {
            var rm = this.data.roadmap;
            var header = document.getElementById('rm-header');
            if (header) header.style.display = 'block';

            var el = function(id) { return document.getElementById(id); };

            // Decode HTML entities in title (e.g. &#039; -> ')
            var temp = document.createElement('span');
            if (el('rm-title')) {
                temp.innerHTML = rm.title || '';
                el('rm-title').textContent = temp.textContent;
            }
            if (el('rm-description')) el('rm-description').textContent = rm.description || '';
            if (el('rm-level')) el('rm-level').textContent = rm.level || '';
            if (el('rm-hours')) el('rm-hours').textContent = (rm.hours || 0) + 'h';
            if (el('rm-progress-pct')) el('rm-progress-pct').textContent = (rm.progress || 0) + '%';
            if (el('rm-progress-bar')) el('rm-progress-bar').style.width = (rm.progress || 0) + '%';

            // Tags
            var tagsEl = el('rm-tags');
            if (tagsEl && rm.tags && rm.tags.length) {
                tagsEl.innerHTML = rm.tags.map(function(t) {
                    return '<span class="rm-tag">' + RoadmapView.esc(t) + '</span>';
                }).join(' ');
            }
        },

        renderLeft: function() {
            var rm = this.data.roadmap;
            var sections = rm.sections || [];
            var self = this;
            var html = '';

            if (sections.length === 0) {
                html = '<div class="text-center py-5"><p class="text-body-secondary">No sections in this roadmap yet.</p></div>';
            } else {
                html = '<div class="rm-sections">';
                sections.forEach(function(section, idx) {
                    var topics = section.topics || [];
                    html += '<section class="rm-section" data-section="' + idx + '">';
                    html += '<div class="rm-section-label">' + self.esc(section.title || 'Section ' + (idx + 1)) + '</div>';
                    html += '<div class="rm-section-cards">';

                    if (topics.length === 0) {
                        html += '<div class="rm-card rm-card-default"><p class="text-body-secondary small mb-0">No topics yet.</p></div>';
                    }

                    topics.forEach(function(topic) {
                        var topicId = topic.id || '';
                        var items = topic.items || [];
                        var cardClass = 'rm-card rm-card-default';
                        if (items.length > 0) {
                            var typePriority = { milestone: 4, checkpoint: 3, project: 2, decision: 2 };
                            var bestType = null;
                            var bestPriority = 0;
                            items.forEach(function(item) {
                                var t = item.type || 'learning';
                                var p = typePriority[t] || 0;
                                if (p > bestPriority) { bestPriority = p; bestType = t; }
                            });
                            if (bestType === 'milestone') cardClass = 'rm-card rm-card-milestone';
                            else if (bestType === 'checkpoint') cardClass = 'rm-card rm-card-checkpoint';
                            else if (bestType === 'project') cardClass = 'rm-card rm-card-project';
                            else if (bestType === 'decision') cardClass = 'rm-card rm-card-decision';
                        }

                        html += '<div class="' + cardClass + '" data-slug="' + self.esc(topicId) + '">';
                        html += '<h4 class="rm-card-title">' + self.esc(topic.title || 'Untitled') + '</h4>';

                        if (items.length > 0) {
                            html += '<ul class="rm-list">';
                            items.forEach(function(item) {
                                var itemId = item.id || '';
                                var itemText = item.text || item.title || '';
                                var itemType = item.type || 'learning';
                                var checked = self.isCompleted(topicId, itemId);
                                var hasEv = checked && self.hasEvidence(topicId, itemId);
                                var cls = self.classFor(itemType);
                                var glyph = self.glyphFor(itemType);
                                var checkClass = checked ? ' rm-checked' : '';

                                html += '<li class="rm-item ' + cls + checkClass + '" data-slug="' + self.esc(itemId) + '">';
                                html += '<input type="checkbox" class="rm-check" data-slug="' + self.esc(itemId) + '"' + (checked ? ' checked' : '') + '>';
                                if (glyph) html += '<span class="rm-glyph">' + glyph + '</span>';
                                html += '<span class="rm-node-link" style="cursor:pointer;" data-topic="' + self.esc(topicId) + '" data-item="' + self.esc(itemId) + '" data-item-text="' + self.esc(itemText) + '" data-section="' + self.esc(section.id || '') + '">' + self.esc(itemText) + '</span>';
                                if (checked) {
                                    html += '<span class="rm-item-actions">';
                                    html += '<span class="rm-item-action rm-item-action-note" title="Notes"><i class="bx bx-pencil"></i></span>';
                                    if (hasEv) {
                                        html += '<span class="rm-item-action rm-item-action-ev" title="View evidence" data-action="view-ev" data-topic="' + self.esc(topicId) + '" data-item="' + self.esc(itemId) + '"><i class="bx bx-paperclip"></i></span>';
                                    } else {
                                        html += '<span class="rm-item-action rm-item-action-ev" title="Add evidence" data-action="add-ev" data-topic="' + self.esc(topicId) + '" data-item="' + self.esc(itemId) + '"><i class="bx bx-upvote"></i></span>';
                                    }
                                    html += '</span>';
                                }
                                html += '</li>';
                            });
                            html += '</ul>';
                        } else {
                            html += '<p class="text-body-secondary small mb-0" style="font-size:0.75rem;">No items yet.</p>';
                        }

                        html += '</div>';
                    });

                    html += '</div></section>';
                });
                html += '</div>';
            }

            var el = document.getElementById('rm-left-content');
            if (el) el.innerHTML = html;

            // Wire up checkbox clicks
            document.querySelectorAll('#rm-left-content .rm-check').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    var li = this.closest('.rm-item');
                    var slug = this.getAttribute('data-slug');
                    if (!li || !slug) return;
                    var topicCard = this.closest('.rm-card');
                    var topicId = topicCard ? topicCard.getAttribute('data-slug') : '';

                    // Instant UI update — toggle dim class
                    if (this.checked) {
                        li.classList.add('rm-checked');
                    } else {
                        li.classList.remove('rm-checked');
                    }

                    RoadmapView.toggleCheckpoint(topicId, slug, this.checked);
                });
            });

            // Wire up item click -> open topic modal
            document.querySelectorAll('#rm-left-content .rm-node-link').forEach(function(link) {
                link.addEventListener('click', function() {
                    var topicId = this.getAttribute('data-topic');
                    var itemId = this.getAttribute('data-item');
                    var itemText = this.getAttribute('data-item-text');
                    var sectionId = this.getAttribute('data-section');
                    if (topicId && itemId) RoadmapView.openTopicItem(topicId, itemId, itemText, sectionId);
                });
            });

            // Wire up evidence action icons in left panel
            document.querySelectorAll('#rm-left-content .rm-item-action[data-action]').forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    var action = this.getAttribute('data-action');
                    var topicId = this.getAttribute('data-topic');
                    var itemId = this.getAttribute('data-item');
                    if (!topicId || !itemId) return;
                    if (action === 'add-ev' || action === 'view-ev') {
                        RoadmapView.openDeclareModal(topicId, itemId);
                    }
                });
            });

            // Draw SVG connector wires (retry until layout settles)
            var self = this;
            var attempts = 0;
            function tryDrawWires() {
                attempts++;
                var container = document.getElementById('rm-left-content');
                if (!container) return;
                var sections = container.querySelectorAll('.rm-section');
                if (!sections.length) return;

                // Check if all sections have non-zero height (layout ready)
                var ready = true;
                sections.forEach(function(s) {
                    if (s.offsetHeight === 0) ready = false;
                });

                if (ready || attempts > 10) {
                    self.drawWires();
                } else {
                    requestAnimationFrame(tryDrawWires);
                }
            }
            requestAnimationFrame(tryDrawWires);
        },

        drawWires: function() {
            var container = document.getElementById('rm-left-content');
            if (!container) return;

            var sectionsWrap = container.querySelector('.rm-sections');
            if (!sectionsWrap) return;

            var sections = container.querySelectorAll('.rm-section');
            if (!sections.length) return;

            var cRect = container.getBoundingClientRect();
            var st = container.scrollTop;

            function rY(el) { var r = el.getBoundingClientRect(); return r.top - cRect.top + st; }
            function rX(el) { var r = el.getBoundingClientRect(); return r.left - cRect.left; }

            var sectionData = [];

            sections.forEach(function(section) {
                var label = section.querySelector('.rm-section-label');
                var cards = section.querySelectorAll('.rm-card');
                if (!label || !cards.length) return;

                var lH = label.offsetHeight;
                var lCY = rY(label) + lH / 2;
                var lBY = rY(label) + lH;
                var lCX = rX(label) + label.offsetWidth / 2;

                var cardTops = [];
                cards.forEach(function(card) {
                    cardTops.push({
                        x: rX(card) + card.offsetWidth / 2,
                        y: rY(card)
                    });
                });

                if (cardTops.length === 0) return;

                var leftX = Math.min.apply(null, cardTops.map(function(c){return c.x;}));
                var rightX = Math.max.apply(null, cardTops.map(function(c){return c.x;}));
                var tapY = lBY + 16;

                sectionData.push({
                    labelCX: lCX, labelCY: lCY, labelBY: lBY,
                    tapY: tapY, leftX: leftX, rightX: rightX, cardTops: cardTops
                });
            });

            if (sectionData.length === 0) return;

            var parts = [];

            var spineX = sectionData[0].labelCX;
            var spineTop = sectionData[0].labelCY;
            var spineBottom = sectionData[sectionData.length - 1].labelCY;
            parts.push('<line x1="'+spineX+'" y1="'+spineTop+'" x2="'+spineX+'" y2="'+spineBottom+'" class="rm-spine"></line>');

            sectionData.forEach(function(sd) {
                parts.push('<line x1="'+sd.labelCX+'" y1="'+sd.labelCY+'" x2="'+sd.labelCX+'" y2="'+sd.tapY+'" class="rm-tap"></line>');
                parts.push('<line x1="'+sd.leftX+'" y1="'+sd.tapY+'" x2="'+sd.rightX+'" y2="'+sd.tapY+'" class="rm-tap"></line>');
                sd.cardTops.forEach(function(c) {
                    parts.push('<path d="M '+c.x+' '+sd.tapY+' L '+c.x+' '+c.y+'" class="rm-wire"></path>');
                });
            });

            if (!parts.length) return;

            var svgW = container.scrollWidth;
            var svgH = container.scrollHeight;
            var svg = '<svg class="rm-wires" aria-hidden="true" overflow="hidden" width="'+svgW+'" height="'+svgH+'" viewBox="0 0 '+svgW+' '+svgH+'">'+parts.join('')+'</svg>';

            var existing = container.querySelector('.rm-wires');
            if (existing) existing.remove();
            container.insertAdjacentHTML('afterbegin', svg);
        },

        renderRight: function() {
            var rm = this.data.roadmap;
            var sections = rm.sections || [];
            var self = this;
            var html = '';

            // Count totals
            var totalItems = 0;
            var completedItems = 0;
            sections.forEach(function(section) {
                (section.topics || []).forEach(function(topic) {
                    (topic.items || []).forEach(function(item) {
                        totalItems++;
                        if (self.isCompleted(topic.id, item.id)) completedItems++;
                    });
                });
            });

            var currentSection = '';
            sections.forEach(function(section) {
                var secTitle = section.title || '';
                var topics = section.topics || [];
                if (secTitle !== currentSection) {
                    currentSection = secTitle;
                    html += '<div class="rm-section-header">' + self.esc(secTitle) + '</div>';
                }
                topics.forEach(function(topic) {
                    var topicId = topic.id || '';
                    var items = topic.items || [];
                    items.forEach(function(item) {
                        var itemId = item.id || '';
                        var itemType = item.type || 'concept';
                        var itemText = item.text || item.title || '';
                        var declared = self.isCompleted(topicId, itemId);
                        var hasEv = declared && self.hasEvidence(topicId, itemId);
                        var bc = self.badgeClass(itemType);
                        var bl = itemType.charAt(0).toUpperCase() + itemType.slice(1);

                        // Checkbox icon
                        var checkIcon = declared
                            ? '<div class="rm-progress-check done"><i class="bx bx-check"></i></div>'
                            : '<div class="rm-progress-check"></div>';

                        // Row class
                        var rowClass = 'rm-progress-item' + (declared ? ' completed' : '');

                        // Action button: only when checked
                        var actionHtml = '';
                        if (declared) {
                            var actionIcon = hasEv
                                ? '<i class="bx bx-paperclip"></i>'
                                : '<i class="bx bx-upvote"></i>';
                            var actionLabel = hasEv ? 'View evidence' : 'Add evidence';
                            actionHtml = '<div class="rm-progress-action flex-shrink-0 always-visible">'
                                + '<span class="rm-progress-action-btn' + (hasEv ? ' declared' : '') + '" title="' + actionLabel + '">' + actionIcon + '</span>'
                                + '</div>';
                        }

                        html += '<div class="' + rowClass + '" style="cursor:pointer;" onclick="window.RoadmapView.openDeclareModal(\'' + self.esc(topicId) + '\',\'' + self.esc(itemId) + '\')">';
                        html += '<div class="d-flex align-items-start gap-2">';
                        html += '<div class="flex-shrink-0 mt-1">';
                        html += checkIcon;
                        html += '</div>';
                        html += '<div class="flex-grow-1" style="min-width:0;">';
                        html += '<div class="rm-progress-item-text' + (declared ? ' text-secondary' : '') + '">' + self.esc(itemText) + '</div>';
                        html += '<span class="rm-progress-badge ' + bc + '">' + bl + '</span>';
                        html += '</div>';
                        html += actionHtml;
                        html += '</div></div>';
                    });
                });
            });

            var el = document.getElementById('rm-progress-list');
            if (el) el.innerHTML = html || '<div class="text-center py-3"><p class="text-body-secondary small">No items yet.</p></div>';

            // Progress tab header
            var pctTab = document.getElementById('rm-progress-pct-tab');
            var barTab = document.getElementById('rm-progress-bar-tab');
            var countEl = document.getElementById('rm-progress-count');
            var warnEl = document.getElementById('rm-progress-warning');

            // Count actual declared items (any type with evidence/declaration)
            var totalDeclared = 0;
            sections.forEach(function(section) {
                (section.topics || []).forEach(function(topic) {
                    (topic.items || []).forEach(function(item) {
                        if (self.hasEvidence(topic.id, item.id)) totalDeclared++;
                    });
                });
            });
            var totalAllItems = totalItems;
            var remainingEvidence = totalAllItems - totalDeclared;

            if (pctTab) pctTab.textContent = (rm.progress || 0) + '%';
            if (barTab) barTab.style.width = (rm.progress || 0) + '%';
            if (countEl) countEl.textContent = totalDeclared + ' / ' + totalAllItems + ' checkpoints declared';

            // Warning message
            if (warnEl) {
                if (remainingEvidence > 0 && (rm.progress || 0) < 100) {
                    warnEl.innerHTML = '<i class="bx bx-error text-warning me-1"></i><span class="text-warning" style="font-size:0.72rem;">' + remainingEvidence + ' evidence needed for 100% — max 50% without proof</span>';
                    warnEl.classList.remove('d-none');
                } else {
                    warnEl.classList.add('d-none');
                }
            }

            // No separate checkbox click handler — entire row is clickable
        },

        toggleCheckpoint: function(topicId, itemId, completed) {
            var self = this;
            fetch('/api/roadmaps/progress', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    roadmap_id: this.data.roadmap.id,
                    topic_id: topicId,
                    item_id: itemId,
                    completed: completed
                })
            })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.progress !== undefined) {
                    // Update local progress_data
                    if (!self.data.progress_data[topicId]) self.data.progress_data[topicId] = [];
                    var arr = self.data.progress_data[topicId];
                    var idx = arr.indexOf(itemId);
                    if (completed && idx === -1) arr.push(itemId);
                    else if (!completed && idx !== -1) arr.splice(idx, 1);

                    // Use server-returned values for accurate progress
                    self.data.roadmap.progress = json.progress;
                    self.data.roadmap.checkpoints_completed = json.checkpoints_completed;
                    self.data.roadmap.checkpoints_total = json.checkpoints_total;

                    // Live update header
                    var pctEl = document.getElementById('rm-progress-pct');
                    var barEl = document.getElementById('rm-progress-bar');
                    if (pctEl) pctEl.textContent = json.progress + '%';
                    if (barEl) barEl.style.width = json.progress + '%';

                    // Re-render left panel to update evidence icons
                    self.renderLeft();

                    // Live update right panel progress tab
                    var progressList = document.getElementById('rm-progress-list');
                    if (progressList) self.renderRight();
                }
            })
            .catch(function() {});
        },

        openTopicItem: function(topicId, itemId, itemText, sectionId) {
            var self = this;
            // Ensure modal exists
            if (!document.getElementById('rm-topic-modal')) {
                var modalHtml = '<div class="modal fade" id="rm-topic-modal" tabindex="-1" aria-hidden="true">' +
                    '<div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">' +
                    '<div class="modal-content blur">' +
                    '<div class="modal-header border-0 pb-0">' +
                    '<h5 class="modal-title fw-bold" id="rm-topic-title" style="font-size:1.1rem;"></h5>' +
                    '<button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal" aria-label="Close"></button>' +
                    '</div>' +
                    '<div class="modal-body" id="rm-topic-body"></div>' +
                    '<div class="modal-footer border-0 pt-0" id="rm-topic-footer" style="justify-content:center;gap:8px;"></div>' +
                    '</div></div></div>';
                document.body.insertAdjacentHTML('beforeend', modalHtml);
            }

            var modal = document.getElementById('rm-topic-modal');
            var titleEl = document.getElementById('rm-topic-title');
            var bodyEl = document.getElementById('rm-topic-body');
            var footerEl = document.getElementById('rm-topic-footer');

            titleEl.textContent = itemText;
            bodyEl.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="text-body-secondary small mt-2">Generating content and finding resources...</p></div>';
            footerEl.innerHTML = '';

            var bsModal = new coreui.Modal(modal);
            bsModal.show();

            // Find section title and item type
            var sectionTitle = '';
            var itemType = 'concept';
            var roadmapSections = self.data.roadmap.sections || [];
            for (var si = 0; si < roadmapSections.length; si++) {
                var sec = roadmapSections[si];
                if (sec.id === sectionId) sectionTitle = sec.title;
                var topics = sec.topics || [];
                for (var ti = 0; ti < topics.length; ti++) {
                    var items = topics[ti].items || [];
                    for (var ii = 0; ii < items.length; ii++) {
                        if (items[ii].id === itemId) { itemType = items[ii].type || 'concept'; break; }
                    }
                }
            }

            // Badge style by type
            var typeLabel = itemType.charAt(0).toUpperCase() + itemType.slice(1);
            var typeBadgeClass = 'badge-soft-primary';
            if (itemType === 'milestone') typeBadgeClass = 'badge-soft-warning';
            else if (itemType === 'checkpoint') typeBadgeClass = 'badge-soft-success';
            else if (itemType === 'decision') typeBadgeClass = 'badge-soft-info';

            // Generate content (or get existing)
            fetch('/api/roadmaps/topic_generate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({ roadmap_id: self.data.roadmap.id, topic_id: topicId, item_id: itemId })
            })
            .then(function(r) { return r.json(); })
            .then(function(json) {
                if (json.error) {
                    bodyEl.innerHTML = '<p class="text-danger small">' + self.esc(json.error) + '</p>';
                    return;
                }

                var content = json.content_html || json.content || '';
                var resources = json.resources || [];
                var regenCount = json.regenerate_count || 0;

                var regenIcon = '<svg class="icon text-warning" style="height:20px;width:20px;vertical-align:middle;"><use xlink:href="/assets/icons/sprites/free.svg#cil-loop-circular"></use></svg>';
                var regenTag = regenCount > 0 ? ' <span class="rm-regen-tag text-body-secondary" style="font-size:0.6rem;">(regenerated ' + regenCount + 'x)</span>' : '<span class="rm-regen-tag" style="display:none;font-size:0.6rem;"></span>';

                // Subtitle with regenerate button for content
                var subtitleHtml = '<div class="mb-3 d-flex align-items-center gap-2">' +
                    '<span class="badge ' + typeBadgeClass + '" style="font-size:0.65rem;">' + self.esc(typeLabel) + '</span>' +
                    '<span class="text-body-secondary small">' + self.esc(sectionTitle) + '</span>' +
                    '<button class="btn btn-link p-0 ms-1 rm-regen-btn" data-section="content" title="Regenerate content" style="text-decoration:none;opacity:0.6;transition:opacity 0.2s;">' + regenIcon + '</button>' +
                    regenTag + '</div>';

                // Resources header with regenerate button
                var resourcesHeaderHtml = '<div class="mt-3 d-flex align-items-center gap-2">' +
                    '<h6 class="fw-bold mb-0" style="font-size:0.85rem;"><i class="bx bx-link-alt me-1"></i>Free Resources</h6>' +
                    '<button class="btn btn-link p-0 rm-regen-btn" data-section="resources" title="Regenerate resources" style="text-decoration:none;opacity:0.6;transition:opacity 0.2s;">' + regenIcon + '</button>' +
                    '</div>';

                // Build resources HTML
                var resourcesHtml = '';
                if (resources.length > 0) {
                    resourcesHtml += resourcesHeaderHtml;
                    resourcesHtml += '<div class="d-flex flex-column gap-1">';
                    resources.forEach(function(res) {
                        var resType = (res.type || 'Article');
                        var resClass = resType.toLowerCase() === 'video' ? 'badge-soft-danger' : 'badge-soft-success';
                        resourcesHtml += '<a href="' + self.esc(res.url) + '" target="_blank" rel="noopener noreferrer" class="d-flex align-items-center gap-2 text-decoration-none p-2 rounded" style="background:rgba(var(--cui-body-color-rgb),0.03);border:1px solid rgba(var(--cui-body-color-rgb),0.06);">';
                        resourcesHtml += '<span class="badge ' + resClass + '" style="font-size:0.6rem;min-width:50px;">' + self.esc(resType) + '</span>';
                        resourcesHtml += '<span class="flex-grow-1 small" style="color:var(--cui-body-color);">' + self.esc(res.title || res.url) + '</span>';
                        if (res.source) resourcesHtml += '<span class="text-body-secondary" style="font-size:0.65rem;">' + self.esc(res.source) + '</span>';
                        resourcesHtml += '</a>';
                    });
                    resourcesHtml += '</div>';
                }

                if (!content) {
                    bodyEl.innerHTML = subtitleHtml + '<p class="text-body-secondary small">No content generated yet.</p>' + resourcesHtml;
                    return;
                }

                // Show subtitle + empty content div + resources (hidden)
                bodyEl.innerHTML = subtitleHtml +
                    '<div class="mb-3 rm-typewriter" style="font-size:0.88rem;line-height:1.7;color:var(--cui-body-color);"></div>' +
                    '<div class="rm-resources-wrap" style="display:none;">' + resourcesHtml + '</div>';

                // Typewriter effect: append words one by one
                var contentEl = bodyEl.querySelector('.rm-typewriter');
                var resourcesWrap = bodyEl.querySelector('.rm-resources-wrap');
                var tempDiv = document.createElement('div');
                tempDiv.innerHTML = content;

                // Extract text nodes and tags as tokens
                var tokens = [];
                function extractTokens(node) {
                    if (node.nodeType === 3) {
                        var words = node.textContent.split(/(\s+)/);
                        words.forEach(function(w) { if (w) tokens.push({ type: 'text', value: w }); });
                    } else if (node.nodeType === 1) {
                        tokens.push({ type: 'open', tag: node.tagName.toLowerCase(), attrs: node.attributes });
                        node.childNodes.forEach(function(c) { extractTokens(c); });
                        tokens.push({ type: 'close', tag: node.tagName.toLowerCase() });
                    }
                }
                tempDiv.childNodes.forEach(function(c) { extractTokens(c); });

                var idx = 0;
                var buf = '';
                var isTag = false;
                var tagBuf = '';

                function typeNext() {
                    if (idx >= tokens.length) {
                        // Done — remove cursor
                        contentEl.classList.add('rm-typewriter-done');
                        // Show resources with loading delay
                        if (resourcesWrap && resources.length > 0) {
                            var loaderDiv = document.createElement('div');
                            loaderDiv.className = 'rm-resources-loader';
                            loaderDiv.innerHTML = '<span class="text-body-secondary small" style="font-size:0.8rem;"><i class="bx bx-link-alt me-1"></i>Loading resources<span class="rm-dots"></span></span>';
                            bodyEl.appendChild(loaderDiv);

                            setTimeout(function() {
                                loaderDiv.remove();
                                resourcesWrap.style.display = '';
                                resourcesWrap.style.opacity = '0';
                                resourcesWrap.style.transition = 'opacity 0.3s ease';
                                requestAnimationFrame(function() {
                                    requestAnimationFrame(function() {
                                        resourcesWrap.style.opacity = '1';
                                    });
                                });
                            }, 500);
                        }
                        return;
                    }
                    var tok = tokens[idx++];
                    if (tok.type === 'open') {
                        var attrStr = '';
                        for (var i = 0; i < tok.attrs.length; i++) {
                            attrStr += ' ' + tok.attrs[i].name + '="' + tok.attrs[i].value + '"';
                        }
                        buf += '<' + tok.tag + attrStr + '>';
                        contentEl.innerHTML = buf;
                        typeNext();
                    } else if (tok.type === 'close') {
                        buf += '</' + tok.tag + '>';
                        contentEl.innerHTML = buf;
                        typeNext();
                    } else {
                        buf += tok.value;
                        contentEl.innerHTML = buf;
                        // Speed: faster for spaces, slower for words
                        var delay = tok.value.trim() ? 18 : 6;
                        setTimeout(typeNext, delay);
                    }
                }

                typeNext();

                // Regenerate button handlers
                bodyEl.querySelectorAll('.rm-regen-btn').forEach(function(btn) {
                    btn.addEventListener('mouseenter', function() { btn.style.opacity = '1'; });
                    btn.addEventListener('mouseleave', function() { btn.style.opacity = '0.6'; });
                    btn.addEventListener('click', function() {
                        var section = btn.getAttribute('data-section');
                        btn.style.pointerEvents = 'none';
                        btn.classList.add('rm-spin');

                        fetch('/api/roadmaps/topic_generate', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            credentials: 'include',
                            body: JSON.stringify({ roadmap_id: self.data.roadmap.id, topic_id: topicId, item_id: itemId, regenerate: true, regen_section: section })
                        })
                        .then(function(r) { return r.json(); })
                        .then(function(nj) {
                            btn.classList.remove('rm-spin');
                            btn.style.pointerEvents = '';
                            if (nj.error) return;

                            if (section === 'content') {
                                // Update regenerate count tag
                                var regenTag = bodyEl.querySelector('.rm-regen-tag');
                                if (regenTag) regenTag.innerHTML = '(regenerated ' + (nj.regenerate_count || 0) + 'x)';
                                // Typewrite new content in place
                                var newContent = nj.content_html || nj.content || '';
                                contentEl.classList.remove('rm-typewriter-done');
                                contentEl.innerHTML = '';
                                var newTokens = [];
                                var tmp = document.createElement('div');
                                tmp.innerHTML = newContent;
                                function extractNew(n) {
                                    if (n.nodeType === 3) { n.textContent.split(/(\s+)/).forEach(function(w) { if (w) newTokens.push({type:'text',value:w}); }); }
                                    else if (n.nodeType === 1) {
                                        newTokens.push({type:'open',tag:n.tagName.toLowerCase(),attrs:n.attributes});
                                        n.childNodes.forEach(function(c){extractNew(c);});
                                        newTokens.push({type:'close',tag:n.tagName.toLowerCase()});
                                    }
                                }
                                tmp.childNodes.forEach(function(c){extractNew(c);});
                                var ni = 0, nbuf = '';
                                function typeNew() {
                                    if (ni >= newTokens.length) { contentEl.classList.add('rm-typewriter-done'); return; }
                                    var t = newTokens[ni++];
                                    if (t.type === 'open') { var a=''; for(var k=0;k<t.attrs.length;k++) a+=' '+t.attrs[k].name+'="'+t.attrs[k].value+'"'; nbuf+='<'+t.tag+a+'>'; contentEl.innerHTML=nbuf; typeNew(); }
                                    else if (t.type === 'close') { nbuf+='</'+t.tag+'>'; contentEl.innerHTML=nbuf; typeNew(); }
                                    else { nbuf+=t.value; contentEl.innerHTML=nbuf; setTimeout(typeNew, t.value.trim()?18:6); }
                                }
                                typeNew();
                            } else if (section === 'resources') {
                                // Rebuild resources section in place
                                var rw = bodyEl.querySelector('.rm-resources-wrap');
                                if (rw && nj.resources && nj.resources.length > 0) {
                                    var rh = '<div class="d-flex flex-column gap-1">';
                                    nj.resources.forEach(function(res) {
                                        var rt=(res.type||'Article'), rc=rt.toLowerCase()==='video'?'badge-soft-danger':'badge-soft-success';
                                        rh+='<a href="'+self.esc(res.url)+'" target="_blank" rel="noopener noreferrer" class="d-flex align-items-center gap-2 text-decoration-none p-2 rounded" style="background:rgba(var(--cui-body-color-rgb),0.03);border:1px solid rgba(var(--cui-body-color-rgb),0.06);">';
                                        rh+='<span class="badge '+rc+'" style="font-size:0.6rem;min-width:50px;">'+self.esc(rt)+'</span>';
                                        rh+='<span class="flex-grow-1 small" style="color:var(--cui-body-color);">'+self.esc(res.title||res.url)+'</span>';
                                        if(res.source) rh+='<span class="text-body-secondary" style="font-size:0.65rem;">'+self.esc(res.source)+'</span>';
                                        rh+='</a>';
                                    });
                                    rh+='</div>';
                                    rw.style.opacity = '0';
                                    setTimeout(function() {
                                        rw.innerHTML = rh;
                                        rw.style.opacity = '1';
                                    }, 200);
                                }
                            }
                        })
                        .catch(function() { btn.classList.remove('rm-spin'); btn.style.pointerEvents = ''; });
                    });
                });

                // Footer buttons — rounded pill style
                footerEl.innerHTML =
                    '<button type="button" class="btn btn-secondary rounded-pill px-3" data-coreui-dismiss="modal" style="font-size:0.85rem;">Close</button>' +
                    '<button type="button" class="btn btn-primary rounded-pill px-3" id="rm-mark-complete" style="font-size:0.85rem;"><i class="bx bx-check-circle me-1"></i>Mark as Complete</button>' +
                    '<button type="button" class="btn btn-warning rounded-pill px-3" id="rm-take-quiz" style="font-size:0.85rem;"><i class="bx bx-question-mark me-1"></i>Take a Quiz</button>' +
                    '<button type="button" class="btn btn-success rounded-pill px-3" id="rm-generate-lesson" style="font-size:0.85rem;"><i class="bx bx-refresh me-1"></i>Generate New Lesson</button>';
            })
            .catch(function() {
                bodyEl.innerHTML = '<p class="text-danger small">Failed to load topic content.</p>';
            });
        }
    };

    window.RoadmapView = RoadmapView;
    document.addEventListener('DOMContentLoaded', function() { RoadmapView.init(); });
})();
