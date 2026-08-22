<?php
$slug = $_GET['slug'] ?? '';
if (empty($slug)) { header('Location: /roadmaps'); exit; }

$preloaded = null;
try {
    $user = Session::getUser();
    if ($user) {
        $db = DatabaseConnection::getDefaultDatabase();
        $roadmap = $db->ai_roadmaps->findOne([
            'slug' => $slug,
            '$or' => [
                ['user_id' => (int)$user->getUserId()],
                ['visibility' => 'public']
            ]
        ]);
        if ($roadmap) {
            function bsonDecode2($v) {
                if ($v instanceof MongoDB\Model\BSONArray) return iterator_to_array($v, false);
                if ($v instanceof MongoDB\Model\BSONDocument) return iterator_to_array($v, false);
                if (is_array($v)) return array_map('bsonDecode2', $v);
                return $v;
            }
            $preloaded = [
                'success' => true,
                'roadmap' => [
                    'id' => (string)$roadmap['_id'],
                    'title' => (string)($roadmap['title'] ?? ''),
                    'description' => (string)($roadmap['description'] ?? ''),
                    'level' => (string)($roadmap['level'] ?? 'Beginner'),
                    'hours' => (int)($roadmap['hours'] ?? 0),
                    'tags' => bsonDecode2($roadmap['tags'] ?? []),
                    'progress' => (int)($roadmap['progress'] ?? 0),
                ]
            ];
        }
    }
} catch (Exception $e) {}
?>

<script>window.__ROADMAP_PRELOADED=<?= json_encode($preloaded) ?>;</script>

<div id="roadmap-app" class="body d-flex flex-column flex-grow-1 w-100 position-relative roadmap-detail-page" data-slug="<?= htmlspecialchars($slug) ?>">

    <!-- HEADER BAR -->
    <div class="rm-header container-fluid blur px-4 py-2" id="rm-header" style="display:none;">
        <div class="roadmap-banner-headrow">
            <a href="/roadmaps" class="roadmap-banner-back">
                <svg class="icon icon-sm"><use xlink:href="/assets/icons/free.svg#cil-arrow-left"></use></svg>
            </a>
            <span class="roadmap-banner-title">
                <span id="rm-title" class="rm-title-text"></span>
                <span class="badge badge-soft-info" title="Generated with AI">AI</span>
            </span>
            <div class="roadmap-banner-actions">
                <span class="roadmap-progress-chip small" title="0% complete">
                    <span class="progress" style="width: 80px; height: 6px;">
                        <span class="progress-bar bg-success" id="rm-progress-bar" style="width:0%"></span>
                    </span>
                    <strong id="rm-progress-pct">0%</strong>
                </span>
            </div>
        </div>
        <div class="roadmap-banner-meta">
            <span class="text-truncate" id="rm-description" style="max-width: 60%;"></span>
            <span class="badge bg-secondary" id="rm-level"></span>
            <span class="badge bg-secondary" id="rm-hours"></span>
            <div id="rm-tags"></div>
        </div>
    </div>

    <!-- TWO-PANEL ROW -->
    <div class="rm-panels d-flex flex-grow-1 g-3 p-3" style="min-height:0;">

        <!-- LEFT PANEL -->
        <div id="roadmap-panel-left" class="rm-split-panel rm-left" style="width:calc(70% - 2px);">
            <div class="card blur" style="display:flex;flex-direction:column;">
                <div id="rm-legend-bar" class="rm-legend-bar flex-shrink-0">
                    <div class="rm-legend-toggle" id="rm-legend-toggle">
                        <span class="rm-legend-arrow">&#9654;</span>
                        Card legend
                    </div>
                    <div class="rm-legend-body" id="rm-legend-body">
                        <span class="rm-legend-chip rm-legend-chip-topic">Topic</span>
                        <span class="rm-legend-chip rm-legend-chip-milestone">Milestone</span>
                        <span class="rm-legend-chip rm-legend-chip-checkpoint">Task</span>
                        <span class="rm-legend-chip rm-legend-chip-note">Note</span>
                        <span class="rm-legend-chip rm-legend-chip-decision">Decision</span>
                        <span class="rm-legend-chip rm-legend-chip-project">Project</span>
                        <span class="rm-legend-chip rm-legend-chip-grouped">Grouped</span>
                    </div>
                </div>
                <div id="rm-left-content" class="rm-canvas" style="flex:1 1 0%;min-height:0;overflow-y:auto;padding:0.75rem 1rem;">
                    <div class="text-center py-5"><div class="spinner-border text-primary" role="status"></div></div>
                </div>
            </div>
        </div>

        <!-- GUTTER -->
        <div class="rm-gutter gutter gutter-horizontal"></div>

        <!-- RIGHT PANEL -->
        <div id="roadmap-panel-right" class="rm-split-panel" style="width:calc(30% - 2px);">
            <div class="card mb-0 blur" style="display:flex;flex-direction:column;">
                <div class="px-3 card-header roadmap-right-tabs flex-shrink-0">
                    <div style="flex:1;overflow-x:auto;">
                        <ul class="list-unstyled m-0 d-flex flex-row align-items-center gap-2">
                            <li role="button" class="panel-tab roadmap-panel-tab active" data-panel="roadmap-tab-chat">AI Assist</li>
                            <li role="button" class="panel-tab roadmap-panel-tab" data-panel="roadmap-tab-progress">Progress</li>
                        </ul>
                    </div>
                </div>
                <div class="flex-grow-1" style="min-height:0;">
                    <div id="roadmap-tab-chat" class="roadmap-tab-body h-100 d-flex flex-column" style="display:flex!important;">
                        <div class="flex-grow-1 overflow-auto hide-scrollbar p-3 d-flex flex-column gap-3" id="roadmap-chat-history">
                            <div class="d-flex h-100 align-items-center justify-content-center">
                                <img src="/assets/img/chatbot.png" style="width:200px;mix-blend-mode:exclusion;opacity:0.4;" alt="">
                            </div>
                        </div>
                        <div class="p-2 pb-3 flex-shrink-0">
                            <div class="unified-input-box simple-blur">
                                <textarea class="user-text-input" type="text" id="roadmap-chat-input" placeholder="Ask AI about this roadmap" rows="1" style="height:auto;resize:none;"></textarea>
                                <div class="token-ribbon">
                                    <div class="token-metrics" id="roadmap-chat-token-metrics">
                                        <span class="context-value" id="rm-token-context">0/1M</span>
                                        <span class="token-separator">|</span>
                                        <span class="token-metric">↑<span class="output-tokens" id="rm-token-output">0</span></span>
                                        <span class="token-metric cached-metric">□<span class="cached-tokens" id="rm-token-cached">0</span></span>
                                    </div>
                                    <button id="roadmap-chat-send" class="send-button">
                                        <svg class="nav-icon"><use xlink:href="/assets/icons/free.svg#cil-send"></use></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="roadmap-tab-progress" class="roadmap-tab-body h-100" style="display:none!important;">
                        <div class="h-100 d-flex flex-column">
                            <div class="px-3 py-2 flex-shrink-0 border-bottom" style="border-color:rgba(var(--cui-secondary-rgb),0.15)!important;">
                                <div class="d-flex align-items-center justify-content-between mb-1">
                                    <span class="small fw-semibold">Proof of Competence</span>
                                    <span class="small fw-bold" id="rm-progress-pct-tab">0%</span>
                                </div>
                                <div class="progress" style="height:6px;">
                                    <div class="progress-bar bg-success" id="rm-progress-bar-tab" style="width:0%"></div>
                                </div>
                                <div class="mt-1"><span class="text-body-secondary" style="font-size:0.68rem;" id="rm-progress-count">0 / 0 checkpoints declared</span></div>
                                <div class="mt-1 d-none" id="rm-progress-warning"></div>
                            </div>
                            <div class="flex-grow-1 overflow-auto px-2 py-2 mb-5" id="rm-progress-list">
                                <div class="text-center py-3"><div class="spinner-border spinner-border-sm text-primary" role="status"></div></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Declaration Modal (rendered by JS) -->
<div class="modal fade" id="rm-declare-modal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content blur ">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title fw-bold"><span class="text-decoration" style="color:var(--cui-warning);">Declare</span> Completion</h5>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="rm-declare-body"></div>
        </div>
    </div>
</div>

<script src="/assets/js/roadmaps.js?v=3"></script>
<style>
.rm-section-label {
    color: #1a1a1a !important;
}
</style>
<script>
(function(){
    var origDraw = window.RoadmapView && window.RoadmapView.drawWires;
    window.RoadmapView.drawWires = function() {
        var container = document.getElementById('rm-left-content');
        if (!container) return;

        var sectionsWrap = container.querySelector('.rm-sections');
        if (!sectionsWrap) return;

        var sections = container.querySelectorAll('.rm-section');
        if (!sections.length) return;

        var cRect = container.getBoundingClientRect();
        var st = container.scrollTop;
        var zm = (window.RoadmapView._zoomLevel || 100) / 100;

        function rY(el) { var r = el.getBoundingClientRect(); return (r.top - cRect.top + st) / zm; }
        function rX(el) { var r = el.getBoundingClientRect(); return (r.left - cRect.left) / zm; }

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
    };

    if (origDraw) {
        var tries = 0;
        (function retry() {
            tries++;
            var c = document.getElementById('rm-left-content');
            var secs = c ? c.querySelectorAll('.rm-section') : [];
            var ok = true;
            secs.forEach(function(s){ if(s.offsetHeight===0) ok=false; });
            if (ok || tries > 15) { window.RoadmapView.drawWires(); }
            else { requestAnimationFrame(retry); }
        })();
    }

    window.RoadmapView._zoomLevel = 100;
    window.RoadmapView._zoomTarget = 100;

    var leftPanel = document.getElementById('roadmap-panel-left');
    var zoomBadge = null;
    if (leftPanel) {
        zoomBadge = document.createElement('div');
        zoomBadge.className = 'rm-zoom-badge';
        zoomBadge.textContent = '100%';
        leftPanel.style.position = 'relative';
        leftPanel.appendChild(zoomBadge);
    }

    var zoomAnimId = null;
    function zoomTick() {
        var cur = window.RoadmapView._zoomLevel;
        var tgt = window.RoadmapView._zoomTarget;
        if (Math.abs(cur - tgt) < 0.5) {
            window.RoadmapView._zoomLevel = tgt;
            zoomAnimId = null;
        } else {
            window.RoadmapView._zoomLevel = Math.round(cur + (tgt - cur) * 0.1);
            zoomAnimId = requestAnimationFrame(zoomTick);
        }
        var el = document.getElementById('rm-left-content');
        if (el) el.style.zoom = (window.RoadmapView._zoomLevel / 100);
        if (zoomBadge) zoomBadge.textContent = window.RoadmapView._zoomLevel + '%';
        window.RoadmapView.drawWires();
    }

    window.RoadmapView._applyZoom = function() {
        if (!zoomAnimId) zoomAnimId = requestAnimationFrame(zoomTick);
    };

    if (leftPanel) {
        leftPanel.addEventListener('wheel', function(e) {
            if (!e.ctrlKey && !e.metaKey) return;
            e.preventDefault();
            var t = window.RoadmapView._zoomTarget;
            t += e.deltaY < 0 ? 5 : -5;
            t = Math.max(70, Math.min(140, t));
            window.RoadmapView._zoomTarget = t;
            window.RoadmapView._applyZoom();
        }, { passive: false });
    }

    var legendToggle = document.getElementById('rm-legend-toggle');
    var legendBody = document.getElementById('rm-legend-body');
    if (legendToggle && legendBody) {
        legendToggle.addEventListener('click', function() {
            legendToggle.classList.toggle('open');
            legendBody.classList.toggle('open');
        });
    }
})();
</script>
<script>
document.querySelectorAll('.roadmap-panel-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.roadmap-panel-tab').forEach(function(t) { t.classList.remove('active'); });
        tab.classList.add('active');
        document.querySelectorAll('.roadmap-tab-body').forEach(function(b) {
            b.style.setProperty('display', 'none', 'important');
        });
        var target = document.getElementById(tab.dataset.panel);
        target.style.removeProperty('display');
        if (tab.dataset.panel === 'roadmap-tab-progress' && window.RoadmapView && window.RoadmapView.data) {
            window.RoadmapView.renderRight();
        }
    });
});
</script>
