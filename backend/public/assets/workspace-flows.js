(() => {
 'use strict';
 const syncItems=box=>{
  const rows=[...box.querySelectorAll('[data-request-line]')],selected=rows.map(row=>row.querySelector('select').value).filter(Boolean);
  rows.forEach((row,index)=>{row.cells[0].textContent=String(index+1);const select=row.querySelector('select');[...select.options].forEach(option=>option.disabled=!!option.value&&option.value!==select.value&&selected.includes(option.value));row.querySelector('[data-remove-item]').disabled=rows.length===1;});
  box.querySelector('[data-add-item]').disabled=rows.length>=Number(box.dataset.limit);
 };
 const setupItems=box=>{
  if(box.dataset.itemsReady)return;box.dataset.itemsReady='true';
  const original=box.querySelector('[data-request-line]').cloneNode(true);original.querySelectorAll('.search-select').forEach(wrapper=>{const select=wrapper.querySelector('select');select.classList.remove('select-source');delete select.dataset.searchReady;select.removeAttribute('aria-hidden');select.removeAttribute('tabindex');wrapper.replaceWith(select);});
  let sequence=Math.max(...[...box.querySelectorAll('[data-request-line] select')].map(select=>Number(select.name.match(/items\[(\d+)\]/)?.[1]||0)))+1;
  box.addEventListener('change',event=>{if(event.target.matches('select'))syncItems(box);});
  box.addEventListener('click',event=>{
   if(event.target.closest('[data-add-item]')){if(box.querySelectorAll('[data-request-line]').length>=Number(box.dataset.limit))return;const row=original.cloneNode(true);row.querySelectorAll('input,select').forEach(input=>{input.name=input.name.replace(/items\[\d+\]/,'items['+sequence+']');input.value='';input.removeAttribute('id');input.removeAttribute('data-search-ready');input.setAttribute('aria-label',input.tagName==='SELECT'?'Product '+(sequence+1):'Quantity '+(sequence+1));if(input.tagName==='SELECT')[...input.options].forEach(option=>{option.disabled=false;option.selected=false;});});sequence++;box.querySelector('tbody').append(row);window.WorkspaceEnhance?.(row);syncItems(box);row.querySelector('.select-search,input')?.focus();}
   if(event.target.closest('[data-remove-item]')&&box.querySelectorAll('[data-request-line]').length>1){event.target.closest('[data-request-line]').remove();syncItems(box);}
  });syncItems(box);
 };
 window.WorkspaceFlowEnhance=root=>{
  root.querySelectorAll('[data-workspace-section]').forEach(section=>{section.hidden=true;});
  root.querySelectorAll('[data-request-items]').forEach(setupItems);
  root.querySelectorAll('table:not(.data-table)').forEach(table=>{if(table.tHead&&table.closest('.table-wrap'))table.classList.add('data-table');});
  root.querySelectorAll('.data-table:not([data-table-tools=off])').forEach(table=>{
   const headings=[...(table.tHead?.rows[0]?.cells||[])];headings.forEach((heading,index)=>{if(!/^(actions?|controls?|)$/.test(heading.textContent.trim().toLowerCase()))return;heading.textContent='Actions';[...(table.tBodies[0]?.rows||[])].forEach(row=>{const cell=row.cells[index];if(!cell||cell.colSpan>1||cell.querySelector('.row-actions')||!cell.querySelector('a,button,form'))return;const details=document.createElement('details');details.className='row-actions';const summary=document.createElement('summary');summary.textContent='Actions';summary.setAttribute('aria-label','Record actions');const menu=document.createElement('div');menu.className='row-action-menu';menu.append(...cell.childNodes);details.append(summary,menu);cell.append(details);});});
  });
  const intro=root.querySelector('.page-intro,.page-heading'),toolbar=intro?document.createElement('div'):null;
  if(toolbar){toolbar.className='form-actions intro-actions no-print';root.querySelectorAll(':scope>.primary-button,:scope>.modal-action,:scope>.form-actions>a').forEach(link=>{if(/^(new|add|create|record|prepare|register|schedule|upload|assign)\b/i.test(link.textContent.trim())&&!link.closest('[data-remote-panel] .page-intro'))toolbar.append(link);});if(toolbar.childNodes.length)intro.append(toolbar);}
  root.querySelectorAll('a[href]').forEach(link=>{if(link.target==='_blank'||link.hasAttribute('download')||link.hasAttribute('data-native-link'))return;const url=new URL(link.href,location.href);if(url.origin!==location.origin||url.hash||window.WorkspaceIsFile?.(url))return;if(link.closest('.row-actions,.activity-cards')||/\/(new|create|edit|activity)(\/|$)/.test(url.pathname)||url.searchParams.has('workspace_panel')||link.matches('.action-link'))link.dataset.remoteDialog='';});
  Object.values(window.WorkspaceModules||{}).forEach(enhance=>enhance(root));
 };
 window.WorkspaceFlowEnhance(document);
})();
