<?php
$topic = $_GET['topic'] ?? '';
if (empty($topic)) { header('Location: /roadmaps'); exit; }

$level = $_GET['level'] ?? 'Beginner';
$validLevels = ['Beginner', 'Intermediate', 'Advanced'];
if (!in_array($level, $validLevels)) $level = 'Beginner';
?>

<script>
window.__RM_GEN = <?= json_encode(['topic' => $topic, 'level' => $level]) ?>;
</script>

<style>
/* Generation-mode: card entrance animation */
.rm-card-new {
  animation: cardSlideIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
@keyframes cardSlideIn {
  from { opacity: 0; transform: translateY(12px); }
  to { opacity: 1; transform: translateY(0); }
}
.rm-item-new {
  animation: itemFadeIn 0.2s ease;
}
@keyframes itemFadeIn {
  from { opacity: 0; transform: translateX(-6px); }
  to { opacity: 1; transform: translateX(0); }
}
</style>

<div id="rm-gen-app" class="body d-flex flex-column flex-grow-1 w-100 position-relative roadmap-detail-page">

    <!-- HEADER BAR -->
    <div class="rm-header container-fluid blur px-4 py-2" id="rm-header">
        <div class="roadmap-banner-headrow">
            <a href="/roadmaps" class="roadmap-banner-back">
                <svg class="icon icon-sm"><use xlink:href="/assets/icons/free.svg#cil-arrow-left"></use></svg>
            </a>
            <span class="roadmap-banner-title">
                <span id="rm-title" class="rm-title-text">Generating...</span>
            </span>
            <div class="roadmap-banner-actions">
                <span class="roadmap-progress-chip small" id="rm-gen-progress-chip" title="Generating">
                    <span class="progress" style="width: 80px; height: 6px;">
                        <span class="progress-bar bg-primary progress-bar-striped progress-bar-animated" id="rm-progress-bar" style="width:0%"></span>
                    </span>
                    <strong id="rm-progress-pct">0%</strong>
                </span>
            </div>
        </div>
        <div class="roadmap-banner-meta">
            <span id="rm-gen-status" class="text-secondary" style="font-size:0.85rem;">
                <i class='bx bx-bot me-1 text-info'></i>
                <span id="rm-gen-msg">Initializing AI...</span>
            </span>
        </div>
    </div>

    <!-- SINGLE PANEL — FULL WIDTH DURING GENERATION -->
    <div class="rm-panels d-flex flex-grow-1 g-3 p-3" style="min-height:0;">
        <div id="roadmap-panel-left" class="rm-split-panel rm-left" style="width:100%;">
            <div class="card blur" style="display:flex;flex-direction:column;">
                <div id="rm-left-content" class="rm-canvas" style="flex:1 1 0%;min-height:0;overflow-y:auto;padding:0.75rem 1rem;">
                    <!-- Empty card — sections will be appended here -->
                    <div class="text-center py-5" id="rm-gen-empty-state">
                        <div class="spinner-border text-primary mb-3" role="status" style="width:3rem;height:3rem;"></div>
                        <h5 class="text-white mb-2">Designing your roadmap...</h5>
                        <p class="text-secondary" style="font-size:0.85rem;">AI is analyzing your topic and building a structured learning path</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="/assets/js/roadmaps.js?v=5"></script>
<script src="/assets/js/mq.js?v=3"></script>
<script>
(function() {
    var genConfig = window.__RM_GEN || {};
    var topic = genConfig.topic || '';
    var level = genConfig.level || 'Beginner';
    var jobId = null;
    var TYPING_SPEED = 30;
    var CARD_PAUSE = 200;

    var sectionElements = {};
    var cardElements = {};
    var activeIntervals = [];

    var topicQueue = [];
    var activeTopic = null;
    var typewriterQueue = [];
    var isTyping = false;
    var allTopicItems = {};
    var generationComplete = false;
    var roadmapSlug = null;
    var completionTimer = null;
    var waitingForItems = false;

    document.getElementById('rm-gen-msg').textContent = 'Analyzing your topic...';
    document.getElementById('rm-progress-bar').style.width = '5%';

    fetch('/api/roadmaps/generate_stream', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'include',
        body: JSON.stringify({ prompt: topic, level: level })
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.error) {
            document.getElementById('rm-gen-msg').textContent = data.error;
            document.getElementById('rm-progress-bar').classList.remove('progress-bar-animated');
            return;
        }
        jobId = data.job_id;
        document.getElementById('rm-gen-msg').textContent = 'Connecting to AI stream...';
        document.getElementById('rm-progress-bar').style.width = '10%';
        connectToStream(jobId);
    })
    .catch(function(e) {
        document.getElementById('rm-gen-msg').textContent = 'Failed to start generation. Try again.';
        document.getElementById('rm-progress-bar').classList.remove('progress-bar-animated');
    });

    function connectToStream(jobId) {
        var streamSocket = new TomSocketClient();
        window._rmGenSocket = streamSocket;

        streamSocket.connect('roadmap_stream.' + jobId, function(msg) {
            if (!msg || !msg.type) return;
            switch(msg.type) {
                case 'progress': handleProgress(msg); break;
                case 'section_start': handleSectionStart(msg); break;
                case 'topic_start': handleTopicStart(msg); break;
                case 'topic_item': handleTopicItem(msg); break;
                case 'topic_done': handleTopicDone(msg); break;
                case 'section_done': handleSectionDone(msg); break;
                case 'completed': handleCompleted(msg); break;
                case 'error': handleError(msg); break;
            }
        }, null, function() {
            document.getElementById('rm-gen-msg').textContent = 'Connected. Generating roadmap...';
        });
    }

    function handleProgress(msg) {
        var pct = msg.percentage || 0;
        document.getElementById('rm-progress-bar').style.width = pct + '%';
        document.getElementById('rm-progress-pct').textContent = pct + '%';
        document.getElementById('rm-gen-msg').textContent = msg.message || 'Processing...';
        if (msg.title) document.getElementById('rm-title').textContent = msg.title;
    }

    function ensureSectionsWrap() {
        var container = document.getElementById('rm-left-content');
        var emptyState = document.getElementById('rm-gen-empty-state');
        if (emptyState) emptyState.remove();
        var wrap = container.querySelector('.rm-sections');
        if (!wrap) {
            wrap = document.createElement('div');
            wrap.className = 'rm-sections';
            container.appendChild(wrap);
        }
        return wrap;
    }

    function handleSectionStart(msg) {
        var section = msg.section;
        if (!section) return;
        var idx = msg.section_index || 0;
        var wrap = ensureSectionsWrap();
        if (sectionElements[idx]) return;

        var sectionEl = document.createElement('section');
        sectionEl.className = 'rm-section';
        sectionEl.setAttribute('data-section', idx);
        sectionEl.innerHTML = '<div class="rm-section-label">' + (idx + 1) + '. ' + escHtml(section.title || 'Section ' + (idx + 1)) + '</div>'
            + '<div class="rm-section-cards"></div>';
        wrap.appendChild(sectionEl);
        sectionElements[idx] = sectionEl;
        updateHeader(msg);
        smoothScroll();
    }

    function createCardSkeleton(section_idx, topic_idx, topicData) {
        var topicKey = section_idx + ':' + topic_idx;
        if (cardElements[topicKey]) return cardElements[topicKey];

        var sectionEl = sectionElements[section_idx];
        if (!sectionEl) {
            handleSectionStart({ section: { title: 'Section ' + (section_idx + 1) }, section_index: section_idx });
            sectionEl = sectionElements[section_idx];
        }
        var cardsContainer = sectionEl.querySelector('.rm-section-cards');
        if (!cardsContainer) {
            cardsContainer = document.createElement('div');
            cardsContainer.className = 'rm-section-cards';
            sectionEl.appendChild(cardsContainer);
        }

        var topicId = topicData.id || ('topic_' + escHtml(topicData.title || '').replace(/\s+/g, '_'));
        var card = document.createElement('div');
        card.className = 'rm-card rm-card-default rm-card-new';
        card.setAttribute('data-topic-id', topicId);
        card.innerHTML = '<h4 class="rm-card-title">' + escHtml(topicData.title || 'Untitled') + '</h4>'
            + '<ul class="rm-list"></ul>';
        cardsContainer.appendChild(card);
        cardElements[topicKey] = card;

        setTimeout(function() { card.classList.remove('rm-card-new'); }, 300);
        smoothScroll();
        return card;
    }

    function handleTopicStart(msg) {
        var idx = msg.section_index || 0;
        var ti = msg.topic_index || 0;
        var topicData = msg.topic || {};
        var topicKey = idx + ':' + ti;

        // Auto-create section if it doesn't exist yet (no section_start event needed)
        if (!sectionElements[idx]) {
            var sectionTitle = msg.section_title || ('Section ' + (idx + 1));
            var wrap = ensureSectionsWrap();
            var sectionEl = document.createElement('section');
            sectionEl.className = 'rm-section';
            sectionEl.setAttribute('data-section', idx);
            sectionEl.innerHTML = '<div class="rm-section-label">' + (idx + 1) + '. ' + escHtml(sectionTitle) + '</div>'
                + '<div class="rm-section-cards"></div>';
            wrap.appendChild(sectionEl);
            sectionElements[idx] = sectionEl;
            updateHeader(msg);
            smoothScroll();
            setTimeout(function() { drawWires(); }, 50);
        }

        topicQueue.push({
            section_idx: idx,
            topic_idx: ti,
            topic: topicData,
            topicKey: topicKey
        });
        allTopicItems[topicKey] = [];

        if (!activeTopic) processNextTopic();
    }

    function handleTopicItem(msg) {
        var idx = msg.section_index || 0;
        var ti = msg.topic_index || 0;
        var item = msg.item;
        if (!item) return;
        var topicKey = idx + ':' + ti;

        if (!allTopicItems[topicKey]) allTopicItems[topicKey] = [];
        allTopicItems[topicKey].push(item);

        if (activeTopic && activeTopic.topicKey === topicKey) {
            addItemToTypewriter(item, topicKey);
            if (waitingForItems && !isTyping && typewriterQueue.length > 0) {
                waitingForItems = false;
                startTypewriter();
            }
        }
    }

    function handleTopicDone(msg) {}
    function handleSectionDone(msg) {}

    function processNextTopic() {
        if (topicQueue.length === 0) {
            activeTopic = null;
            isTyping = false;
            waitingForItems = false;
            checkAllDone();
            return;
        }

        activeTopic = topicQueue.shift();
        var topicKey = activeTopic.topicKey;

        createCardSkeleton(activeTopic.section_idx, activeTopic.topic_idx, activeTopic.topic);

        var items = allTopicItems[topicKey] || [];
        if (items.length > 0) {
            waitingForItems = false;
            items.forEach(function(item) {
                addItemToTypewriter(item, topicKey);
            });
            if (!isTyping && typewriterQueue.length > 0) {
                startTypewriter();
            }
        } else {
            waitingForItems = true;
        }
    }

    function addItemToTypewriter(item, topicKey) {
        var card = cardElements[topicKey];
        if (!card) return;
        var list = card.querySelector('.rm-list');
        if (!list) return;

        var topicId = card.getAttribute('data-topic-id') || topicKey;
        var itemId = item.id || (topicId + '_' + hashStr(item.text || item.title || ''));

        typewriterQueue.push({ list: list, item: item, itemId: itemId, topicKey: topicKey });

        if (!isTyping) startTypewriter();
    }

    function startTypewriter() {
        if (typewriterQueue.length === 0) {
            isTyping = false;
            if (waitingForItems) {
                return;
            }
            if (activeTopic && topicQueue.length > 0) {
                markCardComplete(activeTopic.topicKey);
                setTimeout(function() {
                    activeTopic = null;
                    processNextTopic();
                    drawWires();
                }, CARD_PAUSE);
            } else {
                checkAllDone();
                drawWires();
            }
            return;
        }
        waitingForItems = false;
        isTyping = true;
        var entry = typewriterQueue.shift();
        typeItem(entry.list, entry.item, entry.itemId, function() {
            smoothScroll();
            startTypewriter();
        });
    }

    function typeItem(list, item, itemId, callback) {
        var itemType = item.type || 'learning';
        var cls = classFor(itemType);
        var glyph = glyphFor(itemType);
        var fullText = item.text || item.title || '';

        var li = document.createElement('li');
        li.className = 'rm-item ' + cls + ' rm-item-new';
        li.setAttribute('data-item-id', itemId);
        li.innerHTML = '<input type="checkbox" class="rm-check" disabled>'
            + (glyph ? '<span class="rm-glyph">' + glyph + '</span>' : '')
            + '<span class="rm-node-link"></span>';
        list.appendChild(li);

        setTimeout(function() { li.classList.remove('rm-item-new'); }, 200);

        var textEl = li.querySelector('.rm-node-link');
        var words = fullText.split(' ');
        var wordIndex = 0;

        var interval = setInterval(function() {
            if (wordIndex < words.length) {
                if (wordIndex === 0) {
                    textEl.textContent = words[0];
                } else {
                    textEl.textContent += ' ' + words[wordIndex];
                }
                wordIndex++;
            } else {
                clearInterval(interval);
                li.classList.add('rm-item-typed');
                updateCardClass(list.closest('.rm-card'));
                callback();
            }
        }, TYPING_SPEED);

        activeIntervals.push(interval);
    }

    function markCardComplete(topicKey) {
        var card = cardElements[topicKey];
        if (!card) return;
        card.classList.add('rm-card-complete');
    }

    function insertConnector(topicKey) {
        var card = cardElements[topicKey];
        if (!card) return;
        var parent = card.parentNode;
        if (!parent) return;

        var connector = document.createElement('div');
        connector.className = 'rm-connector';
        parent.insertBefore(connector, card.nextSibling);

        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                connector.classList.add('rm-connector-active');
            });
        });
    }

    function updateCardClass(card) {
        if (!card) return;
        card.className = 'rm-card rm-card-default';
    }

    function classFor(type) {
        switch(type) {
            case 'milestone': return 'rm-item-milestone';
            case 'checkpoint': return 'rm-item-checkpoint';
            case 'project': return 'rm-item-project';
            case 'decision': return 'rm-item-decision';
            default: return 'rm-item-topic';
        }
    }

    function glyphFor(type) {
        switch(type) {
            case 'milestone': return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>';
            case 'checkpoint': return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
            case 'decision': return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
            case 'project': return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
            default: return '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/></svg>';
        }
    }

    function hashStr(s) {
        var h = 0;
        for (var i = 0; i < s.length; i++) {
            h = ((h << 5) - h) + s.charCodeAt(i);
            h |= 0;
        }
        return Math.abs(h).toString(36);
    }

    function updateHeader(msg) {
        if (msg.title) document.getElementById('rm-title').textContent = msg.title;
        if (msg.percentage) {
            document.getElementById('rm-progress-bar').style.width = msg.percentage + '%';
            document.getElementById('rm-progress-pct').textContent = msg.percentage + '%';
        }
        if (msg.message) document.getElementById('rm-gen-msg').textContent = msg.message;
    }

    function smoothScroll() {
        var el = document.getElementById('rm-left-content');
        if (el) el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' });
    }

    function drawWires() {
        var container = document.getElementById('rm-left-content');
        if (!container) return;
        var sw = container.querySelector('.rm-sections');
        if (!sw) return;
        var secs = sw.querySelectorAll('.rm-section');
        if (secs.length < 1) return;
        var wr = sw.getBoundingClientRect();
        var sp = [], ac = [], sd = [];
        secs.forEach(function(s) {
            var lb = s.querySelector('.rm-section-label');
            var cs = s.querySelectorAll('.rm-card');
            if (!lb || !cs.length) return;
            var lr = lb.getBoundingClientRect();
            var lcx = lr.left + lr.width / 2 - wr.left;
            var lby = lr.bottom - wr.top;
            var lty = lr.top - wr.top;
            var ct = [], cb = [];
            cs.forEach(function(c) { var r = c.getBoundingClientRect(); ct.push({x: r.left+r.width/2-wr.left, y: r.top-wr.top}); cb.push(r.bottom-wr.top); });
            if (!ct.length) return;
            var lx = Math.min.apply(null, ct.map(function(c){return c.x;}));
            var rx = Math.max.apply(null, ct.map(function(c){return c.x;}));
            var ty = lby + 14;
            var lcb = Math.max.apply(null, cb);
            sd.push({lcx:lcx, lby:lby, lty:lty, ty:ty, lx:lx, rx:rx, ct:ct, lcb:lcb});
            ac.push(lby, ty); ct.forEach(function(c){ac.push(c.y);});
        });
        if (!sd.length) return;
        for (var i=0;i<sd.length;i++) {
            var d=sd[i];
            sp.push('<line x1="'+d.lcx+'" y1="'+d.lby+'" x2="'+d.lcx+'" y2="'+d.ty+'" class="rm-tap"/>');
            sp.push('<line x1="'+d.lx+'" y1="'+d.ty+'" x2="'+d.rx+'" y2="'+d.ty+'" class="rm-tap"/>');
            d.ct.forEach(function(c){sp.push('<path d="M '+c.x+' '+d.ty+' L '+c.x+' '+c.y+'" class="rm-wire"/>');});
            if (i<sd.length-1) {
                var nd=sd[i+1];
                var st=d.lcb+10, sb=nd.lty-4;
                if (sb>st) { sp.push('<line x1="'+d.lcx+'" y1="'+st+'" x2="'+nd.lcx+'" y2="'+sb+'" class="rm-spine"/>'); ac.push(st,sb); }
            }
        }
        if (!sp.length) return;
        var mn=Math.min.apply(null,ac), mx=Math.max.apply(null,ac);
        var svg='<svg class="rm-wires" aria-hidden="true" overflow="hidden" width="'+sw.scrollWidth+'" height="'+(mx-mn+40)+'" viewBox="0 0 '+sw.scrollWidth+' '+(mx-mn+40)+'">'+sp.join('')+'</svg>';
        var old=sw.querySelector('.rm-wires'); if(old) old.remove();
        sw.insertAdjacentHTML('afterbegin', svg);
    }

    function checkAllDone() {
        if (generationComplete && typewriterQueue.length === 0 && topicQueue.length === 0 && !activeTopic && !isTyping) {
            doRedirect();
            return;
        }
        // Safety fallback: if completed but typewriter still going, wait then force redirect
        if (generationComplete && !completionTimer) {
            completionTimer = setTimeout(function() {
                if (generationComplete) doRedirect();
            }, 15000);
        }
    }

    function doRedirect() {
        if (!roadmapSlug) return;
        document.getElementById('rm-gen-msg').textContent = 'Roadmap generated!';
        document.getElementById('rm-progress-bar').style.width = '100%';
        document.getElementById('rm-progress-pct').textContent = '100%';
        document.getElementById('rm-progress-bar').classList.remove('progress-bar-animated');
        document.getElementById('rm-progress-bar').classList.remove('bg-primary');
        document.getElementById('rm-progress-bar').classList.add('bg-success');
        history.pushState({}, '', '/roadmaps/' + roadmapSlug);
        setTimeout(function() { window.location.href = '/roadmaps/' + roadmapSlug; }, 800);
    }

    function handleCompleted(msg) {
        generationComplete = true;
        roadmapSlug = msg.slug || null;
        document.getElementById('rm-gen-msg').textContent = 'Finalizing roadmap...';
        // Immediately check — if typewriter already drained, redirect now
        checkAllDone();
    }

    function handleError(msg) {
        activeIntervals.forEach(function(iv) { clearInterval(iv); });
        activeIntervals = [];
        typewriterQueue = [];
        topicQueue = [];
        activeTopic = null;
        isTyping = false;

        document.getElementById('rm-gen-msg').textContent = msg.message || 'Generation failed';
        document.getElementById('rm-progress-bar').classList.remove('progress-bar-animated');
        document.getElementById('rm-gen-msg').innerHTML += ' <a href="/roadmaps" class="text-primary ms-2">Go back</a>';
    }

    function escHtml(s) {
        if (!s) return '';
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
})();
</script>
