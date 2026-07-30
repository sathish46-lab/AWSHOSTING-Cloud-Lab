/**
 * Instance File Manager (Copy-on-Write template inheritance)
 * Renders the real DB-backed file tree for the active instance's "Files" tab.
 * Works with the AJAX container rendered by
 *   src/template/pages/instances/manage/files.php
 *
 * Storage model:
 *   - Base layer  : seeded from lab-templates/<template>/ (read-only, shared)
 *   - User layer  : created copy-on-write on first edit (keyed by instance_id)
 *   Modified (user-overridden) files are flagged with a dot.
 */
(function () {
  "use strict";

  function initFilesManager() {
    const container = document.getElementById("filesManager");
    if (!container || container.dataset.initialized === "1") return;
    container.dataset.initialized = "1";

  const slug = container.dataset.slug;
  const treeEl = document.getElementById("fileTree");
  const editor = document.getElementById("fileEditor");
  const fileNameEl = document.getElementById("editorFileName");
  const langEl = document.getElementById("editorLang");
  const modifiedEl = document.getElementById("editorModified");
  const overlayEl = document.getElementById("editorOverlay");
  const overlayTextEl = document.getElementById("editorOverlayText");
  const statusEl = document.getElementById("editorStatus");
  const saveBtn = document.querySelector("[data-save-file]");
  const metaEl = document.getElementById("editorMeta");
  const newFileBtn = document.querySelector("[data-new-file]");
  const newFolderBtn = document.querySelector("[data-new-folder]");
  const openEditorBtn = document.querySelector("[data-editor-open]");
  const uploadBtn = document.querySelector("[data-upload-file]");
  const uploadInput = document.querySelector("[data-upload-input]");
  const refreshBtn = document.querySelector("[data-refresh-files]");
  const deleteBtn = document.querySelector("[data-delete-file]");

  // ---- CodeMirror editor (real IDE surface, forced dark) ---------------
  const cm = CodeMirror.fromTextArea(editor, {
    lineNumbers: true,
    theme: "material-darker",
    mode: "text/plain",
    indentUnit: 4,
    tabSize: 4,
    indentWithTabs: true,
    lineWrapping: false,
    readOnly: true,
    styleActiveLine: true,
    matchBrackets: true,
    autoCloseBrackets: true,
    extraKeys: { "Ctrl-S": saveFile, "Cmd-S": saveFile },
  });
  cm.setSize("100%", "100%");

  function modeFor(name) {
    const ext = (name.split(".").pop() || "").toLowerCase();
    const map = {
      php: "application/x-httpd-php",
      py: "text/x-python",
      sh: "text/x-sh",
      bash: "text/x-sh",
      yml: "text/x-yaml",
      yaml: "text/x-yaml",
      js: "text/javascript",
      json: "application/json",
      css: "text/css",
      html: "htmlmixed",
      htm: "htmlmixed",
      xml: "application/xml",
      md: "text/x-markdown",
      markdown: "text/x-markdown",
      dockerfile: "text/x-dockerfile",
      txt: "text/plain",
      rst: "text/plain",
      log: "text/plain",
    };
    if (map[ext]) return map[ext];
    if (name.toLowerCase() === "dockerfile") return "text/x-dockerfile";
    return "text/plain";
  }

  function langLabel(name) {
    const m = modeFor(name);
    return ({
      "application/x-httpd-php": "PHP",
      "text/x-python": "Python",
      "text/x-sh": "Shell",
      "text/x-yaml": "YAML",
      "text/javascript": "JavaScript",
      "application/json": "JSON",
      "text/css": "CSS",
      "htmlmixed": "HTML",
      "application/xml": "XML",
      "text/x-markdown": "Markdown",
      "text/x-dockerfile": "Dockerfile",
      "text/plain": "Plain",
    })[m] || "Text";
  }

  function updateStatus() {
    if (!statusEl) return;
    const line = cm.getCursor().line + 1;
    const col = cm.getCursor().ch + 1;
    const text = cm.getValue();
    const words = text.trim() ? text.trim().split(/\s+/).length : 0;
    statusEl.innerHTML =
      '<span class="text-info">Ln ' + line + '</span>, ' +
      '<span class="text-warning">Col ' + col + '</span> &nbsp;·&nbsp; ' +
      '<span class="text-success">' + words + ' words</span> &nbsp;·&nbsp; ' +
      '<span class="text-primary">UTF-8</span>';
  }

  cm.on("cursorActivity", updateStatus);
  cm.on("changes", updateStatus);

  function showOverlay(text, icon) {
    if (!overlayEl) return;
    overlayEl.classList.remove("d-none");
    if (overlayTextEl) {
      overlayTextEl.textContent = text;
    }
    const ic = overlayEl.querySelector("i");
    if (ic && icon) ic.className = icon + " fs-1 opacity-50";
  }

  function hideOverlay() {
    if (overlayEl) overlayEl.classList.add("d-none");
  }

  let activeFile = null; // { path, name, modified, version }
  let selectedNode = null; // { path, name, is_dir } — currently selected tree item
  let loadedContent = "";
  let lastOpenedPath = null; // from DB

  // ---- Helpers ---------------------------------------------------------
  const api = (path, opts) =>
    fetch(path, Object.assign({ headers: { "Accept": "application/json" } }, opts));

  function iconFor(node) {
    if (node.is_dir) return "bxs-folder";
    const ext = (node.name.split(".").pop() || "").toLowerCase();
    if (["png", "jpg", "jpeg", "gif", "webp", "svg"].includes(ext)) return "bxs-image";
    if (["sh", "py", "js", "php", "json", "yml", "yaml", "Dockerfile"].includes(ext)) return "bx-code-alt";
    if (["md", "txt", "rst", "log"].includes(ext)) return "bx-file-blank";
    return "bxs-file";
  }

  function humanSize(bytes) {
    if (!bytes) return "0 B";
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + " MB";
    if (bytes >= 1024) return Math.round(bytes / 1024) + " KB";
    return bytes + " B";
  }

  // ---- Tree rendering --------------------------------------------------
  function renderNode(node, depth) {
    const wrap = document.createElement("div");
    const padPx = depth * 18;

    if (node.is_dir) {
      const header = document.createElement("div");
      header.className = "instance-tree-item is-folder";
      header.setAttribute("data-path", node.path);
      header.style.paddingLeft = padPx + "px";
      header.innerHTML =
        '<i class="bx bx-chevron-right"></i> <i class="bx ' +
        iconFor(node) +
        ' text-secondary"></i> <span>' +
        escapeHtml(node.name) +
        "</span>";
      const childBox = document.createElement("div");
      childBox.className = "instance-tree-children";
      childBox.style.display = "none";
      (node.children || []).forEach((c) => childBox.appendChild(renderNode(c, depth + 1)));

      header.addEventListener("click", (e) => {
        const chevron = header.querySelector(".bx-chevron-right, .bx-chevron-down");
        const open = childBox.style.display !== "none";
        childBox.style.display = open ? "none" : "block";
        chevron.classList.toggle("bx-chevron-right", open);
        chevron.classList.toggle("bx-chevron-down", !open);

        treeEl.querySelectorAll(".instance-tree-item.is-active").forEach((el) => el.classList.remove("is-active"));
        header.classList.add("is-active");
        selectedNode = { path: node.path, name: node.name, is_dir: true };
        if (deleteBtn) deleteBtn.disabled = false;
      });
      wrap.appendChild(header);
      wrap.appendChild(childBox);
    } else {
      const item = document.createElement("div");
      item.className = "instance-tree-item";
      item.setAttribute("data-path", node.path);
      item.style.paddingLeft = (padPx + 4) + "px";
      item.innerHTML =
        '<i class="bx ' +
        iconFor(node) +
        ' text-info"></i> <span class="flex-grow-1">' +
        escapeHtml(node.name) +
        "</span>" +
        (node.modified ? '<span class="tree-modified-dot" title="You modified this file"></span>' : "") +
        ' <span class="small text-secondary opacity-50">' +
        humanSize(node.size) +
        "</span>";
      item.addEventListener("click", () => {
        treeEl.querySelectorAll(".instance-tree-item.is-active").forEach((el) => el.classList.remove("is-active"));
        item.classList.add("is-active");
        selectedNode = { path: node.path, name: node.name, is_dir: false };
        openFile(node.path, node.name, item);
      });
      wrap.appendChild(item);
    }
    return wrap;
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) =>
      ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])
    );
  }

  let treeData = [];

  async function loadTree() {
    treeEl.innerHTML =
      '<div class="text-secondary small py-3 text-center"><div class="spinner-border spinner-border-sm" role="status"></div></div>';
    try {
      const res = await api("/api/instances/files_tree?slug=" + encodeURIComponent(slug));
      const data = await res.json();
      if (data.status !== "success") {
        treeEl.innerHTML = '<div class="text-danger small p-2">' + escapeHtml(data.error || "Failed to load files") + "</div>";
        return;
      }
      treeEl.innerHTML = "";
      treeData = data.tree;
      lastOpenedPath = data.last_opened;
      if (!data.tree.length) {
        treeEl.innerHTML = '<div class="text-secondary small p-2">No files in this template.</div>';
        return;
      }
      data.tree.forEach((node) => treeEl.appendChild(renderNode(node, 0)));
      // Auto-expand ALL folders
      treeEl.querySelectorAll(".instance-tree-item.is-folder").forEach((h) => h.click());
      // Auto-open last file from DB
      if (lastOpenedPath) {
        const node = findNode(data.tree, lastOpenedPath);
        if (node && !node.is_dir) {
          const items = treeEl.querySelectorAll(".instance-tree-item:not(.is-folder)");
          for (const item of items) {
            const span = item.querySelector("span.flex-grow-1");
            if (span && span.textContent === node.name) {
              openFile(node.path, node.name, item);
              break;
            }
          }
        }
      }
    } catch (e) {
      treeEl.innerHTML = '<div class="text-danger small p-2">Network error loading files.</div>';
    }
  }

  // ---- File open / save ------------------------------------------------
  async function openFile(path, name, itemEl) {
    document.querySelectorAll(".instance-tree-item.is-active").forEach((e) => e.classList.remove("is-active"));
    if (itemEl) itemEl.classList.add("is-active");
    saveLastOpened(path);

    fileNameEl.textContent = path;
    if (langEl) langEl.textContent = langLabel(name);
    cm.setValue("Loading...");
    cm.setOption("readOnly", true);
    cm.getWrapperElement().classList.add("CodeMirror-readonly");
    saveBtn.disabled = true;
    metaEl.innerHTML = "";
    activeFile = null;
    if (modifiedEl) modifiedEl.classList.add("d-none");
    hideOverlay();

    try {
      const res = await api(
        "/api/instances/file_get?slug=" + encodeURIComponent(slug) + "&path=" + encodeURIComponent(path)
      );
      const data = await res.json();
      if (data.status !== "success") {
        cm.setValue("");
        fileNameEl.textContent = path + " (unavailable)";
        showOverlay(data.error || "Failed to load file", "bx bx-error-circle");
        return;
      }
      if (data.s3_key) {
        cm.setValue("");
        cm.setOption("readOnly", true);
        cm.getWrapperElement().classList.add("CodeMirror-readonly");
        saveBtn.disabled = true;
        if (deleteBtn) deleteBtn.disabled = false;
        activeFile = { path, name, modified: data.modified };
        metaEl.innerHTML =
          '<span class="badge bg-primary rounded-pill px-2 py-1">Binary file</span>' +
          ' <span class="small text-secondary">' + humanSize(data.size || 0) + "</span>";
        showOverlay("Binary file — manage via MinIO.", "bx bx-file-blank");
        updateStatus();
        return;
      }
      loadedContent = data.content ?? "";
      cm.setOption("mode", modeFor(name));
      cm.setValue(loadedContent);
      cm.setOption("readOnly", false);
      cm.getWrapperElement().classList.remove("CodeMirror-readonly");
      saveBtn.disabled = false;
      if (deleteBtn) deleteBtn.disabled = false;
      activeFile = { path, name, modified: data.modified };
      if (modifiedEl) modifiedEl.classList.toggle("d-none", !data.modified);
      metaEl.innerHTML = data.modified
        ? '<span class="badge bg-warning rounded-pill px-2 py-1">Modified by you</span>'
        : '<span class="badge bg-info rounded-pill px-2 py-1">Base template</span>';
      cm.focus();
      updateStatus();
    } catch (e) {
      cm.setValue("");
      fileNameEl.textContent = path + " (error)";
      showOverlay("Could not load this file.", "bx bx-error-circle");
    }
  }

  async function saveFile() {
    if (!activeFile || cm.getOption("readOnly")) return;
    const btn = saveBtn;
    const original = btn.innerHTML;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Saving...';
    btn.disabled = true;
    try {
      const fd = new FormData();
      fd.append("slug", slug);
      fd.append("path", activeFile.path);
      fd.append("content", cm.getValue());
      const res = await fetch("/api/instances/file_save", { method: "POST", body: fd });
      const data = await res.json();
      if (data.status === "success") {
        loadedContent = cm.getValue();
        activeFile.modified = true;
        if (modifiedEl) modifiedEl.classList.remove("d-none");
        metaEl.innerHTML =
          '<span class="badge bg-warning rounded-pill px-2 py-1">Modified by you</span>';
        showToast("Saved.", "success");
      } else {
        showToast("Save failed: " + (data.error || "unknown"), "danger");
      }
    } catch (e) {
      showToast("Network error while saving.", "danger");
    } finally {
      btn.innerHTML = original;
      btn.disabled = false;
    }
  }

  function showToast(msg, type) {
    if (window.TomNotify) {
      window.TomNotify.show(msg, "File Manager", type);
    }
  }

  // Save last opened file to DB
  async function saveLastOpened(path) {
    try {
      await fetch("/api/instances/save_last_opened_file", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ slug: slug, path: path }),
      });
    } catch (e) { /* ignore */ }
  }

  // Find a file node in the tree by path
  function findNode(tree, path) {
    for (const node of tree) {
      if (node.path === path) return node;
      if (node.is_dir && node.children) {
        const found = findNode(node.children, path);
        if (found) return found;
      }
    }
    return null;
  }

  // ---- Create new file / folder (inline VS Code style) -------------------
  function getSelectedFolderPath() {
    if (selectedNode && selectedNode.is_dir) return selectedNode.path;
    return "";
  }

  function createInlineInput(isDir) {
    const parentPath = getSelectedFolderPath();
    const parentEl = parentPath
      ? treeEl.querySelector('[data-path="' + CSS.escape(parentPath) + '"]')
      : null;
    const childContainer = parentEl ? parentEl.nextElementSibling : treeEl;
    if (!childContainer) return;

    const depth = parentPath ? (parentPath.split("/").length) : 0;
    const padPx = depth * 18;

    const inputWrap = document.createElement("div");
    inputWrap.className = "instance-tree-item instance-tree-input-wrap";
    inputWrap.style.paddingLeft = (padPx + 4) + "px";

    const iconClass = isDir ? "bxs-folder text-secondary" : "bx-file-blank text-info";
    inputWrap.innerHTML =
      '<i class="bx ' + iconClass + '"></i>' +
      '<input type="text" class="instance-tree-inline-input" placeholder="' +
      (isDir ? "Folder name" : "File name (e.g. scripts/setup.sh)") +
      '" autofocus>';

    childContainer.prepend(inputWrap);
    const input = inputWrap.querySelector("input");
    input.focus();

    function commit() {
      const val = input.value.trim().replace(/^\/+/, "");
      if (!val) { inputWrap.remove(); return; }
      const fullPath = parentPath ? parentPath + "/" + val : val;
      inputWrap.remove();
      doCreateNode(fullPath, isDir);
    }

    input.addEventListener("keydown", (e) => {
      if (e.key === "Enter") { e.preventDefault(); commit(); }
      if (e.key === "Escape") { inputWrap.remove(); }
    });
    input.addEventListener("blur", () => { if (inputWrap.parentNode) inputWrap.remove(); });
  }

  async function doCreateNode(fullPath, isDir) {
    const fd = new FormData();
    fd.append("slug", slug);
    fd.append("path", fullPath);
    fd.append("is_dir", isDir ? "1" : "0");
    try {
      const res = await fetch("/api/instances/file_create", { method: "POST", body: fd });
      const data = await res.json();
      if (data.status === "success") {
        showToast((isDir ? "Folder" : "File") + " created.", "success");
        appendNodeToTree(fullPath, isDir);
      } else {
        showToast("Create failed: " + (data.error || "exists?"), "danger");
      }
    } catch (e) {
      showToast("Network error.", "danger");
    }
  }

  function appendNodeToTree(fullPath, isDir) {
    const parts = fullPath.split("/");
    const name = parts.pop();
    let parentPath = parts.join("/");
    let container = treeEl;

    if (parentPath) {
      const parentEl = treeEl.querySelector('[data-path="' + CSS.escape(parentPath) + '"]');
      if (parentEl) {
        let childBox = parentEl.nextElementSibling;
        if (!childBox || !childBox.classList.contains("instance-tree-children")) {
          childBox = document.createElement("div");
          childBox.className = "instance-tree-children";
          childBox.style.display = "block";
          parentEl.parentNode.insertBefore(childBox, parentEl.nextSibling);
        }
        container = childBox;
        const chevron = parentEl.querySelector(".bx-chevron-right");
        if (chevron) {
          chevron.classList.remove("bx-chevron-right");
          chevron.classList.add("bx-chevron-down");
        }
      }
    }

    const depth = parentPath ? parentPath.split("/").length : 0;
    const padPx = depth * 18;
    const nodeData = { path: fullPath, name: name, is_dir: isDir, children: isDir ? [] : undefined, size: isDir ? undefined : 0 };
    const el = renderNode(nodeData, parentPath ? depth : 0);
    container.appendChild(el);

    if (isDir) {
      el.querySelector(".instance-tree-item").click();
      el.querySelector(".instance-tree-item").click();
    }
  }

  // ---- Upload asset ----------------------------------------------------
  function uploadFile() {
    if (!uploadInput) return;
    uploadInput.click();
  }

  if (uploadInput) {
    uploadInput.addEventListener("change", async () => {
      const file = uploadInput.files && uploadInput.files[0];
      if (!file) return;
      const fd = new FormData();
      fd.append("slug", slug);
      fd.append("path", file.name);
      fd.append("file", file);
      const btn = uploadBtn;
      const original = btn ? btn.innerHTML : "";
      if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Uploading...';
      }
      try {
        const res = await fetch("/api/instances/file_upload", { method: "POST", body: fd });
        const data = await res.json();
        if (data.status === "success") {
          showToast("Asset uploaded to your instance file store.", "success");
          appendNodeToTree(file.name, false);
        } else {
          showToast("Upload failed: " + (data.error || "unknown"), "danger");
        }
      } catch (e) {
        showToast("Network error while uploading.", "danger");
      } finally {
        if (btn) {
          btn.disabled = false;
          btn.innerHTML = original;
        }
        uploadInput.value = "";
      }
    });
  }

  // ---- Refresh tree + content -----------------------------------------
  function refreshFiles() {
    if (activeFile) openFile(activeFile.path, activeFile.name, null);
    loadTree();
  }

  // ---- Delete selected file or folder ----------------------------------
  async function deleteFile() {
    if (!selectedNode) return;
    const label = selectedNode.is_dir ? "folder" : "file";
    if (!confirm('Delete ' + label + ' "' + selectedNode.name + '"? This cannot be undone.')) return;
    const fd = new FormData();
    fd.append("slug", slug);
    fd.append("path", selectedNode.path);
    try {
      const res = await fetch("/api/instances/file_delete", { method: "POST", body: fd });
      const data = await res.json();
      if (data.status === "success") {
        showToast(selectedNode.name + " deleted.", "success");
        removeNodeFromTree(selectedNode.path);
        if (!selectedNode.is_dir && activeFile && activeFile.path === selectedNode.path) {
          activeFile = null;
          cm.setValue("");
          cm.setOption("readOnly", true);
          cm.getWrapperElement().classList.add("CodeMirror-readonly");
          fileNameEl.textContent = "No file selected";
          if (langEl) langEl.textContent = "";
          if (modifiedEl) modifiedEl.classList.add("d-none");
          metaEl.innerHTML = "";
          showOverlay("Select a file to view its contents", "bx bx-file");
          if (saveBtn) saveBtn.disabled = true;
        }
        selectedNode = null;
        if (deleteBtn) deleteBtn.disabled = true;
        updateStatus();
      } else {
        showToast("Delete failed: " + (data.error || "unknown"), "danger");
      }
    } catch (e) {
      showToast("Network error while deleting.", "danger");
    }
  }

  function removeNodeFromTree(path) {
    const el = treeEl.querySelector('[data-path="' + CSS.escape(path) + '"]');
    if (el) {
      const parent = el.parentNode;
      el.remove();
      if (parent && parent.classList.contains("instance-tree-children") && !parent.children.length) {
        parent.remove();
      }
    }
    const fileItem = treeEl.querySelector('.instance-tree-item:not(.is-folder) [data-path="' + CSS.escape(path) + '"]');
    if (fileItem) fileItem.closest(".instance-tree-item").remove();
  }

  // ---- Wire up ---------------------------------------------------------
  if (saveBtn) saveBtn.addEventListener("click", saveFile);
  if (newFileBtn) newFileBtn.addEventListener("click", () => createInlineInput(false));
  if (newFolderBtn) newFolderBtn.addEventListener("click", () => createInlineInput(true));
  if (uploadBtn) uploadBtn.addEventListener("click", uploadFile);
  if (refreshBtn) refreshBtn.addEventListener("click", refreshFiles);
  if (deleteBtn) deleteBtn.addEventListener("click", deleteFile);
  if (openEditorBtn) {
    openEditorBtn.addEventListener("click", () => {
      showToast("Opening full VS Code editor (code-server) for this instance...", "info");
      // TODO: wire to the instance's code-server URL once deployment exposes it.
    });
  }

  // Initial load
  loadTree();
  }

  // ---- Bootstrap -------------------------------------------------------
  // The Files tab is loaded via AJAX into #instanceTabsContent AFTER the
  // initial DOMContentLoaded, so we must also initialise when the container
  // appears dynamically.
  function tryInit() {
    const c = document.getElementById("filesManager");
    if (c && c.dataset.initialized !== "1") {
      initFilesManager();
    }
  }

  if (document.readyState !== "loading") {
    tryInit();
  }
  document.addEventListener("DOMContentLoaded", tryInit);

  // Watch for the Files tab being (re)injected via AJAX tab switching.
  // The tab content is replaced wholesale each time, so a fresh #filesManager
  // (without the data-initialized="1" flag) must be (re)initialised. We keep
  // observing so navigation back to the Files tab works without a full reload.
  if (window.MutationObserver) {
    const observer = new MutationObserver(() => tryInit());
    observer.observe(document.body, { childList: true, subtree: true });
  }
})();
