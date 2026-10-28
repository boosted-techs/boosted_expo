(() => {
 'use strict';
 let counter=0, navigation=0, pendingMain;
 const safeUrl=value=>{const url=new URL(value,location.href);if(url.origin!==location.origin)throw Error('Open this link in a new tab.');return url;};
 window.WorkspaceIsFile=url=>url.pathname!=='/labels/print'&&(/\.(pdf|png|jpe?g|zpl|xlsx?|csv|zip)(\?|$)/i.test(url.href)||/\/(pdf|print|download|export|print-helper)(\/|$)/.test(url.pathname)||['format','export'].some(key=>url.searchParams.get(key)==='csv')||/\/(evidence|template)(\/|$)/.test(url.pathname)||/\/shipment-readiness\/document\//.test(url.pathname)||/\/stock\/case-evidence\//.test(url.pathname));
 const alertIn=(root,message,error=false)=>{let box=root.querySelector(':scope>[data-panel-message]');if(!box){box=document.createElement('div');box.dataset.panelMessage='';box.setAttribute('role',error?'alert':'status');root.prepend(box);}box.className='flash '+(error?'error':'success');box.textContent=message;box.scrollIntoView({block:'nearest'});};
 function notify(){let notice=document.querySelector('main.content [data-refresh-notice]');if(!notice){notice=document.createElement('div');notice.className='flash success workspace-refresh no-print';notice.dataset.refreshNotice='';notice.hidden=true;notice.setAttribute('role','status');const text=document.createElement('span');text.textContent='Changes saved. Refresh to update this view. ';const button=document.createElement('button');button.type='button';button.className='secondary-button';button.dataset.refreshDirectory='';button.textContent='Refresh';notice.append(text,button);document.querySelector('main.content')?.prepend(notice);}if(notice)notice.hidden=false;}
 function ready(root){
  window.WorkspaceFlowEnhance?.(root);window.WorkspaceEnhance?.(root);window.WorkspaceFlowEnhance?.(root);
  document.querySelectorAll('.workspace-dialog').forEach(dialog=>{
   const body=dialog.querySelector('.dialog-body');if(body?.querySelector('form')&&!body.hasAttribute('data-remote-panel')){body.dataset.remotePanel='';body.dataset.url=root.closest?.('[data-remote-panel]')?.dataset.url||location.href;const owner=root.closest?.('dialog');if(owner&&owner!==dialog)dialog.dataset.ownerPanel=owner.id;}
   if(!dialog.hasAttribute('data-modules-ready')){dialog.dataset.modulesReady='';window.WorkspaceFlowEnhance?.(dialog);}
  });
 }

 let loading=0;
 function busy(start){loading=Math.max(0,loading+(start?1:-1));let progress=document.querySelector('.workspace-load-progress'),status=document.querySelector('.workspace-loading-status');if(loading&&!progress){progress=document.createElement('div');progress.className='workspace-load-progress';progress.setAttribute('aria-hidden','true');status=document.createElement('div');status.className='workspace-loading-status';status.setAttribute('role','status');status.textContent='Loading…';document.body.append(progress,status);}else if(!loading){progress?.remove();status?.remove();}}
 async function populate(panel,url,options={}){
  url=safeUrl(url);panel.setAttribute('aria-busy','true');busy(true);
  const isMain=panel.matches('main.content');let ticket;
  if(isMain){ticket=++navigation;pendingMain?.abort();pendingMain=new AbortController();options.signal=pendingMain.signal;}else{panel._controller?.abort();panel._controller=new AbortController();options.signal=panel._controller.signal;}
  try{
   const {headers={},history:historyMode,back:goingBack,...fetchOptions}=options;
   const response=await fetch(url,{credentials:'same-origin',...fetchOptions,headers:{'X-Workspace-Panel':'1',...headers}});
   if(isMain&&ticket!==navigation)return;
   if(response.status===401||response.status===419||new URL(response.url).pathname==='/login')throw Error('Your session has expired. Sign in again to continue.');
   if((response.headers.get('content-type')||'').includes('application/json')){
    const data=await response.json();if(!response.ok){alertIn(panel,Object.values(data.errors||{error:[data.message||'Unable to save changes.']}).flat().join(' '),true);return;}
    if(data.selection&&panel._selectionTarget){const select=panel._selectionTarget;select.add(new Option(data.selection.label,String(data.selection.value),true,true));select.dispatchEvent(new Event('change',{bubbles:true}));panel.closest('dialog')?.close();notify();return;}
    alertIn(panel,data.message||'Changes saved.');notify();return;
   }
   if(!response.ok)throw Error(response.status===403?'You do not have permission for this action.':response.status===404?'This record is no longer available.':'Unable to load this activity. Please try again.');
   const parsed=new DOMParser().parseFromString(await response.text(),'text/html'),content=parsed.querySelector('main.content');if(!content)throw Error('Open this activity in a new tab to continue.');
   content.querySelectorAll('script').forEach(node=>node.remove());
   if(!isMain)content.querySelectorAll('.page-breadcrumb,.activity-nav,.install-entry').forEach(node=>node.remove());
   if(isMain){document.querySelectorAll('.workspace-dialog:not([data-pwa-dialog])').forEach(dialog=>{dialog.close();dialog.remove();});if(options.history!=='none'){if(options.history==='replace')history.replaceState(null,'',response.url);else history.pushState(null,'',response.url);}document.title=parsed.title;const nextSide=parsed.querySelector('#sidebar'),side=document.querySelector('#sidebar');if(nextSide&&side){side.innerHTML=nextSide.innerHTML;window.WorkspaceSyncNavigation?.();}}
   if(!isMain&&panel.dataset.url&&panel.dataset.url!==response.url&&!options.back){panel._history||=[];panel._history.push(panel.dataset.url);}panel.replaceChildren(...content.childNodes);panel.dataset.url=response.url;const back=panel.closest('dialog')?.querySelector('[data-panel-back]');if(back)back.hidden=!panel._history?.length;
   const heading=panel.closest('dialog')?.querySelector(':scope>.dialog-heading h2');if(heading)heading.textContent=parsed.title.replace(/ · Boosted One$/,'');const intro=panel.querySelector('.page-intro'),h1=intro?.querySelector('h1');if(h1&&heading){heading.textContent=h1.textContent.trim();h1.remove();if(!intro.textContent.trim()&&!intro.querySelector('button,a,input,select'))intro.remove();}
   ready(panel);panel.scrollTop=0;if(!isMain&&heading){heading.tabIndex=-1;heading.focus({preventScroll:true});}if(isMain){window.scrollTo({top:0,behavior:'instant'});const title=panel.querySelector('h1');if(title){title.tabIndex=-1;title.focus({preventScroll:true});}}
   if(options.method&&options.method!=='GET')notify();
  }catch(error){if(error.name!=='AbortError')alertIn(panel,error.message,true);}finally{if(!isMain||ticket===navigation)panel.removeAttribute('aria-busy');busy(false);}
 }
 async function openPanel(link){
  const dialog=document.createElement('dialog');dialog.className='workspace-dialog dialog-wide remote-dialog';dialog.id='remote-dialog-'+(++counter);
  const header=document.createElement('div');header.className='dialog-heading';const title=document.createElement('h2');title.id=dialog.id+'-title';title.textContent=link.dataset.dialogTitle||link.textContent.trim();dialog.setAttribute('aria-labelledby',title.id);
  const close=document.createElement('button');close.type='button';close.className='icon-button';close.setAttribute('aria-label','Close activity');close.textContent='×';close.addEventListener('click',()=>dialog.close());
  const panel=document.createElement('div');panel.className='dialog-body content remote-content';panel.dataset.remotePanel='';panel.textContent='Loading…';if(link.dataset.selectionTarget)panel._selectionTarget=(link.closest('[data-remote-panel]')||document.querySelector('main.content')).querySelector(link.dataset.selectionTarget);
  const back=document.createElement('button');back.type='button';back.className='secondary-button';back.dataset.panelBack='';back.textContent='← Back';back.hidden=true;back.addEventListener('click',()=>{const previous=panel._history?.pop();if(previous)populate(panel,previous,{back:true});});header.append(back,title,close);dialog.append(header,panel);document.body.append(dialog);dialog.showModal();
  dialog.addEventListener('close',()=>{panel._controller?.abort();document.querySelectorAll('[data-owner-panel="'+dialog.id+'"]').forEach(node=>node.remove());dialog.remove();if(link.isConnected)(link.closest('.row-actions')?.querySelector('summary')||link).focus();},{once:true});await populate(panel,link.href);
 }
 document.addEventListener('click',event=>{
  const refresh=event.target.closest('[data-refresh-directory]');if(refresh){event.preventDefault();refreshDirectory(refresh);return;}
  const link=event.target.closest('a[href]');if(event.defaultPrevented||!link||link.target==='_blank'||link.hasAttribute('download')||link.hasAttribute('data-native-link')||event.metaKey||event.ctrlKey||event.shiftKey||event.altKey||event.button!==0)return;
  let url;try{url=safeUrl(link.href);}catch{return;}if(window.WorkspaceIsFile(url)||url.hash||!/^https?:$/.test(url.protocol))return;
  const panel=link.closest('[data-remote-panel]');
  if(panel&&/^cancel$/i.test(link.textContent.trim())){event.preventDefault();panel.closest('dialog')?.close();return;}
  if(link.hasAttribute('data-remote-dialog')){event.preventDefault();if(panel&&!link.dataset.selectionTarget){populate(panel,url);return;}link.closest('.row-actions')?.removeAttribute('open');openPanel(link);return;}
  if(panel){event.preventDefault();populate(panel,url);return;}
  if(link.closest('[data-directory] .server-pagination')){event.preventDefault();refreshDirectory(null,url);return;}
  if(link.closest('main.content,#sidebar,.header-brand')&&!/\/(login|logout|choose-business|platform)(\/|$)/.test(url.pathname)){event.preventDefault();populate(document.querySelector('main.content'),url);}
 });
 document.addEventListener('workspace:navigate',event=>{const panel=event.target.closest('[data-remote-panel]');if(panel)populate(panel,event.detail.url);else if(event.target.closest('[data-directory]'))refreshDirectory(null,event.detail.url);else populate(document.querySelector('main.content'),event.detail.url,{history:'replace'});});
 document.addEventListener('submit',event=>{
  const form=event.target,panel=form.closest('[data-remote-panel]'),directory=form.closest('[data-directory]'),main=form.closest('main.content');
  if(event.defaultPrevented||form.hasAttribute('data-download-form')||form.target==='_blank'||window.WorkspaceIsFile(safeUrl(event.submitter?.hasAttribute('formaction')?event.submitter.formAction:form.action))||(!panel&&!main&&!form.hasAttribute('data-background-form')))return;
  event.preventDefault();if(form.dataset.saving)return;const method=(form.method||'GET').toUpperCase(),data=new FormData(form);if(event.submitter?.name)data.set(event.submitter.name,event.submitter.value);
  const action=event.submitter?.hasAttribute('formaction')?event.submitter.formAction:form.getAttribute('action')||panel?.dataset.url||location.href;
  if(method==='GET'){const url=safeUrl(action);url.search=new URLSearchParams(data).toString();if(directory&&!panel)refreshDirectory(null,url);else populate(panel||main,url,{history:'replace'});return;}
  const buttons=[...form.querySelectorAll('button[type=submit],button:not([type])')].map(button=>({button,disabled:button.disabled}));form.dataset.saving='true';buttons.forEach(({button})=>button.disabled=true);
  populate(panel||main||form.parentElement,action,{method,body:data,referrer:panel?.dataset.url||location.href,headers:{Accept:'application/json'},history:'replace'}).finally(()=>{delete form.dataset.saving;buttons.forEach(({button,disabled})=>button.disabled=disabled);});
 });
 async function refreshDirectory(button,url=location.href){if(button)button.disabled=true;const current=document.querySelector('main.content [data-directory]');if(!current){await populate(document.querySelector('main.content'),url,{history:'replace'});if(button)button.disabled=false;return;}busy(true);try{url=safeUrl(url);const response=await fetch(url,{credentials:'same-origin'});if(!response.ok)throw Error();const parsed=new DOMParser().parseFromString(await response.text(),'text/html'),next=parsed.querySelector('[data-directory]');if(!next)throw Error();document.querySelectorAll('[data-owner-panel="'+current.id+'"]').forEach(node=>node.remove());current.replaceWith(next);history.replaceState(null,'',url);ready(next);const notice=document.querySelector('[data-refresh-notice]');if(notice)notice.hidden=true;}catch{alertIn(document.querySelector('main.content'),'Unable to refresh. Please try again.',true);}finally{busy(false);if(button)button.disabled=false;}}
 window.addEventListener('popstate',()=>populate(document.querySelector('main.content'),location.href,{history:'none'}));
 document.addEventListener('input',event=>{if(!event.target.matches('[data-filter-choices]'))return;const term=event.target.value.toLowerCase();event.target.closest('form').querySelectorAll('.invitation-products label').forEach(label=>label.hidden=!label.textContent.toLowerCase().includes(term));});
 document.addEventListener('toggle',event=>{const details=event.target;if(!details.matches?.('.row-actions')||!details.open)return;document.querySelectorAll('.row-actions[open]').forEach(other=>{if(other!==details)other.open=false;});const rect=details.querySelector('summary').getBoundingClientRect(),menu=details.querySelector('.row-action-menu');if(!menu)return;menu.style.left=Math.max(8,Math.min(rect.right-260,innerWidth-276))+'px';menu.style.top=Math.max(8,Math.min(rect.bottom+5,innerHeight-350))+'px';},true);
 document.addEventListener('click',event=>{if(!event.target.closest('.row-actions'))document.querySelectorAll('.row-actions[open]').forEach(details=>details.open=false);});
 document.addEventListener('keydown',event=>{if(event.key==='Escape')document.querySelectorAll('.row-actions[open]').forEach(details=>details.open=false);});
 document.body.classList.add('workspace-ready');ready(document.querySelector('main.content'));
})();
