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
        body { margin: 0; font-family: system-ui, sans-serif; }
        .excalidraw-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 16px;
            background: #fff;
            border-bottom: 1px solid #e5e7eb;
        }
        .excalidraw-toolbar h1 { margin: 0; font-size: 1.1rem; font-weight: 600; }
        .excalidraw-toolbar-actions { display: flex; gap: 8px; }
        .excalidraw-toolbar button {
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid #d1d5db;
            background: #fff;
            cursor: pointer;
            font-size: 14px;
        }
        .excalidraw-toolbar button:hover { background: #f3f4f6; }
        .excalidraw-toolbar button.primary { background: #2563eb; color: #fff; border-color: #2563eb; }
        .excalidraw-toolbar button.primary:hover { background: #1d4ed8; }
        .excalidraw-toolbar button:disabled { opacity: 0.6; cursor: not-allowed; }
        #app { height: calc(100vh - 52px); width: 100%; }
        #loading { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: #6b7280; }
    </style>
</head>
<body>
    <div class="excalidraw-toolbar">
        <h1>{{ $page->name }}</h1>
        <div class="excalidraw-toolbar-actions">
            <button type="button" id="saveBtn" disabled>Save</button>
            <button type="button" id="saveAndExitBtn" class="primary" disabled>Save & exit</button>
            <a href="{{ $returnUrl }}" id="cancelBtn"><button type="button">Back</button></a>
        </div>
    </div>
    <div id="app">
        <div id="loading">Loading Excalidraw…</div>
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

            var excalidrawAPI = null;
            var initialElements = null;

            function updateSaveButtons(disabled) {
                var saveBtn = document.getElementById('saveBtn');
                var saveAndExitBtn = document.getElementById('saveAndExitBtn');
                if (saveBtn) saveBtn.disabled = disabled;
                if (saveAndExitBtn) saveAndExitBtn.disabled = disabled;
            }

            function checkChanges() {
                if (!excalidrawAPI || !initialElements) return;
                var current = JSON.stringify(excalidrawAPI.getSceneElements());
                var initial = JSON.stringify(initialElements);
                updateSaveButtons(current === initial);
            }

            function doSave(thenRedirect) {
                if (!excalidrawAPI) { alert('Editor not ready'); return Promise.reject(); }
                var elements = excalidrawAPI.getSceneElements();
                var appState = excalidrawAPI.getAppState();
                var files = excalidrawAPI.getFiles();
                var libraryItems = excalidrawAPI.getLibraryItems ? excalidrawAPI.getLibraryItems() : [];
                var serializableFiles = {};
                for (var id in files) {
                    var f = files[id];
                    if (f && f.dataURL) serializableFiles[id] = { id: f.id || id, dataURL: f.dataURL, mimeType: f.mimeType || 'image/png', created: f.created || Date.now() };
                }
                var data = { elements: elements, appState: appState, files: serializableFiles, libraryItems: libraryItems };

                var btn = thenRedirect ? document.getElementById('saveAndExitBtn') : document.getElementById('saveBtn');
                if (btn) btn.textContent = 'Saving…';

                if (isEmbed && thenRedirect) {
                    return window.BrainitiveExcalidraw.exportToCanvas({
                        elements: elements,
                        appState: appState,
                        files: files,
                        exportPadding: 10,
                        exportBackground: true
                    }).then(function(canvas) {
                        return new Promise(function(resolve) { canvas.toBlob(resolve, 'image/png'); });
                    }).then(function(blob) {
                        var formData = new FormData();
                        formData.append('diagram_data', JSON.stringify(data));
                        formData.append('diagram_id', diagramId);
                        formData.append('block_id', blockId);
                        formData.append('image', blob, 'diagram.png');
                        formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);
                        return fetch(saveUrl, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: formData
                        });
                    }).then(function(r) { return r.json(); }).then(function(result) {
                        if (!result.success) throw new Error(result.message || 'Save failed');
                        var url = returnUrl + '&image_url=' + encodeURIComponent(result.imageUrl || '');
                        window.location.href = url;
                    }).catch(function(e) {
                        alert('Error: ' + (e.message || 'Save failed'));
                        if (btn) btn.textContent = 'Save & exit';
                    });
                }

                if (!isEmbed && thenRedirect) {
                    return window.BrainitiveExcalidraw.exportToCanvas({
                        elements: elements,
                        appState: appState,
                        files: files,
                        exportPadding: 10,
                        exportBackground: true
                    }).then(function(canvas) {
                        return new Promise(function(resolve) { canvas.toBlob(resolve, 'image/png'); });
                    }).then(function(blob) {
                        var formData = new FormData();
                        formData.append('diagram_data', JSON.stringify(data));
                        formData.append('image', blob, 'preview.png');
                        formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);
                        return fetch(saveUrl, {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: formData
                        });
                    }).then(function(r) { return r.json(); }).then(function(result) {
                        if (!result.success) throw new Error(result.message || 'Save failed');
                        window.location.href = returnUrl;
                    }).catch(function(e) {
                        alert('Error: ' + (e.message || 'Save failed'));
                        if (btn) btn.textContent = 'Save & exit';
                    });
                }

                return fetch(saveUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({ diagram_data: JSON.stringify(data) })
                })
                .then(function(r) { return r.json(); })
                .then(function(result) {
                    if (!result.success) throw new Error(result.message || 'Save failed');
                    if (btn) btn.textContent = thenRedirect ? 'Save & exit' : 'Saved';
                    if (!thenRedirect) setTimeout(function() { if (btn) btn.textContent = 'Save'; }, 2000);
                    initialElements = JSON.parse(JSON.stringify(excalidrawAPI.getSceneElements()));
                    updateSaveButtons(true);
                    if (thenRedirect) window.location.href = returnUrl;
                })
                .catch(function(e) {
                    alert('Error: ' + (e.message || 'Save failed'));
                    if (btn) btn.textContent = thenRedirect ? 'Save & exit' : 'Save';
                });
            }

            window.addEventListener('DOMContentLoaded', function() {
                setTimeout(function() {
                    if (!window.BrainitiveExcalidraw) {
                        document.getElementById('loading').textContent = 'Failed to load Excalidraw. Run: cd excalidraw-build && npm run build';
                        return;
                    }
                    try {
                        var safe = initialData || { elements: [], appState: {}, files: {}, libraryItems: [] };
                        if (!Array.isArray(safe.elements)) safe.elements = [];
                        if (!safe.appState) safe.appState = {};
                        excalidrawAPI = window.BrainitiveExcalidraw.init('app', { initialData: safe, theme: theme });
                        document.getElementById('loading').style.display = 'none';
                        setTimeout(function() {
                            if (excalidrawAPI) {
                                initialElements = JSON.parse(JSON.stringify(excalidrawAPI.getSceneElements()));
                                setInterval(checkChanges, 500);
                            }
                        }, 500);
                    } catch (err) {
                        document.getElementById('loading').textContent = 'Error: ' + err.message;
                    }
                }, 800);
            });

            document.getElementById('saveBtn') && document.getElementById('saveBtn').addEventListener('click', function() { doSave(false); });
            document.getElementById('saveAndExitBtn') && document.getElementById('saveAndExitBtn').addEventListener('click', function() { doSave(true); });
        })();
    </script>
</body>
</html>
