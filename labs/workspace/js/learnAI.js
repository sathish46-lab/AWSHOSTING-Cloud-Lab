/**
 * Wrapped with IIFE Error Boundary - Learn AI
 */
try {
  (function() {
    "use strict";


/**
 * Learn AI Application UI Logic
 */
(function () {
    'use strict';

    const LearnApp = {
        isInitialized: false,
        isDragging: false,
        currentResizer: null,
        animationFrame: null,
        initialAnchor: 0,
        initialWidth: 0,
        _aiGenerating: false,
        _userScrolledUp: false,

        setChatSendState: function (state) {
            const btn = document.getElementById('aiChatSend');
            if (!btn) return;
            if (state === 'stop') {
                this._aiGenerating = true;
                btn.classList.add('is-generating');
                btn.setAttribute('data-coreui-original-title', 'Stop AI generation');
                btn.setAttribute('title', 'Stop AI generation');
                btn.innerHTML = '<svg class="nav-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-media-stop"></use></svg>';
            } else {
                this._aiGenerating = false;
                btn.classList.remove('is-generating');
                btn.setAttribute('data-coreui-original-title', 'Send message');
                btn.setAttribute('title', 'Send message');
                btn.innerHTML = '<svg class="nav-icon"><use xlink:href="/assets/icons/sprites/free.svg#cil-paper-plane"></use></svg>';
            }
        },

        // Token usage tracking (cumulative per session)
        tokenStats: {
            totalInputTokens: 0,
            totalOutputTokens: 0,
            totalCachedTokens: 0,
            totalTokens: 0,
            responseCount: 0,
            history: [] // Per-message token history
        },

        formatTokenCount: function(num) {
            if (num >= 1000000) return (num / 1000000).toFixed(1) + 'M';
            if (num >= 1000) return (num / 1000).toFixed(0) + 'K';
            return num.toString();
        },

        updateStatsBar: function(usage) {
            if (!usage) return;

            // Maintain cumulative tokens for background tracking (e.g. if we ever need it)
            if (usage.source !== 'local' && usage.source !== 'lm_studio') {
                this.tokenStats.totalInputTokens += (usage.input_tokens || 0);
                this.tokenStats.totalOutputTokens += (usage.output_tokens || 0);
                this.tokenStats.totalCachedTokens += (usage.cached_tokens || 0);
                this.tokenStats.totalTokens += (usage.total_tokens || 0);
            }
            this.tokenStats.responseCount++;

            // Update DOM elements using the LATEST usage values for the current Context Window state
            const latestInput = usage.input_tokens || 0;
            const latestOutput = usage.output_tokens || 0;
            const latestCached = usage.cached_tokens || 0;

            const inputDisplay = document.getElementById('aiTokensDisplay');
            const outputDisplay = document.getElementById('aiOutputTokens');
            const cachedDisplay = document.getElementById('aiCachedTokens');
            const cachedWrap = document.getElementById('aiCachedTokensWrap');
            const cacheBadge = document.getElementById('aiCachePercBadge');
            const progressRing = document.getElementById('aiContextProgressRing');

            if (inputDisplay) {
                inputDisplay.innerText = `${this.formatTokenCount(latestInput)}/1M`;
                inputDisplay.setAttribute('data-coreui-original-title', `Context window: ${latestInput.toLocaleString()} / 1,000,000 tokens`);
            }
            if (outputDisplay) {
                outputDisplay.innerText = this.formatTokenCount(latestOutput);
            }

            // Cache metrics
            if (cachedWrap && cachedDisplay) {
                if (latestCached > 0) {
                    cachedWrap.classList.remove('d-none');
                    cachedDisplay.innerText = this.formatTokenCount(latestCached);
                    
                    const cachePct = latestInput > 0 ? Math.round((latestCached / latestInput) * 100) : 0;
                    cachedWrap.setAttribute('data-coreui-original-title', `Cached tokens: ${latestCached.toLocaleString()} (${cachePct}% cache hit)`);
                } else {
                    cachedWrap.classList.add('d-none');
                }
            }

            // Context fill percentage ring (How full is the 1M window?)
            if (cacheBadge && progressRing) {
                // 1M limit
                const contextLimit = 1000000;
                let fillPct = (latestInput / contextLimit) * 100;
                // If it's less than 1% but greater than 0, show 1% so it's visible, otherwise round it
                if (fillPct > 0 && fillPct < 1) fillPct = 1;
                else fillPct = Math.round(fillPct);

                cacheBadge.innerText = fillPct + '%';
                
                // Calculate dash offset for a circle with r=10 (circumference approx 63)
                // 100% = offset 0, 0% = offset 63
                const offset = 63 - (fillPct / 100) * 63;
                progressRing.style.strokeDashoffset = offset;
            }

            // For LM Studio, show N/A style
            if (usage.source === 'lm_studio') {
                if (inputDisplay) inputDisplay.innerText = `N/A`;
                if (outputDisplay) outputDisplay.innerText = `N/A`;
                if (progressRing) progressRing.style.strokeDashoffset = 63;
                if (cacheBadge) cacheBadge.innerText = '0%';
            }

            // For local tool execution, show the ⚡ indicator
            if (usage.source === 'local' && usage.tool_name) {
                if (inputDisplay) inputDisplay.innerHTML = `<span class="text-warning"><i class="bx bx-bolt-circle me-1"></i>Local Tool</span>`;
            }
        },

        _dashboardInitialized: false,
        initDashboard: function () {
            if (this._dashboardInitialized) return;
            const promptInput = document.getElementById('aiLessonPrompt') || document.getElementById('lessonTopicInput');
            const levelSelect = document.getElementById('aiLessonLevel');
            const btnSend = document.getElementById('btnGenerateLesson');
            const filterBtns = document.querySelectorAll('.lesson-filter-btn');

            if (!promptInput && !btnSend && filterBtns.length === 0) return;
            this._dashboardInitialized = true;

            // Make sample topic badges fill prompt with rich pre-prompt
            document.querySelectorAll('.topic-pill').forEach(badge => {
                badge.style.cursor = 'pointer';
                badge.addEventListener('click', () => {
                    const targetInput = document.getElementById('aiLessonPrompt') || document.getElementById('lessonTopicInput');
                    if (targetInput) {
                        const promptText = badge.getAttribute('data-prompt') || badge.textContent.trim();
                        targetInput.value = promptText;
                        targetInput.focus();
                    }
                });
            });

            // Handle level selection dropdown clicks
            function updateLevelSelectUI(chosenLevel) {
                const targetLevelInput = document.getElementById('aiLessonLevel');
                if (targetLevelInput) targetLevelInput.value = chosenLevel;
                if (levelSelect) levelSelect.value = chosenLevel;
                const labelSpan = document.getElementById('selectedLevelText') || document.getElementById('aiLessonLevelLabel');
                if (labelSpan) labelSpan.textContent = chosenLevel;

                document.querySelectorAll('.level-select-item').forEach(el => {
                    const elLevel = el.getAttribute('data-level');
                    if (elLevel === chosenLevel) {
                        el.className = 'level-select-item dropdown-item rounded-2 py-1 px-3 d-flex align-items-center justify-content-between fw-semibold';
                        el.innerHTML = `<span>${elLevel}</span>`;
                    } else {
                        el.className = 'level-select-item dropdown-item rounded-2 py-1 px-3 d-flex align-items-center justify-content-between';
                        el.innerHTML = `<span>${elLevel}</span>`;
                    }
                });
            }

            const initPrefs = (window.TOM_CONFIG && window.TOM_CONFIG.ui_preferences) || {};
            if (initPrefs.lesson_difficulty) {
                const savedDifficulty = initPrefs.lesson_difficulty.charAt(0).toUpperCase() + initPrefs.lesson_difficulty.slice(1).toLowerCase();
                updateLevelSelectUI(savedDifficulty);
            }

            document.querySelectorAll('.level-select-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    const chosenLevel = item.getAttribute('data-level') || 'Beginner';
                    updateLevelSelectUI(chosenLevel);
                    if (typeof LearnApp.savePreferenceToDB === 'function') {
                        LearnApp.savePreferenceToDB('lesson_difficulty', chosenLevel.toLowerCase());
                    }
                });
            });

            let pollInterval = null;

            function stopPolling() {
                if (pollInterval) {
                    clearInterval(pollInterval);
                    pollInterval = null;
                }
            }

            function startLessonGeneration() {
                const targetInput = document.getElementById('aiLessonPrompt') || document.getElementById('lessonTopicInput');
                const targetLevelInput = document.getElementById('aiLessonLevel');
                const topic = targetInput ? targetInput.value.trim() : '';
                const level = targetLevelInput ? targetLevelInput.value : (levelSelect ? levelSelect.value : 'Beginner');

                if (!topic) {
                    if (targetInput) targetInput.focus();
                    return;
                }

                stopPolling();

                const modalEl = document.getElementById('lessonGenModal');
                const modal = (typeof coreui !== 'undefined' && coreui.Modal) ? (coreui.Modal.getInstance(modalEl) || new coreui.Modal(modalEl)) : null;

                const topicDisp = document.getElementById('lessonGenTopicDisplay');
                if (topicDisp) topicDisp.textContent = "Curating AI Learning Path...";
                const levelDisp = document.getElementById('lessonGenLevelDisplay');
                if (levelDisp) levelDisp.textContent = level + ' Level Professional Course';
                const prog = document.getElementById('lessonGenProgress');
                if (prog) {
                    prog.style.width = '10%';
                    prog.className = 'progress-bar progress-bar-striped progress-bar-animated bg-primary rounded-pill';
                }
                const perc = document.getElementById('lessonGenPercent');
                if (perc) perc.textContent = '10%';
                const status = document.getElementById('lessonGenStatus');
                if (status) status.textContent = 'Initiating AI request...';
                const reqId = document.getElementById('lessonGenRequestId');
                if (reqId) reqId.textContent = 'Generating...';
                const statusVal = document.getElementById('lessonGenStatusValue');
                if (statusVal) statusVal.textContent = 'running';
                const msgVal = document.getElementById('lessonGenMessageValue');
                if (msgVal) msgVal.textContent = 'running';
                const compVal = document.getElementById('lessonGenCompletedValue');
                if (compVal) compVal.textContent = 'false';

                const statusBadge = document.getElementById('lessonGenStatusBadge');
                if (statusBadge) {
                    statusBadge.className = 'badge bg-warning bg-opacity-25 text-warning border border-warning border-opacity-25';
                    statusBadge.textContent = 'RUNNING';
                }

                if (modal) modal.show();

                fetch('/api/learnAI/generate_lesson', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ topic: topic, level: level })
                })
                .then(res => res.json())
                .then(data => {
                    if (data && data.request_id) {
                        if (reqId) reqId.textContent = data.request_id;
                        if (statusVal) statusVal.textContent = data.status || 'running';
                        if (msgVal) msgVal.textContent = data.message || 'running';

                        // Poll job status
                        pollInterval = setInterval(() => {
                            fetch(`/api/learnAI/job_status?request_id=${encodeURIComponent(data.request_id)}`)
                                .then(r => r.json())
                                .then(job => {
                                    if (!job) return;

                                    const pct = job.percentage || 40;
                                    if (prog) prog.style.width = pct + '%';
                                    if (perc) perc.textContent = pct + '%';
                                    if (status) status.textContent = job.message || 'Processing...';
                                    if (statusVal) statusVal.textContent = job.status || 'running';
                                    if (msgVal) msgVal.textContent = job.message || 'running';
                                    if (compVal) compVal.textContent = job.completed ? 'true' : 'false';

                                    if (job.completed && job.lesson_id) {
                                        stopPolling();
                                        if (prog) {
                                            prog.style.width = '100%';
                                            prog.className = 'progress-bar bg-success rounded-pill';
                                        }
                                        if (perc) perc.textContent = '100%';
                                        if (status) status.textContent = 'Complete! Redirecting to lesson...';
                                        if (statusBadge) {
                                            statusBadge.className = 'badge bg-success bg-opacity-25 text-success border border-success border-opacity-25';
                                            statusBadge.textContent = 'COMPLETED';
                                        }

                                        if (promptInput) promptInput.value = '';

                                        setTimeout(() => {
                                            window.location.href = `/learn/lesson/${job.lesson_id}`;
                                        }, 1200);
                                    } else if (job.failed) {
                                        stopPolling();
                                        if (prog) prog.className = 'progress-bar bg-danger rounded-pill';
                                        if (status) status.textContent = job.error_message || 'Generation failed.';
                                        if (statusBadge) {
                                            statusBadge.className = 'badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-25';
                                            statusBadge.textContent = 'FAILED';
                                        }
                                    }
                                })
                                .catch(() => {});
                        }, 1500);
                    } else {
                        if (status) status.textContent = data.message || 'Failed to start generation.';
                        if (prog) prog.className = 'progress-bar bg-danger rounded-pill';
                    }
                })
                .catch(() => {
                    if (status) status.textContent = 'Network error starting generator.';
                    if (prog) prog.className = 'progress-bar bg-danger rounded-pill';
                });
            }

            let currentFilterTab = 'all';
            let currentFilterLevel = 'All Levels';

            const filterPrefs = (window.TOM_CONFIG && window.TOM_CONFIG.ui_preferences && window.TOM_CONFIG.ui_preferences.learn_ai_filters) || null;
            if (filterPrefs) {
                let fPref = filterPrefs;
                if (typeof fPref === 'string') {
                    try { fPref = JSON.parse(fPref); } catch (e) {}
                }
                if (fPref && typeof fPref === 'object') {
                    if (fPref.tab) currentFilterTab = fPref.tab;
                    if (fPref.level && fPref.level !== 'all levels') {
                        currentFilterLevel = fPref.level.charAt(0).toUpperCase() + fPref.level.slice(1).toLowerCase();
                    } else if (fPref.level === 'all levels') {
                        currentFilterLevel = 'All Levels';
                    }
                }
            }

            if (currentFilterTab !== 'all') {
                document.querySelectorAll('.lesson-filter-btn').forEach(b => {
                    b.classList.remove('active', 'text-white');
                    b.classList.add('text-secondary');
                    if (b.getAttribute('data-filter') === currentFilterTab) {
                        b.classList.add('active', 'text-white');
                        b.classList.remove('text-secondary');
                    }
                });
            }
            if (currentFilterLevel !== 'All Levels') {
                const levelBtn = document.getElementById('lessonLevelDropdownBtn');
                if (levelBtn) levelBtn.textContent = currentFilterLevel;
            }

            function saveLearnAIFiltersPreference() {
                if (typeof LearnApp.savePreferenceToDB === 'function') {
                    const prefValue = {
                        tab: currentFilterTab || 'all',
                        level: (currentFilterLevel && currentFilterLevel !== 'All Levels') ? currentFilterLevel.toLowerCase() : 'all levels'
                    };
                    LearnApp.savePreferenceToDB('learn_ai_filters', JSON.stringify(prefValue));
                }
            }

            let currentPage = 2;
            let isLoadingLessons = false;
            let hasMoreLessons = true;

            async function fetchLessonsGrid(reset = true) {
                const masonryArea = document.getElementById('masonry-area');
                if (!masonryArea) return;
                
                if (reset) {
                    currentPage = 1;
                    hasMoreLessons = true;
                    masonryArea.innerHTML = `
                        <div class="col-12 text-center py-5">
                            <div class="spinner-border text-info mb-2" role="status"></div>
                            <p class="text-secondary small mb-0">Loading lessons...</p>
                        </div>`;
                }

                if (isLoadingLessons || !hasMoreLessons) return;
                isLoadingLessons = true;
                
                if (!reset) {
                    let loader = document.createElement('div');
                    loader.id = 'masonry-loader';
                    loader.className = 'col-12 text-center py-4 masonry-loader w-100';
                    loader.innerHTML = '<div class="spinner-border spinner-border-sm text-info mb-2" role="status"></div><p class="text-secondary small mb-0">Loading more...</p>';
                    masonryArea.appendChild(loader);
                    if (window.Masonry) {
                        const msnry = Masonry.data(masonryArea) || new Masonry(masonryArea, { percentPosition: true });
                        msnry.appended(loader);
                    }
                }
                
                try {
                    const res = await fetch('/api/learnAI/lessons?filter=' + encodeURIComponent(currentFilterTab) + '&level=' + encodeURIComponent(currentFilterLevel) + '&page=' + currentPage);
                    if (!res.ok) {
                        throw new Error('Network response was not ok');
                    }
                    const html = await res.text();
                    
                    if (reset) {
                        masonryArea.innerHTML = html;
                        if (html.includes('No lessons found!') || html.trim() === '') {
                            hasMoreLessons = false;
                        }
                        if (window.Masonry) {
                            new Masonry(masonryArea, { percentPosition: true });
                        }
                    } else {
                        const loader = document.getElementById('masonry-loader');
                        let msnry = null;
                        if (window.Masonry) {
                            msnry = Masonry.data(masonryArea) || new Masonry(masonryArea, { percentPosition: true });
                            if (loader) {
                                msnry.remove(loader);
                            }
                        }
                        if (loader) loader.remove();
                        
                        if (html.trim() === '' || html.includes('No lessons found!')) {
                            hasMoreLessons = false;
                        } else {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = html;
                            const newElements = Array.from(tempDiv.children);
                            
                            newElements.forEach(el => masonryArea.appendChild(el));
                            
                            if (msnry) {
                                msnry.appended(newElements);
                                msnry.layout();
                            }
                        }
                    }
                    
                    currentPage++;
                } catch (e) {
                    console.error('Error fetching lessons:', e);
                    if (reset) {
                        masonryArea.innerHTML = `<div class="col-12 text-center py-5"><p class="text-danger small">Error loading lessons.</p></div>`;
                    } else {
                        const loader = document.getElementById('masonry-loader');
                        if (loader) loader.remove();
                    }
                } finally {
                    isLoadingLessons = false;
                }
            }

            const panel2 = document.getElementById('learn-panel-2');
            if (panel2) {
                panel2.addEventListener('scroll', () => {
                    if (panel2.scrollTop + panel2.clientHeight >= panel2.scrollHeight - 300) {
                        if (!isLoadingLessons && hasMoreLessons) {
                            fetchLessonsGrid(false);
                        }
                    }
                });
            } else {
                window.addEventListener('scroll', () => {
                    if (window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 300) {
                        if (!isLoadingLessons && hasMoreLessons) {
                            fetchLessonsGrid(false);
                        }
                    }
                });
            }

            document.querySelectorAll('.lesson-filter-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.preventDefault();
                    document.querySelectorAll('.lesson-filter-btn').forEach(b => {
                        b.classList.remove('active', 'text-white');
                        b.classList.add('text-secondary');
                    });
                    btn.classList.add('active', 'text-white');
                    btn.classList.remove('text-secondary');

                    currentFilterTab = btn.getAttribute('data-filter') || 'all';
                    fetchLessonsGrid();
                    saveLearnAIFiltersPreference();
                });
            });

            document.querySelectorAll('.lesson-level-item').forEach(item => {
                item.addEventListener('click', (e) => {
                    e.preventDefault();
                    currentFilterLevel = item.getAttribute('data-level') || 'All Levels';
                    const levelBtn = document.getElementById('lessonLevelDropdownBtn');
                    if (levelBtn) levelBtn.textContent = currentFilterLevel;
                    fetchLessonsGrid();
                    saveLearnAIFiltersPreference();
                });
            });

            if (btnSend) {
                btnSend.addEventListener('click', startLessonGeneration);
            }
            if (promptInput) {
                promptInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        startLessonGeneration();
                    }
                });
            }

            // Delegated click handlers for cards and dashboard actions without inline onclick
            if (!window._dashboardDelegationAdded) {
                window._dashboardDelegationAdded = true;
                document.addEventListener('click', (e) => {
                    const likeBtn = e.target.closest('.toggle-like-btn, .like-lesson-btn');
                    if (likeBtn) {
                        e.preventDefault();
                        e.stopPropagation();
                        const lessonId = likeBtn.getAttribute('data-lesson-id') || document.getElementById('currentLessonId')?.value;
                        if (lessonId) {
                            window.toggleLikeLessonAction(lessonId, likeBtn);
                        }
                        return;
                    }

                    const shareBtn = e.target.closest('.share-lesson-btn');
                    if (shareBtn) {
                        e.preventDefault();
                        e.stopPropagation();
                        const lessonId = shareBtn.getAttribute('data-lesson-id') || document.getElementById('currentLessonId')?.value;
                        const lessonTitle = shareBtn.getAttribute('data-lesson-title') || document.querySelector('.card-header strong')?.innerText || 'AI Lesson';
                        if (lessonId) {
                            window.shareLessonAction(lessonId, lessonTitle, shareBtn);
                        }
                        return;
                    }

                    const deleteAction = e.target.closest('.delete-lesson-action');
                    if (deleteAction) {
                        e.preventDefault();
                        e.stopPropagation();
                        const lessonId = deleteAction.getAttribute('data-lesson-id');
                        const lessonTitle = deleteAction.getAttribute('data-lesson-title');
                        if (lessonId && typeof window.deleteLessonAction === 'function') {
                            window.deleteLessonAction(lessonId, lessonTitle);
                        }
                        return;
                    }

                    const visAction = e.target.closest('.visibility-toggle-action');
                    if (visAction) {
                        e.preventDefault();
                        e.stopPropagation();
                        const lessonId = visAction.getAttribute('data-lesson-id');
                        const targetVis = visAction.getAttribute('data-target-visibility');
                        if (lessonId && targetVis && typeof window.toggleLessonVisibility === 'function') {
                            window.toggleLessonVisibility(lessonId, targetVis);
                        }
                        return;
                    }

                    const revealHintBtn = e.target.closest('.reveal-hint-btn');
                    if (revealHintBtn) {
                        e.preventDefault();
                        e.stopPropagation();
                        const lessonId = revealHintBtn.getAttribute('data-lesson-id') || revealHintBtn.closest('.lesson-card')?.getAttribute('data-lesson-id');
                        if (!lessonId) return;

                        const modalEl = document.getElementById('revealPromptModal');
                        const modalContent = document.getElementById('revealPromptModalContent');
                        if (!modalEl || !modalContent) return;

                        modalContent.innerHTML = '<div class="p-4 text-center text-white"><span class="spinner-border spinner-border-sm text-warning me-2"></span>Loading generation prompt details...</div>';
                        const modalInstance = new (window.coreui?.Modal || window.bootstrap?.Modal)(modalEl);
                        modalInstance.show();

                        fetch('/api/learnAI/reveal_prompt', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ lesson_id: lessonId })
                        })
                        .then(r => r.text())
                        .then(html => {
                            modalContent.innerHTML = html;
                            if (html.indexOf('Generation Prompt') !== -1 && html.indexOf('btn-confirm-reveal-prompt') === -1) {
                                const bulbBtn = document.querySelector('.reveal-hint-btn[data-lesson-id="' + lessonId + '"]');
                                if (bulbBtn) {
                                    bulbBtn.classList.remove('text-secondary');
                                    bulbBtn.classList.add('text-success');
                                    bulbBtn.title = 'Generation Prompt (Unlocked)';
                                }
                            }
                        })
                        .catch(err => {
                            modalContent.innerHTML = '<div class="p-4 text-center text-danger">Failed to load prompt details.</div>';
                        });
                        return;
                    }

                    // Card click navigation removed — only "Start Learning" button opens lesson
                });

                document.addEventListener('click', function (e) {
                    const confirmBtn = e.target.closest('.btn-confirm-reveal-prompt');
                    if (confirmBtn) {
                        e.preventDefault();
                        const lessonId = confirmBtn.getAttribute('data-lesson-id');
                        if (!lessonId) return;

                        confirmBtn.disabled = true;
                        const originalText = confirmBtn.innerHTML;
                        confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Revealing...';

                        fetch('/api/learnAI/reveal_prompt_confirm', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ lesson_id: lessonId })
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res.error) {
                                if (typeof TomNotify !== 'undefined') {
                                    TomNotify.show(res.error, "Insufficient Fuel", "warning", 5000);
                                } else {
                                    alert(res.error);
                                }
                                confirmBtn.disabled = false;
                                confirmBtn.innerHTML = originalText;
                                return;
                            }
                            if (res.new_jolt !== undefined) {
                                const headerJolt = document.getElementById('header-jolt');
                                if (headerJolt) headerJolt.textContent = res.new_jolt;
                            }
                            if (typeof TomNotify !== 'undefined' && res.message) {
                                TomNotify.show(res.message, "Success", "success", 3000);
                            }
                            const bulbBtn = document.querySelector('.reveal-hint-btn[data-lesson-id="' + lessonId + '"]');
                            if (bulbBtn) {
                                bulbBtn.classList.remove('text-secondary');
                                bulbBtn.classList.add('text-success');
                                bulbBtn.title = 'Generation Prompt (Unlocked)';
                            }
                            // Re-fetch exact revealed HTML
                            fetch('/api/learnAI/reveal_prompt', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ lesson_id: lessonId })
                            })
                            .then(r => r.text())
                            .then(html => {
                                const modalContent = document.getElementById('revealPromptModalContent');
                                if (modalContent) modalContent.innerHTML = html;
                            });
                        })
                        .catch(err => {
                            confirmBtn.disabled = false;
                            confirmBtn.innerHTML = originalText;
                            if (typeof TomNotify !== 'undefined') {
                                TomNotify.show("Error confirming reveal prompt.", "Error", "danger", 3500);
                            }
                        });
                    }
                });
            }
        },

        init: function () {
            if (!document.querySelector('.stable-app-view') && !document.querySelector('.split-panel-view')) {
                document.body.style.removeProperty('overflow');
                document.body.style.removeProperty('height');
                const mainWrapper = document.querySelector('.wrapper');
                if (mainWrapper) {
                    mainWrapper.style.removeProperty('overflow');
                    mainWrapper.style.removeProperty('height');
                }
            }

            const appWrapper = document.querySelector('.learn-app-wrapper');
            if (!appWrapper) return;

            // Enforce Panel 3 visibility based on whether we are in a chapter or overview
            var currentChapterInput = document.getElementById('currentChapterId');
            var panel3El = document.getElementById('learn-panel-3');
            var resizer2El = document.querySelector('.gutter-horizontal[data-target="learn-panel-3"]');
            
            if (currentChapterInput && !currentChapterInput.value) {
                // No chapter selected (Course Overview) -> Hide AI Assist Panel
                if (panel3El) {
                    panel3El.classList.add('d-none');
                    panel3El.classList.remove('d-flex');
                }
                if (resizer2El) resizer2El.classList.add('d-none');
            } else if (currentChapterInput && currentChapterInput.value) {
                // Chapter selected -> Show AI Assist Panel
                if (panel3El) {
                    panel3El.classList.remove('d-none');
                    panel3El.classList.add('d-flex');
                }
                if (resizer2El) resizer2El.classList.remove('d-none');
            }

            this.restorePaneWidths();
            this.adjustVHE(appWrapper);
            this.initAIChat();
            this.initContentGenerator();
            this.initChapterNav();
            this.initStudyHighlighter();
            this.initFloatingTooltips();

            if (!this.isInitialized) {
                this.initResizers();
                window.addEventListener('resize', () => {
                    const currentWrapper = document.querySelector('.learn-app-wrapper');
                    if (currentWrapper) this.adjustVHE(currentWrapper);
                });
                this.isInitialized = true;
            }
        },

        // Top-layer floating tooltip on document.body for narrow Panel 1 icons/numbers
        initFloatingTooltips: function () {
            let floatingEl = document.getElementById('learn-first-layer-tooltip');
            if (!floatingEl) {
                floatingEl = document.createElement('div');
                floatingEl.id = 'learn-first-layer-tooltip';
                floatingEl.style.cssText = 'position: fixed; z-index: 99999999; padding: 6px 12px; background: rgba(15, 17, 23, 0.98); color: #ffffff; font-size: 11px; font-weight: 600; white-space: nowrap; border-radius: 6px; box-shadow: 0 4px 16px rgba(0, 0, 0, 0.75); border: 1px solid rgba(255, 255, 255, 0.15); pointer-events: none; opacity: 0; visibility: hidden; transition: opacity 0.15s ease, transform 0.15s ease;';
                document.body.appendChild(floatingEl);
            }

            const hideTooltip = () => {
                floatingEl.style.opacity = '0';
                floatingEl.style.visibility = 'hidden';
            };

            document.addEventListener('mouseover', (e) => {
                const target = e.target.closest('#learn-panel-1 [data-tooltip], #learn-panel-1 [title]');
                if (!target) return;

                // Remove native browser title to prevent default ugly tooltips
                if (target.hasAttribute('title')) {
                    target.setAttribute('data-tooltip', target.getAttribute('title'));
                    target.removeAttribute('title');
                }
                target.removeAttribute('data-coreui-original-title');

                const panel1 = document.getElementById('learn-panel-1');
                if (!panel1) return;

                // Only show custom floating tooltip if Panel 1 is in narrow / compact mode (<= 175px or collapsed)
                const isNarrow = panel1.offsetWidth <= 175 || panel1.classList.contains('auto-compact') || panel1.getAttribute('data-state') === 'collapsed';
                if (!isNarrow) {
                    hideTooltip();
                    return;
                }

                const text = target.getAttribute('data-tooltip');
                if (!text) return;

                const rect = target.getBoundingClientRect();
                floatingEl.textContent = text;
                floatingEl.style.left = (rect.left + rect.width / 2) + 'px';
                floatingEl.style.top = (rect.top - 8) + 'px';
                floatingEl.style.transform = 'translate(-50%, -100%)';
                floatingEl.style.opacity = '1';
                floatingEl.style.visibility = 'visible';
            });

            document.addEventListener('mouseout', (e) => {
                const target = e.target.closest('#learn-panel-1 [data-tooltip], #learn-panel-1 [title]');
                if (target) {
                    hideTooltip();
                }
            });

            window.addEventListener('scroll', hideTooltip, true);
        },

        // Save and Restore Pane Widths & Three Panel Sizes to DB / Storage
        savePreferenceToDB: function (key, value) {
            clearTimeout(this._dbSyncTimeout);
            this._pendingPrefs = this._pendingPrefs || {};
            this._pendingPrefs[key] = value;
            this._dbSyncTimeout = setTimeout(() => {
                const prefs = { ...this._pendingPrefs };
                this._pendingPrefs = {};
                Object.keys(prefs).forEach(k => {
                    const fd = new FormData();
                    fd.append('preference_id', k);
                    fd.append('value', prefs[k]);
                    fetch('/api/user/preference_save', { method: 'POST', body: fd }).catch(() => {});
                });
            }, 300);
        },

        savePaneWidth: function (id, width) {
            localStorage.removeItem(`learn-pane-${id}`);
            sessionStorage.removeItem(`learn-pane-${id}`);

            // Compute and save ONLY learnAiThreePanelSizes (SNA-style: raw percentages)
            const wrapper = document.querySelector('.split-panel-body') || document.querySelector('.learn-app-wrapper');
            if (wrapper) {
                const totalW = wrapper.offsetWidth || window.innerWidth;
                const p1El = document.getElementById('learn-panel-1');
                const p2El = document.getElementById('learn-panel-2');
                const p3El = document.getElementById('learn-panel-3');
                if (p1El && totalW > 0) {
                    const pct1 = (p1El.offsetWidth / totalW) * 100;
                    const isP3Visible = p3El && !p3El.classList.contains('d-none') && p3El.offsetWidth > 0;
                    let pct3;
                    if (isP3Visible) {
                        pct3 = (p3El.offsetWidth / totalW) * 100;
                    } else {
                        // Preserve existing Panel 3 percentage
                        try {
                            const prev = JSON.parse(localStorage.getItem('learnAiThreePanelSizes') || '[]');
                            pct3 = (Array.isArray(prev) && prev[2] > 5) ? prev[2] : 25;
                        } catch(e) { pct3 = 25; }
                    }
                    const pct2 = 100 - pct1 - pct3;
                    // SNA sanitizeSplitSizes: ensure all > 0 and sum ~ 100
                    if (pct1 > 0 && pct2 > 0 && pct3 > 0) {
                        const sum = pct1 + pct2 + pct3;
                        const normalized = [pct1 * 100 / sum, pct2 * 100 / sum, pct3 * 100 / sum];
                        const sizesStr = JSON.stringify(normalized.map(v => Math.round(v * 100) / 100));
                        localStorage.setItem('learnAiThreePanelSizes', sizesStr);
                        sessionStorage.setItem('learnAiThreePanelSizes', sizesStr);
                        this.savePreferenceToDB('learnAiThreePanelSizes', sizesStr);
                    }
                }
            }
        },

        restorePaneWidths: function () {
            var prefs = (window.TOM_CONFIG && window.TOM_CONFIG.ui_preferences) || {};

            ['outlineSidebar', 'courseSidebar', 'paneAI'].forEach(function(id) {
                localStorage.removeItem('learn-pane-' + id);
                sessionStorage.removeItem('learn-pane-' + id);
            });

            var threePanelSizes = localStorage.getItem('learnAiThreePanelSizes') || sessionStorage.getItem('learnAiThreePanelSizes') || prefs['learnAiThreePanelSizes'];
            if (threePanelSizes && typeof threePanelSizes !== 'string') threePanelSizes = JSON.stringify(threePanelSizes);
            if (threePanelSizes) {
                localStorage.setItem('learnAiThreePanelSizes', threePanelSizes);
                sessionStorage.setItem('learnAiThreePanelSizes', threePanelSizes);
                try {
                    var arr = JSON.parse(threePanelSizes);
                    if (Array.isArray(arr) && arr.length === 3) {
                        var p1El = document.getElementById('learn-panel-1');
                        var p2El = document.getElementById('learn-panel-2');
                        var p3El = document.getElementById('learn-panel-3');

                        // SNA normalizeSizes: ensure sum = 100
                        var sum = arr[0] + arr[1] + arr[2];
                        var sizes = (Math.abs(sum - 100) > 0.01 && sum > 0) ?
                            arr.map(function(v) { return v * 100 / sum; }) : arr.slice();

                        if (sizes[0] + sizes[2] > 75) {
                            var excess = (sizes[0] + sizes[2]) - 75;
                            sizes[2] = Math.max(18, sizes[2] - excess);
                        }

                        if (p1El) {
                            p1El.style.width = sizes[0] + '%';
                            p1El.style.flexBasis = sizes[0] + '%';
                            var wrapperW = p1El.parentElement ? p1El.parentElement.offsetWidth : window.innerWidth;
                            var p1Px = (sizes[0] / 100) * wrapperW;
                            this.setOutlineState(p1El, p1Px > 175 ? 'expanded' : 'collapsed');
                        }
                        if (p2El) {
                            p2El.style.width = '0';
                            p2El.style.flex = '1 1 0%';
                        }
                        if (p3El) {
                            p3El.style.width = sizes[2] + '%';
                            p3El.style.flexBasis = sizes[2] + '%';
                        }
                    }
                } catch (e) {}
            }
            var zf = document.getElementById('learn-panel-zero-flicker');
            if (zf) zf.remove();
        },

        initResizers: function () {
            var self = this;
            if (self._resizersAttached) return;
            self._resizersAttached = true;

            var onMouseDown = function (e) {
                var resizer = e.target.closest('.gutter-horizontal');
                if (!resizer) return;

                e.preventDefault();
                self.isDragging = true;
                self.currentResizer = resizer;
                self.initialAnchor = e.clientX || (e.touches && e.touches[0] ? e.touches[0].clientX : 0);

                var targetId = resizer.getAttribute('data-target');
                var target = document.getElementById(targetId);
                if (target) {
                    self.initialWidth = target.offsetWidth;
                }
                document.body.classList.add('user-select-none');
            };

            var onMouseMove = function (e) {
                if (!self.isDragging || !self.currentResizer) return;
                if (self.animationFrame) {
                    cancelAnimationFrame(self.animationFrame);
                }
                self.animationFrame = requestAnimationFrame(function () {
                    self.handleResize(e);
                });
            };

            var onMouseUp = function () {
                if (!self.isDragging) return;
                self.isDragging = false;
                if (self.animationFrame) {
                    cancelAnimationFrame(self.animationFrame);
                    self.animationFrame = null;
                }
                document.body.classList.remove('user-select-none');

                if (self.currentResizer) {
                    var targetId = self.currentResizer.getAttribute('data-target');
                    var target = document.getElementById(targetId);
                    if (target) {
                        self.savePaneWidth(targetId, target.offsetWidth);
                    }
                    self.currentResizer = null;
                }
            };

            document.addEventListener('mousedown', onMouseDown);
            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);

            document.addEventListener('touchstart', onMouseDown, { passive: false });
            document.addEventListener('touchmove', onMouseMove, { passive: false });
            document.addEventListener('touchend', onMouseUp);
        },

        handleResize: function (e) {
            if (!this.isDragging || !this.currentResizer) return;

            var targetId = this.currentResizer.getAttribute('data-target');
            var target = document.getElementById(targetId);
            if (!target) return;

            var clientX = e.clientX;
            if (clientX === undefined && e.touches && e.touches[0]) {
                clientX = e.touches[0].clientX;
            }
            if (clientX === undefined) return;

            var direction = this.currentResizer.getAttribute('data-direction') || 'left';
            var delta = (direction === 'right') ? (this.initialAnchor - clientX) : (clientX - this.initialAnchor);
            var newWidth = this.initialWidth + delta;

            var wrapper = document.querySelector('.split-panel-body') || document.querySelector('.learn-app-wrapper');
            var totalW = wrapper ? wrapper.offsetWidth : window.innerWidth;

            var p1 = document.getElementById('learn-panel-1');
            var p3 = document.getElementById('learn-panel-3');
            var isP3Visible = p3 && !p3.classList.contains('d-none') && p3.offsetWidth > 0;

            if (targetId === 'learn-panel-1') {
                var minW = 70;
                var p3W = isP3Visible ? p3.offsetWidth : 0;
                var maxW = totalW - 300 - p3W - 16;
                if (maxW < minW) maxW = minW;

                if (newWidth < minW) newWidth = minW;
                if (newWidth > maxW) newWidth = maxW;

                target.style.width = newWidth + 'px';
                target.style.flexBasis = newWidth + 'px';
                target.style.flexGrow = '0';
                target.style.flexShrink = '0';

                this.setOutlineState(target, newWidth > 175 ? 'expanded' : 'collapsed');
            } else if (targetId === 'learn-panel-3') {
                var minW = 300;
                var p1W = p1 ? p1.offsetWidth : 70;
                var maxW = totalW - 300 - p1W - 16;
                if (maxW < minW) maxW = minW;

                if (newWidth < minW) newWidth = minW;
                if (newWidth > maxW) newWidth = maxW;

                target.style.width = newWidth + 'px';
                target.style.flexBasis = newWidth + 'px';
                target.style.flexGrow = '0';
                target.style.flexShrink = '0';
            }
        },

        setOutlineState: function (sidebar, state) {
            const compactView = sidebar.querySelector('.outline-compact');
            const fullView = sidebar.querySelector('.outline-full');

            if (state === 'expanded') {
                sidebar.setAttribute('data-state', 'expanded');
                sidebar.classList.remove('auto-compact');
                sidebar.style.removeProperty('max-width');
                if (compactView && fullView) {
                    compactView.classList.add('d-none');
                    compactView.classList.remove('d-flex');
                    fullView.classList.remove('d-none');
                    fullView.classList.add('d-flex');
                }
            } else {
                sidebar.setAttribute('data-state', 'collapsed');
                sidebar.classList.add('auto-compact');
                sidebar.style.width = '68px';
                sidebar.style.flexBasis = '68px';
                if (compactView && fullView) {
                    fullView.classList.add('d-none');
                    fullView.classList.remove('d-flex');
                    compactView.classList.remove('d-none');
                    compactView.classList.add('d-flex');
                }
            }
        },

        toggleOutline: function () {
            const sidebar = document.getElementById('learn-panel-1') || document.getElementById('outlineSidebar');
            if (!sidebar) return;

            const isCollapsed = sidebar.getAttribute('data-state') === 'collapsed' || sidebar.classList.contains('auto-compact');
            const newWidth = isCollapsed ? 280 : 68;

            sidebar.style.width = newWidth + 'px';
            sidebar.style.flexBasis = newWidth + 'px';
            this.setOutlineState(sidebar, isCollapsed ? 'expanded' : 'collapsed');
            this.savePaneWidth(sidebar.id, newWidth);
        },

        adjustVHE: function (appWrapper) {
            const header = document.querySelector('header.header');
            const footer = document.querySelector('footer.footer');

            const headerHeight = header ? (header.offsetHeight || 64) : 64;
            const footerHeight = footer ? (footer.offsetHeight || 38) : 38;

            const availableHeight = window.innerHeight - headerHeight - footerHeight;

            if (appWrapper && (appWrapper.classList.contains('stable-app-view') || appWrapper.classList.contains('split-panel-view') || document.querySelector('.split-panel'))) {
                appWrapper.style.height = `${availableHeight}px`;
                document.body.style.height = '100vh';
                document.body.style.overflow = 'hidden';
                const mainWrapper = document.querySelector('.wrapper');
                if (mainWrapper) {
                    mainWrapper.style.height = '100vh';
                    mainWrapper.style.overflow = 'hidden';
                }
            } else if (appWrapper) {
                appWrapper.style.removeProperty('height');
                document.body.style.removeProperty('height');
                document.body.style.removeProperty('overflow');
                const mainWrapper = document.querySelector('.wrapper');
                if (mainWrapper) {
                    mainWrapper.style.removeProperty('height');
                    mainWrapper.style.removeProperty('overflow');
                }
            }
        },

        _processChatHistoryHtml: function () {
            const chatHistory = document.getElementById('aiChatHistory');
            if (!chatHistory) return;

            if (typeof coreui !== 'undefined' && coreui.Popover) {
                chatHistory.querySelectorAll('[data-coreui-toggle="popover"]').forEach(btn => {
                    new coreui.Popover(btn, { trigger: 'focus' });
                });
            }
            
            chatHistory.querySelectorAll('.ai-row .msg-bubble p').forEach(p => {
                const mdText = p.dataset.rawMd || p.innerText;
                if (mdText.trim()) {
                    LearnApp.renderMarkdownWithHighlighting(mdText, p);
                }
            });

            // Restore token usage metrics from history
            LearnApp.tokenStats.totalInputTokens = 0;
            LearnApp.tokenStats.totalOutputTokens = 0;
            LearnApp.tokenStats.totalCachedTokens = 0;
            
            const aiRows = chatHistory.querySelectorAll('.ai-row[data-total-tokens]');
            
            // Keep cumulative stats for background tracking
            aiRows.forEach(row => {
                LearnApp.tokenStats.totalInputTokens += parseInt(row.getAttribute('data-input-tokens') || 0, 10);
                LearnApp.tokenStats.totalOutputTokens += parseInt(row.getAttribute('data-output-tokens') || 0, 10);
                LearnApp.tokenStats.totalCachedTokens += parseInt(row.getAttribute('data-cached-tokens') || 0, 10);
            });
            
            // Display current Context Window size based on the LATEST interaction
            if (aiRows.length > 0) {
                const lastRow = aiRows[aiRows.length - 1];
                const latestUsage = {
                    input_tokens: parseInt(lastRow.getAttribute('data-input-tokens') || 0, 10),
                    output_tokens: parseInt(lastRow.getAttribute('data-output-tokens') || 0, 10),
                    cached_tokens: parseInt(lastRow.getAttribute('data-cached-tokens') || 0, 10),
                    source: 'gemini'
                };
                LearnApp.tokenStats.totalInputTokens -= latestUsage.input_tokens;
                LearnApp.tokenStats.totalOutputTokens -= latestUsage.output_tokens;
                LearnApp.tokenStats.totalCachedTokens -= latestUsage.cached_tokens;
                
                LearnApp.updateStatsBar(latestUsage);
            } else {
                LearnApp.updateStatsBar({ input_tokens: 0, output_tokens: 0, cached_tokens: 0, source: 'gemini' });
            }
            
            setTimeout(() => { chatHistory.scrollTop = chatHistory.scrollHeight; }, 100);
        },

        unlockAiAssistAction: function (lessonId) {
            if (!lessonId) return;

            const btn = document.getElementById('unlockAiAssistBtn');
            const origHtml = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="bx bx-loader-circle bx-spin fs-5"></i> Unlocking...';
            }

            fetch('/api/learnAI/unlock_ai_assist', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ lesson_id: lessonId })
            })
            .then(r => r.json())
            .then(data => {
                if (data.status === 'success') {
                    // Update header Jolt display
                    const headerJolt = document.getElementById('header-jolt');
                    if (headerJolt && data.new_jolt !== undefined) {
                        headerJolt.innerText = Number(data.new_jolt).toLocaleString();
                    }

                    // Set unlocked status to 1
                    const unlockInput = document.getElementById('isAiAssistUnlocked');
                    if (unlockInput) unlockInput.value = '1';

                    // Remove locked screen and show chat + input
                    const lockedScreen = document.getElementById('aiAssistLockedScreen');
                    if (lockedScreen) lockedScreen.remove();

                    const chatHistory = document.getElementById('aiChatHistory');
                    const chatInputBar = document.getElementById('aiChatInputBar');
                    if (chatHistory) {
                        chatHistory.classList.remove('d-none');
                        chatHistory.classList.add('d-flex');
                    }
                    if (chatInputBar) {
                        chatInputBar.classList.remove('d-none');
                    }

                    // Now load chat history
                    const chapterId = document.getElementById('currentChapterId')?.value || '';
                    if (chatHistory && chapterId) {
                        chatHistory.innerHTML = '<div class="text-center py-4 my-auto text-secondary small d-flex flex-column align-items-center gap-2"><i class="bx bx-loader-circle bx-spin fs-4 text-primary"></i><span>Loading conversation...</span></div>';
                        fetch(`/api/learnAI/history?lesson_id=${lessonId}&chapter_id=${chapterId}`)
                            .then(r => r.text())
                            .then(html => {
                                chatHistory.innerHTML = html;
                                if (typeof LearnApp._processChatHistoryHtml === 'function') LearnApp._processChatHistoryHtml();
                            });
                    }
                } else {
                    alert(data.error || 'Failed to unlock AI Assist.');
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = origHtml;
                    }
                }
            })
            .catch(err => {
                alert('Error connecting to unlock API.');
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = origHtml;
                }
            });
        },

        // AI Chat Functionality
        initAIChat: function () {
            const chatInput = document.getElementById('aiChatInput');
            const chatSend = document.getElementById('aiChatSend');
            const chatHistory = document.getElementById('aiChatHistory');
            const chapterId = document.getElementById('currentChapterId')?.value;

            if (!chatInput || !chatSend || !chatHistory) return;
            if (chatInput.dataset.aiChatInit === 'true') return;
            chatInput.dataset.aiChatInit = 'true';
            
            // Token History Modal Logic
            const tokenHistoryBtn = document.getElementById('token-history-btn');
            if (tokenHistoryBtn) {
                tokenHistoryBtn.addEventListener('click', () => {
                    const lessonId = document.getElementById('currentLessonId')?.value || '';
                    const chapterId = document.getElementById('currentChapterId')?.value || '';
                    
                    const modalContent = document.getElementById('tokenHistoryModalContent');
                    if (modalContent) {
                        modalContent.innerHTML = '<div class="p-5 text-center"><i class="bx bx-loader-circle bx-spin fs-2 text-primary"></i><p class="mt-2 text-secondary">Loading token history...</p></div>';
                    }
                    
                    const modalEl = document.getElementById('tokenHistoryModal');
                    if (modalEl) {
                        if (typeof coreui !== 'undefined' && coreui.Modal) {
                            const modal = new coreui.Modal(modalEl);
                            modal.show();
                        } else if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                            const modal = new bootstrap.Modal(modalEl);
                            modal.show();
                        }
                    }
                    
                    fetch(`/api/learnAI/token_history?lesson_id=${lessonId}&chapter_id=${chapterId}`)
                        .then(r => r.text())
                        .then(html => {
                            if (modalContent) modalContent.innerHTML = html;
                        })
                        .catch(err => {
                            if (modalContent) modalContent.innerHTML = '<div class="p-5 text-center text-danger">Failed to load token history.</div>';
                        });
                });
            }

            // Scroll-to-bottom button & scroll spy (scroll to wait when user scrolls up during stream)
            this._userScrolledUp = false;
            const scrollBtn = document.getElementById('aiChatScrollToBottom');
            if (scrollBtn && !chatHistory.dataset.scrollSpyInit) {
                chatHistory.dataset.scrollSpyInit = 'true';
                chatHistory.addEventListener('scroll', () => {
                    const isAtBottom = chatHistory.scrollHeight - chatHistory.scrollTop - chatHistory.clientHeight <= 45;
                    if (isAtBottom) {
                        LearnApp._userScrolledUp = false;
                        scrollBtn.classList.remove('d-flex');
                        scrollBtn.classList.add('d-none');
                    } else {
                        LearnApp._userScrolledUp = true;
                        scrollBtn.classList.remove('d-none');
                        scrollBtn.classList.add('d-flex');
                        const activeStream = document.querySelector('.current-ai-stream');
                        if (activeStream) {
                            scrollBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin fs-4"></i>';
                        } else {
                            scrollBtn.innerHTML = '<i class="bx bx-down-arrow-alt fs-4"></i>';
                        }
                    }
                });

                scrollBtn.addEventListener('click', () => {
                    LearnApp._userScrolledUp = false;
                    chatHistory.scrollTo({ top: chatHistory.scrollHeight, behavior: 'smooth' });
                    setTimeout(() => {
                        scrollBtn.classList.remove('d-flex');
                        scrollBtn.classList.add('d-none');
                    }, 300);
                });
            }

            // Check if AI Assist is locked for this user on this lesson
            if (document.getElementById('isAiAssistUnlocked') && document.getElementById('isAiAssistUnlocked').value !== '1') {
                return;
            }

            // Check if chat history was pre-loaded by PHP directly inside content.php
            if (chatHistory.dataset.preloaded === 'true') {
                chatHistory.removeAttribute('data-preloaded');
                chatHistory.dataset.chatLoaded = 'true';
                LearnApp._processChatHistoryHtml();
            } else {
                const lessonId = document.getElementById('currentLessonId')?.value || '';
                if (!chapterId) {
                    chatHistory.innerHTML = '';
                } else {
                    chatHistory.innerHTML = '<div class="text-center py-4 my-auto text-secondary small d-flex flex-column align-items-center gap-2"><i class="bx bx-loader-circle bx-spin fs-4 text-primary"></i><span>Loading conversation...</span></div>';
                    
                    fetch(`/api/learnAI/history?lesson_id=${lessonId}&chapter_id=${chapterId}`)
                        .then(r => r.text())
                        .then(html => {
                            chatHistory.innerHTML = html;
                            chatHistory.dataset.chatLoaded = 'true';
                            LearnApp._processChatHistoryHtml();
                        })
                        .catch(err => {
                            chatHistory.innerHTML = '<div class="text-center p-3 text-danger small">Failed to load chat history.</div>';
                        });
                }
            }

            const userSessionId = 'sess_' + Math.random().toString(36).substr(2, 9);
            if (window.aiSocket && typeof window.aiSocket.disconnect === 'function') {
                window.aiSocket.disconnect();
            }
            window.aiSocket = new TomSocketClient();
            const aiSocket = window.aiSocket;
            let idleTimer = null;

            const handleAIStream = (data) => {
                const aiMsgContainer = data.message_id ? document.getElementById('ai_msg_' + data.message_id) : document.querySelector('.current-ai-stream');

                if (data.type === 'tool_execution') {
                    
                    // Parse output if it's JSON string
                    let parsedOutput = data.tool_output;
                    try {
                        parsedOutput = JSON.parse(data.tool_output);
                    } catch(e) {}
                    
                    const lab = parsedOutput.name || 'ubuntu';
                    const labName = parsedOutput.name || 'Lab Environment';
                    let formattedOutput = parsedOutput;
                    if (typeof formattedOutput === 'object') {
                        formattedOutput = JSON.stringify(formattedOutput, null, 2);
                    }
                    
                    let labIcon = 'bx bxl-tux';
                    const lowerLab = lab.toLowerCase();
                    if (lowerLab.includes('ubuntu')) labIcon = 'bx bxl-ubuntu';
                    else if (lowerLab.includes('node')) labIcon = 'bx bxl-nodejs';
                    else if (lowerLab.includes('python')) labIcon = 'bx bxl-python';
                    else if (lowerLab.includes('n8n')) labIcon = 'bx bxs-network-chart';

                    // Inject or update tool badge ABOVE the current AI message
                    if (aiMsgContainer) {
                        const contentWrapper = aiMsgContainer.querySelector('.msg-content-wrapper');
                        let existingWrapper = aiMsgContainer.querySelector('.tool-badge-wrapper');
                        
                        const popoverContent = `
                            <div class="popover-header" style="background:transparent; margin-bottom:0; border-bottom:1px solid #1e293b; padding:0 0 8px 0; margin-top: 8px;">
                                <div style="display:flex; align-items:center; gap:8px; margin-bottom:4px;">
                                    <i class='bx bxs-check-circle' style="color:#22c55e;"></i>
                                    <span style="font-weight:700; color:#fff; font-size:0.95rem;">${data.tool_name || 'Execute'}</span>
                                </div>
                                <div style="display:flex; align-items:center; gap:6px; padding-left:2px;">
                                    <i class='${labIcon}' style="color:#f97316; font-size:0.9rem;"></i>
                                    <span style="color:#94a3b8; font-size:0.8rem; font-weight:500;">Run in ${labName}</span>
                                </div>
                            </div>
                            <div class="popover-body" style="padding:12px 0 0 0; background:transparent;">
                                <div class="popover-row">
                                    <span class="pop-label" style="color:#818cf8; font-weight:600; display:block; margin-bottom:4px; font-size:0.85rem;">Output:</span>
                                    <div class="pop-output" style="color:#2dd4bf; white-space:pre-wrap; font-family:monospace; font-size:0.85rem; padding-left:8px; border-left:2px solid #334155;">${formattedOutput}</div>
                                </div>
                            </div>
                        `;

                        if (existingWrapper) {
                            // Update existing badge
                            const btn = existingWrapper.querySelector('.agent-activity-btn');
                            let currentContent = btn.getAttribute('data-coreui-content') || '';
                            btn.setAttribute('data-coreui-content', currentContent + popoverContent);
                            
                            // Update text to "X tools"
                            let currentText = btn.innerText.trim();
                            let count = 1;
                            let match = currentText.match(/(\d+)\s+tool/);
                            if (match) count = parseInt(match[1]);
                            count++;
                            btn.innerHTML = `
                                <svg class="icon" style="width:14px; height:14px; fill:currentColor;">
                                    <use xlink:href="/assets/icons/free.svg#cil-settings"></use>
                                </svg>
                                ${count} tools
                            `;
                            
                            // Re-init popover to pick up new content
                            if (typeof coreui !== 'undefined' && coreui.Popover) {
                                const popInstance = coreui.Popover.getInstance(btn);
                                if (popInstance) popInstance.dispose();
                                new coreui.Popover(btn, { container: 'body', html: true, trigger: 'focus', placement: 'bottom', customClass: 'custom-tool-popover' });
                            }
                        } else {
                            // Create new badge
                            const toolBadgeDiv = document.createElement('div');
                            toolBadgeDiv.className = 'tool-badge-wrapper mb-1';
                            toolBadgeDiv.innerHTML = `
                                <div class="agent-activity-btn-wrapper d-flex mb-1" style="position:relative;">
                                    <button class="agent-activity-btn btn btn-sm" tabindex="0" data-coreui-toggle="popover" data-coreui-placement="bottom" data-coreui-html="true" data-coreui-custom-class="simple-blur" style="background:transparent; border:1px solid #334155; border-radius:6px; padding:4px 10px; color:#94a3b8; font-size:0.85rem; display:flex; align-items:center; gap:6px; cursor:pointer;">
                                        <svg class="icon" style="width:14px; height:14px; fill:currentColor;">
                                            <use xlink:href="/assets/icons/free.svg#cil-settings"></use>
                                        </svg>
                                        1 tool
                                    </button>
                                </div>
                            `;
                            const btn = toolBadgeDiv.querySelector('.agent-activity-btn');
                            btn.setAttribute('data-coreui-content', popoverContent);
                            
                            if (typeof coreui !== 'undefined' && coreui.Popover) {
                                new coreui.Popover(btn, { container: 'body', html: true, trigger: 'focus', placement: 'bottom', customClass: 'custom-tool-popover' });
                            }
                            
                            if (contentWrapper) {
                                contentWrapper.insertBefore(toolBadgeDiv, contentWrapper.firstChild);
                            } else {
                                aiMsgContainer.insertBefore(toolBadgeDiv, aiMsgContainer.firstChild);
                            }
                        }
                        chatHistory.scrollTop = chatHistory.scrollHeight;
                    }
                    return;
                }

                if (!aiMsgContainer) return;
                const p = aiMsgContainer.querySelector('p');

                if (data.type === 'stream_end') {
                    LearnApp.setChatSendState('idle');
                    const dots = aiMsgContainer.querySelector('.typing-dots');
                    if (dots) {
                        dots.remove();
                        const bubble = aiMsgContainer.querySelector('.msg-bubble');
                        if (bubble) bubble.style.display = 'block';
                    }

                    if (p && p.dataset.rawMd) {
                        LearnApp.renderMarkdownWithHighlighting(p.dataset.rawMd, p);
                    }
                    aiMsgContainer.classList.remove('current-ai-stream');

                    const scrollBtn = document.getElementById('aiChatScrollToBottom');
                    if (scrollBtn && !scrollBtn.classList.contains('d-none')) {
                        scrollBtn.innerHTML = '<i class="bx bx-down-arrow-alt fs-4"></i>';
                    }

                    if (!LearnApp._userScrolledUp) {
                        chatHistory.scrollTop = chatHistory.scrollHeight;
                        if (scrollBtn) {
                            scrollBtn.classList.remove('d-flex');
                            scrollBtn.classList.add('d-none');
                        }
                    }

                    // Update token stats bar with usage data
                    if (data.usage) {
                        LearnApp.updateStatsBar(data.usage);
                    }
                    return;
                }

                if (data.type === 'text_delta') {
                    const dots = aiMsgContainer.querySelector('.typing-dots');
                    if (dots) {
                        dots.remove();
                        const bubble = aiMsgContainer.querySelector('.msg-bubble');
                        if (bubble) bubble.style.display = 'block';
                    }

                    if (p.querySelector('.typing-dots')) {
                        p.innerHTML = '';
                    }

                    p.dataset.rawMd = (p.dataset.rawMd || '') + data.data;
                    LearnApp.renderMarkdownWithHighlighting(p.dataset.rawMd, p);

                    const scrollBtn = document.getElementById('aiChatScrollToBottom');
                    if (!LearnApp._userScrolledUp) {
                        chatHistory.scrollTop = chatHistory.scrollHeight;
                    } else if (scrollBtn) {
                        scrollBtn.classList.remove('d-none');
                        scrollBtn.classList.add('d-flex');
                        scrollBtn.innerHTML = '<i class="bx bx-loader-circle bx-spin fs-4"></i>';
                    }
                }
            };

            const ensureSocketConnection = (onReady = null) => {
                if (aiSocket.isActive()) {
                    if (typeof onReady === 'function') onReady();
                } else {
                    aiSocket.connect(`ai_stream.${userSessionId}`, handleAIStream, null, onReady);
                }
            };

            const resetIdleTimer = () => {
                ensureSocketConnection();
                clearTimeout(idleTimer);
                idleTimer = setTimeout(() => {
                    console.log('AI Chat idle for 60s... dropping persistent socket to save resources.');
                    aiSocket.disconnect();
                }, 60000); // 60 seconds
            };

            // Setup a monitor for the active stream in case connection closes abruptly
            setInterval(() => {
                const activeStream = document.querySelector('.current-ai-stream');
                if (activeStream && window.aiSocket) {
                    if (!window.aiSocket.isActive()) {
                        if (window.aiSocket.isConnecting) return;
                        ensureSocketConnection();
                    }
                }
            }, 2000);

            // Connect instantly on load
            resetIdleTimer();

            // Re-establish/refresh connection idleness on typing
            chatInput.addEventListener('input', resetIdleTimer);

            chatSend.addEventListener('click', () => {
                if (LearnApp._aiGenerating === true) {
                    if (window.aiSocket && typeof window.aiSocket.disconnect === 'function') {
                        window.aiSocket.disconnect();
                    }
                    LearnApp.setChatSendState('idle');
                    const activeStream = document.querySelector('.current-ai-stream');
                    if (activeStream) {
                        const dots = activeStream.querySelector('.typing-dots');
                        if (dots) dots.remove();
                        const bubble = activeStream.querySelector('.msg-bubble');
                        if (bubble) bubble.style.display = 'block';
                        const p = activeStream.querySelector('p');
                        if (p && p.dataset.rawMd) {
                            LearnApp.renderMarkdownWithHighlighting(p.dataset.rawMd, p);
                        } else if (p && !p.innerHTML) {
                            p.innerHTML = '<span class="text-secondary small fst-italic">Generation stopped by user.</span>';
                        }
                        activeStream.classList.remove('current-ai-stream');
                    }
                    return;
                }

                const query = chatInput.value.trim();
                const currentLessonId = document.getElementById('currentLessonId')?.value || '';
                const currentChapterId = document.getElementById('currentChapterId')?.value || '';
                if (!query) return;

                LearnApp._userScrolledUp = false;
                const scrollBtn = document.getElementById('aiChatScrollToBottom');
                if (scrollBtn) {
                    scrollBtn.classList.remove('d-flex');
                    scrollBtn.classList.add('d-none');
                }

                const modelSelect = document.getElementById('aiModelSelect');
                const aiModel = modelSelect ? modelSelect.value : 'gemini';

                const messageId = 'msg_' + Math.random().toString(36).substr(2, 9);
                chatInput.value = '';

                // Add user message
                this.appendChatMessage('User', query, 'user-row ms-auto');

                // Prepare AI message placeholder
                const aiMsgId = 'ai_msg_' + messageId;
                this.appendChatMessage('SathishBot', '[LOADING]', 'ai-row current-ai-stream', aiMsgId);
                LearnApp.setChatSendState('stop');

                const sendAskRequest = () => {
                    fetch('/api/learnAI/ask', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        credentials: 'include',
                        body: JSON.stringify({ query, lesson_id: currentLessonId, chapter_id: currentChapterId, message_id: messageId, session_id: userSessionId, ai_model: aiModel })
                    })
                        .then(res => res.json())
                        .catch(err => {
                            console.error('AI Request Failed:', err);
                            LearnApp.setChatSendState('idle');
                            const aiMsgContainer = document.getElementById(aiMsgId);
                            if (aiMsgContainer) {
                                const p = aiMsgContainer.querySelector('p');
                                if (p) p.innerText = 'Sorry, something went wrong.';
                            }
                        });
                };

                ensureSocketConnection(() => {
                    sendAskRequest();
                });
            });

            chatInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') chatSend.click();
            });
        },

        appendChatMessage: function (sender, text, type, id = null) {
            const chatHistory = document.getElementById('aiChatHistory');
            if (!chatHistory) return;

            const userAvatar = document.getElementById('userAvatarUrl')?.value || '';
            const userAvatarStyle = document.getElementById('userAvatarStyle')?.value || '';
            const aiAvatar = document.getElementById('aiAvatarUrl')?.value || '/assets/logo/logo.png';

            const msgDiv = document.createElement('div');
            msgDiv.className = `message-row ${type}`;
            if (id) msgDiv.id = id;

            if (type.includes('ai-row')) {
                const isLoader = text === '[LOADING]';
                const loaderHtml = isLoader ? `
                    <div class="typing-dots d-flex align-items-center gap-2 mb-1 p-1 text-primary small">
                        <i class="bx bx-loader-circle bx-spin fs-5"></i>
                        <span class="text-secondary fw-medium">AI is thinking...</span>
                    </div>
                ` : '';
                const bubbleDisplay = isLoader ? 'display: none;' : '';
                const pText = isLoader ? '' : text;

                msgDiv.innerHTML = `
                    <div class="msg-avatar">
                        <img src="${aiAvatar}" style="width: 30px;" alt="AI">
                    </div>
                    <div class="msg-content-wrapper d-flex flex-column" style="max-width:85%; width:100%;">
                        ${loaderHtml}
                        <div class="msg-bubble w-100 ai-transparent-bubble" style="background:transparent !important; border:none !important; box-shadow:none !important; padding:0 !important; ${bubbleDisplay}">
                            <p class="m-0">${pText}</p>
                        </div>
                    </div>
                `;
            } else {
                msgDiv.innerHTML = `
                    <div class="msg-bubble">
                        <p class="m-0">${text}</p>
                    </div>
                    <!-- <div class="msg-avatar">
                        <img src="${userAvatar}" style="width: 30px; ${userAvatarStyle}" alt="User">
                    </div> -->
                `;
            }

            chatHistory.appendChild(msgDiv);
            chatHistory.scrollTop = chatHistory.scrollHeight;
        },

        initStudyHighlighter: function () {
            if (this._highlighterInitialized) return;
            this._highlighterInitialized = true;

            const self = this;
            let popover = document.getElementById('studyHighlightPopover');
            if (!popover) {
                popover = document.createElement('div');
                popover.id = 'studyHighlightPopover';
                popover.style.display = 'none';
                popover.innerHTML = `
                    <div class="hl-popover-group">
                        <div class="hl-popover-row">
                            <span class="hl-mode-badge" title="Pencil Circle around word"><i class="bx bx-shape-circle"></i> <span>Circle</span></span>
                            <button type="button" class="hl-color-btn hl-circle hl-yellow" data-color="yellow" data-style="circle" title="Yellow Pencil Circle"></button>
                            <button type="button" class="hl-color-btn hl-circle hl-orange" data-color="orange" data-style="circle" title="Orange Pencil Circle"></button>
                            <button type="button" class="hl-color-btn hl-circle hl-green" data-color="green" data-style="circle" title="Green Pencil Circle"></button>
                            <button type="button" class="hl-color-btn hl-circle hl-pink" data-color="pink" data-style="circle" title="Pink Pencil Circle"></button>
                            <button type="button" class="hl-color-btn hl-circle hl-blue" data-color="blue" data-style="circle" title="Blue Pencil Circle"></button>
                        </div>
                        <div class="hl-popover-row">
                            <span class="hl-mode-badge" title="Marker Brush behind word"><i class="bx bx-highlight"></i> <span>Highlight</span></span>
                            <button type="button" class="hl-color-btn hl-brush hl-yellow" data-color="yellow" data-style="brush" title="Yellow Marker Brush"></button>
                            <button type="button" class="hl-color-btn hl-brush hl-orange" data-color="orange" data-style="brush" title="Orange Marker Brush"></button>
                            <button type="button" class="hl-color-btn hl-brush hl-green" data-color="green" data-style="brush" title="Green Marker Brush"></button>
                            <button type="button" class="hl-color-btn hl-brush hl-pink" data-color="pink" data-style="brush" title="Pink Marker Brush"></button>
                            <button type="button" class="hl-color-btn hl-brush hl-blue" data-color="blue" data-style="brush" title="Blue Marker Brush"></button>
                        </div>
                    </div>
                    <div class="hl-vertical-divider"></div>
                    <button type="button" class="hl-clear-btn" title="Remove Highlight"><i class="bx bx-trash"></i></button>
                `;
                document.body.appendChild(popover);
            }

            let penToolbar = document.getElementById('studyPenToolbar');
            if (!penToolbar) {
                penToolbar = document.createElement('div');
                penToolbar.id = 'studyPenToolbar';
                penToolbar.className = 'study-pen-toolbar';
                penToolbar.innerHTML = `
                    <div class="pen-speed-dial-menu">
                        <div class="pen-tools-group">
                            <button type="button" class="pen-tool-btn active" data-tool="cursor" title="Select / Read Mode (Normal text selection)"><i class="bx bx-pointer"></i></button>
                            <button type="button" class="pen-tool-btn" data-tool="pencil" title="Freehand Pencil Circle Tool (Draw & circle anywhere)"><i class="bx bx-pencil"></i></button>
                            <button type="button" class="pen-tool-btn" data-tool="eraser" title="Eraser Tool (Click/drag to erase drawn circle)"><i class="bx bx-eraser"></i></button>
                            <button type="button" class="pen-tool-btn pen-clear-btn" title="Clear all freehand drawings on this chapter"><i class="bx bx-trash"></i></button>
                        </div>
                        <div class="pen-color-picker" style="display: none;">
                            <button type="button" class="pen-color-btn active" data-color="yellow" title="Yellow Pencil"></button>
                            <button type="button" class="pen-color-btn" data-color="orange" title="Orange Pencil"></button>
                            <button type="button" class="pen-color-btn" data-color="green" title="Green Pencil"></button>
                            <button type="button" class="pen-color-btn" data-color="pink" title="Pink Pencil"></button>
                            <button type="button" class="pen-color-btn" data-color="blue" title="Blue Pencil"></button>
                        </div>
                    </div>
                    <button type="button" class="pen-menu-toggle shadow-lg" title="Study Pen Tools">
                        <i class="bx bx-pencil pen-icon-main fs-4"></i>
                        <i class="bx bx-x pen-icon-close fs-3 d-none"></i>
                    </button>
                `;
                const panel2 = document.getElementById('learn-panel-2') || document.body;
                panel2.appendChild(penToolbar);

                this._activePenTool = 'cursor';
                this._activePenColor = 'yellow';

                penToolbar.addEventListener('click', (e) => {
                    if (penToolbar.classList.contains('is-disabled')) {
                        const toggleBtn = e.target.closest('.pen-menu-toggle');
                        if (toggleBtn) {
                            toggleBtn.setAttribute('title', 'Freehand drawing is disabled right now');
                        }
                        return;
                    }

                    const toggleBtn = e.target.closest('.pen-menu-toggle');
                    const toolBtn = e.target.closest('.pen-tool-btn[data-tool]');
                    const colorBtn = e.target.closest('.pen-color-btn[data-color]');
                    const clearBtn = e.target.closest('.pen-clear-btn');

                    const closePenMenu = () => {
                        penToolbar.classList.remove('is-open');
                        const mainIcon = penToolbar.querySelector('.pen-icon-main');
                        const closeIcon = penToolbar.querySelector('.pen-icon-close');
                        if (mainIcon && closeIcon) {
                            mainIcon.classList.remove('d-none');
                            closeIcon.classList.add('d-none');
                        }
                    };

                    if (toggleBtn) {
                        const isOpen = penToolbar.classList.toggle('is-open');
                        const mainIcon = penToolbar.querySelector('.pen-icon-main');
                        const closeIcon = penToolbar.querySelector('.pen-icon-close');
                        if (mainIcon && closeIcon) {
                            if (isOpen) {
                                mainIcon.classList.add('d-none');
                                closeIcon.classList.remove('d-none');
                            } else {
                                mainIcon.classList.remove('d-none');
                                closeIcon.classList.add('d-none');
                            }
                        }
                    } else if (toolBtn) {
                        const tool = toolBtn.getAttribute('data-tool');
                        const isAlreadyActive = self._activePenTool === tool;

                        self._activePenTool = tool;
                        penToolbar.querySelectorAll('.pen-tool-btn[data-tool]').forEach(b => b.classList.remove('active'));
                        toolBtn.classList.add('active');
                        const colorPicker = penToolbar.querySelector('.pen-color-picker');
                        if (tool === 'pencil') {
                            colorPicker.style.display = 'flex';
                        } else {
                            colorPicker.style.display = 'none';
                        }
                        self._updateDrawingLayerState();

                        if (isAlreadyActive || tool === 'cursor') {
                            closePenMenu();
                        }
                    } else if (colorBtn) {
                        const color = colorBtn.getAttribute('data-color');
                        self._activePenColor = color;
                        penToolbar.querySelectorAll('.pen-color-btn').forEach(b => b.classList.remove('active'));
                        colorBtn.classList.add('active');
                        closePenMenu();
                    } else if (clearBtn) {
                        const container = document.getElementById('chapterContentContainer') || document.querySelector('.learn-chapter-content');
                        if (container) {
                            const svg = container.querySelector('svg.study-drawing-layer');
                            if (svg) svg.innerHTML = '';
                            const chId = document.getElementById('currentChapterId')?.value || document.querySelector('.learn-accordion-btn.active')?.getAttribute('data-chapter-id');
                            if (chId) self.saveStudyDrawings(chId);
                        }
                        closePenMenu();
                    }
                });

                document.addEventListener('click', (e) => {
                    const penToolbar = document.getElementById('studyPenToolbar');
                    if (penToolbar && penToolbar.classList.contains('is-open') && !e.target.closest('#studyPenToolbar')) {
                        penToolbar.classList.remove('is-open');
                        const mainIcon = penToolbar.querySelector('.pen-icon-main');
                        const closeIcon = penToolbar.querySelector('.pen-icon-close');
                        if (mainIcon && closeIcon) {
                            mainIcon.classList.remove('d-none');
                            closeIcon.classList.add('d-none');
                        }
                    }
                });
            }
            this._updatePenToolbarVisibility();

            let activeSelectionRange = null;
            let activeHighlightMark = null;

            const hidePopover = () => {
                popover.style.display = 'none';
                activeSelectionRange = null;
                activeHighlightMark = null;
            };

            // Hide when clicking outside
            document.addEventListener('mousedown', (e) => {
                if (popover.contains(e.target)) return;
                const container = document.getElementById('chapterContentContainer');
                if (!container || !container.contains(e.target)) {
                    hidePopover();
                }
            });

            // Handle Text Selection in chapterContentContainer
            document.addEventListener('mouseup', (e) => {
                if (popover.contains(e.target)) return;
                const container = document.getElementById('chapterContentContainer');
                if (!container || !container.contains(e.target)) return;

                // Check if clicked an existing highlight mark directly
                const clickedMark = e.target.closest('mark.study-highlight');
                if (clickedMark) {
                    activeHighlightMark = clickedMark;
                    activeSelectionRange = null;
                    const rect = clickedMark.getBoundingClientRect();
                    showPopoverAtRect(rect);
                    return;
                }

                const sel = window.getSelection();
                if (!sel || sel.isCollapsed || sel.rangeCount === 0) {
                    if (!popover.contains(e.target)) hidePopover();
                    return;
                }

                const range = sel.getRangeAt(0);
                const text = range.toString().trim();
                if (text.length < 2) {
                    hidePopover();
                    return;
                }

                let ancestor = range.commonAncestorContainer;
                if (ancestor.nodeType === 3) ancestor = ancestor.parentNode;
                if (!container.contains(ancestor) || ancestor.closest('pre') || ancestor.closest('button')) {
                    hidePopover();
                    return;
                }

                activeSelectionRange = range.cloneRange();
                activeHighlightMark = null;
                const rect = range.getBoundingClientRect();
                showPopoverAtRect(rect);
            });

            const showPopoverAtRect = (rect) => {
                const scrollX = window.scrollX || window.pageXOffset;
                const scrollY = window.scrollY || window.pageYOffset;
                popover.style.display = 'flex';
                popover.style.opacity = '0';
                
                setTimeout(() => {
                    const popWidth = popover.offsetWidth || 160;
                    const popHeight = popover.offsetHeight || 38;
                    let top = rect.top + scrollY - popHeight - 8;
                    let left = rect.left + scrollX + (rect.width / 2) - (popWidth / 2);

                    if (top < scrollY + 10) top = rect.bottom + scrollY + 8;
                    if (left < scrollX + 10) left = scrollX + 10;
                    if (left + popWidth > window.innerWidth + scrollX - 10) left = window.innerWidth + scrollX - popWidth - 10;

                    popover.style.top = top + 'px';
                    popover.style.left = left + 'px';
                    popover.style.opacity = '1';
                }, 10);
            };

            popover.addEventListener('click', (e) => {
                const colorBtn = e.target.closest('.hl-color-btn');
                const clearBtn = e.target.closest('.hl-clear-btn');
                const chapterId = document.getElementById('currentChapterId')?.value || document.querySelector('.learn-accordion-btn.active')?.getAttribute('data-chapter-id');
                if (!chapterId) return;

                if (colorBtn) {
                    const color = colorBtn.getAttribute('data-color') || 'yellow';
                    const styleType = colorBtn.getAttribute('data-style') || 'brush';
                    if (activeHighlightMark) {
                        activeHighlightMark.className = `study-highlight hl-${styleType} hl-${color}`;
                        activeHighlightMark.setAttribute('data-color', color);
                        activeHighlightMark.setAttribute('data-style', styleType);
                    } else if (activeSelectionRange) {
                        const sel = window.getSelection();
                        sel.removeAllRanges();
                        sel.addRange(activeSelectionRange);

                        try {
                            const mark = document.createElement('mark');
                            mark.className = `study-highlight hl-${styleType} hl-${color}`;
                            mark.setAttribute('data-color', color);
                            mark.setAttribute('data-style', styleType);
                            mark.setAttribute('data-text', activeSelectionRange.toString().trim());
                            mark.setAttribute('data-id', 'hl_' + Math.random().toString(36).substr(2, 9));
                            
                            activeSelectionRange.surroundContents(mark);
                        } catch (err) {
                            const text = activeSelectionRange.toString();
                            const span = document.createElement('mark');
                            span.className = `study-highlight hl-${styleType} hl-${color}`;
                            span.setAttribute('data-color', color);
                            span.setAttribute('data-style', styleType);
                            span.setAttribute('data-text', text.trim());
                            span.setAttribute('data-id', 'hl_' + Math.random().toString(36).substr(2, 9));
                            span.appendChild(activeSelectionRange.extractContents());
                            activeSelectionRange.insertNode(span);
                        }
                        sel.removeAllRanges();
                    }
                    hidePopover();
                    self.saveStudyHighlights(chapterId);
                } else if (clearBtn) {
                    if (activeHighlightMark) {
                        const parent = activeHighlightMark.parentNode;
                        while (activeHighlightMark.firstChild) {
                            parent.insertBefore(activeHighlightMark.firstChild, activeHighlightMark);
                        }
                        activeHighlightMark.remove();
                        parent.normalize();
                    } else if (activeSelectionRange) {
                        const sel = window.getSelection();
                        sel.removeAllRanges();
                    }
                    hidePopover();
                    self.saveStudyHighlights(chapterId);
                }
            });
        },

        saveStudyHighlights: function (chapterId) {
            if (!chapterId) return;
            const container = document.getElementById('chapterContentContainer');
            if (!container) return;

            const highlights = [];
            container.querySelectorAll('mark.study-highlight').forEach((mark) => {
                const text = mark.innerText || mark.textContent || mark.getAttribute('data-text');
                const color = mark.getAttribute('data-color') || 'yellow';
                const styleType = mark.getAttribute('data-style') || (mark.classList.contains('hl-circle') ? 'circle' : 'brush');
                const id = mark.getAttribute('data-id') || ('hl_' + Math.random().toString(36).substr(2, 9));
                if (text && text.trim()) {
                    highlights.push({ id: id, color: color, style: styleType, text: text.trim() });
                }
            });

            fetch('/api/learnAI/highlights', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    chapter_id: chapterId,
                    highlights: highlights,
                    drawings: (this._cachedDrawings && this._cachedDrawings[chapterId]) || []
                })
            })
            .then(r => r.json())
            .then(res => console.log('Saved highlights for chapter', chapterId, res))
            .catch(err => console.error('Failed to save highlights:', err));
        },

        loadStudyHighlights: function (container, chapterId, forceApplyFromCache) {
            if (!container || !chapterId) return;
            const targetContainer = container.id === 'chapterContentContainer' || container.classList.contains('learn-chapter-content') || container.classList.contains('chapter-text-content') ? container : (document.getElementById('chapterContentContainer') || document.querySelector('.learn-chapter-content'));
            if (!targetContainer) return;
            const self = this;
            this._updatePenToolbarVisibility();

            this._cachedHighlights = this._cachedHighlights || {};
            this._cachedDrawings = this._cachedDrawings || {};

            if (forceApplyFromCache && (this._cachedHighlights[chapterId] || this._cachedDrawings[chapterId])) {
                if (this._cachedHighlights[chapterId]) self._applyStudyHighlights(targetContainer, this._cachedHighlights[chapterId]);
                if (this._cachedDrawings[chapterId]) self._applyStudyDrawings(targetContainer, this._cachedDrawings[chapterId]);
                return;
            }

            // Prevent duplicate parallel requests for the same chapter_id within 1.5s
            if (this._loadingHighlightsChapter === chapterId && (Date.now() - (this._loadingHighlightsTime || 0) < 1500)) {
                return;
            }
            this._loadingHighlightsChapter = chapterId;
            this._loadingHighlightsTime = Date.now();

            fetch('/api/learnAI/highlights?chapter_id=' + encodeURIComponent(chapterId), {
                credentials: 'include'
            })
            .then(r => r.json())
            .then(res => {
                if (res && res.status === 'success' && res.chapter_id === chapterId) {
                    if (Array.isArray(res.highlights)) {
                        self._cachedHighlights[chapterId] = res.highlights;
                        self._applyStudyHighlights(targetContainer, res.highlights);
                    }
                    if (Array.isArray(res.drawings)) {
                        self._cachedDrawings[chapterId] = res.drawings;
                        self._applyStudyDrawings(targetContainer, res.drawings);
                    } else {
                        self._applyStudyDrawings(targetContainer, []);
                    }
                }
                self._updatePenToolbarVisibility();
            })
            .catch(err => {
                this._loadingHighlightsChapter = null;
                console.error('Failed to load highlights:', err);
                self._updatePenToolbarVisibility();
            });
        },

        _applyStudyHighlights: function (container, highlights) {
            const targetContainer = container && (container.id === 'chapterContentContainer' || container.classList.contains('learn-chapter-content') || container.classList.contains('chapter-text-content')) ? container : (document.getElementById('chapterContentContainer') || document.querySelector('.learn-chapter-content'));
            if (!targetContainer || !highlights || !highlights.length) return;

            const sorted = [...highlights].sort((a, b) => (b.text || '').length - (a.text || '').length);
            const walker = document.createTreeWalker(targetContainer, NodeFilter.SHOW_TEXT, null, false);
            const textNodes = [];
            let node;
            while (node = walker.nextNode()) {
                if (!node.nodeValue || !node.nodeValue.trim()) continue;
                const parent = node.parentNode;
                if (parent && (parent.closest('mark.study-highlight') || parent.closest('pre') || parent.closest('button') || parent.closest('script') || parent.closest('style'))) {
                    continue;
                }
                textNodes.push(node);
            }

            sorted.forEach(hl => {
                if (!hl || !hl.text) return;
                const targetText = hl.text.trim();
                if (!targetText) return;

                for (let i = 0; i < textNodes.length; i++) {
                    const tNode = textNodes[i];
                    if (!tNode || !tNode.parentNode) continue;
                    const val = tNode.nodeValue;
                    const idx = val.indexOf(targetText);
                    if (idx !== -1) {
                        const before = val.substring(0, idx);
                        const match = val.substring(idx, idx + targetText.length);
                        const after = val.substring(idx + targetText.length);

                        const frag = document.createDocumentFragment();
                        if (before) frag.appendChild(document.createTextNode(before));

                        const mark = document.createElement('mark');
                        const styleType = hl.style || 'brush';
                        mark.className = `study-highlight hl-${styleType} hl-${hl.color || 'yellow'}`;
                        mark.setAttribute('data-style', styleType);
                        mark.setAttribute('data-color', hl.color || 'yellow');
                        mark.setAttribute('data-id', hl.id || ('hl_' + Math.random().toString(36).substr(2, 9)));
                        mark.setAttribute('data-text', match);
                        mark.textContent = match;
                        frag.appendChild(mark);

                        let afterNode = null;
                        if (after) {
                            afterNode = document.createTextNode(after);
                            frag.appendChild(afterNode);
                        }

                        tNode.parentNode.replaceChild(frag, tNode);
                        textNodes[i] = afterNode;
                        break;
                    }
                }
            });
        },

        _updatePenToolbarVisibility: function () {
            const container = document.getElementById('chapterContentContainer') || document.querySelector('.learn-chapter-content');
            const penToolbar = document.getElementById('studyPenToolbar');
            if (!penToolbar) return;

            const panel2 = document.getElementById('learn-panel-2');
            if (panel2 && penToolbar.parentNode !== panel2) {
                panel2.appendChild(penToolbar);
            }

            penToolbar.style.display = 'flex';
            const isEnabled = container && container.getAttribute('data-enable-pen-toolbar') === 'true';
            if (isEnabled) {
                penToolbar.classList.remove('is-disabled');
            } else {
                penToolbar.classList.add('is-disabled');
                if (penToolbar.classList.contains('is-open')) {
                    penToolbar.classList.remove('is-open');
                    const mainIcon = penToolbar.querySelector('.pen-icon-main');
                    const closeIcon = penToolbar.querySelector('.pen-icon-close');
                    if (mainIcon && closeIcon) {
                        mainIcon.classList.remove('d-none');
                        closeIcon.classList.add('d-none');
                    }
                }
                if (this._activePenTool !== 'cursor') {
                    this._activePenTool = 'cursor';
                    this._updateDrawingLayerState();
                }
            }
        },

        _updateDrawingLayerState: function () {
            const container = document.getElementById('chapterContentContainer') || document.querySelector('.learn-chapter-content');
            if (!container) return;
            let svg = container.querySelector('svg.study-drawing-layer');
            if (!svg) {
                svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.id = 'studyDrawingLayer';
                container.appendChild(svg);
                this._initDrawingLayerEvents(container, svg);
            }
            const isEnabled = container.getAttribute('data-enable-pen-toolbar') === 'true';
            let modeClass = '';
            if (isEnabled && this._activePenTool === 'pencil') modeClass = ' mode-pencil';
            else if (isEnabled && this._activePenTool === 'eraser') modeClass = ' mode-eraser';
            svg.setAttribute('class', 'study-drawing-layer' + modeClass);
            this._syncDrawingLayerSize(container, svg);
        },

        _anchorPathToBlock: function (container, path) {
            if (!container || !path) return;
            const d = path.getAttribute('data-orig-d') || path.getAttribute('d');
            if (!d) return;
            if (!path.getAttribute('data-orig-d')) path.setAttribute('data-orig-d', d);

            // Compute bounding box and extract raw points of the drawn path
            let minX = Infinity, maxX = -Infinity, minY = Infinity, maxY = -Infinity;
            const rawPts = [];
            const matches = d.matchAll(/[ML]\s*([0-9.-]+)\s+([0-9.-]+)/g);
            for (const m of matches) {
                const x = parseFloat(m[1]), y = parseFloat(m[2]);
                rawPts.push({ x, y });
                if (x < minX) minX = x; if (x > maxX) maxX = x;
                if (y < minY) minY = y; if (y > maxY) maxY = y;
            }
            if (!rawPts.length) return;
            const y0 = (minY + maxY) / 2;
            const cRect = container.getBoundingClientRect();

            // 1. Check if the path encloses or overlaps specific text words inside container
            let foundBlockIdx = -1;
            let matchedWords = [];
            const children = container.children;

            for (let i = 0; i < children.length; i++) {
                const child = children[i];
                if (!child || child.tagName.toLowerCase() === 'svg' || child.tagName.toLowerCase() === 'script' || child.tagName.toLowerCase() === 'style' || child.id === 'studyDrawingLayer') continue;
                if (child.offsetTop <= maxY + 10 && (child.offsetTop + (child.offsetHeight || 0)) >= minY - 10) {
                    const walker = document.createTreeWalker(child, NodeFilter.SHOW_TEXT, null, false);
                    let tNode = walker.nextNode();
                    let blockWords = [];
                    while (tNode) {
                        const str = tNode.nodeValue || '';
                        const regex = /\S+/g;
                        let match;
                        while ((match = regex.exec(str)) !== null) {
                            if (match[0].length >= 1) {
                                const range = document.createRange();
                                range.setStart(tNode, match.index);
                                range.setEnd(tNode, match.index + match[0].length);
                                const rRect = range.getBoundingClientRect();
                                if (rRect.width > 0) {
                                    const relLeft = rRect.left - cRect.left;
                                    const relTop = rRect.top - cRect.top;
                                    const relRight = relLeft + rRect.width;
                                    const relBottom = relTop + rRect.height;
                                    blockWords.push({
                                        text: match[0],
                                        left: relLeft,
                                        top: relTop,
                                        right: relRight,
                                        bottom: relBottom,
                                        width: rRect.width,
                                        height: rRect.height
                                    });
                                }
                            }
                        }
                        tNode = walker.nextNode();
                    }

                    // Check which words intersect the exact drawn points along each vertical line row
                    const touchingWords = blockWords.filter(w => {
                        const rowPts = rawPts.filter(p => p.y >= w.top - 6 && p.y <= w.bottom + 6);
                        if (rowPts.length > 0) {
                            let minRowX = Infinity, maxRowX = -Infinity;
                            rowPts.forEach(p => {
                                if (p.x < minRowX) minRowX = p.x;
                                if (p.x > maxRowX) maxRowX = p.x;
                            });
                            return !(w.right < minRowX - 4 || w.left > maxRowX + 4);
                        }
                        return false;
                    });

                    if (touchingWords.length > 0) {
                        foundBlockIdx = i;
                        const rowGroups = [];
                        touchingWords.forEach(tw => {
                            let placed = false;
                            for (let g = 0; g < rowGroups.length; g++) {
                                if (Math.abs(rowGroups[g][0].top - tw.top) < 14) {
                                    rowGroups[g].push(tw);
                                    placed = true;
                                    break;
                                }
                            }
                            if (!placed) rowGroups.push([tw]);
                        });
                        matchedWords = [];
                        rowGroups.forEach(rg => {
                            const startIdx = blockWords.indexOf(rg[0]);
                            const endIdx = blockWords.indexOf(rg[rg.length - 1]);
                            if (startIdx !== -1 && endIdx !== -1) {
                                const slice = blockWords.slice(startIdx, endIdx + 1).filter(w => Math.abs(w.top - rg[0].top) < 14);
                                matchedWords.push(...slice);
                            }
                        });
                        break;
                    }
                }
            }

            if (matchedWords.length > 0 && foundBlockIdx !== -1) {
                let minWLeft = Infinity, minWTop = Infinity, maxWRight = -Infinity, maxWBottom = -Infinity;
                const textArr = [];
                matchedWords.forEach(w => {
                    textArr.push(w.text);
                    if (w.left < minWLeft) minWLeft = w.left;
                    if (w.top < minWTop) minWTop = w.top;
                    if (w.right > maxWRight) maxWRight = w.right;
                    if (w.bottom > maxWBottom) maxWBottom = w.bottom;
                });
                const wBoxWidth = Math.max(10, maxWRight - minWLeft);
                const wBoxHeight = Math.max(10, maxWBottom - minWTop);

                path.setAttribute('data-anchor-type', 'text');
                path.setAttribute('data-anchor-idx', foundBlockIdx);
                path.setAttribute('data-anchor-text', textArr.join(' '));
                path.setAttribute('data-word-left', Math.round(minWLeft * 10) / 10);
                path.setAttribute('data-word-top', Math.round(minWTop * 10) / 10);
                path.setAttribute('data-word-width', Math.round(wBoxWidth * 10) / 10);
                path.setAttribute('data-word-height', Math.round(wBoxHeight * 10) / 10);
                return;
            }

            // 2. Fallback: Block-level anchoring if no specific text words were enclosed
            let targetIdx = foundBlockIdx;
            let targetBlock = targetIdx !== -1 ? children[targetIdx] : null;
            if (targetIdx === -1 && children.length > 0) {
                for (let i = children.length - 1; i >= 0; i--) {
                    const child = children[i];
                    if (!child || child.tagName.toLowerCase() === 'svg' || child.tagName.toLowerCase() === 'script' || child.tagName.toLowerCase() === 'style' || child.id === 'studyDrawingLayer') continue;
                    if (child.offsetTop <= y0) {
                        targetIdx = i;
                        targetBlock = child;
                        break;
                    }
                }
            }
            if (targetBlock && targetIdx !== -1) {
                path.setAttribute('data-anchor-type', 'block');
                path.setAttribute('data-anchor-idx', targetIdx);
                path.setAttribute('data-anchor-top', targetBlock.offsetTop);
                path.setAttribute('data-anchor-width', targetBlock.offsetWidth || container.scrollWidth || 800);
                path.setAttribute('data-anchor-height', targetBlock.offsetHeight || 100);
            }
        },

        _syncDrawingLayerSize: function (container, svg) {
            if (!container || !svg) return;
            const w = container.scrollWidth || container.offsetWidth || 800;
            const h = container.scrollHeight || container.offsetHeight || 600;
            svg.setAttribute('width', w);
            svg.setAttribute('height', h);
            svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);

            const cRect = container.getBoundingClientRect();

            // Re-anchor and scale paths when container resizes
            svg.querySelectorAll('path.study-drawn-path').forEach(path => {
                const origD = path.getAttribute('data-orig-d');
                if (!origD) return;
                const anchorType = path.getAttribute('data-anchor-type') || 'block';
                const idxStr = path.getAttribute('data-anchor-idx');
                if (idxStr === null || idxStr === '') return;
                const idx = parseInt(idxStr);
                const block = container.children[idx];
                if (!block || block.tagName.toLowerCase() === 'svg') return;

                if (anchorType === 'text') {
                    const targetText = path.getAttribute('data-anchor-text');
                    const origLeft = parseFloat(path.getAttribute('data-word-left')) || 0;
                    const origTop = parseFloat(path.getAttribute('data-word-top')) || 0;
                    const origW = parseFloat(path.getAttribute('data-word-width')) || 100;
                    const origH = parseFloat(path.getAttribute('data-word-height')) || 30;

                    if (targetText && origW > 0 && origH > 0) {
                        const targetTokens = targetText.split(/\s+/).filter(Boolean);
                        const walker = document.createTreeWalker(block, NodeFilter.SHOW_TEXT, null, false);
                        let tNode = walker.nextNode();
                        let allBlockWords = [];

                        // Collect all word positions currently inside this block
                        while (tNode) {
                            const str = tNode.nodeValue || '';
                            const regex = /\S+/g;
                            let match;
                            while ((match = regex.exec(str)) !== null) {
                                if (match[0].length >= 1) {
                                    const range = document.createRange();
                                    range.setStart(tNode, match.index);
                                    range.setEnd(tNode, match.index + match[0].length);
                                    const rRect = range.getBoundingClientRect();
                                    if (rRect.width > 0) {
                                        allBlockWords.push({
                                            text: match[0],
                                            left: rRect.left - cRect.left,
                                            top: rRect.top - cRect.top,
                                            right: rRect.left - cRect.left + rRect.width,
                                            bottom: rRect.top - cRect.top + rRect.height
                                        });
                                    }
                                }
                            }
                            tNode = walker.nextNode();
                        }

                        let matchedBoxes = [];
                        if (targetTokens.length > 0 && allBlockWords.length >= targetTokens.length) {
                            const cleanToken = t => (t || '').replace(/^[^a-zA-Z0-9]+|[^a-zA-Z0-9]+$/g, '');
                            // Search for exact or normalized contiguous sequence inside allBlockWords
                            for (let i = 0; i <= allBlockWords.length - targetTokens.length; i++) {
                                let matchSlice = true;
                                for (let j = 0; j < targetTokens.length; j++) {
                                    const bwText = allBlockWords[i + j].text;
                                    const ttText = targetTokens[j];
                                    if (bwText !== ttText && (cleanToken(bwText) !== cleanToken(ttText) || !cleanToken(ttText))) {
                                        matchSlice = false;
                                        break;
                                    }
                                }
                                if (matchSlice) {
                                    matchedBoxes = allBlockWords.slice(i, i + targetTokens.length);
                                    break;
                                }
                            }

                            // Fallback if exact contiguous match not found due to punctuation/split
                            if (matchedBoxes.length === 0) {
                                const cleanFirst = cleanToken(targetTokens[0]);
                                const cleanLast = cleanToken(targetTokens[targetTokens.length - 1]);
                                for (let i = 0; i < allBlockWords.length; i++) {
                                    if (allBlockWords[i].text === targetTokens[0] || (cleanFirst && cleanToken(allBlockWords[i].text) === cleanFirst)) {
                                        for (let k = Math.max(i, i + targetTokens.length - 4); k <= Math.min(allBlockWords.length - 1, i + targetTokens.length + 4); k++) {
                                            if (allBlockWords[k].text === targetTokens[targetTokens.length - 1] || (cleanLast && cleanToken(allBlockWords[k].text) === cleanLast)) {
                                                matchedBoxes = allBlockWords.slice(i, k + 1);
                                                break;
                                            }
                                        }
                                        if (matchedBoxes.length > 0) break;
                                    }
                                }
                            }
                        }

                        let lineGroups = [];
                        if (matchedBoxes.length > 0) {
                            matchedBoxes.forEach(wb => {
                                let placed = false;
                                for (let g = 0; g < lineGroups.length; g++) {
                                    if (Math.abs(lineGroups[g][0].top - wb.top) < 14) {
                                        lineGroups[g].push(wb);
                                        placed = true;
                                        break;
                                    }
                                }
                                if (!placed) lineGroups.push([wb]);
                            });

                            if (lineGroups.length > 0) {
                                const subpaths = lineGroups.map(lg => {
                                    let minL = Infinity, minT = Infinity, maxR = -Infinity, maxB = -Infinity;
                                    lg.forEach(wb => {
                                        if (wb.left < minL) minL = wb.left;
                                        if (wb.top < minT) minT = wb.top;
                                        if (wb.right > maxR) maxR = wb.right;
                                        if (wb.bottom > maxB) maxB = wb.bottom;
                                    });
                                    const lgRect = {
                                        left: minL - 4,
                                        top: minT - 3,
                                        width: Math.max(10, maxR - minL) + 8,
                                        height: Math.max(10, maxB - minT) + 6
                                    };
                                    const rx = lgRect.width / 2;
                                    const ry = lgRect.height / 2;
                                    const cx = lgRect.left + rx;
                                    const cy = lgRect.top + ry;
                                    return `M ${Math.round((cx - rx)*10)/10} ${Math.round(cy*10)/10} C ${Math.round((cx - rx)*10)/10} ${Math.round((cy - ry*1.3)*10)/10} ${Math.round((cx + rx)*10)/10} ${Math.round((cy - ry*1.3)*10)/10} ${Math.round((cx + rx)*10)/10} ${Math.round(cy*10)/10} C ${Math.round((cx + rx)*10)/10} ${Math.round((cy + ry*1.3)*10)/10} ${Math.round((cx - rx)*10)/10} ${Math.round((cy + ry*1.3)*10)/10} ${Math.round((cx - rx)*10)/10} ${Math.round(cy*10)/10} Z`;
                                });
                                path.setAttribute('d', subpaths.join(' '));
                                return;
                            }
                        }
                    }
                }

                const oldTop = parseFloat(path.getAttribute('data-anchor-top')) || 0;
                const oldW = parseFloat(path.getAttribute('data-anchor-width')) || w;
                const oldH = parseFloat(path.getAttribute('data-anchor-height')) || h;
                const newTop = block.offsetTop;
                const newW = block.offsetWidth || w;
                const newH = block.offsetHeight || h;

                if (oldW > 0 && oldH > 0 && (Math.abs(oldTop - newTop) > 0.5 || Math.abs(oldW - newW) > 0.5 || Math.abs(oldH - newH) > 0.5)) {
                    const newD = origD.replace(/([ML])\s*([0-9.-]+)\s+([0-9.-]+)/g, (m, cmd, xStr, yStr) => {
                        const x = parseFloat(xStr);
                        const y = parseFloat(yStr);
                        const relX = x / oldW;
                        const newX = Math.round((relX * newW) * 10) / 10;
                        const relY = (y - oldTop) / oldH;
                        const newY = Math.round((newTop + relY * newH) * 10) / 10;
                        return `${cmd} ${newX} ${newY}`;
                    });
                    path.setAttribute('d', newD);
                }
            });

            if (!container._drawingResizeObserver) {
                container._drawingResizeObserver = new ResizeObserver(() => {
                    LearnApp._syncDrawingLayerSize(container, svg);
                });
                container._drawingResizeObserver.observe(container);
            }
        },

        _initDrawingLayerEvents: function (container, svg) {
            if (svg._drawingInitialized) return;
            svg._drawingInitialized = true;
            const self = this;
            let isDrawing = false;
            let currentPath = null;

            const getPenRgb = (colorName) => {
                switch(colorName) {
                    case 'yellow': return 'rgb(250, 204, 21)';
                    case 'orange': return 'rgb(249, 115, 22)';
                    case 'green': return 'rgb(74, 222, 128)';
                    case 'pink': return 'rgb(248, 113, 113)';
                    case 'blue': return 'rgb(96, 165, 250)';
                    default: return 'rgb(250, 204, 21)';
                }
            };

            const getRelativeCoords = (e) => {
                const rect = svg.getBoundingClientRect();
                return {
                    x: Math.round(e.clientX - rect.left),
                    y: Math.round(e.clientY - rect.top)
                };
            };

            svg.addEventListener('mousedown', (e) => {
                if (self._activePenTool === 'eraser') {
                    const hitPath = e.target.closest('path.study-drawn-path');
                    if (hitPath) {
                        hitPath.remove();
                        const chId = document.getElementById('currentChapterId')?.value || document.querySelector('.learn-accordion-btn.active')?.getAttribute('data-chapter-id');
                        if (chId) self.saveStudyDrawings(chId);
                    }
                    return;
                }
                if (self._activePenTool !== 'pencil') return;

                isDrawing = true;
                const coords = getRelativeCoords(e);
                currentPath = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                currentPath.setAttribute('class', 'study-drawn-path');
                currentPath.setAttribute('data-id', 'dr_' + Math.random().toString(36).substr(2, 9));
                const color = self._activePenColor || 'yellow';
                currentPath.setAttribute('data-color', color);
                currentPath.setAttribute('stroke', getPenRgb(color));
                currentPath.setAttribute('stroke-width', '3.5');
                currentPath.setAttribute('fill', 'none');
                currentPath.setAttribute('stroke-linecap', 'round');
                currentPath.setAttribute('stroke-linejoin', 'round');
                currentPath.setAttribute('d', `M ${coords.x} ${coords.y}`);
                svg.appendChild(currentPath);
            });

            svg.addEventListener('mousemove', (e) => {
                if (self._activePenTool === 'eraser' && e.buttons === 1) {
                    const hitPath = e.target.closest('path.study-drawn-path');
                    if (hitPath) {
                        hitPath.remove();
                        const chId = document.getElementById('currentChapterId')?.value || document.querySelector('.learn-accordion-btn.active')?.getAttribute('data-chapter-id');
                        if (chId) self.saveStudyDrawings(chId);
                    }
                    return;
                }
                if (!isDrawing || !currentPath || self._activePenTool !== 'pencil') return;
                const coords = getRelativeCoords(e);
                const d = currentPath.getAttribute('d');
                currentPath.setAttribute('d', d + ` L ${coords.x} ${coords.y}`);
            });

            const stopDrawing = () => {
                if (isDrawing && currentPath && self._activePenTool === 'pencil') {
                    self._anchorPathToBlock(container, currentPath);
                    isDrawing = false;
                    currentPath = null;
                    const chId = document.getElementById('currentChapterId')?.value || document.querySelector('.learn-accordion-btn.active')?.getAttribute('data-chapter-id');
                    if (chId) self.saveStudyDrawings(chId);
                }
                isDrawing = false;
                currentPath = null;
            };

            svg.addEventListener('mouseup', stopDrawing);
            svg.addEventListener('mouseleave', stopDrawing);
        },

        saveStudyDrawings: function (chapterId) {
            if (!chapterId) return;
            const container = document.getElementById('chapterContentContainer') || document.querySelector('.learn-chapter-content');
            if (!container) return;
            const svg = container.querySelector('svg.study-drawing-layer');
            if (!svg) return;

            const drawings = [];
            svg.querySelectorAll('path.study-drawn-path').forEach((path) => {
                const origD = path.getAttribute('data-orig-d') || path.getAttribute('d');
                const color = path.getAttribute('data-color') || 'yellow';
                const id = path.getAttribute('data-id') || ('dr_' + Math.random().toString(36).substr(2, 9));
                const anchorType = path.getAttribute('data-anchor-type') || 'block';
                const anchorIdx = path.getAttribute('data-anchor-idx');
                const anchorText = path.getAttribute('data-anchor-text');
                const anchorTop = path.getAttribute('data-anchor-top');
                const anchorWidth = path.getAttribute('data-anchor-width');
                const anchorHeight = path.getAttribute('data-anchor-height');
                const wordLeft = path.getAttribute('data-word-left');
                const wordTop = path.getAttribute('data-word-top');
                const wordWidth = path.getAttribute('data-word-width');
                const wordHeight = path.getAttribute('data-word-height');

                if (origD) {
                    drawings.push({ 
                        id: id, 
                        color: color, 
                        path: origD,
                        anchorType: anchorType,
                        anchorIdx: anchorIdx !== null ? parseInt(anchorIdx) : null,
                        anchorText: anchorText || null,
                        anchorTop: anchorTop !== null ? parseFloat(anchorTop) : null,
                        anchorWidth: anchorWidth !== null ? parseFloat(anchorWidth) : null,
                        anchorHeight: anchorHeight !== null ? parseFloat(anchorHeight) : null,
                        wordLeft: wordLeft !== null ? parseFloat(wordLeft) : null,
                        wordTop: wordTop !== null ? parseFloat(wordTop) : null,
                        wordWidth: wordWidth !== null ? parseFloat(wordWidth) : null,
                        wordHeight: wordHeight !== null ? parseFloat(wordHeight) : null
                    });
                }
            });

            this._cachedDrawings = this._cachedDrawings || {};
            this._cachedDrawings[chapterId] = drawings;

            fetch('/api/learnAI/highlights', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    chapter_id: chapterId,
                    highlights: (this._cachedHighlights && this._cachedHighlights[chapterId]) || [],
                    drawings: drawings
                })
            })
            .then(r => r.json())
            .then(res => console.log('Saved drawings for chapter', chapterId, res))
            .catch(err => console.error('Failed to save drawings:', err));
        },

        _applyStudyDrawings: function (container, drawings) {
            const targetContainer = container && (container.id === 'chapterContentContainer' || container.classList.contains('learn-chapter-content') || container.classList.contains('chapter-text-content')) ? container : (document.getElementById('chapterContentContainer') || document.querySelector('.learn-chapter-content'));
            if (!targetContainer) return;

            let svg = targetContainer.querySelector('svg.study-drawing-layer');
            if (!svg) {
                svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                svg.id = 'studyDrawingLayer';
                targetContainer.appendChild(svg);
                this._initDrawingLayerEvents(targetContainer, svg);
            }
            // Clear existing paths using DOM methods (innerHTML can be unreliable on SVG)
            while (svg.firstChild) svg.removeChild(svg.firstChild);
            this._updateDrawingLayerState();

            if (!drawings || !drawings.length) return;

            const getPenRgb = (colorName) => {
                switch(colorName) {
                    case 'yellow': return 'rgb(250, 204, 21)';
                    case 'orange': return 'rgb(249, 115, 22)';
                    case 'green': return 'rgb(74, 222, 128)';
                    case 'pink': return 'rgb(248, 113, 113)';
                    case 'blue': return 'rgb(96, 165, 250)';
                    default: return 'rgb(250, 204, 21)';
                }
            };

            drawings.forEach(dr => {
                if (!dr.path) return;
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('class', 'study-drawn-path');
                path.setAttribute('data-id', dr.id || ('dr_' + Math.random().toString(36).substr(2, 9)));
                path.setAttribute('data-color', dr.color || 'yellow');
                path.setAttribute('stroke', getPenRgb(dr.color || 'yellow'));
                path.setAttribute('stroke-width', '3.5');
                path.setAttribute('fill', 'none');
                path.setAttribute('stroke-linecap', 'round');
                path.setAttribute('stroke-linejoin', 'round');
                path.setAttribute('d', dr.path);
                path.setAttribute('data-orig-d', dr.path);
                if (dr.anchorType) path.setAttribute('data-anchor-type', dr.anchorType);
                if (dr.anchorIdx !== undefined && dr.anchorIdx !== null) path.setAttribute('data-anchor-idx', dr.anchorIdx);
                if (dr.anchorText) path.setAttribute('data-anchor-text', dr.anchorText);
                if (dr.anchorTop !== undefined && dr.anchorTop !== null) path.setAttribute('data-anchor-top', dr.anchorTop);
                if (dr.anchorWidth !== undefined && dr.anchorWidth !== null) path.setAttribute('data-anchor-width', dr.anchorWidth);
                if (dr.anchorHeight !== undefined && dr.anchorHeight !== null) path.setAttribute('data-anchor-height', dr.anchorHeight);
                if (dr.wordLeft !== undefined && dr.wordLeft !== null) path.setAttribute('data-word-left', dr.wordLeft);
                if (dr.wordTop !== undefined && dr.wordTop !== null) path.setAttribute('data-word-top', dr.wordTop);
                if (dr.wordWidth !== undefined && dr.wordWidth !== null) path.setAttribute('data-word-width', dr.wordWidth);
                if (dr.wordHeight !== undefined && dr.wordHeight !== null) path.setAttribute('data-word-height', dr.wordHeight);

                if (!dr.anchorIdx && dr.anchorIdx !== 0) {
                    this._anchorPathToBlock(targetContainer, path);
                }
                svg.appendChild(path);
            });

            // Re-sync SVG size after paths are added (layout may have changed)
            this._syncDrawingLayerSize(targetContainer, svg);

            // Safety: re-check after a frame to catch any late DOM wipe
            const savedDrawings = [...drawings];
            const savedContainer = targetContainer;
            requestAnimationFrame(() => {
                const checkSvg = savedContainer.querySelector('svg.study-drawing-layer');
                const checkPaths = checkSvg ? checkSvg.querySelectorAll('path.study-drawn-path').length : 0;
                if (!checkSvg || checkPaths === 0) {
                    console.warn('[StudyDraw] SVG was WIPED after apply! Re-applying...');
                    let resvg = savedContainer.querySelector('svg.study-drawing-layer');
                    if (!resvg) {
                        resvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                        resvg.id = 'studyDrawingLayer';
                        savedContainer.appendChild(resvg);
                        LearnApp._initDrawingLayerEvents(savedContainer, resvg);
                    }
                    while (resvg.firstChild) resvg.removeChild(resvg.firstChild);
                    savedDrawings.forEach(dr => {
                        if (!dr.path) return;
                        const p = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                        p.setAttribute('class', 'study-drawn-path');
                        p.setAttribute('data-id', dr.id || ('dr_' + Math.random().toString(36).substr(2, 9)));
                        p.setAttribute('data-color', dr.color || 'yellow');
                        p.setAttribute('stroke', getPenRgb(dr.color || 'yellow'));
                        p.setAttribute('stroke-width', '3.5');
                        p.setAttribute('fill', 'none');
                        p.setAttribute('stroke-linecap', 'round');
                        p.setAttribute('stroke-linejoin', 'round');
                        p.setAttribute('d', dr.path);
                        p.setAttribute('data-orig-d', dr.path);
                        if (dr.anchorType) p.setAttribute('data-anchor-type', dr.anchorType);
                        if (dr.anchorIdx !== undefined && dr.anchorIdx !== null) p.setAttribute('data-anchor-idx', dr.anchorIdx);
                        if (dr.anchorText) p.setAttribute('data-anchor-text', dr.anchorText);
                        if (dr.anchorTop !== undefined && dr.anchorTop !== null) p.setAttribute('data-anchor-top', dr.anchorTop);
                        if (dr.anchorWidth !== undefined && dr.anchorWidth !== null) p.setAttribute('data-anchor-width', dr.anchorWidth);
                        if (dr.anchorHeight !== undefined && dr.anchorHeight !== null) p.setAttribute('data-anchor-height', dr.anchorHeight);
                        if (dr.wordLeft !== undefined && dr.wordLeft !== null) p.setAttribute('data-word-left', dr.wordLeft);
                        if (dr.wordTop !== undefined && dr.wordTop !== null) p.setAttribute('data-word-top', dr.wordTop);
                        if (dr.wordWidth !== undefined && dr.wordWidth !== null) p.setAttribute('data-word-width', dr.wordWidth);
                        if (dr.wordHeight !== undefined && dr.wordHeight !== null) p.setAttribute('data-word-height', dr.wordHeight);

                        if (!dr.anchorIdx && dr.anchorIdx !== 0) {
                            LearnApp._anchorPathToBlock(savedContainer, p);
                        }
                        resvg.appendChild(p);
                    });
                    LearnApp._syncDrawingLayerSize(savedContainer, resvg);
                    LearnApp._updateDrawingLayerState();
                }
            });
        },

        renderMarkdownWithHighlighting: function(markdownText, container) {
            if (!container) return;
            const isChapterContainer = container.id === 'chapterContentContainer' || container.classList.contains('learn-chapter-content') || container.classList.contains('chapter-text-content');

            const finishRendering = () => {
                if (typeof hljs !== 'undefined') {
                    container.querySelectorAll('pre code').forEach((el) => {
                        hljs.highlightElement(el);
                    });
                }
                this._bindCopyButtons(container);
                if (isChapterContainer) {
                    const chId = document.getElementById('currentChapterId')?.value || document.querySelector('.learn-accordion-btn.active')?.getAttribute('data-chapter-id');
                    if (chId) {
                        this.loadStudyHighlights(container, chId);
                    }
                }
            };

            if (typeof marked !== 'undefined') {
                container.innerHTML = marked.parse(markdownText);
                finishRendering();
            } else {
                let retries = 0;
                const checkMarked = setInterval(() => {
                    if (typeof marked !== 'undefined') {
                        clearInterval(checkMarked);
                        container.innerHTML = marked.parse(markdownText);
                        finishRendering();
                    } else if (retries++ > 20) {
                        clearInterval(checkMarked);
                        if (container.innerHTML.trim() === '') {
                             container.innerText = markdownText;
                        }
                        finishRendering();
                    }
                }, 100);
            }
        },

        _bindCopyButtons: function (container) {
            if (!container) return;
            container.querySelectorAll('pre').forEach((pre) => {
                if (pre.querySelector('.btn-copy-code')) return;
                pre.style.position = 'relative';

                const copyBtn = document.createElement('button');
                copyBtn.className = 'btn btn-sm btn-copy-code';
                copyBtn.innerHTML = '<i class="bx bx-copy"></i> Copy';
                copyBtn.style.position = 'absolute';
                copyBtn.style.top = '0.5rem';
                copyBtn.style.right = '0.5rem';
                copyBtn.style.zIndex = '5';

                copyBtn.addEventListener('click', () => {
                    const codeText = pre.querySelector('code')?.innerText || pre.innerText;
                    copyText(codeText, 'Code copied!');
                    copyBtn.innerHTML = '<i class="bx bx-check text-success"></i> Copied!';
                    setTimeout(() => { copyBtn.innerHTML = '<i class="bx bx-copy"></i> Copy'; }, 2000);
                });

                pre.appendChild(copyBtn);
            });
        },

        _renderEmptyPrompt: function (container, chapterId) {
            if (!container) return;
            container.innerHTML = `<div id="emptyContentPrompt" class="text-center py-5 my-4">
                <div class="mb-3">
                    <i class="bx bx-book-open text-secondary opacity-50" style="font-size: 3.5rem;"></i>
                </div>
                <h5 class="fw-bold text-white mb-2">Ready to Learn?</h5>
                <p class="text-secondary small mb-4">Click below to generate practical, human-like tutorial content with live code blocks.</p>
                <button class="btn btn-primary rounded-pill px-4 btn-trigger-generate">
                    <i class="bx bx-magic-wand me-1"></i> Generate Chapter Material
                </button>
            </div>`;
            const btnEmpty = container.querySelector('.btn-trigger-generate');
            if (btnEmpty) {
                btnEmpty.addEventListener('click', () => {
                    this.triggerGenerate(chapterId || document.getElementById('currentChapterId')?.value);
                });
            }
        },

        triggerGenerate: function (chapterId) {
            if (!chapterId) return;
            const container = document.getElementById('chapterContentContainer');
            if (!container) return;
            const statusDiv = document.getElementById('contentGeneratingStatus');
            if (statusDiv) statusDiv.classList.remove('d-none');

            const emptyPrompt = document.getElementById('emptyContentPrompt');
            if (emptyPrompt) emptyPrompt.remove();

            const sessionId = 'content_sess_' + Math.random().toString(36).substr(2, 9);
            const messageId = 'content_msg_' + Math.random().toString(36).substr(2, 9);

            let accumulatedMd = "";

            if (typeof TomSocketClient !== 'undefined') {
                const contentSocket = new TomSocketClient();
                contentSocket.connect('/topic/content_stream.' + sessionId, (frame) => {
                    try {
                        let payload = frame;
                        if (typeof frame === 'string') {
                            try { payload = JSON.parse(frame); } catch(e) {}
                        } else if (frame && frame.body) {
                            try { payload = JSON.parse(frame.body); } catch(e) {}
                        }
                        if (payload && payload.type === 'text_delta') {
                            accumulatedMd += payload.data;
                            this.renderMarkdownWithHighlighting(accumulatedMd, container);
                        } else if (payload && payload.type === 'stream_end') {
                            if (statusDiv) statusDiv.classList.add('d-none');
                            this.renderMarkdownWithHighlighting(accumulatedMd, container);
                            contentSocket.disconnect();
                        }
                    } catch (e) {
                        console.error('Content stream error:', e);
                    }
                });
            }

            fetch('/api/learnAI/content_generate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    chapter_id: chapterId,
                    session_id: sessionId,
                    message_id: messageId
                })
            })
                .then(res => res.json())
                .catch(err => {
                    console.error('Content generate trigger failed:', err);
                    if (statusDiv) statusDiv.classList.add('d-none');
                });
        },

        initContentGenerator: function () {
            const container = document.getElementById('chapterContentContainer');
            if (!container) return;
            if (container.dataset.contentInit === 'true') return;
            container.dataset.contentInit = 'true';

            const initChId = document.getElementById('currentChapterId')?.value || document.querySelector('.learn-accordion-btn.active')?.getAttribute('data-chapter-id');
            const rawFallback = container.querySelector('.raw-markdown-fallback, .raw-markdown');
            if (rawFallback) {
                const md = container.getAttribute('data-raw-md') || rawFallback.innerText;
                // renderMarkdownWithHighlighting already calls loadStudyHighlights at the end,
                // so do NOT call it again below to avoid race conditions
                this.renderMarkdownWithHighlighting(md, container);
            } else {
                if (typeof hljs !== 'undefined') {
                    container.querySelectorAll('pre code').forEach((el) => hljs.highlightElement(el));
                }
                this._bindCopyButtons(container);
                // Only load highlights here for pre-rendered HTML (no rawFallback)
                if (initChId) {
                    this.loadStudyHighlights(container, initChId);
                }
            }

            const btnHeader = document.getElementById('btnGenerateContent');
            if (btnHeader) {
                btnHeader.addEventListener('click', () => {
                    const chId = btnHeader.getAttribute('data-chapter-id') || document.getElementById('currentChapterId')?.value;
                    this.triggerGenerate(chId);
                });
            }

            const btnEmpty = container.querySelector('.btn-trigger-generate');
            if (btnEmpty) {
                btnEmpty.addEventListener('click', () => {
                    const chId = btnHeader ? btnHeader.getAttribute('data-chapter-id') : document.getElementById('currentChapterId')?.value;
                    this.triggerGenerate(chId);
                });
            }
        },

        initChapterNav: function () {
            if (this._chapterNavInitialized) return;
            this._chapterNavInitialized = true;

            document.addEventListener('click', (e) => {
                const btn = e.target.closest('.learn-accordion-btn, .ajax-chapter-load');
                if (!btn) return;

                const panel2Card = document.querySelector('#learn-panel-2 .card');
                if (!panel2Card) return; // Something is wrong with the layout, fallback

                e.preventDefault();
                e.stopPropagation();

                let chapterId = btn.getAttribute('data-chapter-id');
                let lessonId = btn.getAttribute('data-lesson-id');
                const href = btn.getAttribute('href') || '';

                if (!href) return;
                
                LearnApp.navigateTo(href, chapterId, lessonId, btn);
            });
            
            // Handle Browser Back/Forward buttons
            window.addEventListener('popstate', (e) => {
                if (window.location.pathname.includes('/learn/lesson/')) {
                    LearnApp.navigateTo(window.location.pathname, null, null, null, true);
                }
            });
        },
    
    navigateTo: function(href, chapterId, lessonId, btn, isPopState) {
        btn = btn || null;
        isPopState = isPopState || false;

        if (!chapterId) {
            var chMatch = href.match(/chapter\/([^\/?#]+)/);
            if (chMatch) chapterId = chMatch[1];
        }
        if (!lessonId) {
            var lsMatch = href.match(/lesson\/([^\/?#]+)/);
            if (lsMatch) lessonId = lsMatch[1];
        }

        var currentChapterInput = document.getElementById('currentChapterId');
        if (currentChapterInput && currentChapterInput.value === (chapterId || '') && chapterId !== null) {
            return; // Already on this chapter
        }
        
        var isOverview = !chapterId;

        // Update left sidebar active states
        if (isOverview) {
            // Clear all sidebar states
            document.querySelectorAll('.learn-accordion-btn').forEach(function(a) {
                a.classList.remove('active', 'bg-primary', 'bg-opacity-25', 'text-white', 'fw-bold');
                a.classList.add('text-secondary');
                var icon = a.querySelector('.icon');
                if (icon) {
                    icon.classList.remove('bxs-check-circle', 'text-primary');
                    icon.classList.add('bx-check-circle', 'opacity-50');
                }
                var text = a.querySelector('.chapter-title-text');
                if (text) {
                    text.classList.remove('text-white');
                    text.classList.add('text-secondary');
                }
            });
        } else if (chapterId) {
            var targetBtn = (btn && btn.classList.contains('learn-accordion-btn')) ? btn : document.querySelector('.learn-accordion-btn[data-chapter-id="' + chapterId + '"]');

            document.querySelectorAll('.learn-accordion-btn').forEach(function(a) {
                a.classList.remove('active', 'bg-primary', 'bg-opacity-25', 'text-white', 'fw-bold');
                a.classList.add('text-secondary');
                var icon = a.querySelector('.icon');
                if (icon) {
                    icon.classList.remove('bxs-check-circle', 'text-primary');
                    icon.classList.add('bx-check-circle', 'opacity-50');
                }
                var text = a.querySelector('.chapter-title-text');
                if (text) {
                    text.classList.remove('text-white');
                    text.classList.add('text-secondary');
                }
            });

            if (targetBtn) {
                targetBtn.classList.add('active', 'bg-primary', 'bg-opacity-25', 'text-white', 'fw-bold');
                targetBtn.classList.remove('text-secondary');
                var clickedIcon = targetBtn.querySelector('.icon');
                if (clickedIcon) {
                    clickedIcon.classList.remove('bx-check-circle', 'opacity-50');
                    clickedIcon.classList.add('bxs-check-circle', 'text-primary');
                }
                var clickedText = targetBtn.querySelector('.chapter-title-text');
                if (clickedText) {
                    clickedText.classList.remove('text-secondary');
                    clickedText.classList.add('text-white');
                }
            }
        }

        if (!isPopState && href && window.history && window.history.pushState) {
            window.history.pushState(null, '', href);
        }

        if (currentChapterInput) currentChapterInput.value = chapterId || '';
        var currentLessonInput = document.getElementById('currentLessonId');
        if (currentLessonInput && lessonId) currentLessonInput.value = lessonId;

        // Toggle Panel 3 visibility
        var panel3El = document.getElementById('learn-panel-3');
        var resizer2El = document.querySelector('.gutter-horizontal[data-target="learn-panel-3"]');
        
        if (isOverview) {
            if (panel3El) {
                panel3El.classList.add('d-none');
                panel3El.classList.remove('d-flex');
            }
            if (resizer2El) resizer2El.classList.add('d-none');
        } else {
            if (panel3El) {
                panel3El.classList.remove('d-none');
                panel3El.classList.add('d-flex');
            }
            if (resizer2El) resizer2El.classList.remove('d-none');
        }
        
        this.restorePaneWidths();

        var btnGenerate = document.getElementById('btnGenerateContent');
        if (btnGenerate) btnGenerate.setAttribute('data-chapter-id', chapterId);

        var panel2Card = document.querySelector('#learn-panel-2 .card');
        if (panel2Card) {
            panel2Card.innerHTML = '<div class="text-center py-5 my-5 w-100 h-100 d-flex flex-column justify-content-center align-items-center"><div class="spinner-border text-primary mb-3" role="status" style="width: 2.5rem; height: 2.5rem;"></div><p class="text-secondary small">Loading chapter...</p></div>';
        }

        var self = this;
        // Fetch the partial HTML representation of Panel 2
        fetch(href, { 
            credentials: 'include',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'text/html'
            }
        })
            .then(function(res) { return res.text(); })
            .then(function(html) {
                if (html && panel2Card) {
                    panel2Card.innerHTML = html;
                    
                    // Re-initialize highlighting for the newly inserted content
                    var container = document.getElementById('chapterContentContainer');
                    if (container) {
                        var rawFallback = container.querySelector('.raw-markdown-fallback');
                        if (rawFallback) {
                            self.renderMarkdownWithHighlighting(rawFallback.innerText, container);
                        } else {
                            if (typeof hljs !== 'undefined') {
                                container.querySelectorAll('pre code').forEach(function(el) { hljs.highlightElement(el); });
                            }
                            self._bindCopyButtons(container);
                            self.loadStudyHighlights(container, chapterId);
                            var btnEmpty = container.querySelector('.btn-trigger-generate');
                            if (btnEmpty) {
                                btnEmpty.addEventListener('click', function() {
                                    self.triggerGenerate(chapterId || document.getElementById('currentChapterId').value);
                                });
                            }
                        }
                    }
                    
                    // Load AI Chat History ONLY if not already loaded
                    var chatHistory = document.getElementById('aiChatHistory');
                    if (chatHistory) {
                        if (!isOverview && chatHistory.dataset.chatLoaded !== 'true') {
                            chatHistory.innerHTML = '<div class="text-center py-4 my-auto text-secondary small d-flex flex-column align-items-center gap-2"><i class="bx bx-loader-circle bx-spin fs-4 text-primary"></i><span>Loading conversation...</span></div>';
                            fetch('/api/learnAI/history?lesson_id=' + lessonId + '&chapter_id=' + chapterId)
                                .then(function(r) { return r.text(); })
                                .then(function(chatHtml) {
                                    chatHistory.innerHTML = chatHtml;
                                    chatHistory.dataset.chatLoaded = 'true';
                                    self._processChatHistoryHtml();
                                });
                        }
                    }
                    
                    // Reset AI token cache badge
                    var cacheBadge = document.getElementById('aiCachePercBadge');
                    if (cacheBadge) cacheBadge.innerText = '0%';
                }
            })
            .catch(function(err) {
                console.error('Failed to fetch chapter:', err);
                if (panel2Card) {
                    panel2Card.innerHTML = '<div class="text-center p-5 text-danger">Failed to load content.</div>';
                }
            });
    }
};

    // Initialize
    if (typeof jQuery !== 'undefined') {
        jQuery(document).ready(function() {
            LearnApp.init();
            LearnApp.initDashboard();
        });
    } else if (document.readyState === 'loading') {
        window.onPageLoad( () => { LearnApp.init(); LearnApp.initDashboard(); });
    } else {
        LearnApp.init();
        LearnApp.initDashboard();
    }

    // Export toggle for button clicks
    window.toggleOutline = () => LearnApp.toggleOutline();
    window.unlockAiAssistAction = (lessonId) => LearnApp.unlockAiAssistAction(lessonId);

    window.LearnAI = LearnApp;
    window.LearnApp = LearnApp;

    // Re-check for resizers after a small delay in case of late rendering
    setTimeout(() => { if (!LearnApp.isInitialized) LearnApp.init(); LearnApp.initDashboard(); }, 1000);
})();


    

    // --- Explicit Window Exports for Inline HTML ---
    window.toggleLessonVisibility = function(lessonId, targetVisibility) {
        fetch('/api/learnAI/visibility_toggle', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lesson_id: lessonId, visibility: targetVisibility })
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.status === 'success') {
                const cardEl = document.querySelector(`.lesson-card[data-lesson-id="${lessonId}"]`);
                if (cardEl) {
                    const gridItem = cardEl.closest('.lesson-grid-item');
                    if (gridItem) gridItem.setAttribute('data-visibility', targetVisibility);

                    const badgeEl = cardEl.querySelector('.lesson-visibility-badge');
                    if (badgeEl) {
                        const isPrivate = (targetVisibility === 'Private');
                        badgeEl.className = `badge bg-${isPrivate ? 'secondary' : 'info'}-gradient d-inline-flex align-items-center gap-1 lesson-visibility-badge`;
                        badgeEl.innerHTML = `<i class="bx ${isPrivate ? 'bx-lock-alt' : 'bx-globe'}"></i> <span class="visibility-text">${isPrivate ? 'Private' : 'Public'}</span>`;
                    }

                    const toggleLi = cardEl.querySelector('.visibility-toggle-item');
                    if (toggleLi) {
                        if (targetVisibility === 'Private') {
                            toggleLi.innerHTML = `<a class="dropdown-item small py-1" href="#" onclick="event.stopPropagation(); toggleLessonVisibility('${lessonId}', 'Public'); return false;"><i class="bx bx-globe me-2 text-info"></i>Make Public</a>`;
                        } else {
                            toggleLi.innerHTML = `<a class="dropdown-item small py-1" href="#" onclick="event.stopPropagation(); toggleLessonVisibility('${lessonId}', 'Private'); return false;"><i class="bx bx-lock-alt me-2 text-warning"></i>Make Private</a>`;
                        }
                    }
                }
            } else {
                alert(data.error || 'Failed to change lesson visibility');
            }
        })
        .catch(() => alert('Network error while toggling lesson visibility'));
    };

    window.deleteLessonAction = function(lessonId, lessonTitle) {
        if (!confirm(`Are you sure you want to delete lesson "${lessonTitle}"? This will permanently remove all chapters and content.`)) {
            return;
        }
        fetch('/api/learnAI/lesson_delete', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lesson_id: lessonId })
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.status === 'success') {
                const cardEl = document.querySelector(`.lesson-card[data-lesson-id="${lessonId}"]`);
                if (cardEl) {
                    const colEl = cardEl.closest('.lesson-grid-item') || cardEl.closest('.col');
                    if (colEl) colEl.remove();
                }
            } else {
                alert(data.error || 'Failed to delete lesson');
            }
        })
        .catch(() => alert('Network error while deleting lesson'));
    };

    window.shareLessonAction = function(lessonId, lessonTitle, btnEl) {
        const shareUrl = window.location.origin + '/learn/lesson/' + lessonId;
        copyText(shareUrl, 'Lesson link copied!');
        if (btnEl) {
            const icon = btnEl.querySelector('i');
            if (icon) {
                const origClass = icon.className;
                icon.className = 'bx bx-check text-success fs-6';
                setTimeout(() => { icon.className = origClass; }, 2000);
            }
        }
    };

    window.toggleLikeLessonAction = function(lessonId, btnEl) {
        if (!lessonId || !btnEl) return;
        const icon = btnEl.querySelector('i');
        const span = btnEl.querySelector('.lesson-like-count, span');
        let count = 0;
        if (span) count = parseInt(span.innerText, 10) || 0;
        const wasLiked = icon && (icon.classList.contains('bxs-heart') || icon.classList.contains('text-danger'));
        
        // Optimistic toggle
        if (icon) {
            if (wasLiked) {
                icon.className = 'bx bx-heart fs-6';
                if (span) span.innerText = Math.max(0, count - 1);
            } else {
                icon.className = 'bx bxs-heart text-danger fs-6';
                if (span) span.innerText = count + 1;
            }
        }

        fetch('/api/learnAI/lesson_like', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lesson_id: lessonId })
        })
        .then(r => r.json())
        .then(data => {
            if (data && data.result === 'success') {
                if (span && typeof data.like_count !== 'undefined') {
                    span.innerText = data.like_count;
                }
                if (icon) {
                    if (data.liked) {
                        icon.className = icon.className.replace(/fs-\d+/, '').trim() + ' bx bxs-heart text-danger fs-6';
                    } else {
                        icon.className = icon.className.replace(/fs-\d+/, '').trim() + ' bx bx-heart fs-6';
                    }
                }
                const gridItem = btnEl.closest('.lesson-grid-item');
                if (gridItem) {
                    gridItem.setAttribute('data-liked', data.liked ? 'true' : 'false');
                    gridItem.setAttribute('data-likes-count', data.like_count || '0');
                }
                // Also sync all other like buttons for this same lesson on the page
                document.querySelectorAll(`.toggle-like-btn[data-lesson-id="${lessonId}"], .like-lesson-btn[data-lesson-id="${lessonId}"]`).forEach(otherBtn => {
                    if (otherBtn !== btnEl) {
                        const otherIcon = otherBtn.querySelector('i');
                        const otherSpan = otherBtn.querySelector('.lesson-like-count, span');
                        if (otherIcon) {
                            const baseClass = otherBtn.classList.contains('like-lesson-btn') ? 'fs-5' : 'fs-6';
                            otherIcon.className = `bx ${data.liked ? 'bxs-heart text-danger' : 'bx-heart'} ${baseClass}`;
                        }
                        if (otherSpan && typeof data.like_count !== 'undefined') {
                            otherSpan.innerText = data.like_count;
                        }
                    }
                });
                if (typeof TomNotify !== 'undefined' && data.message) {
                    TomNotify.show(data.message, data.liked ? "Liked" : "Unliked", data.liked ? "success" : "info", 2000);
                }
            } else {
                // Revert optimistic change on failure
                if (icon) {
                    icon.className = wasLiked ? 'bx bxs-heart text-danger fs-6' : 'bx bx-heart fs-6';
                }
                if (span) span.innerText = count;
                if (typeof TomNotify !== 'undefined') {
                    TomNotify.show(data?.error || "Could not update like status", "Error", "error", 3000);
                }
            }
        })
        .catch(err => {
            console.error("Like API error:", err);
            if (icon) {
                icon.className = wasLiked ? 'bx bxs-heart text-danger fs-6' : 'bx bx-heart fs-6';
            }
            if (span) span.innerText = count;
        });
    };

  })();
} catch (e) {
  console.error("[Fatal Error in learnAI.js]", e);
}
