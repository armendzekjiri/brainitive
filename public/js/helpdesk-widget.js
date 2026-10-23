(function(){"use strict";(function(){const k=document.currentScript;if(!k)return;const _=k.getAttribute("data-helpdesk-id"),q=k.getAttribute("data-button-text")||"Support",F=k.getAttribute("data-button-position")||"bottom-right",R=k.getAttribute("data-brand-color")||"#0E83DC",H=k.getAttribute("data-ticket-uuid"),M=k.getAttribute("data-token");if(!_){console.warn("[Helpdesk widget] data-helpdesk-id is required.");return}const T=k.src.replace(/\/js\/helpdesk-widget\.js.*$/,""),z=document.createElement("div");z.id="brainitive-helpdesk-widget",document.body.appendChild(z);const D=z.attachShadow({mode:"closed"}),X=`
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
    `;let b=null,v=H||null,S=M||null,N=!1,j=H&&M?"ticket":"form",B="";const A=document.createElement("div");D.appendChild(document.createElement("style")).textContent=X,D.appendChild(A);function P(t){const r=document.createElement("div");r.className="panel-header",r.style.borderTopColor=R,r.style.borderTopWidth="3px",r.style.borderTopStyle="solid",r.textContent=t;const n=document.createElement("button");return n.type="button",n.className="panel-close",n.textContent="×",n.setAttribute("aria-label","Close"),n.onclick=()=>{N=!1,L()},r.appendChild(n),r}function U(){const t=document.createElement("div");if(t.className="panel-body",!b)return t.textContent="Loading…",t;const r=document.createElement("p");r.style.marginBottom="16px",r.style.color="#666",r.textContent=b.branding.welcome_message||"How can we help you?",t.appendChild(r);const n=document.createElement("form");n.onsubmit=async o=>{o.preventDefault();const l=new FormData(n),a={subject:l.get("subject")||"",contact_email:l.get("contact_email")||""},i=l.get("description");i!=null&&String(i).trim()!==""&&(a.description=String(i).trim());const m=l.get("contact_name");m!=null&&String(m).trim()!==""&&(a.contact_name=String(m).trim()),b.fields.forEach(d=>{if(d.type==="checkboxes"){const p=l.getAll("field_"+d.name).map(String);p.length&&(a["field_"+d.name]=JSON.stringify(p));return}const e=l.get("field_"+d.name);e!=null&&String(e).trim()!==""&&(a["field_"+d.name]=String(e))});const s=n.querySelector('button[type="submit"]');s&&(s.disabled=!0,s.textContent="Sending…");try{const d=await fetch(T+"/widget/helpdesks/"+_+"/tickets",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify(a)}),e=await d.json();if(!d.ok)throw new Error(e.message||"Submission failed");v=e.ticket_uuid,S=e.access_token;const p=n.querySelector('input[name="attachments"]');if(p?.files&&p.files.length>0)for(let f=0;f<p.files.length;f++){const h=new FormData;h.append("file",p.files[f]),h.append("access_token",S??"");const O=await fetch(T+"/widget/helpdesks/"+_+"/tickets/"+v+"/attachments",{method:"POST",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:h});if(!O.ok){const J=await O.json().catch(()=>({}));throw new Error(J.message||"Failed to upload attachment")}}b?.texts?.after_submit==="message"?(B=e.message||b.texts.success_message,j="thanks"):j="ticket",L()}catch(d){const e=document.createElement("div");e.className="msg error",e.textContent=d instanceof Error?d.message:"Something went wrong. Please try again.",n.insertBefore(e,n.firstChild),s&&(s.disabled=!1,s.textContent=b?.texts?.submit_label||"Submit")}};const u=(o,l,a,i,m)=>{const s=document.createElement("div");s.className="form-group";const d=document.createElement("label");if(d.htmlFor=o,d.textContent=l,i&&d.classList.add("required"),s.appendChild(d),a==="select"&&m&&m.length){const e=document.createElement("select");e.id=e.name=o,e.required=i;const p=document.createElement("option");p.value="",p.textContent="— Select —",e.appendChild(p),m.forEach(f=>{const h=document.createElement("option");h.value=f,h.textContent=f,e.appendChild(h)}),s.appendChild(e)}else if(a==="checkbox"){const e=document.createElement("input");e.type="checkbox",e.id=e.name=o,e.value="1",e.required=i,s.appendChild(e)}else if((a==="radio"||a==="checkboxes")&&m&&m.length)m.forEach((e,p)=>{const f=document.createElement("label");f.style.display="flex",f.style.gap="6px",f.style.alignItems="center",f.style.fontWeight="normal";const h=document.createElement("input");h.type=a==="radio"?"radio":"checkbox",h.name=o,h.value=e,a==="radio"&&i&&p===0&&(h.required=!0),f.appendChild(h),f.appendChild(document.createTextNode(e)),s.appendChild(f)});else{const e=typeof a=="string"&&(a==="rich_text"||a==="textarea"||a.toLowerCase().replace(/[\s-]/g,"_")==="rich_text"),p=document.createElement(e?"textarea":"input");p.id=p.name=o,p.required=i,p instanceof HTMLInputElement?p.type=a==="number"?"number":a==="date"?"date":o==="contact_email"?"email":"text":p.rows=4,s.appendChild(p)}n.appendChild(s)},g=b.default_editable||{};g.contact_name&&u("contact_name",g.contact_name.label,"text",g.contact_name.is_required),u("contact_email","Email","text",!0),b.subject?.hidden||u("subject",b.subject?.label||"Subject","text",!0),g.description&&u("description",g.description.label,"textarea",g.description.is_required),b.fields.forEach(o=>{u("field_"+o.name,o.label,o.type,o.is_required,o.options)});const y=document.createElement("div");b.attachments_enabled===!1&&(y.style.display="none"),y.className="form-group";const w=document.createElement("label");w.htmlFor="attachments",w.textContent="Attachments (optional)",y.appendChild(w);const c=document.createElement("input");c.type="file",c.name="attachments",c.multiple=!0,c.id="attachments",c.style.marginTop="4px",y.appendChild(c);const x=document.createElement("div");x.className="attachments-list",x.setAttribute("aria-live","polite"),y.appendChild(x);const E=()=>{x.innerHTML="";const o=c.files;if(o)for(let l=0;l<o.length;l++){const a=document.createElement("span"),i=document.createElement("button");i.type="button",i.textContent="×",i.setAttribute("aria-label","Remove file");const m=l;i.onclick=()=>{const s=new DataTransfer;for(let d=0;d<c.files.length;d++)d!==m&&s.items.add(c.files[d]);c.files=s.files,E()},a.textContent=o[l].name+" ",a.appendChild(i),x.appendChild(a)}};c.onchange=E,n.appendChild(y);const C=document.createElement("button");return C.type="submit",C.className="btn btn-primary",C.textContent=b.texts?.submit_label||"Submit",n.appendChild(C),t.appendChild(n),t}function W(){const t=document.createElement("div");return t.className="panel-body",t.textContent="Loading…",t}async function I(t){if(!v||!S){t.innerHTML='<p class="msg error">Missing ticket or access token.</p>';return}try{const r=await fetch(T+"/widget/tickets/"+v+"?token="+encodeURIComponent(S)),n=await r.json();if(!r.ok)throw new Error(n.error||"Failed to load ticket");const u=n.ticket,g=n.replies;t.innerHTML="";const y=document.createElement("div");if(y.className="ticket-subject",y.textContent=u.subject,t.appendChild(y),u.description){const o=document.createElement("div");o.className="ticket-description",o.textContent=u.description.replace(/<[^>]+>/g,""),t.appendChild(o)}const w=document.createElement("div");w.style.marginTop="16px",w.style.marginBottom="16px",g.forEach(o=>{const l=document.createElement("div");l.className="reply "+(o.from_contact?"contact":"agent");const a=document.createElement("div");a.className="reply-meta",a.textContent=new Date(o.created_at).toLocaleString()+(o.from_contact?" · You":" · Support"),l.appendChild(a);const i=document.createElement("div");i.className="reply-body",i.textContent=o.body.replace(/<[^>]+>/g,""),l.appendChild(i),w.appendChild(l)}),t.appendChild(w);const c=document.createElement("form");c.onsubmit=async o=>{o.preventDefault();const l=c.querySelector("textarea"),a=l?.value?.trim();if(!a)return;const i=c.querySelector('button[type="submit"]');i&&(i.disabled=!0,i.textContent="Sending…");try{const m=await fetch(T+"/widget/helpdesks/"+_+"/tickets/"+v+"/replies",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify({body:a,access_token:S})}),s=await m.json();if(!m.ok)throw new Error(s.message||"Failed to send");l.value="",I(t)}catch(m){const s=document.createElement("div");s.className="msg error",s.textContent=m instanceof Error?m.message:"Failed to send reply.",c.insertBefore(s,c.firstChild),i&&(i.disabled=!1,i.textContent="Send reply")}};const x=document.createElement("label");x.htmlFor="reply-body",x.textContent="Reply",x.style.display="block",x.style.marginBottom="4px",c.appendChild(x);const E=document.createElement("textarea");E.id="reply-body",E.name="body",E.rows=3,E.placeholder="Type your reply…",c.appendChild(E);const C=document.createElement("button");C.type="submit",C.className="btn btn-primary",C.textContent="Send reply",c.appendChild(C),t.appendChild(c)}catch(r){const n=document.createElement("p");n.className="msg error",n.textContent=r instanceof Error?r.message:"Failed to load ticket",t.replaceChildren(n)}}function L(){A.innerHTML="";const t=document.createElement("button");if(t.type="button",t.className="floating-btn bottom-"+(F==="bottom-left"?"left":"right"),t.style.backgroundColor=R,t.textContent=q,t.setAttribute("aria-label",q),t.onclick=()=>{N=!N,L()},A.appendChild(t),N){const r=document.createElement("div");r.className="panel bottom-"+(F==="bottom-left"?"left":"right");let n="Support",u;if(j==="form")n=b?.texts?.form_title||"Contact support",u=U();else if(j==="thanks"){n=b?.texts?.form_title||"Thank you",u=document.createElement("div"),u.className="panel-body";const g=document.createElement("div");g.className="msg success",g.setAttribute("role","status"),g.textContent=B,u.appendChild(g)}else n="Your ticket",u=W(),v&&S&&I(u);r.appendChild(P(n)),r.appendChild(u),A.appendChild(r)}}(async function(){try{b=await(await fetch(T+"/widget/helpdesks/"+_+"/form")).json()}catch{b={helpdesk_uuid:_,fields:[],branding:{brand_color:R,welcome_message:"How can we help?",button_text:q,button_position:F}}}L()})()})()})();
