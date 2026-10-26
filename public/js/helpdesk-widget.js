(function(){"use strict";const X=new Set(["exe","msi","msp","msix","appx","com","scr","pif","cpl","dll","sys","lnk","reg","inf","bat","cmd","ps1","psm1","psd1","vbs","vbe","js","jse","mjs","wsf","wsh","hta","msc","jar","class","apk","aab","ipa","app","dmg","pkg","mpkg","command","deb","rpm","appimage","run","snap","sh","bash","zsh","csh","ksh","fish","py","pyc","pyw","pl","rb","lua","php","phtml","phar","cgi","asp","aspx","jsp","html","htm","xhtml","xhtm","svg","svgz","xpi","crx","so","dylib","elf","iso","img","vhd","vhdx"]);function R(C){return C.toLowerCase().split(".").slice(1).some(E=>X.has(E.trim()))}const W="Programs and scripts can't be attached here.";(function(){const C=document.currentScript;if(!C)return;const E=C.getAttribute("data-helpdesk-id"),z=C.getAttribute("data-button-text")||"Support",D=C.getAttribute("data-button-position")||"bottom-right",F=C.getAttribute("data-brand-color")||"#0E83DC",M=C.getAttribute("data-ticket-uuid"),B=C.getAttribute("data-token");if(!E){console.warn("[Helpdesk widget] data-helpdesk-id is required.");return}const j=C.src.replace(/\/js\/helpdesk-widget\.js.*$/,""),H=document.createElement("div");H.id="brainitive-helpdesk-widget",document.body.appendChild(H);const O=H.attachShadow({mode:"closed"}),J=`
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
        .privacy-note { margin: 10px 0 0; font-size: 11px; line-height: 1.4; color: #6b7280; }
        .privacy-note a { color: inherit; text-decoration: underline; }
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
    `;let u=null,S=M||null,T=B||null,N=!1,A=M&&B?"ticket":"form",P="";const L=document.createElement("div");O.appendChild(document.createElement("style")).textContent=J,O.appendChild(L);function $(a){const i=document.createElement("div");i.className="panel-header",i.style.borderTopColor=F,i.style.borderTopWidth="3px",i.style.borderTopStyle="solid",i.textContent=a;const n=document.createElement("button");return n.type="button",n.className="panel-close",n.textContent="×",n.setAttribute("aria-label","Close"),n.onclick=()=>{N=!1,q()},i.appendChild(n),i}function G(){const a=document.createElement("div");if(a.className="panel-body",!u)return a.textContent="Loading…",a;const i=document.createElement("p");i.style.marginBottom="16px",i.style.color="#666",i.textContent=u.branding.welcome_message||"How can we help you?",a.appendChild(i);const n=document.createElement("form");n.onsubmit=async t=>{t.preventDefault();const r=new FormData(n),e={subject:r.get("subject")||"",contact_email:r.get("contact_email")||""},c=r.get("description");c!=null&&String(c).trim()!==""&&(e.description=String(c).trim());const m=r.get("contact_name");m!=null&&String(m).trim()!==""&&(e.contact_name=String(m).trim()),u.fields.forEach(l=>{if(l.type==="checkboxes"){const d=r.getAll("field_"+l.name).map(String);d.length&&(e["field_"+l.name]=JSON.stringify(d));return}const o=r.get("field_"+l.name);o!=null&&String(o).trim()!==""&&(e["field_"+l.name]=String(o))});const b=n.querySelector('button[type="submit"]');b&&(b.disabled=!0,b.textContent="Sending…");try{const l=await fetch(j+"/widget/helpdesks/"+E+"/tickets",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify(e)}),o=await l.json();if(!l.ok)throw new Error(o.message||"Submission failed");S=o.ticket_uuid,T=o.access_token;const d=n.querySelector('input[name="attachments"]');if(d?.files&&d.files.length>0)for(let h=0;h<d.files.length;h++){const x=new FormData;x.append("file",d.files[h]),x.append("access_token",T??"");const U=await fetch(j+"/widget/helpdesks/"+E+"/tickets/"+S+"/attachments",{method:"POST",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:x});if(!U.ok){const V=await U.json().catch(()=>({}));throw new Error(V.message||"Failed to upload attachment")}}u?.texts?.after_submit==="message"?(P=o.message||u.texts.success_message,A="thanks"):A="ticket",q()}catch(l){const o=document.createElement("div");o.className="msg error",o.textContent=l instanceof Error?l.message:"Something went wrong. Please try again.",n.insertBefore(o,n.firstChild),b&&(b.disabled=!1,b.textContent=u?.texts?.submit_label||"Submit")}};const f=(t,r,e,c,m)=>{const b=document.createElement("div");b.className="form-group";const l=document.createElement("label");if(l.htmlFor=t,l.textContent=r,c&&l.classList.add("required"),b.appendChild(l),e==="select"&&m&&m.length){const o=document.createElement("select");o.id=o.name=t,o.required=c;const d=document.createElement("option");d.value="",d.textContent="— Select —",o.appendChild(d),m.forEach(h=>{const x=document.createElement("option");x.value=h,x.textContent=h,o.appendChild(x)}),b.appendChild(o)}else if(e==="checkbox"){const o=document.createElement("input");o.type="checkbox",o.id=o.name=t,o.value="1",o.required=c,b.appendChild(o)}else if((e==="radio"||e==="checkboxes")&&m&&m.length)m.forEach((o,d)=>{const h=document.createElement("label");h.style.display="flex",h.style.gap="6px",h.style.alignItems="center",h.style.fontWeight="normal";const x=document.createElement("input");x.type=e==="radio"?"radio":"checkbox",x.name=t,x.value=o,e==="radio"&&c&&d===0&&(x.required=!0),h.appendChild(x),h.appendChild(document.createTextNode(o)),b.appendChild(h)});else{const o=typeof e=="string"&&(e==="rich_text"||e==="textarea"||e.toLowerCase().replace(/[\s-]/g,"_")==="rich_text"),d=document.createElement(o?"textarea":"input");d.id=d.name=t,d.required=c,d instanceof HTMLInputElement?d.type=e==="number"?"number":e==="date"?"date":t==="contact_email"?"email":"text":d.rows=4,b.appendChild(d)}n.appendChild(b)},g=u.default_editable||{};g.contact_name&&f("contact_name",g.contact_name.label,"text",g.contact_name.is_required),f("contact_email","Email","text",!0),u.subject?.hidden||f("subject",u.subject?.label||"Subject","text",!0),g.description&&f("description",g.description.label,"textarea",g.description.is_required),u.fields.forEach(t=>{f("field_"+t.name,t.label,t.type,t.is_required,t.options)});const k=document.createElement("div");u.attachments_enabled===!1&&(k.style.display="none"),k.className="form-group";const v=document.createElement("label");v.htmlFor="attachments",v.textContent="Attachments (optional)",k.appendChild(v);const s=document.createElement("input");s.type="file",s.name="attachments",s.multiple=!0,s.id="attachments",s.style.marginTop="4px",k.appendChild(s);const y=document.createElement("div");y.className="attachments-list",y.setAttribute("aria-live","polite"),k.appendChild(y);const _=()=>{y.innerHTML="";const t=s.files;if(t)for(let r=0;r<t.length;r++){const e=document.createElement("span"),c=document.createElement("button");c.type="button",c.textContent="×",c.setAttribute("aria-label","Remove file");const m=r;c.onclick=()=>{const b=new DataTransfer;for(let l=0;l<s.files.length;l++)l!==m&&b.items.add(s.files[l]);s.files=b.files,_()},e.textContent=t[r].name+" ",e.appendChild(c),y.appendChild(e)}};s.onchange=()=>{const t=s.files;if(t&&Array.from(t).some(r=>R(r.name))){const r=new DataTransfer;Array.from(t).filter(e=>!R(e.name)).forEach(e=>r.items.add(e)),s.files=r.files,window.alert(W)}_()},n.appendChild(k);const w=document.createElement("button");w.type="submit",w.className="btn btn-primary",w.textContent=u.texts?.submit_label||"Submit",n.appendChild(w);const p=u.operator;if(p){const t=document.createElement("p");t.className="privacy-note",t.appendChild(document.createTextNode(`${p.name} uses what you send here to answer your request. `));const r=p.privacy_url&&/^https:\/\//i.test(p.privacy_url)?p.privacy_url:p.platform_privacy_url,e=document.createElement("a");e.href=r,e.target="_blank",e.rel="noopener noreferrer",e.textContent=p.privacy_url?"Privacy policy":`How ${p.platform_name} hosts this form`,t.appendChild(e),n.appendChild(t)}return a.appendChild(n),a}function K(){const a=document.createElement("div");return a.className="panel-body",a.textContent="Loading…",a}async function I(a){if(!S||!T){a.innerHTML='<p class="msg error">Missing ticket or access token.</p>';return}try{const i=await fetch(j+"/widget/tickets/"+S+"?token="+encodeURIComponent(T)),n=await i.json();if(!i.ok)throw new Error(n.error||"Failed to load ticket");const f=n.ticket,g=n.replies;a.innerHTML="";const k=document.createElement("div");if(k.className="ticket-subject",k.textContent=f.subject,a.appendChild(k),f.description){const p=document.createElement("div");p.className="ticket-description",p.textContent=f.description.replace(/<[^>]+>/g,""),a.appendChild(p)}const v=document.createElement("div");v.style.marginTop="16px",v.style.marginBottom="16px",g.forEach(p=>{const t=document.createElement("div");t.className="reply "+(p.from_contact?"contact":"agent");const r=document.createElement("div");r.className="reply-meta",r.textContent=new Date(p.created_at).toLocaleString()+(p.from_contact?" · You":" · Support"),t.appendChild(r);const e=document.createElement("div");e.className="reply-body",e.textContent=p.body.replace(/<[^>]+>/g,""),t.appendChild(e),v.appendChild(t)}),a.appendChild(v);const s=document.createElement("form");s.onsubmit=async p=>{p.preventDefault();const t=s.querySelector("textarea"),r=t?.value?.trim();if(!r)return;const e=s.querySelector('button[type="submit"]');e&&(e.disabled=!0,e.textContent="Sending…");try{const c=await fetch(j+"/widget/helpdesks/"+E+"/tickets/"+S+"/replies",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify({body:r,access_token:T})}),m=await c.json();if(!c.ok)throw new Error(m.message||"Failed to send");t.value="",I(a)}catch(c){const m=document.createElement("div");m.className="msg error",m.textContent=c instanceof Error?c.message:"Failed to send reply.",s.insertBefore(m,s.firstChild),e&&(e.disabled=!1,e.textContent="Send reply")}};const y=document.createElement("label");y.htmlFor="reply-body",y.textContent="Reply",y.style.display="block",y.style.marginBottom="4px",s.appendChild(y);const _=document.createElement("textarea");_.id="reply-body",_.name="body",_.rows=3,_.placeholder="Type your reply…",s.appendChild(_);const w=document.createElement("button");w.type="submit",w.className="btn btn-primary",w.textContent="Send reply",s.appendChild(w),a.appendChild(s)}catch(i){const n=document.createElement("p");n.className="msg error",n.textContent=i instanceof Error?i.message:"Failed to load ticket",a.replaceChildren(n)}}function q(){L.innerHTML="";const a=document.createElement("button");if(a.type="button",a.className="floating-btn bottom-"+(D==="bottom-left"?"left":"right"),a.style.backgroundColor=F,a.textContent=z,a.setAttribute("aria-label",z),a.onclick=()=>{N=!N,q()},L.appendChild(a),N){const i=document.createElement("div");i.className="panel bottom-"+(D==="bottom-left"?"left":"right");let n="Support",f;if(A==="form")n=u?.texts?.form_title||"Contact support",f=G();else if(A==="thanks"){n=u?.texts?.form_title||"Thank you",f=document.createElement("div"),f.className="panel-body";const g=document.createElement("div");g.className="msg success",g.setAttribute("role","status"),g.textContent=P,f.appendChild(g)}else n="Your ticket",f=K(),S&&T&&I(f);i.appendChild($(n)),i.appendChild(f),L.appendChild(i)}}(async function(){try{u=await(await fetch(j+"/widget/helpdesks/"+E+"/form")).json()}catch{u={helpdesk_uuid:E,fields:[],branding:{brand_color:F,welcome_message:"How can we help?",button_text:z,button_position:D}}}q()})()})()})();
