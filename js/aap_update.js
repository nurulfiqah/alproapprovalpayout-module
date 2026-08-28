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
    var saveBtn = document.getElementById('case-edit-save');

    // Only present when $can_edit_case (aap_update.php) rendered this
    // section - other viewers get none of these elements.
    if (!toggleBtn || !viewEl || !editEl) return;

    // The Evidence Note/file fields live in their own standalone form (Add
    // Evidence button) so they can be saved on their own - but if the
    // requester instead clicks Save Changes, re-parent them into the
    // case-edit form right before it submits so whatever they typed/picked
    // rides along in that request too, instead of being silently dropped.
    if (saveBtn) {
        saveBtn.addEventListener('click', function () {
            var caseEditForm = document.getElementById('case-edit');
            var noteInput = document.querySelector('#evidence-add-form textarea[name="new_note"]');
            var fileInput = document.getElementById('evidence-file-input');
            if (caseEditForm && noteInput) caseEditForm.appendChild(noteInput);
            if (caseEditForm && fileInput) caseEditForm.appendChild(fileInput);
        });
    }

    var deleteForms = document.querySelectorAll('.attachment-delete-form');
    var noteActions = document.querySelectorAll('.note-actions');

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

    function showEdit() {
        viewEl.style.display = 'none';
        editEl.style.display = 'block';
        toggleBtn.style.display = 'none';
        if (actionsEl) actionsEl.style.display = 'block';
        deleteForms.forEach(function (f) { f.style.display = 'inline'; });
        noteActions.forEach(function (s) { s.style.display = 'flex'; });
    }
    function showView() {
        viewEl.style.display = 'block';
        editEl.style.display = 'none';
        toggleBtn.style.display = 'inline-flex';
        if (actionsEl) actionsEl.style.display = 'none';
        deleteForms.forEach(function (f) { f.style.display = 'none'; });
        noteActions.forEach(function (s) { s.style.display = 'none'; });
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

    // Warn before an accidental refresh/close/navigate discards unsaved edits
    // - no auto-save, since silently writing partial edits without an
    // explicit Save click isn't appropriate here. Cleared on any actual
    // submit (case-edit, per-note edit, attachment delete, etc.) since that's
    // an intentional save, not an accidental loss.
    var formDirty = false;
    document.querySelectorAll('#case-edit input, #case-edit select, #case-edit textarea, [form="case-edit"], .note-edit-form input, .note-edit-form textarea').forEach(function (el) {
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

    // Live preview of files chosen in "Add More Evidence" before the Add
    // button is clicked, each with its own remove button - mirrors the already-saved
    // attachment list's look. A native file input replaces its whole
    // selection every time "Choose files" is used again, so pendingFiles
    // accumulates across picks and the input's FileList is rebuilt from it
    // via the DataTransfer trick (a FileList is otherwise read-only).
    var evidenceFileInput = document.getElementById('evidence-file-input');
    var evidenceFilePreview = document.getElementById('evidence-file-preview');
    var pendingFiles = [];

    function syncFileInput() {
        var dt = new DataTransfer();
        pendingFiles.forEach(function (f) { dt.items.add(f); });
        evidenceFileInput.files = dt.files;
    }

    function renderFilePreview() {
        evidenceFilePreview.innerHTML = '';
        pendingFiles.forEach(function (file, index) {
            var li = document.createElement('li');

            var nameLink = document.createElement('a');
            nameLink.href = URL.createObjectURL(file);
            nameLink.target = '_blank';
            nameLink.rel = 'noopener';
            nameLink.innerHTML = '<i class="bi bi-file-earmark-arrow-up"></i> ';
            nameLink.appendChild(document.createTextNode(file.name));
            li.appendChild(nameLink);

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.title = 'Remove from upload';
            removeBtn.style.cssText = 'background:none; border:none; color:#dc3545; cursor:pointer; padding:0; font-size:13px;';
            removeBtn.innerHTML = '<i class="bi bi-trash"></i>';
            removeBtn.addEventListener('click', function () {
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
            pendingFiles.push(f);
        });
        syncFileInput();
        renderFilePreview();
    });
});
