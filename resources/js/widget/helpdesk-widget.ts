/**
 * Helpdesk widget — form-first support with ticket view and replies.
 * Usage: <script src="https://your-app.com/js/helpdesk-widget.js" data-helpdesk-id="UUID" data-button-text="Support" data-button-position="bottom-right" data-brand-color="#0E83DC"></script>
 * With ticket context (e.g. from email link): add data-ticket-uuid and data-token to show ticket + replies.
 */
import { BLOCKED_UPLOAD_MESSAGE, isBlockedUpload } from '../lib/blockedUploads';

(function () {
    const script = document.currentScript as HTMLScriptElement | null;
    if (!script) return;

    const helpdeskId = script.getAttribute('data-helpdesk-id');
    const buttonText = script.getAttribute('data-button-text') || 'Support';
    const position = script.getAttribute('data-button-position') || 'bottom-right';
    const brandColor = script.getAttribute('data-brand-color') || '#0E83DC';
    const ticketUuid = script.getAttribute('data-ticket-uuid');
    const token = script.getAttribute('data-token');

    if (!helpdeskId) {
        console.warn('[Helpdesk widget] data-helpdesk-id is required.');
        return;
    }

    const baseUrl = script.src.replace(/\/js\/helpdesk-widget\.js.*$/, '');

    const container = document.createElement('div');
    container.id = 'brainitive-helpdesk-widget';
    document.body.appendChild(container);

    const shadow = container.attachShadow({ mode: 'closed' });

    const css = `
        * { box-sizing: border-box; }
        .panel {
            position: fixed;
            z-index: 2147483647;
            width: 100%;
            max-width: 420px;
            max-height: 85vh;
            bottom: 80px;
            right: 24px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            display: flex;
            flex-direction: column;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 14px;
        }
        .panel.bottom-left { right: auto; left: 24px; }
        .panel-header {
            padding: 16px;
            border-bottom: 1px solid #eee;
            font-weight: 600;
            color: #333;
        }
        .panel-body { padding: 16px; overflow-y: auto; flex: 1; }
        .panel-close {
            position: absolute;
            top: 12px;
            right: 12px;
            background: none;
            border: none;
            font-size: 20px;
            cursor: pointer;
            color: #666;
        }
        .btn {
            display: inline-block;
            padding: 10px 16px;
            border-radius: 8px;
            border: none;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-primary { background: #0E83DC; color: #fff; }
        .btn-primary:hover { background: #0b6fbf; }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; margin-bottom: 4px; font-weight: 500; color: #333; }
        .form-group input, .form-group textarea, .form-group select {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .form-group .required::after { content: " *"; color: #c00; }
        .msg { padding: 12px; border-radius: 8px; margin-bottom: 12px; }
        .msg.success { background: #e8f5e9; color: #2e7d32; }
        .msg.error { background: #ffebee; color: #c62828; }
        .reply { margin-bottom: 12px; padding: 10px 12px; border-radius: 8px; }
        .reply.agent { background: #e3f2fd; margin-left: 0; margin-right: 24px; }
        .reply.contact { background: #f5f5f5; margin-left: 24px; margin-right: 0; }
        .reply-meta { font-size: 12px; color: #666; margin-bottom: 4px; }
        .reply-body { white-space: pre-wrap; word-break: break-word; }
        .form-group .attachments-list { margin-top: 6px; font-size: 13px; color: #555; }
        .form-group .attachments-list span { display: inline-block; margin-right: 8px; margin-bottom: 4px; }
        .form-group .attachments-list button { margin-left: 4px; color: #999; background: none; border: none; cursor: pointer; padding: 0; font-size: 14px; }
        .form-group .attachments-list button:hover { color: #c00; }
        .ticket-subject { font-weight: 600; margin-bottom: 8px; }
        .ticket-description { margin-bottom: 16px; color: #555; white-space: pre-wrap; }
        .floating-btn {
            position: fixed;
            z-index: 2147483646;
            width: 56px;
            height: 56px;
            border-radius: 50%;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            color: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .floating-btn.bottom-right { bottom: 24px; right: 24px; }
        .floating-btn.bottom-left { bottom: 24px; left: 24px; }
    `;

    type FormConfig = {
        helpdesk_uuid: string;
        fields: Array<{ id: number; name: string; label: string; type: string; options?: string[]; is_required: boolean }>;
        branding: { brand_color: string; welcome_message: string; button_text: string; button_position: string };
        default_editable?: {
            contact_name?: { label: string; is_required: boolean };
            description?: { label: string; is_required: boolean };
        };
        attachments_enabled?: boolean;
        // Per-helpdesk texts and subject field (BRAI-92)
        texts?: { form_title: string; submit_label: string; message_placeholder: string; success_message: string; after_submit: 'portal' | 'message' };
        subject?: { label: string; hidden: boolean };
    };

    type TicketData = {
        ticket: { uuid: string; key: string; subject: string; description: string | null };
        replies: Array<{ id: number; body: string; created_at: string; from_contact: boolean }>;
    };

    let formConfig: FormConfig | null = null;
    let currentTicketUuid: string | null = ticketUuid || null;
    let currentToken: string | null = token || null;
    let panelOpen = false;
    let view: 'form' | 'ticket' | 'thanks' = ticketUuid && token ? 'ticket' : 'form';
    let thanksMessage = '';

    const root = document.createElement('div');
    shadow.appendChild(document.createElement('style')).textContent = css;
    shadow.appendChild(root);

    function renderPanelHeader(title: string) {
        const h = document.createElement('div');
        h.className = 'panel-header';
        h.style.borderTopColor = brandColor;
        h.style.borderTopWidth = '3px';
        h.style.borderTopStyle = 'solid';
        h.textContent = title;
        const close = document.createElement('button');
        close.type = 'button';
        close.className = 'panel-close';
        close.textContent = '×';
        close.setAttribute('aria-label', 'Close');
        close.onclick = () => {
            panelOpen = false;
            render();
        };
        h.appendChild(close);
        return h;
    }

    function renderForm() {
        const wrap = document.createElement('div');
        wrap.className = 'panel-body';
        if (!formConfig) {
            wrap.textContent = 'Loading…';
            return wrap;
        }
        const welcome = document.createElement('p');
        welcome.style.marginBottom = '16px';
        welcome.style.color = '#666';
        welcome.textContent = formConfig.branding.welcome_message || 'How can we help you?';
        wrap.appendChild(welcome);

        const form = document.createElement('form');
        form.onsubmit = async (e) => {
            e.preventDefault();
            const fd = new FormData(form);
            const body: Record<string, string> = {
                subject: (fd.get('subject') as string) || '',
                contact_email: (fd.get('contact_email') as string) || '',
            };
            const desc = fd.get('description');
            if (desc != null && String(desc).trim() !== '') body.description = String(desc).trim();
            const name = fd.get('contact_name');
            if (name != null && String(name).trim() !== '') body.contact_name = String(name).trim();
            formConfig!.fields.forEach((f) => {
                if (f.type === 'checkboxes') {
                    const picked = fd.getAll('field_' + f.name).map(String);
                    if (picked.length) body['field_' + f.name] = JSON.stringify(picked);
                    return;
                }
                const v = fd.get('field_' + f.name);
                if (v != null && String(v).trim() !== '') body['field_' + f.name] = String(v);
            });

            const submitBtn = form.querySelector('button[type="submit"]') as HTMLButtonElement;
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Sending…';
            }
            try {
                const res = await fetch(baseUrl + '/widget/helpdesks/' + helpdeskId + '/tickets', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: JSON.stringify(body),
                });
                const data = await res.json();
                if (!res.ok) throw new Error(data.message || 'Submission failed');
                currentTicketUuid = data.ticket_uuid;
                currentToken = data.access_token;
                const attInput = form.querySelector<HTMLInputElement>('input[name="attachments"]');
                if (attInput?.files && attInput.files.length > 0) {
                    for (let i = 0; i < attInput.files.length; i++) {
                        const fd = new FormData();
                        fd.append('file', attInput.files[i]);
                        fd.append('access_token', currentToken ?? '');
                        const upRes = await fetch(
                            baseUrl + '/widget/helpdesks/' + helpdeskId + '/tickets/' + currentTicketUuid + '/attachments',
                            { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body: fd }
                        );
                        if (!upRes.ok) {
                            const upData = (await upRes.json().catch(() => ({}))) as { message?: string };
                            throw new Error(upData.message || 'Failed to upload attachment');
                        }
                    }
                }
                if (formConfig?.texts?.after_submit === 'message') {
                    thanksMessage = data.message || formConfig.texts.success_message;
                    view = 'thanks';
                } else {
                    view = 'ticket';
                }
                render();
            } catch (err) {
                const msg = document.createElement('div');
                msg.className = 'msg error';
                msg.textContent = err instanceof Error ? err.message : 'Something went wrong. Please try again.';
                form.insertBefore(msg, form.firstChild);
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = formConfig?.texts?.submit_label || 'Submit';
                }
            }
        };

        const addField = (name: string, label: string, type: string, required: boolean, options?: string[]) => {
            const g = document.createElement('div');
            g.className = 'form-group';
            const lab = document.createElement('label');
            lab.htmlFor = name;
            lab.textContent = label;
            if (required) lab.classList.add('required');
            g.appendChild(lab);
            if (type === 'select' && options && options.length) {
                const sel = document.createElement('select');
                sel.id = sel.name = name;
                sel.required = required;
                const empty = document.createElement('option');
                empty.value = '';
                empty.textContent = '— Select —';
                sel.appendChild(empty);
                options.forEach((o) => {
                    const opt = document.createElement('option');
                    opt.value = o;
                    opt.textContent = o;
                    sel.appendChild(opt);
                });
                g.appendChild(sel);
            } else if (type === 'checkbox') {
                const inp = document.createElement('input');
                inp.type = 'checkbox';
                inp.id = inp.name = name;
                inp.value = '1';
                inp.required = required;
                g.appendChild(inp);
            } else if ((type === 'radio' || type === 'checkboxes') && options && options.length) {
                options.forEach((o, i) => {
                    const row = document.createElement('label');
                    row.style.display = 'flex';
                    row.style.gap = '6px';
                    row.style.alignItems = 'center';
                    row.style.fontWeight = 'normal';
                    const inp = document.createElement('input');
                    inp.type = type === 'radio' ? 'radio' : 'checkbox';
                    inp.name = name;
                    inp.value = o;
                    if (type === 'radio' && required && i === 0) inp.required = true;
                    row.appendChild(inp);
                    row.appendChild(document.createTextNode(o));
                    g.appendChild(row);
                });
            } else {
                const isLongText = typeof type === 'string' && (type === 'rich_text' || type === 'textarea' || type.toLowerCase().replace(/[\s-]/g, '_') === 'rich_text');
                const inp = document.createElement(isLongText ? 'textarea' : 'input');
                inp.id = inp.name = name;
                inp.required = required;
                if (inp instanceof HTMLInputElement) {
                    inp.type = type === 'number' ? 'number' : type === 'date' ? 'date' : name === 'contact_email' ? 'email' : 'text';
                } else {
                    (inp as HTMLTextAreaElement).rows = 4;
                }
                g.appendChild(inp);
            }
            form.appendChild(g);
        };

        const de = formConfig.default_editable || {};
        if (de.contact_name) addField('contact_name', de.contact_name.label, 'text', de.contact_name.is_required);
        addField('contact_email', 'Email', 'text', true);
        if (!formConfig.subject?.hidden) addField('subject', formConfig.subject?.label || 'Subject', 'text', true);
        if (de.description) addField('description', de.description.label, 'textarea', de.description.is_required);
        formConfig.fields.forEach((f) => {
            addField('field_' + f.name, f.label, f.type, f.is_required, f.options);
        });

        const attGroup = document.createElement('div');
        if (formConfig.attachments_enabled === false) attGroup.style.display = 'none';
        attGroup.className = 'form-group';
        const attLabel = document.createElement('label');
        attLabel.htmlFor = 'attachments';
        attLabel.textContent = 'Attachments (optional)';
        attGroup.appendChild(attLabel);
        const attInput = document.createElement('input');
        attInput.type = 'file';
        attInput.name = 'attachments';
        attInput.multiple = true;
        attInput.id = 'attachments';
        attInput.style.marginTop = '4px';
        attGroup.appendChild(attInput);
        const attList = document.createElement('div');
        attList.className = 'attachments-list';
        attList.setAttribute('aria-live', 'polite');
        attGroup.appendChild(attList);
        const updateAttList = () => {
            attList.innerHTML = '';
            const files = attInput.files;
            if (!files) return;
            for (let i = 0; i < files.length; i++) {
                const span = document.createElement('span');
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = '×';
                remove.setAttribute('aria-label', 'Remove file');
                const idx = i;
                remove.onclick = () => {
                    const dt = new DataTransfer();
                    for (let j = 0; j < attInput.files!.length; j++) if (j !== idx) dt.items.add(attInput.files![j]);
                    attInput.files = dt.files;
                    updateAttList();
                };
                span.textContent = files[i].name + ' ';
                span.appendChild(remove);
                attList.appendChild(span);
            }
        };
        attInput.onchange = () => {
            // programs / scripts are refused by the server too: drop them right away with a note
            const files = attInput.files;
            if (files && Array.from(files).some((f) => isBlockedUpload(f.name))) {
                const dt = new DataTransfer();
                Array.from(files).filter((f) => !isBlockedUpload(f.name)).forEach((f) => dt.items.add(f));
                attInput.files = dt.files;
                window.alert(BLOCKED_UPLOAD_MESSAGE);
            }
            updateAttList();
        };
        form.appendChild(attGroup);

        const sub = document.createElement('button');
        sub.type = 'submit';
        sub.className = 'btn btn-primary';
        sub.textContent = formConfig.texts?.submit_label || 'Submit';
        form.appendChild(sub);
        wrap.appendChild(form);
        return wrap;
    }

    function renderTicketView() {
        const wrap = document.createElement('div');
        wrap.className = 'panel-body';
        wrap.textContent = 'Loading…';
        return wrap;
    }

    async function loadTicketAndRenderTicketView(wrap: HTMLDivElement) {
        if (!currentTicketUuid || !currentToken) {
            wrap.innerHTML = '<p class="msg error">Missing ticket or access token.</p>';
            return;
        }
        try {
            const res = await fetch(baseUrl + '/widget/tickets/' + currentTicketUuid + '?token=' + encodeURIComponent(currentToken));
            const data: TicketData = await res.json();
            if (!res.ok) throw new Error((data as { error?: string }).error || 'Failed to load ticket');

            const ticket = (data as TicketData).ticket;
            const replies = (data as TicketData).replies;

            wrap.innerHTML = '';
            const subj = document.createElement('div');
            subj.className = 'ticket-subject';
            subj.textContent = ticket.subject;
            wrap.appendChild(subj);
            if (ticket.description) {
                const desc = document.createElement('div');
                desc.className = 'ticket-description';
                desc.textContent = ticket.description.replace(/<[^>]+>/g, '');
                wrap.appendChild(desc);
            }

            const list = document.createElement('div');
            list.style.marginTop = '16px';
            list.style.marginBottom = '16px';
            replies.forEach((r) => {
                const div = document.createElement('div');
                div.className = 'reply ' + (r.from_contact ? 'contact' : 'agent');
                const meta = document.createElement('div');
                meta.className = 'reply-meta';
                meta.textContent = new Date(r.created_at).toLocaleString() + (r.from_contact ? ' · You' : ' · Support');
                div.appendChild(meta);
                const body = document.createElement('div');
                body.className = 'reply-body';
                body.textContent = r.body.replace(/<[^>]+>/g, '');
                div.appendChild(body);
                list.appendChild(div);
            });
            wrap.appendChild(list);

            const replyForm = document.createElement('form');
            replyForm.onsubmit = async (e) => {
                e.preventDefault();
                const ta = replyForm.querySelector('textarea') as HTMLTextAreaElement;
                const body = ta?.value?.trim();
                if (!body) return;
                const subBtn = replyForm.querySelector('button[type="submit"]') as HTMLButtonElement;
                if (subBtn) {
                    subBtn.disabled = true;
                    subBtn.textContent = 'Sending…';
                }
                try {
                    const replyRes = await fetch(baseUrl + '/widget/helpdesks/' + helpdeskId + '/tickets/' + currentTicketUuid + '/replies', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        body: JSON.stringify({ body, access_token: currentToken }),
                    });
                    const replyData = await replyRes.json();
                    if (!replyRes.ok) throw new Error(replyData.message || 'Failed to send');
                    ta.value = '';
                    loadTicketAndRenderTicketView(wrap);
                } catch (err) {
                    const msg = document.createElement('div');
                    msg.className = 'msg error';
                    msg.textContent = err instanceof Error ? err.message : 'Failed to send reply.';
                    replyForm.insertBefore(msg, replyForm.firstChild);
                    if (subBtn) {
                        subBtn.disabled = false;
                        subBtn.textContent = 'Send reply';
                    }
                }
            };
            const replyLabel = document.createElement('label');
            replyLabel.htmlFor = 'reply-body';
            replyLabel.textContent = 'Reply';
            replyLabel.style.display = 'block';
            replyLabel.style.marginBottom = '4px';
            replyForm.appendChild(replyLabel);
            const replyTa = document.createElement('textarea');
            replyTa.id = 'reply-body';
            replyTa.name = 'body';
            replyTa.rows = 3;
            replyTa.placeholder = 'Type your reply…';
            replyForm.appendChild(replyTa);
            const replySub = document.createElement('button');
            replySub.type = 'submit';
            replySub.className = 'btn btn-primary';
            replySub.textContent = 'Send reply';
            replyForm.appendChild(replySub);
            wrap.appendChild(replyForm);
        } catch (err) {
            const msg = document.createElement('p');
            msg.className = 'msg error';
            msg.textContent = err instanceof Error ? err.message : 'Failed to load ticket';
            wrap.replaceChildren(msg);
        }
    }

    function render() {
        root.innerHTML = '';
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'floating-btn bottom-' + (position === 'bottom-left' ? 'left' : 'right');
        btn.style.backgroundColor = brandColor;
        btn.textContent = buttonText;
        btn.setAttribute('aria-label', buttonText);
        btn.onclick = () => {
            panelOpen = !panelOpen;
            render();
        };
        root.appendChild(btn);

        if (panelOpen) {
            const panel = document.createElement('div');
            panel.className = 'panel bottom-' + (position === 'bottom-left' ? 'left' : 'right');
            let title = 'Support';
            let body: HTMLDivElement;
            if (view === 'form') {
                title = formConfig?.texts?.form_title || 'Contact support';
                body = renderForm();
            } else if (view === 'thanks') {
                title = formConfig?.texts?.form_title || 'Thank you';
                body = document.createElement('div');
                body.className = 'panel-body';
                const msg = document.createElement('div');
                msg.className = 'msg success';
                msg.setAttribute('role', 'status');
                msg.textContent = thanksMessage;
                body.appendChild(msg);
            } else {
                title = 'Your ticket';
                body = renderTicketView();
                if (currentTicketUuid && currentToken) loadTicketAndRenderTicketView(body);
            }
            panel.appendChild(renderPanelHeader(title));
            panel.appendChild(body);
            root.appendChild(panel);
        }
    }

    (async function init() {
        try {
            const res = await fetch(baseUrl + '/widget/helpdesks/' + helpdeskId + '/form');
            formConfig = await res.json();
        } catch (_) {
            formConfig = { helpdesk_uuid: helpdeskId, fields: [], branding: { brand_color: brandColor, welcome_message: 'How can we help?', button_text: buttonText, button_position: position } };
        }
        render();
    })();
})();
