<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Allow unload events (Excalidraw uses beforeunload); avoids "Permissions policy violation: unload is not allowed" --}}
    <meta http-equiv="Permissions-Policy" content="unload=(self)">
    <title>Whiteboard – {{ $page->name }}</title>
    <link rel="stylesheet" href="{{ asset('js/excalidraw-dist/excalidraw-bundle.css') }}">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, sans-serif; color: #1f2937; }
        .excalidraw-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            height: 52px;
            padding: 0 16px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
        }
        .excalidraw-toolbar-title { display: flex; align-items: baseline; gap: 12px; min-width: 0; }
        .excalidraw-toolbar h1 { margin: 0; font-size: 1.05rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        #saveStatus { font-size: 12px; color: #6b7280; white-space: nowrap; }
        #saveStatus.error { color: #b91c1c; }
        .excalidraw-toolbar-actions { display: flex; gap: 8px; align-items: center; }
        .btn {
            padding: 7px 14px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
            color: inherit;
            text-decoration: none;
            white-space: nowrap;
        }
        .btn:hover { background: #f3f4f6; }
        .btn.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        .btn.primary:hover { background: #1d4ed8; }
        .btn.ghost { border-color: transparent; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        #app { height: calc(100vh - 52px); width: 100%; }
        #loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #6b7280; }

        .conflict-banner {
            display: none;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 8px 16px;
            background: #fef3c7;
            border-bottom: 1px solid #fcd34d;
            font-size: 14px;
        }
        .conflict-banner.open { display: flex; }
        body.has-banner #app { height: calc(100vh - 52px - 49px); }

        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(17, 24, 39, .45); z-index: 50; align-items: center; justify-content: center; padding: 16px; }
        .modal-backdrop.open { display: flex; }
        .modal { background: #fff; border-radius: 10px; width: min(860px, 100%); max-height: calc(100vh - 32px); display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(0, 0, 0, .25); }
        .modal.narrow { width: min(560px, 100%); }
        .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 18px; border-bottom: 1px solid #e5e7eb; }
        .modal-header h2 { margin: 0; font-size: 1rem; }
        .modal-body { padding: 16px 18px; overflow: auto; }
        .modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; border-top: 1px solid #e5e7eb; }
        .muted { color: #6b7280; font-size: 13px; }

        .template-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
        .template-card { border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; padding: 0; text-align: left; cursor: pointer; overflow: hidden; font: inherit; color: inherit; }
        .template-card:hover, .template-card:focus-visible { border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37, 99, 235, .15); outline: none; }
        .template-thumb { height: 110px; background: #f9fafb; display: flex; align-items: center; justify-content: center; border-bottom: 1px solid #f3f4f6; }
        .template-thumb svg { max-width: 100%; max-height: 100%; padding: 8px; }
        .template-meta { padding: 8px 10px; }
        .template-meta strong { display: block; font-size: 14px; }

        .tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin: -16px -18px 14px; padding: 0 18px; }
        .tab { border: 0; background: none; padding: 10px 8px; font: inherit; font-size: 14px; color: #6b7280; cursor: pointer; border-bottom: 2px solid transparent; }
        .tab.active { color: #1d4ed8; border-bottom-color: #2563eb; }
        .input, .select { width: 100%; padding: 8px 10px; border: 1px solid #d1d5db; border-radius: 6px; font: inherit; font-size: 14px; }
        .issue-list { list-style: none; margin: 10px 0 0; padding: 0; }
        .issue-list li { display: flex; align-items: center; gap: 10px; padding: 8px 6px; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .issue-list label { display: flex; align-items: center; gap: 10px; flex: 1; min-width: 0; cursor: pointer; }
        .issue-key { font-family: ui-monospace, monospace; font-size: 12px; color: #4b5563; white-space: nowrap; }
        .issue-title { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .lozenge { font-size: 11px; font-weight: 600; text-transform: uppercase; padding: 2px 6px; border-radius: 4px; background: #e9ecef; color: #495057; white-space: nowrap; }
        .lozenge.in_progress { background: #d0ebff; color: #1864ab; }
        .lozenge.done { background: #d3f9d8; color: #2b8a3e; }
        .field { display: block; margin-bottom: 12px; font-size: 13px; font-weight: 500; }
        .field > span { display: block; margin-bottom: 4px; }
        .form-error { color: #b91c1c; font-size: 13px; margin-top: 8px; }
    </style>
</head>
<body>
    <div class="excalidraw-toolbar">
        <div class="excalidraw-toolbar-title">
            <h1>{{ $page->name }}</h1>
            <span id="saveStatus" aria-live="polite"></span>
        </div>
        <div class="excalidraw-toolbar-actions">
            <button type="button" class="btn ghost" id="templatesBtn">Templates</button>
            <button type="button" class="btn ghost" id="issuesBtn" hidden>Issues</button>
            <a href="#" class="btn ghost" id="historyBtn" hidden>History</a>
            <button type="button" class="btn" id="saveBtn" disabled>Save</button>
            <button type="button" class="btn primary" id="saveAndExitBtn" disabled>Save & exit</button>
            <a href="{{ $returnUrl }}" class="btn" id="cancelBtn">Back</a>
        </div>
    </div>
    <div class="conflict-banner" id="conflictBanner" role="alert">
        <span id="conflictText">Someone else changed this whiteboard while you were editing.</span>
        <span style="display:flex;gap:8px">
            <button type="button" class="btn" id="loadLatestBtn">Load latest</button>
            <button type="button" class="btn" id="overwriteBtn">Overwrite with mine</button>
        </span>
    </div>
    <div id="app">
        <div id="loading">Loading Excalidraw…</div>
    </div>

    <div class="modal-backdrop" id="templatesModal">
        <div class="modal" role="dialog" aria-modal="true" aria-labelledby="templatesTitle">
            <div class="modal-header">
                <h2 id="templatesTitle">Start from a template</h2>
                <button type="button" class="btn ghost" data-close>Close</button>
            </div>
            <div class="modal-body">
                <div class="template-grid" id="templateGrid"></div>
            </div>
        </div>
    </div>

    <div class="modal-backdrop" id="issuesModal">
        <div class="modal narrow" role="dialog" aria-modal="true" aria-labelledby="issuesTitle">
            <div class="modal-header">
                <h2 id="issuesTitle">Issues on this whiteboard</h2>
                <button type="button" class="btn ghost" data-close>Close</button>
            </div>
            <div class="modal-body">
                <div class="tabs" role="tablist">
                    <button type="button" class="tab active" data-tab="add">Add issue cards</button>
                    <button type="button" class="tab" data-tab="create">Create from selection</button>
                </div>
                <div data-panel="add">
                    <input type="search" class="input" id="issueSearch" placeholder="Search by key or title…" autocomplete="off">
                    <ul class="issue-list" id="issueResults"></ul>
                    <p class="muted" id="issueResultsEmpty" hidden>No issues found.</p>
                </div>
                <div data-panel="create" hidden>
                    <p class="muted" id="selectionHint" style="margin-top:0">Select stickies or shapes on the board first — each one becomes an issue and turns into a live card.</p>
                    <label class="field"><span>Board</span><select class="select" id="issueBoard"></select></label>
                    <ul class="issue-list" id="selectionList"></ul>
                    <p class="form-error" id="createError" hidden></p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" id="refreshCardsBtn">Refresh cards</button>
                <button type="button" class="btn primary" id="issuesPrimaryBtn">Add selected</button>
            </div>
        </div>
    </div>

    <script id="excalidraw-config" type="application/json">
        @json($excalidrawConfig)
    </script>

    <script src="{{ asset('js/excalidraw-dist/excalidraw-bundle.iife.js') }}" defer></script>
    <script>
        (function() {
            var configEl = document.getElementById('excalidraw-config');
            var config = configEl ? JSON.parse(configEl.textContent || '{}') : {};
            var saveUrl = config.saveUrl || '';
            var returnUrl = config.returnUrl || '';
            var initialData = config.initialData || { elements: [], appState: {}, files: {}, libraryItems: [] };
            var theme = config.theme || 'light';
            var isEmbed = config.isEmbed || false;
            var diagramId = config.diagramId || '';
            var blockId = config.blockId || '';
            var autosave = !!config.autosave;
            var editRevision = config.editRevision != null ? config.editRevision : null;
            var csrf = document.querySelector('meta[name="csrf-token"]').content;

            var AUTOSAVE_DELAY = 2500;
            var RETRY_DELAY = 10000;

            var excalidrawAPI = null;
            var savedSignature = null;   // scene signature that is known to be on the server
            var saving = false;
            var conflict = false;
            var autosaveTimer = null;
            var leaving = false;

            var $ = function(id) { return document.getElementById(id); };

            // ── Scene state ───────────────────────────────────────────────

            /** Cheap change detection (like Excalidraw's getSceneVersion): sum of element versions. */
            function sceneSignature() {
                if (!excalidrawAPI) return null;
                var elements = excalidrawAPI.getSceneElements();
                var sum = 0;
                for (var i = 0; i < elements.length; i++) sum += elements[i].version;
                return elements.length + ':' + sum + ':' + Object.keys(excalidrawAPI.getFiles() || {}).length;
            }

            function isDirty() {
                return excalidrawAPI && savedSignature !== null && sceneSignature() !== savedSignature;
            }

            function sceneData() {
                var files = excalidrawAPI.getFiles();
                var serializableFiles = {};
                for (var id in files) {
                    var f = files[id];
                    if (f && f.dataURL) serializableFiles[id] = { id: f.id || id, dataURL: f.dataURL, mimeType: f.mimeType || 'image/png', created: f.created || Date.now() };
                }
                return {
                    elements: excalidrawAPI.getSceneElements(),
                    appState: excalidrawAPI.getAppState(),
                    files: serializableFiles,
                    libraryItems: excalidrawAPI.getLibraryItems ? excalidrawAPI.getLibraryItems() : []
                };
            }

            function previewBlob(data) {
                return window.BrainitiveExcalidraw.exportToCanvas({
                    elements: data.elements,
                    appState: data.appState,
                    files: excalidrawAPI.getFiles(),
                    exportPadding: 10,
                    exportBackground: true
                }).then(function(canvas) {
                    return new Promise(function(resolve) { canvas.toBlob(resolve, 'image/png'); });
                });
            }

            // ── Status + buttons ──────────────────────────────────────────

            function setStatus(text, isError) {
                var el = $('saveStatus');
                el.textContent = text;
                el.className = isError ? 'error' : '';
            }

            function refreshUi() {
                var dirty = isDirty();
                $('saveBtn').disabled = !dirty || saving || conflict;
                $('saveAndExitBtn').disabled = saving || conflict || (!dirty && !autosave);
                if (!autosave || conflict) return;
                if (saving) setStatus('Saving…');
                else if (dirty) setStatus('Unsaved changes');
                else if ($('saveStatus').className !== 'error') setStatus('All changes saved');
            }

            function showConflict(result) {
                conflict = true;
                clearTimeout(autosaveTimer);
                var by = result && result.conflict && result.conflict.updated_by ? result.conflict.updated_by.name : 'Someone else';
                $('conflictText').textContent = by + ' changed this whiteboard while you were editing. Your changes are not saved.';
                $('conflictBanner').classList.add('open');
                document.body.classList.add('has-banner');
                setStatus('Not saved — conflict', true);
                refreshUi();
            }

            function hideConflict() {
                conflict = false;
                $('conflictBanner').classList.remove('open');
                document.body.classList.remove('has-banner');
            }

            // ── Saving ────────────────────────────────────────────────────

            function handleSaveResponse(response) {
                return response.json().catch(function() { return {}; }).then(function(result) {
                    if (response.status === 409) {
                        showConflict(result);
                        throw { handled: true };
                    }
                    if (!response.ok || !result.success) {
                        throw new Error(result.message || ('Save failed (' + response.status + ')'));
                    }
                    if (result.edit_revision != null) editRevision = result.edit_revision;
                    return result;
                });
            }

            /** Autosave / "Save": JSON body, guarded by the revision this editor last saw. "Save" also records a history checkpoint. */
            function saveScene(force, checkpoint) {
                if (saving || !excalidrawAPI) return Promise.resolve();
                saving = true;
                clearTimeout(autosaveTimer);
                var signature = sceneSignature();
                refreshUi();
                var body = { diagram_data: JSON.stringify(sceneData()) };
                if (autosave) {
                    body.base_revision = editRevision;
                    if (force) body.force = true;
                    if (checkpoint) body.checkpoint = true;
                }
                return fetch(saveUrl, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body)
                })
                .then(handleSaveResponse)
                .then(function() {
                    savedSignature = signature;
                    if (force) hideConflict();
                    setStatus(autosave ? 'All changes saved' : 'Saved');
                })
                .catch(function(e) {
                    if (e && e.handled) return;
                    setStatus(autosave ? 'Couldn’t save — retrying…' : ('Error: ' + (e.message || 'Save failed')), true);
                    if (autosave) autosaveTimer = setTimeout(function() { saveScene(false, false); }, RETRY_DELAY);
                })
                .finally(function() {
                    saving = false;
                    refreshUi();
                    // Edits made while the request was in flight
                    if (autosave && !conflict && isDirty()) scheduleAutosave();
                });
            }

            function scheduleAutosave() {
                if (!autosave || conflict) return;
                clearTimeout(autosaveTimer);
                autosaveTimer = setTimeout(function() { saveScene(false, false); }, AUTOSAVE_DELAY);
            }

            /** "Save & exit": also uploads a preview image and records a history checkpoint. */
            function saveAndExit() {
                if (!excalidrawAPI || saving) return;
                saving = true;
                clearTimeout(autosaveTimer);
                var btn = $('saveAndExitBtn');
                btn.textContent = 'Saving…';
                refreshUi();
                var data = sceneData();
                previewBlob(data).then(function(blob) {
                    var formData = new FormData();
                    formData.append('diagram_data', JSON.stringify(data));
                    formData.append('_token', csrf);
                    if (isEmbed) {
                        formData.append('diagram_id', diagramId);
                        formData.append('block_id', blockId);
                        formData.append('image', blob, 'diagram.png');
                    } else {
                        formData.append('image', blob, 'preview.png');
                        if (editRevision != null) formData.append('base_revision', editRevision);
                        formData.append('checkpoint', '1');
                    }
                    return fetch(saveUrl, { method: 'POST', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: formData });
                })
                .then(handleSaveResponse)
                .then(function(result) {
                    leaving = true;
                    window.location.href = isEmbed
                        ? returnUrl + '&image_url=' + encodeURIComponent(result.imageUrl || '')
                        : returnUrl;
                })
                .catch(function(e) {
                    if (!(e && e.handled)) alert('Error: ' + (e.message || 'Save failed'));
                    btn.textContent = 'Save & exit';
                    saving = false;
                    refreshUi();
                });
            }

            // Embeds only save together with their image ("Save & exit")
            $('saveBtn').hidden = !autosave;
            $('saveBtn').addEventListener('click', function() { saveScene(false, true); });
            $('saveAndExitBtn').addEventListener('click', saveAndExit);
            $('overwriteBtn').addEventListener('click', function() { saveScene(true, false); });
            $('loadLatestBtn').addEventListener('click', function() {
                if (!confirm('Discard your unsaved changes and load the latest version?')) return;
                leaving = true;
                window.location.reload();
            });

            window.addEventListener('beforeunload', function(e) {
                if (leaving || !(isDirty() || saving)) return;
                e.preventDefault();
                e.returnValue = '';
            });

            // ── Modals ────────────────────────────────────────────────────

            function openModal(id) { $(id).classList.add('open'); }
            function closeModal(id) { $(id).classList.remove('open'); }
            document.querySelectorAll('.modal-backdrop').forEach(function(backdrop) {
                backdrop.addEventListener('click', function(e) {
                    if (e.target === backdrop || e.target.hasAttribute('data-close')) closeModal(backdrop.id);
                });
            });
            document.addEventListener('keydown', function(e) {
                if (e.key !== 'Escape') return;
                document.querySelectorAll('.modal-backdrop.open').forEach(function(m) { closeModal(m.id); });
            });

            // ── Templates ─────────────────────────────────────────────────

            var templateGridBuilt = false;
            function openTemplates() {
                if (!templateGridBuilt) {
                    templateGridBuilt = true;
                    var grid = $('templateGrid');
                    window.BrainitiveExcalidraw.templates.forEach(function(template) {
                        var card = document.createElement('button');
                        card.type = 'button';
                        card.className = 'template-card';
                        var thumb = document.createElement('div');
                        thumb.className = 'template-thumb';
                        var meta = document.createElement('div');
                        meta.className = 'template-meta';
                        var name = document.createElement('strong');
                        name.textContent = template.name;
                        var description = document.createElement('span');
                        description.className = 'muted';
                        description.textContent = template.description;
                        meta.appendChild(name);
                        meta.appendChild(description);
                        card.appendChild(thumb);
                        card.appendChild(meta);
                        card.addEventListener('click', function() {
                            excalidrawAPI.insertTemplate(template.key);
                            closeModal('templatesModal');
                        });
                        grid.appendChild(card);
                        window.BrainitiveExcalidraw.templatePreview(template.key)
                            .then(function(svg) { svg.removeAttribute('width'); svg.removeAttribute('height'); thumb.appendChild(svg); })
                            .catch(function() {});
                    });
                }
                openModal('templatesModal');
            }
            $('templatesBtn').addEventListener('click', openTemplates);

            // ── Issues ────────────────────────────────────────────────────

            var activeTab = 'add';
            var searchResults = [];
            var selectedTexts = [];
            var searchTimer = null;

            function api(url, options) {
                options = options || {};
                options.headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': csrf }, options.headers || {});
                return fetch(url, options).then(function(r) {
                    return r.json().catch(function() { return {}; }).then(function(body) {
                        if (!r.ok) throw new Error(body.message || ('Request failed (' + r.status + ')'));
                        return body;
                    });
                });
            }

            function lozenge(item) {
                var el = document.createElement('span');
                el.className = 'lozenge ' + item.status_category;
                el.textContent = item.status || 'No status';
                return el;
            }

            function issueRow(item, checkboxValue, checked) {
                var li = document.createElement('li');
                var label = document.createElement('label');
                var box = document.createElement('input');
                box.type = 'checkbox';
                box.value = checkboxValue;
                box.checked = !!checked;
                box.addEventListener('change', updateIssuesPrimary);
                label.appendChild(box);
                if (item.key) {
                    var key = document.createElement('span');
                    key.className = 'issue-key';
                    key.textContent = item.key;
                    label.appendChild(key);
                }
                var title = document.createElement('span');
                title.className = 'issue-title';
                title.textContent = item.title;
                label.appendChild(title);
                li.appendChild(label);
                if (item.status_category) li.appendChild(lozenge(item));
                return li;
            }

            function checkedValues(listId) {
                return Array.prototype.map.call($(listId).querySelectorAll('input:checked'), function(b) { return b.value; });
            }

            function updateIssuesPrimary() {
                var btn = $('issuesPrimaryBtn');
                if (activeTab === 'add') {
                    var n = checkedValues('issueResults').length;
                    btn.textContent = n > 1 ? 'Add ' + n + ' cards' : 'Add card';
                    btn.disabled = n === 0;
                } else {
                    var m = checkedValues('selectionList').length;
                    btn.textContent = m > 1 ? 'Create ' + m + ' issues' : 'Create issue';
                    btn.disabled = m === 0 || !$('issueBoard').value;
                }
            }

            function searchIssues() {
                var q = $('issueSearch').value.trim();
                api(config.issuesUrl + '?q=' + encodeURIComponent(q)).then(function(result) {
                    searchResults = result.items || [];
                    var list = $('issueResults');
                    list.innerHTML = '';
                    searchResults.forEach(function(item) { list.appendChild(issueRow(item, String(item.id), false)); });
                    $('issueResultsEmpty').hidden = searchResults.length > 0;
                    updateIssuesPrimary();
                }).catch(function() {});
            }

            function showTab(tab) {
                activeTab = tab;
                document.querySelectorAll('#issuesModal .tab').forEach(function(t) { t.classList.toggle('active', t.dataset.tab === tab); });
                document.querySelectorAll('#issuesModal [data-panel]').forEach(function(p) { p.hidden = p.dataset.panel !== tab; });
                $('createError').hidden = true;
                if (tab === 'create') {
                    selectedTexts = excalidrawAPI.getSelectedTexts();
                    var list = $('selectionList');
                    list.innerHTML = '';
                    selectedTexts.forEach(function(t, i) { list.appendChild(issueRow({ title: t.text }, String(i), true)); });
                    $('selectionHint').hidden = selectedTexts.length > 0;
                } else {
                    $('issueSearch').focus();
                }
                updateIssuesPrimary();
            }

            function openIssues() {
                var hasSelection = excalidrawAPI.getSelectedTexts().length > 0;
                openModal('issuesModal');
                searchIssues();
                showTab(hasSelection && config.boards.length > 0 ? 'create' : 'add');
            }

            function refreshCards(silent) {
                var ids = excalidrawAPI.getIssueCardIds();
                if (ids.length === 0) return Promise.resolve(0);
                var query = ids.map(function(id) { return 'ids[]=' + encodeURIComponent(id); }).join('&');
                return api(config.issuesUrl + '?' + query).then(function(result) {
                    var changed = excalidrawAPI.refreshIssueCards(result.items || []);
                    if (!silent) setStatus(changed ? changed + ' card(s) updated' : 'Cards are up to date');
                    return changed;
                }).catch(function() { return 0; });
            }

            if (!isEmbed && config.issuesUrl) {
                $('issuesBtn').hidden = false;
                var boardSelect = $('issueBoard');
                (config.boards || []).forEach(function(board) {
                    var option = document.createElement('option');
                    option.value = board.id;
                    option.textContent = board.name;
                    boardSelect.appendChild(option);
                });
                if (!config.boards || config.boards.length === 0) {
                    document.querySelector('#issuesModal .tab[data-tab="create"]').hidden = true;
                }
                boardSelect.addEventListener('change', updateIssuesPrimary);
                $('issuesBtn').addEventListener('click', openIssues);
                document.querySelectorAll('#issuesModal .tab').forEach(function(t) { t.addEventListener('click', function() { showTab(t.dataset.tab); }); });
                $('issueSearch').addEventListener('input', function() { clearTimeout(searchTimer); searchTimer = setTimeout(searchIssues, 250); });
                $('refreshCardsBtn').addEventListener('click', function() { refreshCards(false); });
                $('issuesPrimaryBtn').addEventListener('click', function() {
                    var btn = this;
                    if (activeTab === 'add') {
                        var ids = checkedValues('issueResults');
                        excalidrawAPI.insertIssueCards(searchResults.filter(function(item) { return ids.indexOf(String(item.id)) !== -1; }));
                        closeModal('issuesModal');
                        return;
                    }
                    var picked = checkedValues('selectionList').map(function(i) { return selectedTexts[Number(i)]; });
                    btn.disabled = true;
                    api(config.createIssuesUrl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ board_id: Number($('issueBoard').value), titles: picked.map(function(t) { return t.text.slice(0, 255); }) })
                    }).then(function(result) {
                        var items = result.items || [];
                        excalidrawAPI.convertToIssueCards(picked.map(function(t, i) { return { elementId: t.id, item: items[i] }; }).filter(function(p) { return p.item; }));
                        setStatus(items.length + ' issue(s) created');
                        closeModal('issuesModal');
                    }).catch(function(e) {
                        $('createError').textContent = e.message;
                        $('createError').hidden = false;
                        btn.disabled = false;
                    });
                });
            }

            if (!isEmbed && config.historyUrl) {
                $('historyBtn').hidden = false;
                $('historyBtn').href = config.historyUrl;
            }

            // ── Boot ──────────────────────────────────────────────────────

            window.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    if (!window.BrainitiveExcalidraw) {
                        $('loading').textContent = 'Failed to load Excalidraw. Run: cd excalidraw-build && npm run build';
                        return;
                    }
                    try {
                        var safe = initialData || { elements: [], appState: {}, files: {}, libraryItems: [] };
                        if (!Array.isArray(safe.elements)) safe.elements = [];
                        if (!safe.appState) safe.appState = {};
                        excalidrawAPI = window.BrainitiveExcalidraw.init('app', { initialData: safe, theme: theme });
                        $('loading').style.display = 'none';
                        setTimeout(function() {
                            if (!excalidrawAPI) return;
                            savedSignature = sceneSignature();
                            refreshUi();
                            var lastSignature = savedSignature;
                            setInterval(function() {
                                var signature = sceneSignature();
                                if (signature !== lastSignature) {
                                    lastSignature = signature;
                                    scheduleAutosave();
                                }
                                refreshUi();
                            }, 500);
                            if (!isEmbed && config.issuesUrl) refreshCards(true);
                            if (config.isNew) openTemplates();
                        }, 500);
                    } catch (err) {
                        $('loading').textContent = 'Error: ' + err.message;
                    }
                }, 800);
            });
        })();
    </script>
</body>
</html>
