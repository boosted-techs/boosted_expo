(() => {
 'use strict';
 let counter=0;
 const notify=()=>{const notice=document.querySelector('[data-refresh-notice]');if(notice)notice.hidden=false;};
 const alertIn=(root,message,error=false)=>{let box=root.querySelector(':scope>[data-panel-message]');if(!box){box=document.createElement('div');box.dataset.panelMessage='';box.setAttribute('role','status');root.prepend(box);}box.className='flash '+(error?'error':'success');box.textContent=message;};
 const safeUrl=value=>{const url=new URL(value,location.href);if(url.origin!==location.origin)throw Error('This action must stay within your workspace.');return url;};
 async function populate(panel,url,options={}) {
  url=safeUrl(url);panel.setAttribute('aria-busy','true');
  try{
   const response=await fetch(url,{credentials:'same-origin',headers:{'X-Workspace-Panel':'1',...options.headers},...options});
   if(response.status===401||response.status===419||new URL(response.url).pathname==='/login'){throw Error('Your session has expired. Sign in again before continuing.');}
   if((response.headers.get('content-type')||'').includes('application/json')){
    const data=await response.json();if(!response.ok){alertIn(panel,Object.values(data.errors||{error:[data.message||'Unable to save changes.']}).flat().join(' '),true);return;}
    alertIn(panel,data.message||'Changes saved.');notify();return;
   }
   if(!response.ok)throw Error(response.status===403?'You do not have permission for this action.':response.status===404?'This record is no longer available.':'Unable to load this activity. Please try again.');
   const parsed=new DOMParser().parseFromString(await response.text(),'text/html');const content=parsed.querySelector('.content');if(!content){throw Error('Unable to display this activity.');}
   content.querySelectorAll('.page-breadcrumb,.activity-nav,.install-entry').forEach(n=>n.remove());
   content.querySelectorAll('script').forEach(n=>n.remove());
   panel.replaceChildren(...content.childNodes);panel.dataset.url=response.url;const heading=panel.closest('dialog')?.querySelector(':scope>.dialog-heading h2');if(heading)heading.textContent=parsed.title.replace(/ · Boosted One$/,'');const intro=panel.querySelector('.page-intro'),h1=intro?.querySelector('h1');if(h1&&heading&&h1.textContent.trim().toLowerCase()===heading.textContent.trim().toLowerCase()){h1.remove();const eyebrow=intro.querySelector('.eyebrow');if(eyebrow&&/^(customers|catalogue|stickers)$/i.test(eyebrow.textContent.trim()))eyebrow.remove();if(!intro.textContent.trim()&&!intro.querySelector('button,a,input,select'))intro.remove();}
   window.WorkspaceEnhance?.(panel);panel.scrollTop=0;
   if(options.method&&options.method!=='GET')notify();
  }catch(error){alertIn(panel,error.message,true);}finally{panel.removeAttribute('aria-busy');}
 }
 async function openPanel(link){
  const dialog=document.createElement('dialog');dialog.className='workspace-dialog dialog-wide remote-dialog';dialog.id='remote-dialog-'+(++counter);
  const header=document.createElement('div');header.className='dialog-heading';const title=document.createElement('h2');title.id=dialog.id+'-title';title.textContent=link.dataset.dialogTitle||link.textContent.trim();dialog.setAttribute('aria-labelledby',title.id);
  const close=document.createElement('button');close.type='button';close.className='icon-button';close.setAttribute('aria-label','Close activity');close.textContent='×';close.addEventListener('click',()=>dialog.close());
  const panel=document.createElement('div');panel.className='dialog-body content remote-content';panel.dataset.remotePanel='';panel.textContent='Loading…';header.append(title,close);dialog.append(header,panel);document.body.append(dialog);dialog.showModal();
  dialog.addEventListener('close',()=>{document.querySelectorAll('[data-owner-panel="'+dialog.id+'"]').forEach(n=>n.remove());dialog.remove();(link.closest('.row-actions')?.querySelector('summary')||link).focus();},{once:true});await populate(panel,link.href);
 }
 document.addEventListener('click',event=>{
  const selectCartons=event.target.closest('[data-select-cartons]');if(selectCartons&&selectCartons.closest('[data-remote-panel]')){const inputs=[...selectCartons.closest('form').querySelectorAll('input[name="carton_ids[]"]')];const checked=!inputs.every(i=>i.checked);inputs.forEach(i=>i.checked=checked);return;}
  const refresh=event.target.closest('[data-refresh-directory]');if(refresh){refreshDirectory(refresh);return;}
  const link=event.target.closest('a[href]');if(!link||link.target==='_blank'||link.hasAttribute('download')||event.metaKey||event.ctrlKey)return;
  const panel=link.closest('[data-remote-panel]');
  if(link.hasAttribute('data-remote-dialog')){event.preventDefault();link.closest('.row-actions')?.removeAttribute('open');openPanel(link);return;}
  if(link.closest('[data-directory]') && link.closest('.server-pagination')){event.preventDefault();refreshDirectory(null,link.href);return;}
  if(panel && safeUrl(link.href).origin===location.origin && !/\.(pdf|png|zpl|xlsx)(\?|$)/.test(link.href)){event.preventDefault();populate(panel,link.href);}
 });
 document.addEventListener('workspace:navigate',event=>{const panel=event.target.closest('[data-remote-panel]');if(panel)populate(panel,event.detail.url);else if(event.target.closest('[data-directory]'))refreshDirectory(null,event.detail.url);});
 document.addEventListener('submit',event=>{
  const form=event.target, panel=form.closest('[data-remote-panel]'),directory=form.closest('[data-directory]');
  if(!panel&&directory&&(form.method||'GET').toUpperCase()==='GET'){event.preventDefault();const url=safeUrl(location.href);for(const [name,value] of new FormData(form))url.searchParams.set(name,value);const key=directory.querySelector('.server-pagination')?.dataset.tableKey;if(key){url.searchParams.delete(`tables[${key}][search]`);url.searchParams.delete(key.split('__').pop());}refreshDirectory(null,url);return;}
  if(!panel&&!form.hasAttribute('data-background-form'))return;
  if(event.defaultPrevented||form.hasAttribute('data-download-form'))return;
  event.preventDefault();if(form.dataset.saving)return;const method=(form.method||'GET').toUpperCase();const data=new FormData(form);if(event.submitter?.name)data.set(event.submitter.name,event.submitter.value);
  const action=event.submitter?.hasAttribute('formaction')?event.submitter.formAction:form.action;
  if(method==='GET'){const url=safeUrl(form.getAttribute('action')||panel?.dataset.url||location.href);url.search=new URLSearchParams(data).toString();populate(panel||form.parentElement,url);return;}
  const buttons=[...form.querySelectorAll('button[type=submit],button:not([type])')];form.dataset.saving='true';buttons.forEach(b=>b.disabled=true);
  populate(panel||form.parentElement,action,{method,body:data,referrer:panel?.dataset.url||location.href,headers:{Accept:'application/json','X-Workspace-Panel':'1'}}).finally(()=>{delete form.dataset.saving;buttons.forEach(b=>b.disabled=false);});
 });
 async function refreshDirectory(button,url=location.href){if(button)button.disabled=true;try{url=safeUrl(url);const response=await fetch(url,{credentials:'same-origin'});if(!response.ok)throw Error();const parsed=new DOMParser().parseFromString(await response.text(),'text/html');const next=parsed.querySelector('[data-directory]'),current=document.querySelector('[data-directory]');if(!next||!current)throw Error();document.querySelectorAll('[data-owner-panel="'+current.id+'"]').forEach(n=>n.remove());current.replaceWith(next);history.replaceState(null,'',url);window.WorkspaceEnhance?.(next);document.querySelector('[data-refresh-notice]').hidden=true;}catch{if(button)button.textContent='Refresh failed — try again';else alertIn(document.querySelector('.content'),'Unable to refresh the directory. Please try again.',true);}finally{if(button)button.disabled=false;}}
 document.addEventListener('input',event=>{if(!event.target.matches('[data-filter-choices]'))return;const term=event.target.value.toLowerCase();event.target.closest('form').querySelectorAll('.invitation-products label').forEach(label=>label.hidden=!label.textContent.toLowerCase().includes(term));});
 document.addEventListener('toggle',event=>{const details=event.target;if(!details.matches?.('.row-actions')||!details.open)return;document.querySelectorAll('.row-actions[open]').forEach(other=>{if(other!==details)other.open=false;});const rect=details.querySelector('summary').getBoundingClientRect(),menu=details.querySelector('.row-action-menu');menu.style.left=Math.max(8,Math.min(rect.right-260,innerWidth-276))+'px';menu.style.top=Math.min(rect.bottom+5,innerHeight-350)+'px';},true);
 document.addEventListener('click',event=>{if(!event.target.closest('.row-actions'))document.querySelectorAll('.row-actions[open]').forEach(d=>d.open=false);});
 document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelectorAll('.row-actions[open]').forEach(d=>d.open=false);});
})();
