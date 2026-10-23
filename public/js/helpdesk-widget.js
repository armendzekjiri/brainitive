(function(){"use strict";const U=new Set(["exe","msi","msp","msix","appx","com","scr","pif","cpl","dll","sys","lnk","reg","inf","bat","cmd","ps1","psm1","psd1","vbs","vbe","js","jse","mjs","wsf","wsh","hta","msc","jar","class","apk","aab","ipa","app","dmg","pkg","mpkg","command","deb","rpm","appimage","run","snap","sh","bash","zsh","csh","ksh","fish","py","pyc","pyw","pl","rb","lua","php","phtml","phar","cgi","asp","aspx","jsp","html","htm","xhtml","xhtm","svg","svgz","xpi","crx","so","dylib","elf","iso","img","vhd","vhdx"]);function R(y){return y.toLowerCase().split(".").slice(1).some(w=>U.has(w.trim()))}const X="Programs and scripts can't be attached here.";(function(){const y=document.currentScript;if(!y)return;const w=y.getAttribute("data-helpdesk-id"),q=y.getAttribute("data-button-text")||"Support",z=y.getAttribute("data-button-position")||"bottom-right",D=y.getAttribute("data-brand-color")||"#0E83DC",H=y.getAttribute("data-ticket-uuid"),M=y.getAttribute("data-token");if(!w){console.warn("[Helpdesk widget] data-helpdesk-id is required.");return}const j=y.src.replace(/\/js\/helpdesk-widget\.js.*$/,""),F=document.createElement("div");F.id="brainitive-helpdesk-widget",document.body.appendChild(F);const B=F.attachShadow({mode:"closed"}),W=`
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
    `;let f=null,_=H||null,S=M||null,T=!1,N=H&&M?"ticket":"form",O="";const A=document.createElement("div");B.appendChild(document.createElement("style")).textContent=W,B.appendChild(A);function J(o){const s=document.createElement("div");s.className="panel-header",s.style.borderTopColor=D,s.style.borderTopWidth="3px",s.style.borderTopStyle="solid",s.textContent=o;const a=document.createElement("button");return a.type="button",a.className="panel-close",a.textContent="×",a.setAttribute("aria-label","Close"),a.onclick=()=>{T=!1,L()},s.appendChild(a),s}function G(){const o=document.createElement("div");if(o.className="panel-body",!f)return o.textContent="Loading…",o;const s=document.createElement("p");s.style.marginBottom="16px",s.style.color="#666",s.textContent=f.branding.welcome_message||"How can we help you?",o.appendChild(s);const a=document.createElement("form");a.onsubmit=async e=>{e.preventDefault();const i=new FormData(a),t={subject:i.get("subject")||"",contact_email:i.get("contact_email")||""},r=i.get("description");r!=null&&String(r).trim()!==""&&(t.description=String(r).trim());const m=i.get("contact_name");m!=null&&String(m).trim()!==""&&(t.contact_name=String(m).trim()),f.fields.forEach(d=>{if(d.type==="checkboxes"){const p=i.getAll("field_"+d.name).map(String);p.length&&(t["field_"+d.name]=JSON.stringify(p));return}const n=i.get("field_"+d.name);n!=null&&String(n).trim()!==""&&(t["field_"+d.name]=String(n))});const l=a.querySelector('button[type="submit"]');l&&(l.disabled=!0,l.textContent="Sending…");try{const d=await fetch(j+"/widget/helpdesks/"+w+"/tickets",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify(t)}),n=await d.json();if(!d.ok)throw new Error(n.message||"Submission failed");_=n.ticket_uuid,S=n.access_token;const p=a.querySelector('input[name="attachments"]');if(p?.files&&p.files.length>0)for(let b=0;b<p.files.length;b++){const g=new FormData;g.append("file",p.files[b]),g.append("access_token",S??"");const P=await fetch(j+"/widget/helpdesks/"+w+"/tickets/"+_+"/attachments",{method:"POST",headers:{Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:g});if(!P.ok){const V=await P.json().catch(()=>({}));throw new Error(V.message||"Failed to upload attachment")}}f?.texts?.after_submit==="message"?(O=n.message||f.texts.success_message,N="thanks"):N="ticket",L()}catch(d){const n=document.createElement("div");n.className="msg error",n.textContent=d instanceof Error?d.message:"Something went wrong. Please try again.",a.insertBefore(n,a.firstChild),l&&(l.disabled=!1,l.textContent=f?.texts?.submit_label||"Submit")}};const u=(e,i,t,r,m)=>{const l=document.createElement("div");l.className="form-group";const d=document.createElement("label");if(d.htmlFor=e,d.textContent=i,r&&d.classList.add("required"),l.appendChild(d),t==="select"&&m&&m.length){const n=document.createElement("select");n.id=n.name=e,n.required=r;const p=document.createElement("option");p.value="",p.textContent="— Select —",n.appendChild(p),m.forEach(b=>{const g=document.createElement("option");g.value=b,g.textContent=b,n.appendChild(g)}),l.appendChild(n)}else if(t==="checkbox"){const n=document.createElement("input");n.type="checkbox",n.id=n.name=e,n.value="1",n.required=r,l.appendChild(n)}else if((t==="radio"||t==="checkboxes")&&m&&m.length)m.forEach((n,p)=>{const b=document.createElement("label");b.style.display="flex",b.style.gap="6px",b.style.alignItems="center",b.style.fontWeight="normal";const g=document.createElement("input");g.type=t==="radio"?"radio":"checkbox",g.name=e,g.value=n,t==="radio"&&r&&p===0&&(g.required=!0),b.appendChild(g),b.appendChild(document.createTextNode(n)),l.appendChild(b)});else{const n=typeof t=="string"&&(t==="rich_text"||t==="textarea"||t.toLowerCase().replace(/[\s-]/g,"_")==="rich_text"),p=document.createElement(n?"textarea":"input");p.id=p.name=e,p.required=r,p instanceof HTMLInputElement?p.type=t==="number"?"number":t==="date"?"date":e==="contact_email"?"email":"text":p.rows=4,l.appendChild(p)}a.appendChild(l)},h=f.default_editable||{};h.contact_name&&u("contact_name",h.contact_name.label,"text",h.contact_name.is_required),u("contact_email","Email","text",!0),f.subject?.hidden||u("subject",f.subject?.label||"Subject","text",!0),h.description&&u("description",h.description.label,"textarea",h.description.is_required),f.fields.forEach(e=>{u("field_"+e.name,e.label,e.type,e.is_required,e.options)});const C=document.createElement("div");f.attachments_enabled===!1&&(C.style.display="none"),C.className="form-group";const E=document.createElement("label");E.htmlFor="attachments",E.textContent="Attachments (optional)",C.appendChild(E);const c=document.createElement("input");c.type="file",c.name="attachments",c.multiple=!0,c.id="attachments",c.style.marginTop="4px",C.appendChild(c);const x=document.createElement("div");x.className="attachments-list",x.setAttribute("aria-live","polite"),C.appendChild(x);const v=()=>{x.innerHTML="";const e=c.files;if(e)for(let i=0;i<e.length;i++){const t=document.createElement("span"),r=document.createElement("button");r.type="button",r.textContent="×",r.setAttribute("aria-label","Remove file");const m=i;r.onclick=()=>{const l=new DataTransfer;for(let d=0;d<c.files.length;d++)d!==m&&l.items.add(c.files[d]);c.files=l.files,v()},t.textContent=e[i].name+" ",t.appendChild(r),x.appendChild(t)}};c.onchange=()=>{const e=c.files;if(e&&Array.from(e).some(i=>R(i.name))){const i=new DataTransfer;Array.from(e).filter(t=>!R(t.name)).forEach(t=>i.items.add(t)),c.files=i.files,window.alert(X)}v()},a.appendChild(C);const k=document.createElement("button");return k.type="submit",k.className="btn btn-primary",k.textContent=f.texts?.submit_label||"Submit",a.appendChild(k),o.appendChild(a),o}function K(){const o=document.createElement("div");return o.className="panel-body",o.textContent="Loading…",o}async function I(o){if(!_||!S){o.innerHTML='<p class="msg error">Missing ticket or access token.</p>';return}try{const s=await fetch(j+"/widget/tickets/"+_+"?token="+encodeURIComponent(S)),a=await s.json();if(!s.ok)throw new Error(a.error||"Failed to load ticket");const u=a.ticket,h=a.replies;o.innerHTML="";const C=document.createElement("div");if(C.className="ticket-subject",C.textContent=u.subject,o.appendChild(C),u.description){const e=document.createElement("div");e.className="ticket-description",e.textContent=u.description.replace(/<[^>]+>/g,""),o.appendChild(e)}const E=document.createElement("div");E.style.marginTop="16px",E.style.marginBottom="16px",h.forEach(e=>{const i=document.createElement("div");i.className="reply "+(e.from_contact?"contact":"agent");const t=document.createElement("div");t.className="reply-meta",t.textContent=new Date(e.created_at).toLocaleString()+(e.from_contact?" · You":" · Support"),i.appendChild(t);const r=document.createElement("div");r.className="reply-body",r.textContent=e.body.replace(/<[^>]+>/g,""),i.appendChild(r),E.appendChild(i)}),o.appendChild(E);const c=document.createElement("form");c.onsubmit=async e=>{e.preventDefault();const i=c.querySelector("textarea"),t=i?.value?.trim();if(!t)return;const r=c.querySelector('button[type="submit"]');r&&(r.disabled=!0,r.textContent="Sending…");try{const m=await fetch(j+"/widget/helpdesks/"+w+"/tickets/"+_+"/replies",{method:"POST",headers:{"Content-Type":"application/json",Accept:"application/json","X-Requested-With":"XMLHttpRequest"},body:JSON.stringify({body:t,access_token:S})}),l=await m.json();if(!m.ok)throw new Error(l.message||"Failed to send");i.value="",I(o)}catch(m){const l=document.createElement("div");l.className="msg error",l.textContent=m instanceof Error?m.message:"Failed to send reply.",c.insertBefore(l,c.firstChild),r&&(r.disabled=!1,r.textContent="Send reply")}};const x=document.createElement("label");x.htmlFor="reply-body",x.textContent="Reply",x.style.display="block",x.style.marginBottom="4px",c.appendChild(x);const v=document.createElement("textarea");v.id="reply-body",v.name="body",v.rows=3,v.placeholder="Type your reply…",c.appendChild(v);const k=document.createElement("button");k.type="submit",k.className="btn btn-primary",k.textContent="Send reply",c.appendChild(k),o.appendChild(c)}catch(s){const a=document.createElement("p");a.className="msg error",a.textContent=s instanceof Error?s.message:"Failed to load ticket",o.replaceChildren(a)}}function L(){A.innerHTML="";const o=document.createElement("button");if(o.type="button",o.className="floating-btn bottom-"+(z==="bottom-left"?"left":"right"),o.style.backgroundColor=D,o.textContent=q,o.setAttribute("aria-label",q),o.onclick=()=>{T=!T,L()},A.appendChild(o),T){const s=document.createElement("div");s.className="panel bottom-"+(z==="bottom-left"?"left":"right");let a="Support",u;if(N==="form")a=f?.texts?.form_title||"Contact support",u=G();else if(N==="thanks"){a=f?.texts?.form_title||"Thank you",u=document.createElement("div"),u.className="panel-body";const h=document.createElement("div");h.className="msg success",h.setAttribute("role","status"),h.textContent=O,u.appendChild(h)}else a="Your ticket",u=K(),_&&S&&I(u);s.appendChild(J(a)),s.appendChild(u),A.appendChild(s)}}(async function(){try{f=await(await fetch(j+"/widget/helpdesks/"+w+"/form")).json()}catch{f={helpdesk_uuid:w,fields:[],branding:{brand_color:D,welcome_message:"How can we help?",button_text:q,button_position:z}}}L()})()})()})();
