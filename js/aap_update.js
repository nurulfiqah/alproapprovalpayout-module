// Report Description on the Approval gate is only mandatory when it's
// actually explaining something: rejecting a case, or approving it at a
// different value than what was requested. A straight approval at the
// requested value needs no explanation. Server-side (aap_update.php's
// approve/reject handler) enforces this for real - this is just the
// same rule surfaced live in the UI (asterisk + hint) and checked again on
// submit so the approver isn't sent on an extra round trip to find out.
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('approval-gate-form');
    var valueInput = document.getElementById('approved-value-input');
    var remarkTextarea = document.getElementById('approval-remark-input');
    var reqMark = document.getElementById('approval-remark-req');
    var hintEl = document.getElementById('approval-remark-hint');
    var errorEl = document.getElementById('approval-remark-error');
    if (!form || !valueInput || !remarkTextarea) return;

    var originalValue = parseFloat(form.dataset.originalValue);

    function valueAdjusted() {
        var v = parseFloat(valueInput.value);
        if (isNaN(v) || isNaN(originalValue)) return false;
        return Math.abs(v - originalValue) > 0.001;
    }

    function clearError() {
        if (errorEl) { errorEl.style.display = 'none'; errorEl.textContent = ''; }
        remarkTextarea.style.borderColor = '';
    }

    function refreshHint() {
        var adjusted = valueAdjusted();
        if (reqMark) reqMark.style.display = adjusted ? '' : 'none';
        if (hintEl) hintEl.textContent = adjusted ? '(required - value adjusted)' : '(required if rejecting or adjusting the value)';
        clearError();
    }
    valueInput.addEventListener('input', refreshHint);
    remarkTextarea.addEventListener('input', clearError);
    refreshHint();

    // A native alert()/confirm() dialog isn't guaranteed to actually render
    // in every environment this page can be opened in (some embedded/kiosk
    // webviews silently suppress it) - blocking the submit still works
    // either way (the function still returns false), but the approver would
    // see nothing telling them why nothing happened. An inline message next
    // to the field itself doesn't depend on that dialog subsystem at all.
    window.aapApprovalGateSubmit = function (_btn, action) {
        var remark = remarkTextarea.value.trim();
        var needsRemark = action === 'reject' || valueAdjusted();
        if (needsRemark && remark === '') {
            var message = action === 'reject'
                ? 'Report Description is required when rejecting a case.'
                : 'Report Description is required when adjusting the Approved Value.';
            if (errorEl) { errorEl.textContent = message; errorEl.style.display = 'block'; }
            remarkTextarea.style.borderColor = '#dc3545';
            remarkTextarea.focus();
            remarkTextarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }
        clearError();
        return confirm(action === 'reject' ? 'Reject this case?' : 'Approve this case?');
    };
});

document.addEventListener('DOMContentLoaded', function () {
    var remarkInput = document.getElementById('approval-remark-input');
    var statusEl = document.getElementById('approval-remark-status');
    if (!remarkInput) return;
    var timer = null;
    remarkInput.addEventListener('input', function () {
        if (timer) clearTimeout(timer);
        timer = setTimeout(function () {
            statusEl.textContent = 'Saving…';
            var body = new URLSearchParams({ action: 'ajax_save_remark', remark: remarkInput.value });
            fetch('', { method: 'POST', body: body })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    statusEl.innerHTML = data.success
                        ? 'Saved <i class="bi bi-check-circle-fill" style="color:#28a745;"></i>'
                        : 'Could not save';
                    if (data.success) setTimeout(function () { statusEl.innerHTML = ''; }, 1500);
                })
                .catch(function () { statusEl.textContent = 'Could not save'; });
        }, 900);
    });
});

document.addEventListener('DOMContentLoaded', function () {
    // Membership ID, Transaction No, and Bank Account Number only ever hold
    // digits - strip anything else as they're typed, same restriction as
    // aap_add.php's fields.
    function digitsOnlyFilter(el) {
        if (!el) return;
        el.addEventListener('input', function () {
            var digitsOnly = el.value.replace(/\D/g, '');
            if (digitsOnly !== el.value) el.value = digitsOnly;
        });
    }
    digitsOnlyFilter(document.getElementById('edit-customer-membership-input'));
    digitsOnlyFilter(document.getElementById('edit-transaction-ref-input'));
    digitsOnlyFilter(document.getElementById('edit-bank-account-number-input'));
});

document.addEventListener('DOMContentLoaded', function () {
    var toggle = document.getElementById('void-case-toggle');
    var form = document.getElementById('void-case-form');
    var cancel = document.getElementById('void-case-cancel');
    if (toggle) {
        toggle.addEventListener('click', function () {
            toggle.style.display = 'none';
            form.style.display = 'block';
        });
    }
    if (cancel) {
        cancel.addEventListener('click', function () {
            form.style.display = 'none';
            toggle.style.display = 'inline-flex';
        });
    }

    var executeReveal = document.getElementById('execute-reveal-btn');
    var executeDefault = document.getElementById('execute-actions-default');
    var executeConfirm = document.getElementById('execute-actions-confirm');
    var executeCancel = document.getElementById('execute-cancel-btn');
    if (executeReveal) {
        executeReveal.addEventListener('click', function () {
            executeDefault.style.display = 'none';
            executeConfirm.style.display = 'flex';
        });
    }
    if (executeCancel) {
        executeCancel.addEventListener('click', function () {
            executeConfirm.style.display = 'none';
            executeDefault.style.display = 'flex';
        });
    }

    var executeForm = document.getElementById('execute-form');
    var suspendToggle = document.getElementById('suspend-case-toggle');
    var suspendForm = document.getElementById('suspend-case-form');
    var suspendCancel = document.getElementById('suspend-case-cancel');
    if (suspendToggle) {
        suspendToggle.addEventListener('click', function () {
            if (executeForm) executeForm.style.display = 'none';
            suspendForm.style.display = 'block';
        });
    }
    if (suspendCancel) {
        suspendCancel.addEventListener('click', function () {
            suspendForm.style.display = 'none';
            if (executeForm) executeForm.style.display = 'block';
        });
    }

    // Approval gate's own Suspend Case - same reveal/cancel pattern as
    // Execution's above, distinct element ids since both can exist on the
    // page's history at once (Approval's card stays visible even once the
    // case has moved on to Execution).
    var approvalGateForm = document.getElementById('approval-gate-form');
    var approvalSuspendToggle = document.getElementById('approval-suspend-toggle');
    var approvalSuspendForm = document.getElementById('approval-suspend-form');
    var approvalSuspendCancel = document.getElementById('approval-suspend-cancel');
    if (approvalSuspendToggle) {
        approvalSuspendToggle.addEventListener('click', function () {
            if (approvalGateForm) approvalGateForm.style.display = 'none';
            approvalSuspendForm.style.display = 'block';
        });
    }
    if (approvalSuspendCancel) {
        approvalSuspendCancel.addEventListener('click', function () {
            approvalSuspendForm.style.display = 'none';
            if (approvalGateForm) approvalGateForm.style.display = 'block';
        });
    }
});

// Any action here reloads the page (Post/Redirect/Get) - remember that we
// were in edit mode and where we'd scrolled to, so the user lands back
// exactly where they were instead of at the top in view mode.
function aapRememberEditState() {
    sessionStorage.setItem('aap_reopen_edit', '1');
    sessionStorage.setItem('aap_scroll_y', String(window.scrollY));
}

function aapConfirmDeleteAttachment() {
    if (!confirm('Remove this attachment?')) {
        return false;
    }
    aapRememberEditState();
    return true;
}

function aapConfirmDeleteNote() {
    if (!confirm('Remove this note?')) {
        return false;
    }
    aapRememberEditState();
    return true;
}

document.addEventListener('DOMContentLoaded', function () {
    var toggleBtn = document.getElementById('case-edit-toggle');
    var cancelBtn = document.getElementById('case-edit-cancel');
    var viewEl = document.getElementById('case-view');
    var editEl = document.getElementById('case-edit');
    var actionsEl = document.getElementById('case-edit-actions');
    // Bank Detail toggles together with Case Summary - one unified Edit/Save
    // session rather than its own separate Edit button (see aap_update.php).
    var bankViewEl = document.getElementById('bank-view');
    var bankEditEl = document.getElementById('bank-edit');

    // Only present when $can_edit_case (aap_update.php) rendered this
    // section - other viewers get none of these elements.
    if (!toggleBtn || !viewEl || !editEl) return;

    // The Evidence Note/file fields live in their own standalone form (Add
    // Evidence button) so they can be saved on their own - but if the
    // requester instead clicks Save Changes, re-parent them into the
    // case-edit form right before it submits so whatever they typed/picked
    // rides along in that request too, instead of being silently dropped.
    // Done on the form's "submit" event (not the button's "click") so it
    // only fires once the browser's own required-field validation has
    // actually passed - on a click that native validation blocks, the
    // fields must stay put instead of visibly jumping into the edit form
    // and being left stranded there.
    editEl.addEventListener('submit', function () {
        var noteInput = document.querySelector('#evidence-add-form textarea[name="new_note"]');
        var fileInput = document.getElementById('evidence-file-input');
        if (noteInput) editEl.appendChild(noteInput);
        if (fileInput) editEl.appendChild(fileInput);
    });

    // Open Case submits the same Case Summary form (via form="case-edit")
    // instead of requiring Save Changes first - the browser's own
    // required-field validation on that form (Calculated Value, Recommended
    // Outcome, etc.) already blocks the submit if anything's missing, so
    // there's no separate "fill in first" step for the requester to hit.
    var openCaseBtn = document.getElementById('open-case-btn');
    var caseEditActionField = document.getElementById('case-edit-action-field');
    if (openCaseBtn && caseEditActionField) {
        openCaseBtn.addEventListener('click', function (e) {
            if (!confirm('Open this case? It will move into the approval workflow.')) {
                e.preventDefault();
                return;
            }
            caseEditActionField.value = 'open_case';
        });
    }

    function showEdit() {
        viewEl.style.display = 'none';
        editEl.style.display = 'block';
        toggleBtn.style.display = 'none';
        if (actionsEl) actionsEl.style.display = 'block';
        if (bankViewEl) bankViewEl.style.display = 'none';
        if (bankEditEl) bankEditEl.style.display = 'block';
    }
    function showView() {
        viewEl.style.display = 'block';
        editEl.style.display = 'none';
        toggleBtn.style.display = 'inline-flex';
        if (actionsEl) actionsEl.style.display = 'none';
        if (bankViewEl) bankViewEl.style.display = 'block';
        if (bankEditEl) bankEditEl.style.display = 'none';
    }

    toggleBtn.addEventListener('click', showEdit);
    cancelBtn.addEventListener('click', showView);

    // Still a Draft - open straight into edit mode so the issuer/CS team
    // doesn't have to click Edit first while gathering evidence.
    if (window.AAP_UPDATE && AAP_UPDATE.openInEditMode) {
        showEdit();
    }

    if (sessionStorage.getItem('aap_reopen_edit') === '1') {
        sessionStorage.removeItem('aap_reopen_edit');
        showEdit();
        var savedScrollY = sessionStorage.getItem('aap_scroll_y');
        sessionStorage.removeItem('aap_scroll_y');
        if (savedScrollY !== null) {
            window.scrollTo(0, parseInt(savedScrollY, 10));
        }
    }

});

// Warn before an accidental refresh/close/navigate discards unsaved edits -
// no auto-save, since silently writing partial edits without an explicit
// Save/Confirm click isn't appropriate here. Cleared on any actual submit
// (case-edit, per-note edit, Approve/Reject/Suspend/Execute, attachment
// delete, etc.) since that's an intentional save, not an accidental loss.
// Deliberately its own top-level listener, not nested inside the one above -
// that one bails out early whenever the Case Summary edit UI isn't rendered
// at all (case moved past editable stages), which is exactly when the
// Approval/Execution/Suspend gates' own free-text fields (remarks, reasons)
// are what's actually at risk of being lost to a refresh.
document.addEventListener('DOMContentLoaded', function () {
    var formDirty = false;
    document.querySelectorAll('input, textarea, select').forEach(function (el) {
        el.addEventListener('input', function () { formDirty = true; });
        el.addEventListener('change', function () { formDirty = true; });
    });
    document.addEventListener('submit', function () { formDirty = false; });
    window.addEventListener('beforeunload', function (e) {
        if (formDirty) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
});

// Edit/delete controls on individual notes and attachments are now rendered
// per-item (aapCaseEvidenceItemEditable() in aap_lib.php) rather than tied to
// the Case Summary edit toggle above - a note added during the Approval
// stage can stay editable by its own author even once that toggle (and its
// case-edit/toggleBtn/viewEl elements) no longer exists. querySelectorAll
// simply returns nothing where no such controls were rendered, so this is
// safe to run unconditionally.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.note-edit-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var noteId = btn.dataset.noteId;
            document.getElementById('note-view-' + noteId).style.display = 'none';
            document.getElementById('note-edit-form-' + noteId).style.display = 'block';
        });
    });

    document.querySelectorAll('.note-edit-cancel').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var noteId = btn.dataset.noteId;
            document.getElementById('note-edit-form-' + noteId).style.display = 'none';
            document.getElementById('note-view-' + noteId).style.display = 'block';
        });
    });
});

// The "Add New Evidence" form (evidence-add-form/evidence-file-input) is
// gated by $can_add_evidence, which stays true through the Approval stage
// even after $can_edit_case (and so the case-edit/toggleBtn/viewEl elements
// above) has gone away - so this can't live inside that guarded block above,
// it needs its own independent guard on the elements it actually uses.
document.addEventListener('DOMContentLoaded', function () {
    // Live preview of files chosen in "Add More Evidence" before the Add
    // button is clicked, each with its own remove button - mirrors the already-saved
    // attachment list's look. A native file input replaces its whole
    // selection every time "Choose files" is used again, so pendingFiles
    // accumulates across picks and the input's FileList is rebuilt from it
    // via the DataTransfer trick (a FileList is otherwise read-only).
    var evidenceFileInput = document.getElementById('evidence-file-input');
    var evidenceFilePreview = document.getElementById('evidence-file-preview');
    if (!evidenceFileInput || !evidenceFilePreview) return;

    // Each entry is { file, url } - the blob URL is created exactly once per
    // file (not on every re-render) and explicitly revoked when the file is
    // removed or the page unloads, so picking/removing files repeatedly
    // doesn't leak blob memory and eventually freeze the tab.
    var pendingFiles = [];

    function syncFileInput() {
        var dt = new DataTransfer();
        pendingFiles.forEach(function (p) { dt.items.add(p.file); });
        evidenceFileInput.files = dt.files;
    }

    function renderFilePreview() {
        evidenceFilePreview.innerHTML = '';
        pendingFiles.forEach(function (entry, index) {
            var li = document.createElement('li');

            var nameLink = document.createElement('a');
            nameLink.href = entry.url;
            nameLink.target = '_blank';
            nameLink.rel = 'noopener';
            nameLink.innerHTML = '<i class="bi bi-file-earmark-arrow-up"></i> ';
            nameLink.appendChild(document.createTextNode(entry.file.name));
            li.appendChild(nameLink);

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.title = 'Remove from upload';
            removeBtn.style.cssText = 'background:none; border:none; color:#dc3545; cursor:pointer; padding:0; font-size:13px;';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.addEventListener('click', function () {
                URL.revokeObjectURL(entry.url);
                pendingFiles.splice(index, 1);
                syncFileInput();
                renderFilePreview();
            });
            li.appendChild(removeBtn);

            evidenceFilePreview.appendChild(li);
        });
    }

    evidenceFileInput.addEventListener('change', function () {
        Array.prototype.forEach.call(evidenceFileInput.files, function (f) {
            pendingFiles.push({ file: f, url: URL.createObjectURL(f) });
        });
        syncFileInput();
        renderFilePreview();
    });

    // Release every still-live blob URL on the way out, in case the page is
    // navigated away from (or re-submitted) with files still pending.
    window.addEventListener('beforeunload', function () {
        pendingFiles.forEach(function (p) { URL.revokeObjectURL(p.url); });
    });
});