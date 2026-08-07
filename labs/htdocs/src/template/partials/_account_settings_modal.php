<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- Account Settings Modal — Global overlay, triggered from header  -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<?php
$currentUser = Session::getUser();
$currentUserAvatar = Session::getAvatar();
$currentUserUsername = $currentUser?->getUsername() ?? '';
$currentUserEmail = $currentUser?->getEmail() ?? '';
$currentFirstName = $currentUser?->getFirstName() ?? '';
$currentUserLastName = $currentUser?->getLastName() ?? '';
$currentUserRole = $currentUser?->getRole() ?? 'user';
?>
<div class="modal fade" id="accountSettingsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content blur shadow-lg rounded-4 border-0" style="border: 1px solid rgba(255,255,255,0.08) !important;">
            <div class="modal-header border-0 pt-3 px-4 pb-0">
                <h4 class="modal-title fw-bold fs-6">Account settings</h4>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex" style="min-height: 420px;">

                <!-- ══ Left: Vertical Tab Nav ══ -->
                <div class="acct-settings-nav d-flex flex-column p-2" style="min-width: 180px; border-right: 1px solid rgba(255,255,255,0.06);">
                    <button class="acct-tab-btn active" data-tab="acct-tab-account" type="button">
                        <i class="bx bx-user"></i> Account
                    </button>
                    <button class="acct-tab-btn" data-tab="acct-tab-limits" type="button">
                        <i class="bx bx-bar-chart-alt-2"></i> Account Limits
                    </button>
                    <button class="acct-tab-btn" data-tab="acct-tab-storage" type="button">
                        <i class="bx bx-hdd"></i> Storage
                    </button>
                    <button class="acct-tab-btn" data-tab="acct-tab-ssh" type="button">
                        <i class="bx bx-key"></i> SSH Keys
                    </button>
                    <button class="acct-tab-btn" data-tab="acct-tab-security" type="button">
                        <i class="bx bx-shield-quarter"></i> Security
                    </button>
                    <button class="acct-tab-btn" data-tab="acct-tab-appearance" type="button">
                        <i class="bx bx-palette"></i> Appearance
                    </button>
                </div>

                <!-- ══ Right: Tab Content ══ -->
                <div class="flex-grow-1 p-3 overflow-auto" style="max-height: 420px;">

                    <!-- ── Account Tab ── -->
                    <div class="acct-tab-pane active" id="acct-tab-account">
                        <div class="d-flex align-items-center gap-3 mb-2">
                            <div class="position-relative flex-shrink-0">
                                <img id="acctSettingsAvatar" src="<?= $currentUserAvatar ?>" class="rounded-circle shadow-sm" width="56" height="56" style="object-fit: cover;">
                                <button type="button" class="btn btn-sm btn-primary rounded-circle position-absolute bottom-0 end-0 shadow dropdown-toggle no-caret" style="width:24px;height:24px;font-size:0.6rem;" title="Change Avatar" data-coreui-toggle="dropdown">
                                    <i class='bx bx-camera'></i>
                                </button>
                                <ul class="dropdown-menu shadow-sm border-0">
                                    <li><a class="dropdown-item py-2" href="#" onclick="document.getElementById('acctAvatarUpload').click(); return false;"><i class="bx bx-upload me-2 text-primary"></i> Upload</a></li>
                                </ul>
                                <input type="file" id="acctAvatarUpload" class="d-none" accept="image/png,image/jpeg,image/gif,image/webp">
                            </div>
                            <div class="min-w-0">
                                <div class="fw-semibold fs-5 text-truncate" id="acctSettingsDisplayName"><?= htmlspecialchars($currentFirstName . ' ' . $currentUserLastName) ?: $currentUserUsername ?></div>
                                <div class="small text-body-secondary">@<?= htmlspecialchars($currentUserUsername) ?></div>
                                <div class="small text-body-secondary text-truncate"><?= htmlspecialchars($currentUserEmail) ?></div>
                            </div>
                            <?php if ($currentUserRole === 'superuser'): ?>
                            <span class="ms-auto align-self-start badge" style="background: rgba(var(--cui-primary-rgb,103,115,120),0.15); color: var(--cui-primary, #677378);">Pro</span>
                            <?php endif; ?>
                        </div>
                        <div class="text-body-secondary small mb-2">Your sign-in identity (name, avatar, email) is managed in your account.</div>

                        <!-- Profile Edit Form -->
                        <form id="acctProfileForm" class="mb-2">
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-secondary mb-1">First Name</label>
                                    <input type="text" class="form-control form-control-sm" name="first_name" value="<?= htmlspecialchars($currentFirstName) ?>" maxlength="50" placeholder="First name">
                                </div>
                                <div class="col-6">
                                    <label class="form-label small fw-bold text-secondary mb-1">Last Name</label>
                                    <input type="text" class="form-control form-control-sm" name="last_name" value="<?= htmlspecialchars($currentUserLastName) ?>" maxlength="50" placeholder="Last name">
                                </div>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="submit" class="btn btn-sm btn-primary rounded-pill px-3" id="acctProfileSaveBtn">
                                    <i class="bx bx-check me-1"></i> Save
                                </button>
                                <span class="small text-success d-none" id="acctProfileSaved"><i class="bx bx-check-circle me-1"></i>Saved!</span>
                                <span class="small text-danger d-none" id="acctProfileError"></span>
                            </div>
                        </form>

                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <a class="btn btn-sm btn-outline-primary rounded-pill" href="/<?= htmlspecialchars($currentUserUsername) ?>"><i class="bx bx-user me-1"></i> View my profile</a>
                            <a class="btn btn-sm btn-outline-secondary rounded-pill" href="/account"><i class="bx bx-bar-chart-alt-2 me-1"></i> Activity & Analytics</a>
                        </div>
                        <hr class="border-secondary border-opacity-10">
                        <div class="fw-semibold small text-body-secondary text-uppercase mb-1">Shortcuts</div>
                        <div class="list-group list-group-flush">
                            <a class="list-group-item list-group-item-action bg-transparent d-flex align-items-center gap-2 rounded-2 px-2 py-1" href="javascript:void(0)" onclick="document.querySelector('[data-tab=acct-tab-limits]').click()">
                                <i class="bx bx-bar-chart-alt-2 text-secondary"></i><span>Account Limits</span>
                                <i class="bx bx-chevron-right ms-auto text-secondary small"></i>
                            </a>
                            <a class="list-group-item list-group-item-action bg-transparent d-flex align-items-center gap-2 rounded-2 px-2 py-1" href="/devices">
                                <i class="bx bx-desktop text-secondary"></i><span>My Devices</span>
                                <i class="bx bx-chevron-right ms-auto text-secondary small"></i>
                            </a>
                            <a class="list-group-item list-group-item-action bg-transparent d-flex align-items-center gap-2 rounded-2 px-2 py-1" href="javascript:void(0)" onclick="document.querySelector('[data-tab=acct-tab-ssh]').click()">
                                <i class="bx bx-key text-secondary"></i><span>SSH Keys</span>
                                <i class="bx bx-chevron-right ms-auto text-secondary small"></i>
                            </a>
                        </div>
                    </div>

                    <!-- ── Account Limits Tab ── -->
                    <div class="acct-tab-pane" id="acct-tab-limits">
                        <div class="text-body-secondary small mb-3">Your plan's resource ceilings and how much of each you're using.</div>

                        <div class="acct-section-header">Platform</div>
                        <div class="mb-2" id="acctLimitsPlatform"></div>

                        <div class="acct-section-header">Storage</div>
                        <div class="mb-1" id="acctLimitsStorage"></div>
                        <div class="form-text small mb-2">A hard limit — over it, existing files stay readable but new writes are blocked.</div>

                        <div class="acct-section-header">Services</div>
                        <div class="mb-1" id="acctLimitsServices"></div>
                    </div>

                    <!-- ── Storage Tab ── -->
                    <div class="acct-tab-pane" id="acct-tab-storage">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <div class="fw-semibold small text-body-secondary text-uppercase">Where your data lives</div>
                            <span class="badge rounded-pill" style="background:rgba(var(--cui-info-rgb,13,202,240),0.15);color:var(--cui-info,#0dc2f0);font-size:0.65rem;" id="acctStoragePoolBadge">pool: default · xfs</span>
                        </div>
                        <div class="mb-3" id="acctStorageBreakdown"></div>
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="openStorageLens()">
                                <i class="bx bx-search me-1"></i> Storage Lens
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill" onclick="refreshAccountStorage()">
                                <i class="bx bx-refresh me-1"></i> Re-scan
                            </button>
                        </div>
                    </div>

                    <!-- ── SSH Keys Tab ── -->
                    <div class="acct-tab-pane" id="acct-tab-ssh">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="fw-semibold">Platform keys <span class="fw-normal text-body-secondary ms-1 small">(stored in Labs)</span></div>
                            <button type="button" class="btn btn-sm btn-primary rounded-pill" onclick="document.getElementById('acctAddKeySection').classList.toggle('d-none')">
                                <i class="bx bx-plus me-1"></i> Add key
                            </button>
                        </div>
                        <!-- Add Key Form -->
                        <div id="acctAddKeySection" class="d-none mb-3 p-3 rounded-4" style="background: rgba(var(--cui-info-rgb,13,202,240),0.06); border: 1px solid rgba(var(--cui-info-rgb,13,202,240),0.15);">
                            <form id="acctSshAddForm">
                                <div class="row g-2 mb-2">
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-secondary mb-1">Key Label</label>
                                        <input type="text" class="form-control form-control-sm" name="title" required placeholder="e.g. MacBook">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small fw-bold text-secondary mb-1">Expiration</label>
                                        <input type="date" class="form-control form-control-sm" name="expiration_date">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small fw-bold text-secondary mb-1">Key Content</label>
                                        <textarea class="form-control form-control-sm font-monospace" name="key" rows="3" required placeholder="ssh-rsa ..."></textarea>
                                    </div>
                                </div>
                                <div class="d-flex gap-2 justify-content-end">
                                    <button type="button" class="btn btn-sm btn-secondary px-3 rounded-pill" onclick="document.getElementById('acctAddKeySection').classList.add('d-none')">Cancel</button>
                                    <button type="submit" class="btn btn-sm btn-warning fw-bold px-3 text-dark rounded-pill">Save Key</button>
                                </div>
                            </form>
                        </div>
                        <div id="acctPlatformKeys"><div class="text-center py-3 text-secondary small"><i class="bx bx-loader-alt bx-spin"></i> Loading keys...</div></div>

                        <hr class="border-secondary border-opacity-10 my-3">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
                            <div class="fw-semibold">GitLab keys <span class="fw-normal text-body-secondary ms-1 small">(mirror — read-only)</span></div>
                        </div>
                        <div id="acctGitlabKeys"><div class="text-center py-3 text-secondary small">No GitLab keys configured.</div></div>
                        <div class="form-text mt-2 small">By default every enabled key is installed into <code>authorized_keys</code> on deploy.</div>
                    </div>

                    <!-- ── Security Tab ── -->
                    <div class="acct-tab-pane" id="acct-tab-security">
                        <!-- 2FA Section -->
                        <div class="fw-semibold mb-2">Two-Factor Authentication</div>
                        <div class="d-flex align-items-center justify-content-between mb-3 p-3 rounded-3" style="background: rgba(var(--cui-success-rgb,40,167,69),0.08); border: 1px solid rgba(var(--cui-success-rgb,40,167,69),0.15);">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bx bx-shield-quarter text-success fs-5"></i>
                                <div>
                                    <div class="fw-semibold small" id="acct2faStatus">Loading...</div>
                                    <div class="text-body-secondary small" style="font-size:0.75rem;">Adds a second layer of security to your account.</div>
                                </div>
                            </div>
                            <button type="button" class="btn btn-sm rounded-pill px-3 d-none" id="acct2faToggleBtn"></button>
                        </div>
                        <!-- 2FA OTP Section (hidden by default) -->
                        <div class="d-none mb-3 p-3 rounded-3" id="acct2faOtpSection" style="background: rgba(var(--cui-info-rgb,13,202,240),0.08); border: 1px solid rgba(var(--cui-info-rgb,13,202,240),0.15);">
                            <div class="d-flex align-items-center justify-content-between mb-2">
                                <div class="fw-semibold small">Enter OTP</div>
                                <div class="small text-body-secondary" id="acct2faTimer">01:00</div>
                            </div>
                            <div class="input-group input-group-sm mb-2" style="max-width: 250px;">
                                <input type="text" class="form-control font-monospace text-center" id="acct2faOtpInput" maxlength="6" placeholder="------" autocomplete="one-time-code" inputmode="numeric" pattern="[0-9]*">
                                <button type="button" class="btn btn-primary" id="acct2faVerifyBtn">Verify</button>
                            </div>
                            <div class="d-flex align-items-center gap-2">
                                <button type="button" class="btn btn-sm btn-link text-secondary p-0 d-none" id="acct2faResendBtn">Resend OTP</button>
                                <span class="small text-danger d-none" id="acct2faError"></span>
                                <span class="small text-success d-none" id="acct2faSuccess"></span>
                            </div>
                        </div>

                        <hr class="border-secondary border-opacity-10 my-3">

                        <!-- Sessions Section -->
                        <div class="fw-semibold mb-2">Active sessions <span class="fw-normal text-body-secondary ms-1 small" id="acctSessionCount">(1)</span></div>
                        <div id="acctActiveSessions"><div class="text-center py-3 text-secondary small"><i class="bx bx-loader-alt bx-spin"></i></div></div>

                        <div class="fw-semibold small text-body-secondary text-uppercase mt-3 mb-2">Recent logins</div>
                        <div id="acctRecentLogins"></div>
                        <div class="form-text mt-2 small">"Log out" ends that session on its next request. Sessions are tracked from your logins; older history is pruned automatically.</div>

                        <div class="fw-semibold small text-body-secondary text-uppercase mt-3 mb-2">MCP clients <span class="fw-normal text-body-secondary ms-1">(0)</span></div>
                        <div class="text-body-secondary small">No MCP client is signed in right now. <a href="/mcp">Connect one</a>.</div>
                    </div>

                    <!-- ── Appearance Tab ── -->
                    <div class="acct-tab-pane" id="acct-tab-appearance">
                        <div class="d-flex align-items-center justify-content-between gap-3 py-2">
                            <div>
                                <div class="fw-semibold">Visual blur</div>
                                <div class="small text-body-secondary">Frosted-glass panels across the dashboard.</div>
                            </div>
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" role="switch" id="acctBlurToggle" <?= ($uiPreferences['visual_blur'] ?? 'true') !== 'false' ? 'checked' : '' ?>>
                            </div>
                        </div>
                        <hr class="my-2 border-secondary border-opacity-10">
                        <div class="py-2">
                            <div class="fw-semibold mb-2">Color mode</div>
                            <div class="btn-group" role="group">
                                <button type="button" class="btn btn-outline-secondary btn-sm acct-mode-btn <?= ($serverTheme['theme'] ?? 'dark') === 'light' ? 'active' : '' ?>" data-mode="light"><i class="bx bx-sun me-1"></i> Light</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm acct-mode-btn <?= ($serverTheme['theme'] ?? 'dark') === 'dark' ? 'active' : '' ?>" data-mode="dark"><i class="bx bx-moon me-1"></i> Dark</button>
                                <button type="button" class="btn btn-outline-secondary btn-sm acct-mode-btn <?= ($serverTheme['theme'] ?? 'dark') === 'auto' ? 'active' : '' ?>" data-mode="auto"><i class="bx bx-circle-half-stroke me-1"></i> Auto</button>
                            </div>
                        </div>
                        <hr class="my-2 border-secondary border-opacity-10">
                        <div class="d-flex align-items-center justify-content-between gap-3 py-2">
                            <div>
                                <div class="fw-semibold">Background</div>
                                <div class="small text-body-secondary">Pick a theme or upload your own.</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill" onclick="coreui.Modal.getInstance(document.getElementById('accountSettingsModal')).hide(); setTimeout(()=>document.querySelector('[data-coreui-target=bgSelectModal]')?.click(),400)">
                                <i class="bx bx-palette me-1"></i> Change background
                            </button>
                        </div>
                        <hr class="my-2 border-secondary border-opacity-10">
                        <div class="d-flex align-items-center justify-content-between gap-3 py-2">
                            <div>
                                <div class="fw-semibold">Theme editor</div>
                                <div class="small text-body-secondary">Author a custom parallax theme.</div>
                            </div>
                            <a class="btn btn-sm btn-outline-secondary rounded-pill" href="/theme/editor"><i class="bx bx-edit me-1"></i> Open editor</a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- Storage Lens Modal — Grid cards showing storage breakdown     -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="storageLensModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content blur shadow-lg rounded-4 border-0" style="border: 1px solid rgba(255,255,255,0.08) !important;">
            <div class="modal-header border-0 pt-3 px-4 pb-0">
                <h4 class="modal-title fw-bold fs-6">Storage Lens</h4>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="line-height: 1.7;">
                <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                    <div class="stg-lens-head-crumb">
                        <nav class="stg-lens-crumb d-flex align-items-center flex-wrap gap-1 small">
                            <div id="lensBreadcrumbs" class="d-flex align-items-center flex-wrap gap-1"></div>
                            <span class="badge badge-soft-secondary ms-2 font-monospace" title="Measured size of everything in your home volume." id="lensVolumeBadge">—</span>
                        </nav>
                    </div>
                    <div class="ms-auto d-flex align-items-center flex-wrap gap-2">
                        <span class="stg-lens-head-total small text-body-secondary"></span>
                        <span class="stg-lens-head-quota">
                            <div style="min-width:210px">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height:7px">
                                        <div class="progress-bar bg-primary" id="lensProgressBar" style="width:0%"></div>
                                    </div>
                                    <span class="small text-body-secondary font-monospace flex-shrink-0" id="lensProgressText">—</span>
                                </div>
                            </div>
                            <span class="badge badge-soft-info" title="The storage pool this account's home lives on" id="lensPoolBadge">pool: default · xfs</span>
                        </span>
                        <button type="button" class="btn btn-sm btn-outline-secondary stg-lens-dlg-refresh" title="Re-scan storage usage (3 per 10 min)" onclick="refreshStorageLens()">
                            <i class="bx bx-refresh me-1"></i> Refresh
                        </button>
                    </div>
                </div>
                <div class="text-end mb-2">
                    <span class="stg-lens-remaining stg-lens-sub text-body-secondary" id="lensRefreshCount">—</span>
                </div>
                <div class="stg-lens-body">
                    <div class="stg-lens-grid" id="lensGrid">
                        <div class="col-12 text-center py-5 text-secondary"><i class="bx bx-loader-alt bx-spin fs-3"></i></div>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════ -->
<!-- Delete Confirmation Modal                                      -->
<!-- ═══════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="lensDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content blur shadow-lg rounded-4 border-0" style="border: 1px solid rgba(255,255,255,0.08) !important;">
            <div class="modal-header border-0 pt-3 px-4 pb-0">
                <h4 class="modal-title fw-bold fs-6">Delete from storage</h4>
                <button type="button" class="btn-close btn-close-white" data-coreui-dismiss="modal"></button>
            </div>
            <div class="modal-body px-4 py-3">
                <div class="mb-1">Delete <strong class="lens-del-name"></strong> (<span class="lens-del-size font-monospace"></span>)?</div>
                <div class="text-body-secondary small">This cannot be undone.</div>
            </div>
            <div class="modal-footer border-0 px-4 pb-3 pt-0">
                <button type="button" class="btn btn-sm btn-secondary rounded-pill px-3" data-coreui-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-sm btn-danger rounded-pill px-3 lens-del-btn">Delete</button>
            </div>
        </div>
    </div>
</div>
    </div>
</div>
