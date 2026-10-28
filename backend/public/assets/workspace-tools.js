(() => {
  'use strict';
  const icon = (name) => {
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('viewBox', '0 0 24 24'); svg.setAttribute('fill', 'none'); svg.setAttribute('stroke', 'currentColor'); svg.setAttribute('stroke-width', '1.8'); svg.setAttribute('aria-hidden', 'true'); svg.classList.add('ui-icon');
    const path = document.createElementNS(svg.namespaceURI, 'path');
    path.setAttribute('d', name === 'print' ? 'M6 9V3h12v6M6 17H3V9h18v8h-3M6 14h12v7H6z' : 'M21 21l-6-6M17 10a7 7 0 1 1-14 0 7 7 0 0 1 14 0'); svg.append(path); return svg;
  };
  let sequence = 0;
  const dialogFor = (title, content, wide = true) => {
    const dialog = document.createElement('dialog'); dialog.className = 'workspace-dialog' + (wide ? ' dialog-wide' : ''); dialog.id = 'workspace-dialog-' + (++sequence);
    const heading = document.createElement('div'); heading.className = 'dialog-heading';
    const h2 = document.createElement('h2'); h2.id = dialog.id + '-title'; h2.textContent = title; dialog.setAttribute('aria-labelledby', h2.id);
    const close = document.createElement('button'); close.type = 'button'; close.className = 'icon-button'; close.textContent = '×'; close.setAttribute('aria-label', 'Close ' + title); close.addEventListener('click', () => dialog.close());
    heading.append(h2, close); const body = document.createElement('div'); body.className = 'dialog-body'; body.append(content); dialog.append(heading, body); document.body.append(dialog);
    dialog.addEventListener('click', event => { if (event.target !== dialog) return; const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dialog.close(); });
    const trigger = document.createElement('button'); trigger.type = 'button'; trigger.className = 'secondary-button'; trigger.textContent = title; trigger.setAttribute('aria-haspopup', 'dialog'); trigger.setAttribute('aria-controls', dialog.id); trigger.addEventListener('click', () => dialog.showModal());
    return {dialog, trigger};
  };
  const focusedCreatePage = /\/(new|create|import)(\/|$)|\/activity\/(register|rota-create|attendance-record|schedule)$/.test(location.pathname);
  if (!focusedCreatePage) document.querySelectorAll('.content section.section-card').forEach(section => {
    const title = section.querySelector(':scope > h2');
    if (!title || !/^(add|create|record|schedule|register|prepare|configure|issue|upload|request)\b/i.test(title.textContent.trim()) || !section.querySelector('form') || section.querySelector('.data-table')) return;
    const details = document.createElement('details'); details.className='section-card'; details.dataset.modal=''; const summary=document.createElement('summary'); summary.textContent=title.textContent; title.remove(); details.append(summary,...section.childNodes); section.replaceWith(details);
  });
  const enhanceDialogs = (root) => root.querySelectorAll('details[data-modal], .content details').forEach(details => {
    if(details.classList.contains('row-actions')) return;
    if (!details.hasAttribute('data-modal') && (!details.querySelector('form') || details.hasAttribute('data-print-details'))) return;
    const summary = details.querySelector(':scope > summary'); if (!summary) return;
    const fragment = document.createDocumentFragment(); [...details.childNodes].filter(node => node !== summary).forEach(node => fragment.append(node));
    const parentPanel=details.closest('[data-remote-panel]');
    const owner=parentPanel?.closest('dialog')?.id||details.closest('[data-directory]')?.id;
    const {dialog, trigger} = dialogFor(summary.textContent.trim(), fragment);if(owner)dialog.dataset.ownerPanel=owner;if(parentPanel){const body=dialog.querySelector('.dialog-body');body.dataset.remotePanel='';body.dataset.url=parentPanel.dataset.url;}
    if (details.id) { trigger.id = details.id; trigger.addEventListener('click', () => history.replaceState(null, '', '#' + trigger.id)); }
    trigger.classList.add('modal-action'); details.replaceWith(trigger);
    const openFromHash=()=>{if(trigger.id && location.hash==='#'+trigger.id && !dialog.open)dialog.showModal();};
    openFromHash();window.addEventListener('hashchange',openFromHash);
    if (dialog.querySelector('.field [name]') && document.querySelector('.flash.error')) dialog.dataset.hasErrors = 'true';
  });
  enhanceDialogs(document);
  const stockActions=['record-count','move-stock','count-review','unpack-carton'].map(id=>document.getElementById(id)).filter(node=>node?.classList.contains('modal-action'));
  if(stockActions.length){const toolbar=document.createElement('div');toolbar.className='page-toolbar no-print';toolbar.append(...stockActions);document.querySelector('.content>.page-intro')?.after(toolbar);}
  document.addEventListener('click',event=>{const button=event.target.closest('[data-modal-open]');if(button)document.getElementById(button.dataset.modalOpen)?.showModal();});
  if(location.hash){const target=document.getElementById(location.hash.slice(1));if(target instanceof HTMLDialogElement && !target.open)target.showModal();}
  document.addEventListener('invalid',event=>{const dialog=event.target.closest('dialog');if(dialog&&!dialog.open)dialog.showModal();},true);
  document.addEventListener('click',event=>{if(event.target.closest('[data-modal-close]'))event.target.closest('dialog')?.close();});
  document.querySelectorAll('.data-table td form').forEach(form => {
    if (!form.querySelector('input:not([type=hidden]),textarea,select')) return;
    const title = form.querySelector('button[type=submit],button:not([type])')?.textContent.trim() || 'Update record';
    const marker = document.createElement('span'); form.replaceWith(marker); const {trigger} = dialogFor(title, form); marker.replaceWith(trigger);
  });
  // A focused print surface keeps navigation, unrelated records and forms off the slip.
  const printClasses = [];
  function preparePrint(target) {
    document.body.classList.add('print-focused'); target.classList.add('print-target'); printClasses.push(target);
    for (let parent = target.parentElement; parent && parent !== document.body; parent = parent.parentElement) { parent.classList.add('print-ancestor'); printClasses.push(parent); }
  }
  window.addEventListener('afterprint', () => { document.body.classList.remove('print-focused'); printClasses.splice(0).forEach(node => node.classList.remove('print-target', 'print-ancestor')); });
  document.addEventListener('click', event => { const button = event.target.closest('[data-print-target]'); if (!button) return; const target = document.querySelector(button.dataset.printTarget); if (target) { preparePrint(target); window.print(); } });
  document.querySelectorAll('button[onclick]').forEach(button=>{if(!/window\.print\(/.test(button.getAttribute('onclick')))return;button.removeAttribute('onclick');const target=button.closest('section.section-card')||button.closest('.content');if(target){target.id||='print-view-'+(++sequence);button.dataset.printTarget='#'+target.id;}});
  document.querySelectorAll('button').forEach(button => {
    if (!/^(search|find|find records|find product|filter)$/i.test(button.textContent.trim())) return;
    const title = button.textContent.trim(); button.replaceChildren(icon('search')); button.setAttribute('aria-label', title); button.title = title; button.classList.add('search-icon-button');
  });
  document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]),textarea').forEach(input => {
    if (input.placeholder || ['date', 'datetime-local', 'time', 'file', 'password'].includes(input.type)) return;
    const label = input.labels?.[0]?.querySelector('span')?.textContent.trim() || input.getAttribute('aria-label') || input.name.replaceAll('_', ' ');
    input.placeholder = input.type === 'number' ? (String(input.step).includes('.') ? '0.00' : '0') : input.type === 'email' ? 'name@example.com' : /phone|mobile/i.test(input.name) ? '+256 700 000 000' : /search/i.test(input.name) ? 'Search records…' : 'Enter ' + label.toLowerCase();
    if (input.type === 'number') input.inputMode = 'decimal'; if (/phone|mobile/i.test(input.name)) input.inputMode = 'tel';
  });
  // Keep native selects as the submitted value while providing a searchable, keyboard-operated list.
  const enhanceSelects = (root) => root.querySelectorAll('select:not([multiple]):not([data-native-select]):not([data-search-ready])').forEach(select => {
    select.dataset.searchReady='true';
    if (select.closest('.table-tools')) return;
    const wrapper = document.createElement('div'); wrapper.className = 'search-select'; select.before(wrapper); wrapper.append(select); select.classList.add('select-source'); select.tabIndex = -1; select.setAttribute('aria-hidden', 'true');
    const input = document.createElement('input'); input.type = 'text'; input.autocomplete = 'off'; input.className = 'select-search'; input.setAttribute('role', 'combobox'); input.setAttribute('aria-autocomplete', 'list'); input.setAttribute('aria-expanded', 'false');
    input.setAttribute('aria-label', select.labels?.[0]?.querySelector('span')?.textContent.trim() || select.getAttribute('aria-label') || select.name.replaceAll('_', ' '));
    input.id=select.id ? select.id+'-search' : 'select-search-'+(++sequence); [...(select.labels||[])].forEach(label=>label.htmlFor=input.id);
    const list = document.createElement('div'); list.className = 'select-options'; list.id = 'select-options-' + (++sequence); list.setAttribute('role', 'listbox'); list.hidden = true; input.setAttribute('aria-controls', list.id); wrapper.append(input, list);
    let active = -1; let options = [];
    const sync = () => { input.value = select.selectedOptions[0]?.textContent || ''; input.disabled = select.disabled; input.required = select.required; input.setCustomValidity(''); };
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); input.removeAttribute('aria-activedescendant'); sync(); };
    const choose = option => { select.value = option.value; select.dispatchEvent(new Event('change', {bubbles:true})); close(); input.focus(); };
    const render = (query = '') => {
      list.replaceChildren(); active = -1; options = [];
      [...select.options].filter(option => !option.disabled && !option.hidden && option.textContent.toLowerCase().includes(query.toLowerCase())).forEach(option => {
        const row = document.createElement('div'); row.id = list.id + '-' + options.length; row.setAttribute('role', 'option'); row.setAttribute('aria-selected', String(option.selected)); row.textContent = option.textContent; row.tabIndex = -1;
        row.addEventListener('pointerdown', event => { event.preventDefault(); choose(option); }); list.append(row); options.push({option,row});
      });
      if (!options.length) { const empty = document.createElement('p'); empty.textContent = 'No matching choices'; list.append(empty); }
      list.hidden = false; input.setAttribute('aria-expanded', 'true');
    };
    input.addEventListener('focus', () => { render(); input.select(); }); input.addEventListener('click', () => { if (list.hidden) render(); }); input.addEventListener('input', () => {select.selectedIndex=-1;input.setCustomValidity('Choose a matching option from the list.');render(input.value);}); input.addEventListener('blur', close);
    input.addEventListener('keydown', event => {
      if (event.key === 'Escape') { event.stopPropagation(); close(); }
      if (['ArrowDown','ArrowUp'].includes(event.key)) { event.preventDefault(); if (list.hidden) render(); if (!options.length) return; active = (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length; options.forEach((entry,index) => entry.row.classList.toggle('highlighted',index === active)); input.setAttribute('aria-activedescendant',options[active].row.id); options[active].row.scrollIntoView({block:'nearest'}); }
      if (event.key === 'Enter' && !list.hidden) { event.preventDefault(); if (options[active >= 0 ? active : 0]) choose(options[active >= 0 ? active : 0].option); }
    });
    select.addEventListener('change', sync); select.addEventListener('invalid', event => { event.preventDefault(); input.setCustomValidity(select.validationMessage); input.reportValidity(); input.focus(); });
    select.form?.addEventListener('reset', () => queueMicrotask(sync)); new MutationObserver(sync).observe(select,{childList:true,subtree:true,attributes:true,attributeFilter:['disabled','selected']}); sync();
  });
  enhanceSelects(document);
  document.querySelectorAll('dialog form').forEach(form=>{const marker=document.createElement('input');marker.type='hidden';marker.name='_ui_dialog';marker.value=form.closest('dialog').id;form.append(marker);});
  const enhanceTables = (root) => root.querySelectorAll('.data-table').forEach((table, index) => {
    if (table.dataset.tableReady || !table.tHead || table.tHead.rows.length !== 1 || !table.tBodies.length || table.dataset.tableTools === 'off') return;
    table.dataset.tableReady = 'true'; const tbody = table.tBodies[0]; const sourceRows = [...tbody.rows];
    if (sourceRows.some(row => [...row.cells].some(cell => cell.colSpan > 1)) && sourceRows.length > 1) return;
    const editable = !!table.querySelector('tbody input:not([type=hidden]),tbody select,tbody textarea');
    const section = table.closest('section,.section-card,.panel') || table.parentElement;
    const pagination = [...section.querySelectorAll('.server-pagination')].find(node => !node.dataset.claimed); if (pagination) pagination.dataset.claimed = 'true';
    const columns = [...table.tHead.rows[0].cells]; table.style.setProperty('--table-columns',String(Math.max(4,columns.length+1))); const hint=document.createElement('p');hint.className='table-scroll-hint no-print';hint.textContent='Scroll sideways to see all columns.';table.closest('.table-wrap')?.before(hint); const rows = sourceRows.filter(row => ![...row.cells].some(cell => cell.colSpan > 1));
    const numberHeader = document.createElement('th'); numberHeader.textContent = '#'; numberHeader.className = 'row-number'; table.tHead.rows[0].prepend(numberHeader);
    rows.forEach(row => { const cell = document.createElement('td'); cell.className = 'row-number'; row.prepend(cell); });
    sourceRows.filter(row => !rows.includes(row)).forEach(row => row.cells[0].colSpan++);
    if (editable) { rows.forEach((row,i) => row.cells[0].textContent = String(i + 1)); return; }
    table.id ||= 'workspace-table-' + (++sequence); section.id ||= table.id + '-section';
    const tools = document.createElement('div'); tools.className = 'table-tools no-print';
    const existingSearch=section.querySelector('form[method=GET i] input[name=search]') || (section.id ? document.querySelector('form[data-table-search="'+section.id+'"] input[name=search]') : null);
    const search = existingSearch || document.createElement('input'); if(!existingSearch){search.type = 'search'; search.placeholder = pagination ? 'Search all records…' : 'Search this list…'; search.setAttribute('aria-label', 'Search ' + (section.querySelector('h2')?.textContent || 'records'));} search.maxLength = 120;
    const searchButton = document.createElement('button'); searchButton.type = 'button'; searchButton.className = 'secondary-button search-icon-button'; searchButton.setAttribute('aria-label','Search records'); searchButton.append(icon('search'));
    const filters = document.createElement('select'); filters.setAttribute('aria-label','Filter column'); filters.dataset.nativeSelect = ''; const all = document.createElement('option'); all.value = ''; all.textContent = 'All columns'; filters.append(all); columns.forEach((column,i) => { const option=document.createElement('option'); option.value=String(i+1); option.textContent=column.textContent.trim(); filters.append(option); });
    const size = document.createElement('select'); size.dataset.nativeSelect = ''; size.setAttribute('aria-label','Rows per page'); [10,15,20,24,25,30,40,50,100].forEach(value => { const option=document.createElement('option'); option.value=String(value); option.textContent=value + ' rows'; size.append(option); }); size.value = pagination ? '25' : '10';
    const print = document.createElement('button'); print.type='button'; print.className='secondary-button'; print.setAttribute('aria-label','Print this list'); print.title='Print this list'; print.dataset.printTarget='#'+section.id; print.append(icon('print'));
    const reset=document.createElement('button'); reset.type='button'; reset.className='text-button'; reset.textContent='Reset'; if(existingSearch){tools.append(filters,size,print);existingSearch.form.append(tools);}else{tools.append(search,searchButton,filters,size,reset,print); table.closest('.table-wrap')?.before(tools);}
    let page=1, direction=1, sortColumn=-1; const collator=new Intl.Collator(undefined,{numeric:true,sensitivity:'base'});
    const names={batch:'batch_code',product:'product',received:'received_on',date:'created_at',reference:'reference',quantity:'quantity',amount:'amount',status:'status',starts:'starts_at',ends:'ends_at','clock-in':'clocked_in_at','clock-out':'clocked_out_at'};
    const serverColumns=pagination ? JSON.parse(pagination.dataset.tableColumns || '[]') : [];
    const key=pagination?.dataset.tableKey;
    const currentParams=new URL(table.closest('[data-remote-panel]')?.dataset.url || location.href).searchParams;
    if(key){if(!existingSearch)search.value=currentParams.get(`tables[${key}][search]`)||'';size.value=currentParams.get(`tables[${key}][size]`)||pagination.dataset.pageSize||'25';}
    const serverSubmit = (sort) => { const url=new URL(table.closest('[data-remote-panel]')?.dataset.url || location.href); if(existingSearch){for(const [name,value] of new FormData(existingSearch.form)){url.searchParams.set(name,value);}url.searchParams.delete(`tables[${key}][search]`);}else url.searchParams.set(`tables[${key}][search]`,search.value.trim()); url.searchParams.set(`tables[${key}][size]`,size.value); if(sort){url.searchParams.set(`tables[${key}][sort]`,sort);url.searchParams.set(`tables[${key}][direction]`,direction===1?'asc':'desc');} const pageName=key.split('__').pop();url.searchParams.delete(pageName);const panel=table.closest('[data-remote-panel]')||table.closest('[data-directory]');if(panel){panel.dispatchEvent(new CustomEvent('workspace:navigate',{bubbles:true,detail:{url:url.href}}));}else location.assign(url.href); };
    const pager=document.createElement('div'); pager.className='table-pager no-print'; const info=document.createElement('span'); info.setAttribute('role','status'); const previous=document.createElement('button');previous.type='button';previous.className='secondary-button';previous.textContent='Previous';const next=document.createElement('button');next.type='button';next.className='secondary-button';next.textContent='Next';pager.append(info,previous,next); if(!pagination)table.closest('.table-wrap')?.after(pager);
    const render = () => { const term=(key&&!filters.value?'':search.value.trim().toLowerCase()),column=Number(filters.value); let filtered=rows.filter(row => (column ? (row.cells[column]?.dataset.searchText || row.cells[column]?.textContent || '') : (row.dataset.filterText || row.textContent)).toLowerCase().includes(term)); if(sortColumn>=0)filtered.sort((a,b)=>{const left=a.cells[sortColumn].dataset.sortValue||a.cells[sortColumn].textContent.trim(),right=b.cells[sortColumn].dataset.sortValue||b.cells[sortColumn].textContent.trim(),label=columns[sortColumn-1]?.textContent||'';if(/amount|value|price|total|quantity|unpacked|available|received|weight|balance/i.test(label)&&!/date|reference/i.test(label)){const number=value=>Number((value.match(/-?\d[\d,]*(?:\.\d+)?/)?.[0]||'NaN').replaceAll(',',''));const l=number(left),r=number(right);if(Number.isFinite(l)&&Number.isFinite(r))return direction*(l-r);}return direction*collator.compare(left,right);}); const limit=Number(size.value); page=Math.max(1,Math.min(page,Math.ceil(filtered.length/limit)||1)); rows.forEach(row=>{row.hidden=true;}); filtered.forEach((row,i)=>{row.cells[0].textContent=String((pagination?Number(pagination.dataset.rowStart)-1:0)+i+1);row.hidden=!pagination&&(i<(page-1)*limit||i>=page*limit);tbody.append(row);}); info.textContent=filtered.length ? `${(page-1)*limit+1}–${Math.min(page*limit,filtered.length)} of ${filtered.length}` : 'No matching records'; previous.disabled=page<=1;next.disabled=page*limit>=filtered.length; };
    columns.forEach((column,i)=>{if(/actions?|control|qr information/i.test(column.textContent))return;const button=document.createElement('button');button.type='button';button.className='table-sort';const title=column.textContent.trim();const columnField=column.dataset.sort||names[title.toLowerCase()]||title.toLowerCase().replaceAll(' ','_');if(key&&currentParams.get(`tables[${key}][sort]`)===columnField){sortColumn=i+1;direction=currentParams.get(`tables[${key}][direction]`)==='desc'?-1:1;column.setAttribute('aria-sort',direction===1?'ascending':'descending');}button.textContent=title;button.setAttribute('aria-label','Sort by '+title);column.replaceChildren(button);button.addEventListener('click',()=>{direction=sortColumn===i+1?-direction:1;sortColumn=i+1;columns.forEach(c=>c.removeAttribute('aria-sort'));column.setAttribute('aria-sort',direction===1?'ascending':'descending');const field=column.dataset.sort||names[title.toLowerCase()]||title.toLowerCase().replaceAll(' ','_');if(key&&serverColumns.some(c=>c.value===field)){serverSubmit(field);return;}render();});});
    if(pagination){searchButton.addEventListener('click',()=>serverSubmit());if(!existingSearch)search.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();serverSubmit();}});size.addEventListener('change',()=>serverSubmit());filters.addEventListener('change',()=>{search.placeholder='Filter this page by column…';});}
    search.addEventListener('input',()=>{page=1;render();}); filters.addEventListener('change',()=>{page=1;render();}); if(!pagination)size.addEventListener('change',()=>{page=1;render();});searchButton.addEventListener('click',()=>{if(!pagination)render();});reset.addEventListener('click',()=>{search.value='';filters.value='';page=1;if(pagination)serverSubmit();else render();});previous.addEventListener('click',()=>{page--;render();});next.addEventListener('click',()=>{page++;render();});
    rows.forEach(row=>{row.dataset.filterText=row.textContent;[...row.cells].forEach(cell=>cell.dataset.searchText=cell.textContent);});
    // Long notes remain searchable but open on demand instead of widening a cell.
    rows.forEach(row=>[...row.cells].forEach(cell=>{if(cell.textContent.trim().length<160||cell.querySelector('a,button,form'))return;const content=document.createElement('p');content.textContent=cell.textContent.trim();const {trigger}=dialogFor('Record details',content,false);const printText=document.createElement('span');printText.className='print-only';printText.textContent=content.textContent;trigger.classList.add('no-print');cell.replaceChildren(trigger,printText);}));
    render();
  });
  enhanceTables(document);
  window.WorkspaceEnhance=(root)=>{const previous=new Set(document.querySelectorAll('.workspace-dialog'));enhanceDialogs(root);const added=[...document.querySelectorAll('.workspace-dialog')].filter(dialog=>!previous.has(dialog));[root,...added].forEach(target=>{enhanceSelects(target);enhanceTables(target);});};
  if(document.querySelector('.flash.error')){const dialog=document.getElementById(document.body.dataset.errorDialog||'');if(dialog instanceof HTMLDialogElement && !dialog.open)dialog.showModal();}
})();

