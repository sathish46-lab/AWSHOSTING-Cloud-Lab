<?php
/**
 * Roadmaps - Declare Form API
 * Returns raw HTML for the declaration dialog (like SNA pattern)
 * GET: ?roadmap_id=X&topic_id=Y&item_id=Z
 */
require_once __DIR__ . '/../../../vendor/autoload.php';
require_once __DIR__ . '/../../../src/utils/config.php';
require_once __DIR__ . '/../../../src/lib/core/DatabaseConnection.class.php';

header('Content-Type: text/html; charset=utf-8');

if (Session::getAuthStatus() !== Constants::STATUS_LOGGEDIN) {
    echo '<div class="text-center py-4 text-danger">Unauthorized</div>';
    exit;
}

$user = Session::getUser();
$userId = (int)$user->getUserId();
$username = $user->getUsername() ?? 'User';

$roadmapId = trim($_GET['roadmap_id'] ?? '');
$topicId = trim($_GET['topic_id'] ?? '');
$itemId = trim($_GET['item_id'] ?? '');

if (empty($roadmapId) || empty($topicId) || empty($itemId)) {
    echo '<div class="text-center py-4 text-danger">Missing parameters</div>';
    exit;
}

$db = DatabaseConnection::getDefaultDatabase();

// Get roadmap
$roadmap = $db->ai_roadmaps->findOne([
    '_id' => new MongoDB\BSON\ObjectId($roadmapId),
    '$or' => [
        ['user_id' => $userId],
        ['visibility' => 'public']
    ]
]);

if (!$roadmap) {
    echo '<div class="text-center py-4 text-danger">Roadmap not found</div>';
    exit;
}

function bsonDecode($v) {
    if ($v instanceof MongoDB\Model\BSONArray) return iterator_to_array($v, false);
    if ($v instanceof MongoDB\Model\BSONDocument) return iterator_to_array($v, false);
    if (is_array($v)) return array_map('bsonDecode', $v);
    return $v;
}

// Find item details
$itemText = '';
$itemType = 'checkpoint';
$sectionTitle = '';
$sections = bsonDecode($roadmap['sections'] ?? []);

foreach ($sections as $section) {
    $secTitle = $section['title'] ?? '';
    $topics = bsonDecode($section['topics'] ?? []);
    foreach ($topics as $topic) {
        if (($topic['id'] ?? '') === $topicId) {
            $sectionTitle = $secTitle;
            $items = bsonDecode($topic['items'] ?? []);
            foreach ($items as $item) {
                if (($item['id'] ?? '') === $itemId) {
                    $itemText = $item['text'] ?? $item['title'] ?? '';
                    $itemType = $item['type'] ?? 'checkpoint';
                    break 3;
                }
            }
        }
    }
}

// Check if already declared
$existingProgress = $db->ai_roadmap_progress->findOne([
    'user_id' => $userId,
    'roadmap_id' => new MongoDB\BSON\ObjectId($roadmapId),
    'topic_id' => $topicId,
]);

$completedItems = [];
$declarations = [];
if ($existingProgress) {
    $completedItems = bsonDecode($existingProgress['completed_items'] ?? []);
    $declarations = bsonDecode($existingProgress['declarations'] ?? []);
}

$isDeclared = in_array($itemId, $completedItems);

// Find latest declaration for this item
$latestDeclaration = null;
foreach (array_reverse($declarations) as $decl) {
    if (($decl['item_id'] ?? '') === $itemId) {
        $latestDeclaration = $decl;
        break;
    }
}

$badgeClass = 'badge-' . ($itemType ?: 'checkpoint');
$badgeLabel = ucfirst($itemType ?: 'Checkpoint');

// Render HTML
if ($isDeclared && $latestDeclaration) {
    $declaredAt = $latestDeclaration['declared_at'] ?? null;
    $dateStr = '';
    if ($declaredAt) {
        if ($declaredAt instanceof MongoDB\BSON\UTCDateTime) {
            $dateStr = date('M d, Y \a\t g:i A', (int)($declaredAt->toDateTime()->format('U')));
        } else {
            $dateStr = (string)$declaredAt;
        }
    }
    $evidenceCount = count($latestDeclaration['evidence'] ?? []);
    $notes = $latestDeclaration['notes'] ?? '';
    ?>
    <div class="declaration-dialog" data-node-id="<?= htmlspecialchars($itemId) ?>" data-node-type="<?= htmlspecialchars($itemType) ?>" data-roadmap-id="<?= htmlspecialchars($roadmapId) ?>">

        <!-- Section 1: What you're proving -->
        <div class="rm-declare-info p-3 mb-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="rm-progress-badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span>
                <span class="text-body-secondary small"><?= htmlspecialchars($sectionTitle) ?></span>
            </div>
            <h6 class="mb-1 fw-semibold"><?= htmlspecialchars($itemText) ?></h6>
            <p class="small text-body-secondary mb-0">This checkpoint verifies specific skills or tasks. Submit evidence showing you can perform the described competencies.</p>
        </div>

        <!-- Declaration Submitted status -->
        <div class="rm-declare-info p-3 mb-3" style="border-color:rgba(16,185,129,0.3);background:rgba(16,185,129,0.05);">
            <div class="d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <i class="bx bx-check-circle text-success" style="font-size:1.3rem;"></i>
                    <span class="fw-bold text-success">Declaration Submitted</span>
                </div>
                <span class="text-body-secondary small"><?= $dateStr ?></span>
            </div>
            <?php if ($evidenceCount > 0): ?>
            <div class="mt-2 small text-body-secondary">
                <i class="bx bx-paperclip me-1"></i><?= $evidenceCount ?> evidence file<?= $evidenceCount > 1 ? 's' : '' ?> attached
            </div>
            <?php endif; ?>
            <?php if (!empty($notes)): ?>
            <div class="mt-1 small text-body-secondary">
                <i class="bx bx-note me-1"></i><?= htmlspecialchars($notes) ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- Re-submit section (collapsed) -->
        <div class="mb-3">
            <details class="rm-resubmit-section">
                <summary class="small fw-semibold" style="cursor:pointer;color:var(--cui-primary);">
                    <i class="bx bx-chevron-right me-1"></i>Re-submit with new evidence
                </summary>
                <div class="mt-3" id="rm-resubmit-form">
                    <!-- PDF upload zone -->
                    <div class="rm-declare-upload-zone mb-2" id="rm-declare-drop-zone">
                        <div class="text-center py-3">
                            <i class="bx bx-cloud-upload text-body-secondary mb-1" style="font-size:1.5rem;"></i>
                            <div class="small text-body-secondary">Drop PDF here or <label for="rm-declare-file-input" class="text-primary" style="cursor:pointer;">browse</label></div>
                            <div class="text-body-secondary" style="font-size:0.65rem;">PDF only, max 10 MB</div>
                        </div>
                        <input type="file" id="rm-declare-file-input" accept=".pdf,application/pdf" multiple hidden>
                    </div>
                    <!-- URL input -->
                    <div class="input-group input-group-sm mb-2">
                        <span class="input-group-text"><i class="bx bx-link" style="font-size:0.85rem;"></i></span>
                        <input type="url" class="form-control" id="rm-declare-url-input" placeholder="https://github.com/your-project or any public URL">
                        <button class="btn btn-outline-primary" type="button" id="rm-declare-add-url-btn">Add</button>
                    </div>
                    <div id="rm-declare-evidence-list" class="d-flex flex-column gap-1"></div>
                    <!-- Notes -->
                    <div class="mt-2">
                        <textarea class="form-control form-control-sm" id="rm-declare-notes" rows="2" placeholder="Additional notes..."></textarea>
                    </div>
                    <div class="text-end mt-2">
                        <button class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold" id="rm-declare-submit-btn"
                            data-roadmap="<?= htmlspecialchars($roadmapId) ?>"
                            data-topic="<?= htmlspecialchars($topicId) ?>"
                            data-item="<?= htmlspecialchars($itemId) ?>">
                            <i class="bx bx-send me-1"></i>Update Declaration
                        </button>
                    </div>
                </div>
            </details>
        </div>
    </div>
    <?php
} else {
    ?>
    <div class="declaration-dialog" data-node-id="<?= htmlspecialchars($itemId) ?>" data-node-type="<?= htmlspecialchars($itemType) ?>" data-roadmap-id="<?= htmlspecialchars($roadmapId) ?>">

        <!-- Section 1: What you're proving -->
        <div class="rm-declare-info p-3 mb-3">
            <div class="d-flex align-items-center gap-2 mb-2">
                <span class="rm-progress-badge <?= $badgeClass ?>"><?= htmlspecialchars($badgeLabel) ?></span>
                <span class="text-body-secondary small"><?= htmlspecialchars($sectionTitle) ?></span>
            </div>
            <h6 class="mb-1 fw-semibold"><?= htmlspecialchars($itemText) ?></h6>
            <p class="small text-body-secondary mb-0">This checkpoint verifies specific skills or tasks. Submit evidence showing you can perform the described competencies.</p>
        </div>

        <!-- Section 2: Evidence upload -->
        <div class="mb-3">
            <h6 class="d-flex align-items-center gap-2 mb-2"><i class="bx bx-cloud-upload"></i> Evidence</h6>
            <p class="small text-body-secondary mb-2">
                Upload PDF documents or provide URLs to your work.
                Evidence is optional, but reaching 100% requires at least 50% of checkpoints to have evidence attached.
                <span class="text-body-tertiary">(Without evidence, progress caps at 50%.)</span>
            </p>

            <!-- PDF upload zone -->
            <div class="rm-declare-upload-zone mb-2" id="rm-declare-drop-zone">
                <div class="text-center py-3">
                    <i class="bx bx-file text-body-secondary mb-1" style="font-size:1.5rem;"></i>
                    <div class="small text-body-secondary">Drop PDF here or <label for="rm-declare-file-input" class="text-primary" style="cursor:pointer;">browse</label></div>
                    <div class="text-body-secondary" style="font-size:0.65rem;">PDF only, max 10 MB</div>
                </div>
                <input type="file" id="rm-declare-file-input" accept=".pdf,application/pdf" multiple hidden>
            </div>

            <!-- URL input -->
            <div class="input-group input-group-sm mb-2">
                <span class="input-group-text"><i class="bx bx-link" style="font-size:0.85rem;"></i></span>
                <input type="url" class="form-control" id="rm-declare-url-input" placeholder="https://github.com/your-project or any public URL">
                <button class="btn btn-outline-primary" type="button" id="rm-declare-add-url-btn">Add</button>
            </div>

            <!-- Evidence list -->
            <div id="rm-declare-evidence-list" class="d-flex flex-column gap-1"></div>
        </div>

        <!-- Notes -->
        <div class="mb-3">
            <label class="form-label small fw-semibold" for="rm-declare-notes">Notes <span class="text-body-secondary fw-normal">(optional)</span></label>
            <textarea class="form-control form-control-sm" id="rm-declare-notes" rows="2" placeholder="Any additional context about your submission..."></textarea>
        </div>

        <!-- Section 3: Self-declaration (shown only when evidence attached) -->
        <div class="rm-declare-attestation p-3 mb-3 d-none" id="rm-declare-attestation">
            <h6 class="d-flex align-items-center gap-2 mb-2"><i class="bx bx-shield-quarter"></i> Self-Declaration</h6>
            <div class="small mb-2" style="line-height:1.5;">
                I, <strong><?= htmlspecialchars($username) ?></strong>, hereby declare that the evidence submitted is my own original work and accurately represents my competency in the described area. I understand that submitting fraudulent or plagiarized evidence may result in revocation of this declaration and further disciplinary action.
            </div>
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="rm-declare-agree-check">
                <label class="form-check-label small fw-semibold" for="rm-declare-agree-check">I agree to the above declaration</label>
            </div>
            <div class="text-body-secondary mt-1" style="font-size:0.6rem;">This declaration will be timestamped and recorded with your identity for institutional records.</div>
        </div>

        <!-- Submit -->
        <div class="d-flex justify-content-end">
            <button class="btn btn-primary rounded-pill px-4 fw-semibold" id="rm-declare-submit-btn"
                data-roadmap="<?= htmlspecialchars($roadmapId) ?>"
                data-topic="<?= htmlspecialchars($topicId) ?>"
                data-item="<?= htmlspecialchars($itemId) ?>">
                <i class="bx bx-send me-1"></i>Submit Declaration
            </button>
        </div>
    </div>
    <?php
}
