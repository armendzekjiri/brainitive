(function(){"use strict";(function(){const h=document.currentScript;if(!h)return;const E=h.getAttribute("data-helpdesk-id"),q=h.getAttribute("data-button-text")||"Support",A=h.getAttribute("data-button-position")||"bottom-right",F=h.getAttribute("data-brand-color")||"#0E83DC",R=h.getAttribute("data-ticket-uuid"),z=h.getAttribute("data-token");if(!E){console.warn("[Helpdesk widget] data-helpdesk-id is required.");return}const T=h.src.replace(/\/js\/helpdesk-widget\.js.*$/,""),H=document.createElement("div");H.id="brainitive-helpdesk-widget",document.body.appendChild(H);const M=H.attachShadow({mode:"closed"}),O=`
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
    `;let k=null,v=R||null,S=z||null,j=!1,D=R&&z?"ticket":"form";const N=document.createElement("div");M.appendChild(document.createElement("style")).textContent=O,M.appendChild(N);function X(t){const n=document.createElement("div");n.className="panel-header",n.style.borderTopColor=F,n.style.borderTopWidth="3px",n.style.borderTopStyle="solid",n.textContent=t;const o=document.createElement("button");return o.type="button",o.className="panel-close",o.textContent="×",o.setAttribute("aria-label","Close"),o.onclick=()=>{j=!1,L()},n.appendChild(o),n}function P(){const t=document.createElement("div");if(t.className="panel-body",!k)return t.textContent="Loading…",t;const n=document.createElement("p");n.style.marginBottom="16px",n.style.color="#666",n.textContent=k.branding.welcome_message||"How can we help you?",t.appendChild(n);const o=document.createElement("form");o.onsubmit=async e=>{e.preventDefault();const l=new FormData(o),i={subject:l.get("subject")||"",contact_email:l.get("contact_email")||""},r=l.get("description");r!=null&&String(r).trim()!==""&&(i.description=String(r).trim());const m=l.get("contact_name");m!=null&&String(m).trim()!==""&&(i.contact_name=String(m).trim()),k.fields.forEach(d=>{const a=l.get("field_"+d.name);a!=null&&String(a).trim()!==""&&(i["field_"+d.name]=String(a))});const c=o.querySelector('button[type="submit"]');c&&(c.disabled=!0,c.textContent="Sending…");try{const d=await fetch(T+"/widget/helpdesks/"+E+"/tickets",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify(i)}),a=await d.json();if(!d.ok)throw new Error(a.message||"Submission failed");v=a.ticket_uuid,S=a.access_token;const p=o.querySelector('input[name="attachments"]');if(p?.files&&p.files.length>0)for(let _=0;_<p.files.length;_++){const w=new FormData;w.append("file",p.files[_]),w.append("access_token",S??"");const I=await fetch(T+"/widget/helpdesks/"+E+"/tickets/"+v+"/attachments",{method:"POST",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:w});if(!I.ok){const W=await I.json().catch(()=>({}));throw new Error(W.message||"Failed to upload attachment")}}D="ticket",L()}catch(d){const a=document.createElement("div");a.className="msg error",a.textContent=d instanceof Error?d.message:"Something went wrong. Please try again.",o.insertBefore(a,o.firstChild),c&&(c.disabled=!1,c.textContent="Submit")}};const u=(e,l,i,r,m)=>{const c=document.createElement("div");c.className="form-group";const d=document.createElement("label");if(d.htmlFor=e,d.textContent=l,r&&d.classList.add("required"),c.appendChild(d),i==="select"&&m&&m.length){const a=document.createElement("select");a.id=a.name=e,a.required=r;const p=document.createElement("option");p.value="",p.textContent="— Select —",a.appendChild(p),m.forEach(_=>{const w=document.createElement("option");w.value=_,w.textContent=_,a.appendChild(w)}),c.appendChild(a)}else if(i==="checkbox"){const a=document.createElement("input");a.type="checkbox",a.id=a.name=e,c.appendChild(a)}else{const a=typeof i=="string"&&(i==="rich_text"||i==="textarea"||i.toLowerCase().replace(/[\s-]/g,"_")==="rich_text"),p=document.createElement(a?"textarea":"input");p.id=p.name=e,p.required=r,p instanceof HTMLInputElement?p.type=i==="number"?"number":"text":p.rows=4,c.appendChild(p)}o.appendChild(c)},x=k.default_editable||{};x.contact_name&&u("contact_name",x.contact_name.label,"text",x.contact_name.is_required),u("contact_email","Email","text",!0),u("subject","Subject","text",!0),x.description&&u("description",x.description.label,"textarea",x.description.is_required),k.fields.forEach(e=>{u("field_"+e.name,e.label,e.type,e.is_required,e.options)});const f=document.createElement("div");f.className="form-group";const y=document.createElement("label");y.htmlFor="attachments",y.textContent="Attachments (optional)",f.appendChild(y);const s=document.createElement("input");s.type="file",s.name="attachments",s.multiple=!0,s.id="attachments",s.style.marginTop="4px",f.appendChild(s);const b=document.createElement("div");b.className="attachments-list",b.setAttribute("aria-live","polite"),f.appendChild(b);const C=()=>{b.innerHTML="";const e=s.files;if(e)for(let l=0;l<e.length;l++){const i=document.createElement("span"),r=document.createElement("button");r.type="button",r.textContent="×",r.setAttribute("aria-label","Remove file");const m=l;r.onclick=()=>{const c=new DataTransfer;for(let d=0;d<s.files.length;d++)d!==m&&c.items.add(s.files[d]);s.files=c.files,C()},i.textContent=e[l].name+" ",i.appendChild(r),b.appendChild(i)}};s.onchange=C,o.appendChild(f);const g=document.createElement("button");return g.type="submit",g.className="btn btn-primary",g.textContent="Submit",o.appendChild(g),t.appendChild(o),t}function U(){const t=document.createElement("div");return t.className="panel-body",t.textContent="Loading…",t}async function B(t){if(!v||!S){t.innerHTML='<p class="msg error">Missing ticket or access token.</p>';return}try{const n=await fetch(T+"/widget/tickets/"+v+"?token="+encodeURIComponent(S)),o=await n.json();if(!n.ok)throw new Error(o.error||"Failed to load ticket");const u=o.ticket,x=o.replies;t.innerHTML="";const f=document.createElement("div");if(f.className="ticket-subject",f.textContent=u.subject,t.appendChild(f),u.description){const e=document.createElement("div");e.className="ticket-description",e.textContent=u.description.replace(/<[^>]+>/g,""),t.appendChild(e)}const y=document.createElement("div");y.style.marginTop="16px",y.style.marginBottom="16px",x.forEach(e=>{const l=document.createElement("div");l.className="reply "+(e.from_contact?"contact":"agent");const i=document.createElement("div");i.className="reply-meta",i.textContent=new Date(e.created_at).toLocaleString()+(e.from_contact?" · You":" · Support"),l.appendChild(i);const r=document.createElement("div");r.className="reply-body",r.textContent=e.body.replace(/<[^>]+>/g,""),l.appendChild(r),y.appendChild(l)}),t.appendChild(y);const s=document.createElement("form");s.onsubmit=async e=>{e.preventDefault();const l=s.querySelector("textarea"),i=l?.value?.trim();if(!i)return;const r=s.querySelector('button[type="submit"]');r&&(r.disabled=!0,r.textContent="Sending…");try{const m=await fetch(T+"/widget/helpdesks/"+E+"/tickets/"+v+"/replies",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify({body:i,access_token:S})}),c=await m.json();if(!m.ok)throw new Error(c.message||"Failed to send");l.value="",B(t)}catch(m){const c=document.createElement("div");c.className="msg error",c.textContent=m instanceof Error?m.message:"Failed to send reply.",s.insertBefore(c,s.firstChild),r&&(r.disabled=!1,r.textContent="Send reply")}};const b=document.createElement("label");b.htmlFor="reply-body",b.textContent="Reply",b.style.display="block",b.style.marginBottom="4px",s.appendChild(b);const C=document.createElement("textarea");C.id="reply-body",C.name="body",C.rows=3,C.placeholder="Type your reply…",s.appendChild(C);const g=document.createElement("button");g.type="submit",g.className="btn btn-primary",g.textContent="Send reply",s.appendChild(g),t.appendChild(s)}catch(n){t.innerHTML='<p class="msg error">'+(n instanceof Error?n.message:"Failed to load ticket")+"</p>"}}function L(){N.innerHTML="";const t=document.createElement("button");if(t.type="button",t.className="floating-btn bottom-"+(A==="bottom-left"?"left":"right"),t.style.backgroundColor=F,t.textContent=q,t.setAttribute("aria-label",q),t.onclick=()=>{j=!j,L()},N.appendChild(t),j){const n=document.createElement("div");n.className="panel bottom-"+(A==="bottom-left"?"left":"right");let o="Support",u;D==="form"?(o="Contact support",u=P()):(o="Your ticket",u=U(),v&&S&&B(u)),n.appendChild(X(o)),n.appendChild(u),N.appendChild(n)}}(async function(){try{k=await(await fetch(T+"/widget/helpdesks/"+E+"/form")).json()}catch{k={helpdesk_uuid:E,fields:[],branding:{brand_color:F,welcome_message:"How can we help?",button_text:q,button_position:A}}}L()})()})()})();
