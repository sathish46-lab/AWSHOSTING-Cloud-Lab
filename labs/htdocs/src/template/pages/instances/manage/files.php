<?php
// /src/template/pages/instances/manage/files.php
// Real, DB-backed file manager (Copy-on-Write template inheritance).
// Content is rendered client-side by labs/workspace/js/files.js via AJAX.
$slug = $_GET['slug'] ?? '';
$codeServerUrl = $hdrCodeUrl ?? '';
?>
<div class="card blur border-0 rounded-4 p-4 shadow-lg mb-4" id="filesManager" data-slug="<?= htmlspecialchars($slug) ?>" data-code-server-url="<?= htmlspecialchars($codeServerUrl) ?>">
    <!-- Row 1: title (left) + Open in editor (right) -->
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
        <h5 class="fw-bold theme-text m-0 d-flex align-items-center gap-2">
            <i class='bx bx-folder-open fs-4'></i> Scaffolded files
            <span class="badge bg-primary rounded-pill px-2 py-1">editor: reaped</span>
        </h5>
        <div class="d-flex align-items-center gap-2">
            <button class="btn instance-action-btn btn-sm rounded-pill fw-bold px-3" data-refresh-files>
                <svg class="icon text-warning" style="height: 18px; width: 18px;">
                    <use xlink:href="/assets/icons/sprites/free.svg#cil-loop-circular"></use>
                </svg>
            </button>
            <button class="btn instance-action-btn instance-action-danger btn-sm rounded-pill fw-bold px-3" data-delete-file disabled>
                <i class='bx bx-trash'></i>
            </button>
            <button class="btn instance-action-btn instance-action-success btn-sm rounded-pill fw-bold px-3" data-save-file disabled>
                <i class='bx bx-save' style="font-size: 1.25rem;"></i>
            </button>
            <button class="btn btn-primary rounded-pill px-4 btn-sm fw-bold ms-1" data-editor-open>
                <i class='bx bx-code-alt'></i> Open in editor
            </button>
        </div>
    </div>

    <!-- Row 2: file tree (left) + file content (right) -->
    <div class="instance-file-tree">
        <div class="instance-file-sidebar">
            <div class="instance-sidebar-header">
                <span class="instance-sidebar-title">Explorer</span>
                <div class="d-flex align-items-center gap-1">
                    <button class="instance-sidebar-icon-btn" data-new-file title="New file">
                        <i class='bx bx-file'></i>
                    </button>
                    <button class="instance-sidebar-icon-btn" data-new-folder title="New folder">
                        <i class='bx bx-folder-plus'></i>
                    </button>
                    <button class="instance-sidebar-icon-btn" data-upload-file title="Upload asset">
                        <i class='bx bx-upload'></i>
                    </button>
                </div>
            </div>
            <input type="file" id="fileUploadInput" class="d-none" data-upload-input>
            <div class="instance-tree" id="fileTree" aria-live="polite">
                <div class="text-secondary small py-3 text-center">
                    <div class="spinner-border spinner-border-sm" role="status"></div>
                </div>
            </div>
        </div>
        <div class="instance-file-editor">
            <div class="instance-editor-header">
                <div class="d-flex align-items-center gap-2 min-w-0">
                    <i class='bx bx-file-blank text-editor-accent'></i>
                    <span class="instance-editor-filename text-truncate" id="editorFileName">No file selected</span>
                    <!-- <span class="instance-editor-lang ms-1" id="editorLang"></span> -->
                    <span class="instance-editor-modified d-none" id="editorModified" title="You have unsaved changes">
                        <span class="instance-editor-dot"></span> modified
                    </span>
                </div>
            </div>
            <div class="instance-editor-body">
                <textarea class="instance-code-editor" id="fileEditor" placeholder="# Select a file from the tree to view and edit" disabled spellcheck="false"></textarea>
                <div class="instance-editor-overlay d-none" id="editorOverlay">
                    <div class="text-center">
                        <i class='bx bx-file fs-1 opacity-50'></i>
                        <div class="mt-2" id="editorOverlayText">Select a file to view its contents</div>
                    </div>
                </div>
            </div>
            <div class="instance-editor-status">
                <div class="d-flex align-items-center gap-3" id="editorMeta"></div>
                <div class="instance-editor-stats ms-auto" id="editorStatus">Ln 1, Col 1 &nbsp;·&nbsp; 0 words</div>
            </div>
        </div>
    </div>

    <div class="text-secondary opacity-75 mt-3 small">
        Quick edits save straight to the template (and into its version history). For multi-file refactors, a terminal, or extensions use <strong>Open in editor</strong>—the full VS Code editor. Binary files can only be managed there.
    </div>
</div>
