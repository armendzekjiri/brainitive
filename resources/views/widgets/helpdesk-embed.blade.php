<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contact support</title>
    <style>
        :root {
            --embed-primary: #0E83DC;
            --embed-primary-hover: #0b6fbf;
            --embed-muted: #f4f6f8;
            --embed-muted-foreground: #64748b;
            --embed-border: #e2e8f0;
            --embed-input: #fff;
            --embed-ring: rgba(14, 131, 220, 0.2);
            --embed-destructive: #dc2626;
            --embed-destructive-bg: #fef2f2;
            --embed-success-bg: #f0fdf4;
            --embed-success: #166534;
            --embed-radius: 0.5rem;
            --embed-radius-lg: 0.75rem;
            --embed-shadow: 0 1px 3px 0 rgb(0 0 0 / 0.06), 0 1px 2px -1px rgb(0 0 0 / 0.06);
            --embed-shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.05);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 24px 16px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Inter", sans-serif;
            font-size: 14px;
            line-height: 1.5;
            background: var(--embed-muted);
            color: #1e293b;
            -webkit-font-smoothing: antialiased;
        }
        .embed-root { max-width: 520px; margin: 0 auto; }
        .embed-card {
            background: #fff;
            border-radius: var(--embed-radius-lg);
            border: 1px solid var(--embed-border);
            box-shadow: var(--embed-shadow-lg);
            padding: 28px 24px;
        }
        .embed-title {
            font-size: 1.25rem;
            font-weight: 600;
            letter-spacing: -0.025em;
            margin: 0 0 6px 0;
            color: #0f172a;
        }
        .embed-welcome {
            color: var(--embed-muted-foreground);
            font-size: 0.875rem;
            margin: 0 0 24px 0;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 0.875rem;
            color: #334155;
        }
        .form-group .required::after {
            content: " *";
            color: var(--embed-destructive);
        }
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--embed-border);
            border-radius: var(--embed-radius);
            font-size: 0.875rem;
            background: var(--embed-input);
            color: #1e293b;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: var(--embed-primary);
            box-shadow: 0 0 0 3px var(--embed-ring);
        }
        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: #94a3b8;
        }
        .form-group textarea {
            min-height: 88px;
            resize: vertical;
        }
        .form-group select {
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M2.5 4.5L6 8l3.5-3.5'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            padding-right: 36px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 20px;
            border-radius: var(--embed-radius);
            border: none;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            background: var(--embed-primary);
            color: #fff;
            transition: background 0.15s, opacity 0.15s;
            box-shadow: var(--embed-shadow);
        }
        .btn:hover:not(:disabled) {
            background: var(--embed-primary-hover);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .msg {
            padding: 12px 14px;
            border-radius: var(--embed-radius);
            margin-bottom: 16px;
            font-size: 0.875rem;
        }
        .msg.success {
            background: var(--embed-success-bg);
            color: var(--embed-success);
        }
        .msg.error {
            background: var(--embed-destructive-bg);
            color: var(--embed-destructive);
        }
        .loading {
            text-align: center;
            color: var(--embed-muted-foreground);
            padding: 48px 24px;
            font-size: 0.875rem;
        }
        .loading::before {
            content: "";
            display: block;
            width: 28px;
            height: 28px;
            margin: 0 auto 12px;
            border: 2px solid var(--embed-border);
            border-top-color: var(--embed-primary);
            border-radius: 50%;
            animation: embed-spin 0.7s linear infinite;
        }
        @keyframes embed-spin {
            to { transform: rotate(360deg); }
        }
        .embed-attachments { margin-top: 6px; }
        .embed-attachments .form-group-label { margin-bottom: 6px; }
        .embed-attachments-list {
            margin-top: 8px;
            font-size: 0.8125rem;
            color: var(--embed-muted-foreground);
        }
        .embed-attachments-list span {
            display: inline-flex;
            align-items: center;
            margin-right: 10px;
            margin-bottom: 6px;
            padding: 4px 8px;
            background: var(--embed-muted);
            border-radius: 6px;
        }
        .embed-attachments-list button {
            margin-left: 6px;
            color: #94a3b8;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0 2px;
            font-size: 1rem;
            line-height: 1;
        }
        .embed-attachments-list button:hover {
            color: var(--embed-destructive);
        }
        .file-input-wrap {
            position: relative;
            margin-top: 6px;
        }
        .file-input-wrap input[type="file"] {
            font-size: 0.8125rem;
            padding: 8px 0;
        }
        .embed-rich-wrap {
            border: 1px solid var(--embed-border);
            border-radius: var(--embed-radius);
            background: #fff;
            min-height: 120px;
            overflow: hidden;
            transition: border-color 0.15s, box-shadow 0.15s;
        }
        .embed-rich-wrap:focus-within {
            border-color: var(--embed-primary);
            box-shadow: 0 0 0 3px var(--embed-ring);
        }
        .embed-rich-wrap .ql-toolbar {
            border: none;
            border-bottom: 1px solid var(--embed-border);
            background: var(--embed-muted);
            padding: 8px 12px;
        }
        .embed-rich-wrap .ql-container {
            border: none;
            font-family: inherit;
        }
        .embed-rich-wrap .ql-editor {
            min-height: 100px;
            font-size: 0.875rem;
            padding: 12px;
        }
        .embed-rich-wrap .ql-editor.ql-blank::before {
            color: #94a3b8;
            font-style: normal;
        }
        /* Ticket view */
        .ticket-card {
            background: #fff;
            border-radius: var(--embed-radius-lg);
            border: 1px solid var(--embed-border);
            box-shadow: var(--embed-shadow-lg);
            padding: 28px 24px;
        }
        .ticket-subject {
            font-size: 1.125rem;
            font-weight: 600;
            letter-spacing: -0.025em;
            margin: 0 0 12px 0;
            color: #0f172a;
        }
        .ticket-description {
            margin: 0 0 24px 0;
            padding: 14px 16px;
            background: var(--embed-muted);
            border-radius: var(--embed-radius);
            color: #475569;
            font-size: 0.875rem;
            line-height: 1.6;
        }
        .ticket-description p { margin: 0 0 0.5em 0; }
        .ticket-description p:last-child { margin-bottom: 0; }
        .ticket-description ul, .ticket-description ol { margin: 0.5em 0; padding-left: 1.25em; }
        .ticket-description a { color: var(--embed-primary); }
        .replies-section { margin: 24px 0; }
        .reply {
            margin-bottom: 12px;
            padding: 12px 14px;
            border-radius: var(--embed-radius);
            border: 1px solid var(--embed-border);
            font-size: 0.875rem;
        }
        .reply.agent {
            background: var(--embed-muted);
            margin-right: 20px;
        }
        .reply.contact {
            background: rgba(14, 131, 220, 0.06);
            border-color: rgba(14, 131, 220, 0.2);
            margin-left: 20px;
        }
        .reply-meta {
            font-size: 0.75rem;
            color: var(--embed-muted-foreground);
            margin-bottom: 6px;
        }
        .reply-body {
            word-break: break-word;
            line-height: 1.5;
        }
        .reply-body p { margin: 0 0 0.5em 0; }
        .reply-body p:last-child { margin-bottom: 0; }
        .reply-body ul, .reply-body ol { margin: 0.5em 0; padding-left: 1.25em; }
        .reply-body a { color: var(--embed-primary); }
        .reply-form {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--embed-border);
        }
        .reply-form label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            font-size: 0.875rem;
            color: #334155;
        }
        .reply-form textarea {
            width: 100%;
            padding: 9px 12px;
            border: 1px solid var(--embed-border);
            border-radius: var(--embed-radius);
            font-size: 0.875rem;
            min-height: 88px;
            resize: vertical;
        }
        .reply-form textarea:focus {
            outline: none;
            border-color: var(--embed-primary);
            box-shadow: 0 0 0 3px var(--embed-ring);
        }
        .reply-form .btn { margin-top: 12px; }
    </style>
    <link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
</head>
<body>
    <div id="embed-root" class="embed-root" data-helpdesk-uuid="{{ $helpdesk_uuid }}" data-base-url="{{ $base_url }}" data-ticket-uuid="{{ $ticket_uuid ?? '' }}" data-token="{{ $token ?? '' }}"></div>
    <script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
    <script>
(function() {
    var root = document.getElementById('embed-root');
    var baseUrl = root.dataset.baseUrl.replace(/\/$/, '');
    var helpdeskId = root.dataset.helpdeskUuid;
    var ticketUuid = root.dataset.ticketUuid || null;
    var token = root.dataset.token || null;
    var formConfig = null;

    function el(tag, attrs, children) {
        var e = document.createElement(tag);
        if (attrs) Object.keys(attrs).forEach(function(k) {
            if (k === 'className') e.className = attrs[k];
            else if (k === 'html') e.innerHTML = attrs[k];
            else if (k.startsWith('on')) e[k.toLowerCase()] = attrs[k];
            else if (k !== 'required' && k !== 'placeholder') e.setAttribute(k, attrs[k]);
        });
        if (attrs && attrs.placeholder !== undefined) e.placeholder = attrs.placeholder;
        if (attrs && attrs.required) e.required = true;
        if (children) children.forEach(function(c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
        return e;
    }

    function renderForm() {
        if (!formConfig) { root.innerHTML = '<div class="embed-card"><div class="loading">Loading…</div></div>'; return; }
        richEditors = [];
        var welcome = formConfig.branding.welcome_message || 'How can we help you?';
        var form = el('form', { id: 'support-form' });
        form.appendChild(el('h1', { className: 'embed-title' }, ['Contact support']));
        form.appendChild(el('p', { className: 'embed-welcome' }, [welcome]));
        var formCard = document.createElement('div');
        formCard.className = 'embed-card';

        function isLongTextType(t) {
                if (!t || typeof t !== 'string') return false;
                var n = t.toLowerCase().replace(/[\s-]/g, '_');
                return n === 'textarea' || n === 'rich_text' || n === 'richtext';
            }
        function isRichTextType(t) {
                if (!t || typeof t !== 'string') return false;
                var n = t.toLowerCase().replace(/[\s-]/g, '_');
                return n === 'rich_text' || n === 'richtext';
            }
        var richEditors = [];
        function addField(name, label, type, required, options) {
            var g = el('div', { className: 'form-group' });
            var lab = el('label', { htmlFor: name }, [label]);
            if (required) lab.classList.add('required');
            g.appendChild(lab);
            if (type === 'select' && options && options.length) {
                var sel = el('select', { id: name, name: name, required: required });
                sel.appendChild(el('option', { value: '' }, ['— Select —']));
                options.forEach(function(o) { sel.appendChild(el('option', { value: o }, [o])); });
                g.appendChild(sel);
            } else if (type === 'checkbox') {
                g.appendChild(el('input', { type: 'checkbox', id: name, name: name }));
            } else if (isRichTextType(type)) {
                var wrap = el('div', { className: 'embed-rich-wrap', 'data-field-name': name });
                var editorDiv = document.createElement('div');
                editorDiv.setAttribute('data-field-name', name);
                wrap.appendChild(editorDiv);
                var hidden = el('input', { type: 'hidden', name: name });
                wrap.appendChild(hidden);
                g.appendChild(wrap);
                richEditors.push({ name: name, required: required, container: editorDiv, hidden: hidden });
            } else {
                var useTextarea = isLongTextType(type);
                var inp = useTextarea ? el('textarea', { id: name, name: name, required: required, rows: 4 }) : el('input', { type: type === 'number' ? 'number' : 'text', id: name, name: name, required: required });
                g.appendChild(inp);
            }
            form.appendChild(g);
        }

        var de = formConfig.default_editable || {};
        if (de.contact_name) addField('contact_name', de.contact_name.label, 'text', !!de.contact_name.is_required);
        addField('contact_email', 'Email', 'text', true);
        addField('subject', 'Subject', 'text', true);
        if (de.description) addField('description', de.description.label, 'textarea', !!de.description.is_required);
        (formConfig.fields || []).forEach(function(f) {
            addField('field_' + f.name, f.label, f.type, f.is_required, f.options);
        });

        var attGroup = el('div', { className: 'form-group embed-attachments' });
        attGroup.appendChild(el('label', { htmlFor: 'attachments' }, ['Attachments (optional)']));
        var fileInputWrap = document.createElement('div');
        fileInputWrap.className = 'file-input-wrap';
        var attInput = document.createElement('input');
        attInput.type = 'file';
        attInput.name = 'attachments';
        attInput.multiple = true;
        attInput.id = 'attachments';
        fileInputWrap.appendChild(attInput);
        attGroup.appendChild(fileInputWrap);
        var attList = document.createElement('div');
        attList.className = 'embed-attachments-list';
        attList.setAttribute('aria-live', 'polite');
        attGroup.appendChild(attList);
        function updateAttList() {
            attList.innerHTML = '';
            var files = attInput.files;
            for (var i = 0; i < files.length; i++) {
                (function(idx) {
                    var span = document.createElement('span');
                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.textContent = '×';
                    remove.setAttribute('aria-label', 'Remove file');
                    remove.onclick = function() {
                        var dt = new DataTransfer();
                        for (var j = 0; j < attInput.files.length; j++) if (j !== idx) dt.items.add(attInput.files[j]);
                        attInput.files = dt.files;
                        updateAttList();
                    };
                    span.textContent = files[idx].name + ' ';
                    span.appendChild(remove);
                    attList.appendChild(span);
                })(i);
            }
        }
        attInput.onchange = updateAttList;
        form.appendChild(attGroup);

        var submitBtn = el('button', { type: 'submit', className: 'btn' }, ['Submit']);
        form.appendChild(submitBtn);

        if (typeof Quill !== 'undefined' && richEditors.length > 0) {
            richEditors.forEach(function(entry) {
                entry.quill = new Quill(entry.container, { theme: 'snow', placeholder: 'Type here…' });
            });
        }

        form.onsubmit = async function(e) {
            e.preventDefault();
            if (typeof Quill !== 'undefined' && richEditors.length > 0) {
                richEditors.forEach(function(entry) {
                    var html = entry.quill ? entry.quill.root.innerHTML : '';
                    entry.hidden.value = html;
                });
                var requiredEmpty = richEditors.some(function(entry) {
                    return entry.required && (!entry.quill || !entry.quill.getText().trim());
                });
                if (requiredEmpty) {
                    var errMsg = el('div', { className: 'msg error' }, ['Please fill in all required fields.']);
                    if (!form.querySelector('.msg.error')) form.insertBefore(errMsg, form.firstChild);
                    return;
                }
            }
            var fd = new FormData(form);
            var body = { subject: fd.get('subject') || '', contact_email: fd.get('contact_email') || '' };
            if (de.contact_name) body.contact_name = fd.get('contact_name') || '';
            if (de.description) body.description = fd.get('description') || '';
            (formConfig.fields || []).forEach(function(f) {
                var v = fd.get('field_' + f.name);
                if (v != null && String(v).trim() !== '') body['field_' + f.name] = String(v);
            });
            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending…';
            try {
                var res = await fetch(baseUrl + '/widget/helpdesks/' + helpdeskId + '/tickets', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body)
                });
                var data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Submission failed');
                ticketUuid = data.ticket_uuid;
                token = data.access_token;
                var files = attInput.files;
                if (files && files.length > 0) {
                    for (var i = 0; i < files.length; i++) {
                        var fd = new FormData();
                        fd.append('file', files[i]);
                        fd.append('access_token', token);
                        var upRes = await fetch(baseUrl + '/widget/helpdesks/' + helpdeskId + '/tickets/' + ticketUuid + '/attachments', {
                            method: 'POST',
                            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                            body: fd
                        });
                        if (!upRes.ok) {
                            var upData = await upRes.json().catch(function() { return {}; });
                            throw new Error(upData.message || 'Failed to upload attachment');
                        }
                    }
                }
                renderTicketView();
            } catch (err) {
                var msg = el('div', { className: 'msg error' }, [err.message || 'Something went wrong.']);
                form.insertBefore(msg, form.firstChild);
                submitBtn.disabled = false;
                submitBtn.textContent = 'Submit';
            }
        };

        formCard.appendChild(form);
        root.innerHTML = '';
        root.appendChild(formCard);
    }

    function renderTicketView() {
        root.innerHTML = '<div class="embed-card"><div class="loading">Loading ticket…</div></div>';
        if (!ticketUuid || !token) {
            root.innerHTML = '<div class="embed-card"><div class="msg error">Missing ticket or access token.</div></div>';
            return;
        }
        fetch(baseUrl + '/widget/tickets/' + ticketUuid + '?token=' + encodeURIComponent(token))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) throw new Error(data.error);
                var ticket = data.ticket;
                var replies = data.replies || [];
                root.innerHTML = '';

                var wrap = el('div', { className: 'ticket-card' });
                wrap.appendChild(el('div', { className: 'ticket-subject' }, [ticket.subject]));
                if (ticket.description) {
                    var descEl = document.createElement('div');
                    descEl.className = 'ticket-description';
                    descEl.innerHTML = ticket.description;
                    wrap.appendChild(descEl);
                }
                var repliesSection = el('div', { className: 'replies-section' });
                replies.forEach(function(r) {
                    var div = el('div', { className: 'reply ' + (r.from_contact ? 'contact' : 'agent') });
                    div.appendChild(el('div', { className: 'reply-meta' }, [new Date(r.created_at).toLocaleString() + (r.from_contact ? ' · You' : ' · Support')]));
                    var bodyEl = document.createElement('div');
                    bodyEl.className = 'reply-body';
                    bodyEl.innerHTML = r.body || '';
                    div.appendChild(bodyEl);
                    repliesSection.appendChild(div);
                });
                wrap.appendChild(repliesSection);

                var replyForm = el('form', { className: 'reply-form' });
                replyForm.appendChild(el('label', { htmlFor: 'reply-body' }, ['Reply']));
                var replyTa = el('textarea', { id: 'reply-body', name: 'body', rows: 3, placeholder: 'Type your reply…' });
                replyForm.appendChild(replyTa);
                var replyBtn = el('button', { type: 'submit', className: 'btn' }, ['Send reply']);
                replyForm.appendChild(replyBtn);

                replyForm.onsubmit = function(e) {
                    e.preventDefault();
                    var body = replyTa.value.trim();
                    if (!body) return;
                    replyBtn.disabled = true;
                    replyBtn.textContent = 'Sending…';
                    fetch(baseUrl + '/widget/helpdesks/' + helpdeskId + '/tickets/' + ticketUuid + '/replies', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ body: body, access_token: token })
                    }).then(function(r) { return r.json(); }).then(function(d) {
                        if (!d.success) throw new Error(d.message || 'Failed');
                        replyTa.value = '';
                        renderTicketView();
                    }).catch(function(err) {
                        var m = el('div', { className: 'msg error' }, [err.message || 'Failed to send.']);
                        replyForm.insertBefore(m, replyForm.firstChild);
                        replyBtn.disabled = false;
                        replyBtn.textContent = 'Send reply';
                    });
                };

                wrap.appendChild(replyForm);
                root.innerHTML = '';
                root.appendChild(wrap);
            })
            .catch(function(err) {
                root.innerHTML = '<div class="embed-card"><div class="msg error">' + (err.message || 'Failed to load ticket') + '</div></div>';
            });
    }

    function init() {
        fetch(baseUrl + '/widget/helpdesks/' + helpdeskId + '/form')
            .then(function(r) { return r.json(); })
            .then(function(data) {
                formConfig = data;
                if (ticketUuid && token) renderTicketView();
                else renderForm();
            })
            .catch(function() {
                formConfig = { fields: [], branding: { welcome_message: 'How can we help you?' } };
                if (ticketUuid && token) renderTicketView();
                else renderForm();
            });
    }
    init();
})();
    </script>
</body>
</html>
