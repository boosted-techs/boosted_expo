window.WorkspaceModules ||= {};
window.WorkspaceModules.sources=root=>{

  root.querySelectorAll('[data-source-picker]').forEach(picker => {if(picker.dataset.sourceReady)return;picker.dataset.sourceReady='true';
    const select = picker.querySelector('[data-source-options]');
    const search = picker.querySelector('[data-source-search]');
    const more = picker.querySelector('[data-source-more]');
    const message = picker.querySelector('[data-source-message]');
    let nextPage = null;
    let currentSearch = '';
    let pending = false;
    async function find(append = false) {
      if (pending) return;
      pending = true;
      try {
        const url = new URL(picker.dataset.url, location.origin);
        if (!append) currentSearch = search.value;
        url.searchParams.set('kind', picker.dataset.kind);
        url.searchParams.set('search', currentSearch);
        url.searchParams.set('page', append ? nextPage : 1);
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
        if (!response.ok) throw new Error('Source search unavailable. Check access and connection, then retry.');
        const result = await response.json();
        if (!append) select.replaceChildren(new Option('Choose a source', ''));
        result.items.forEach(item => select.add(new Option(item.label, item.id)));
        nextPage = result.next_page;
        more.hidden = !nextPage;
        message.textContent = result.items.length ? 'Select a source above. Availability is checked again when you save.' : 'No matching source found.';
      } catch (error) { message.textContent = error.message; }
      finally { pending = false; }
    }
    picker.querySelector('[data-source-find]').addEventListener('click', () => find());
    more.addEventListener('click', () => find(true));
    search.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); find(); } });
  });

};
window.WorkspaceModules.sources(document);
