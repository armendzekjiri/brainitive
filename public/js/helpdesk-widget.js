(function(){"use strict";(function(){const h=document.currentScript;if(!h)return;const E=h.getAttribute("data-helpdesk-id"),q=h.getAttribute("data-button-text")||"Support",A=h.getAttribute("data-button-position")||"bottom-right",F=h.getAttribute("data-brand-color")||"#0E83DC",z=h.getAttribute("data-ticket-uuid"),H=h.getAttribute("data-token");if(!E){console.warn("[Helpdesk widget] data-helpdesk-id is required.");return}const T=h.src.replace(/\/js\/helpdesk-widget\.js.*$/,""),R=document.createElement("div");R.id="brainitive-helpdesk-widget",document.body.appendChild(R);const D=R.attachShadow({mode:"closed"}),O=`
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
    `;let k=null,v=z||null,S=H||null,j=!1,M=z&&H?"ticket":"form";const N=document.createElement("div");D.appendChild(document.createElement("style")).textContent=O,D.appendChild(N);function X(t){const o=document.createElement("div");o.className="panel-header",o.style.borderTopColor=F,o.style.borderTopWidth="3px",o.style.borderTopStyle="solid",o.textContent=t;const e=document.createElement("button");return e.type="button",e.className="panel-close",e.textContent="×",e.setAttribute("aria-label","Close"),e.onclick=()=>{j=!1,L()},o.appendChild(e),o}function P(){const t=document.createElement("div");if(t.className="panel-body",!k)return t.textContent="Loading…",t;const o=document.createElement("p");o.style.marginBottom="16px",o.style.color="#666",o.textContent=k.branding.welcome_message||"How can we help you?",t.appendChild(o);const e=document.createElement("form");e.onsubmit=async n=>{n.preventDefault();const l=new FormData(e),i={subject:l.get("subject")||"",contact_email:l.get("contact_email")||""},r=l.get("description");r!=null&&String(r).trim()!==""&&(i.description=String(r).trim());const m=l.get("contact_name");m!=null&&String(m).trim()!==""&&(i.contact_name=String(m).trim()),k.fields.forEach(d=>{const a=l.get("field_"+d.name);a!=null&&String(a).trim()!==""&&(i["field_"+d.name]=String(a))});const c=e.querySelector('button[type="submit"]');c&&(c.disabled=!0,c.textContent="Sending…");try{const d=await fetch(T+"/widget/helpdesks/"+E+"/tickets",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify(i)}),a=await d.json();if(!d.ok)throw new Error(a.message||"Submission failed");v=a.ticket_uuid,S=a.access_token;const p=e.querySelector('input[name="attachments"]');if(p?.files&&p.files.length>0)for(let _=0;_<p.files.length;_++){const w=new FormData;w.append("file",p.files[_]),w.append("access_token",S??"");const I=await fetch(T+"/widget/helpdesks/"+E+"/tickets/"+v+"/attachments",{method:"POST",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:w});if(!I.ok){const W=await I.json().catch(()=>({}));throw new Error(W.message||"Failed to upload attachment")}}M="ticket",L()}catch(d){const a=document.createElement("div");a.className="msg error",a.textContent=d instanceof Error?d.message:"Something went wrong. Please try again.",e.insertBefore(a,e.firstChild),c&&(c.disabled=!1,c.textContent="Submit")}};const u=(n,l,i,r,m)=>{const c=document.createElement("div");c.className="form-group";const d=document.createElement("label");if(d.htmlFor=n,d.textContent=l,r&&d.classList.add("required"),c.appendChild(d),i==="select"&&m&&m.length){const a=document.createElement("select");a.id=a.name=n,a.required=r;const p=document.createElement("option");p.value="",p.textContent="— Select —",a.appendChild(p),m.forEach(_=>{const w=document.createElement("option");w.value=_,w.textContent=_,a.appendChild(w)}),c.appendChild(a)}else if(i==="checkbox"){const a=document.createElement("input");a.type="checkbox",a.id=a.name=n,c.appendChild(a)}else{const a=typeof i=="string"&&(i==="rich_text"||i==="textarea"||i.toLowerCase().replace(/[\s-]/g,"_")==="rich_text"),p=document.createElement(a?"textarea":"input");p.id=p.name=n,p.required=r,p instanceof HTMLInputElement?p.type=i==="number"?"number":"text":p.rows=4,c.appendChild(p)}e.appendChild(c)},x=k.default_editable||{};x.contact_name&&u("contact_name",x.contact_name.label,"text",x.contact_name.is_required),u("contact_email","Email","text",!0),u("subject","Subject","text",!0),x.description&&u("description",x.description.label,"textarea",x.description.is_required),k.fields.forEach(n=>{u("field_"+n.name,n.label,n.type,n.is_required,n.options)});const f=document.createElement("div");f.className="form-group";const y=document.createElement("label");y.htmlFor="attachments",y.textContent="Attachments (optional)",f.appendChild(y);const s=document.createElement("input");s.type="file",s.name="attachments",s.multiple=!0,s.id="attachments",s.style.marginTop="4px",f.appendChild(s);const b=document.createElement("div");b.className="attachments-list",b.setAttribute("aria-live","polite"),f.appendChild(b);const C=()=>{b.innerHTML="";const n=s.files;if(n)for(let l=0;l<n.length;l++){const i=document.createElement("span"),r=document.createElement("button");r.type="button",r.textContent="×",r.setAttribute("aria-label","Remove file");const m=l;r.onclick=()=>{const c=new DataTransfer;for(let d=0;d<s.files.length;d++)d!==m&&c.items.add(s.files[d]);s.files=c.files,C()},i.textContent=n[l].name+" ",i.appendChild(r),b.appendChild(i)}};s.onchange=C,e.appendChild(f);const g=document.createElement("button");return g.type="submit",g.className="btn btn-primary",g.textContent="Submit",e.appendChild(g),t.appendChild(e),t}function U(){const t=document.createElement("div");return t.className="panel-body",t.textContent="Loading…",t}async function B(t){if(!v||!S){t.innerHTML='<p class="msg error">Missing ticket or access token.</p>';return}try{const o=await fetch(T+"/widget/tickets/"+v+"?token="+encodeURIComponent(S)),e=await o.json();if(!o.ok)throw new Error(e.error||"Failed to load ticket");const u=e.ticket,x=e.replies;t.innerHTML="";const f=document.createElement("div");if(f.className="ticket-subject",f.textContent=u.subject,t.appendChild(f),u.description){const n=document.createElement("div");n.className="ticket-description",n.textContent=u.description.replace(/<[^>]+>/g,""),t.appendChild(n)}const y=document.createElement("div");y.style.marginTop="16px",y.style.marginBottom="16px",x.forEach(n=>{const l=document.createElement("div");l.className="reply "+(n.from_contact?"contact":"agent");const i=document.createElement("div");i.className="reply-meta",i.textContent=new Date(n.created_at).toLocaleString()+(n.from_contact?" · You":" · Support"),l.appendChild(i);const r=document.createElement("div");r.className="reply-body",r.textContent=n.body.replace(/<[^>]+>/g,""),l.appendChild(r),y.appendChild(l)}),t.appendChild(y);const s=document.createElement("form");s.onsubmit=async n=>{n.preventDefault();const l=s.querySelector("textarea"),i=l?.value?.trim();if(!i)return;const r=s.querySelector('button[type="submit"]');r&&(r.disabled=!0,r.textContent="Sending…");try{const m=await fetch(T+"/widget/helpdesks/"+E+"/tickets/"+v+"/replies",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify({body:i,access_token:S})}),c=await m.json();if(!m.ok)throw new Error(c.message||"Failed to send");l.value="",B(t)}catch(m){const c=document.createElement("div");c.className="msg error",c.textContent=m instanceof Error?m.message:"Failed to send reply.",s.insertBefore(c,s.firstChild),r&&(r.disabled=!1,r.textContent="Send reply")}};const b=document.createElement("label");b.htmlFor="reply-body",b.textContent="Reply",b.style.display="block",b.style.marginBottom="4px",s.appendChild(b);const C=document.createElement("textarea");C.id="reply-body",C.name="body",C.rows=3,C.placeholder="Type your reply…",s.appendChild(C);const g=document.createElement("button");g.type="submit",g.className="btn btn-primary",g.textContent="Send reply",s.appendChild(g),t.appendChild(s)}catch(o){const e=document.createElement("p");e.className="msg error",e.textContent=o instanceof Error?o.message:"Failed to load ticket",t.replaceChildren(e)}}function L(){N.innerHTML="";const t=document.createElement("button");if(t.type="button",t.className="floating-btn bottom-"+(A==="bottom-left"?"left":"right"),t.style.backgroundColor=F,t.textContent=q,t.setAttribute("aria-label",q),t.onclick=()=>{j=!j,L()},N.appendChild(t),j){const o=document.createElement("div");o.className="panel bottom-"+(A==="bottom-left"?"left":"right");let e="Support",u;M==="form"?(e="Contact support",u=P()):(e="Your ticket",u=U(),v&&S&&B(u)),o.appendChild(X(e)),o.appendChild(u),N.appendChild(o)}}(async function(){try{k=await(await fetch(T+"/widget/helpdesks/"+E+"/form")).json()}catch{k={helpdesk_uuid:E,fields:[],branding:{brand_color:F,welcome_message:"How can we help?",button_text:q,button_position:A}}}L()})()})()})();
