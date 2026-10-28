window.WorkspaceModules ||= {};
window.WorkspaceModules.packing=root=>{

const addBatch = root.querySelector('#more-batch');
if(addBatch&&!addBatch.dataset.packingReady){addBatch.dataset.packingReady='true';
 let nextIndex=1;
 addBatch.addEventListener('click',()=>{
   if(nextIndex>=10)return;
   const target=root.querySelector('#batch-lines');
   const html=root.querySelector('#batch-line-template').innerHTML.replaceAll('__INDEX__',String(nextIndex++));
   target.insertAdjacentHTML('beforeend',html);
   if(nextIndex>=10)addBatch.disabled=true;
 });
 root.querySelector('#batch-lines').addEventListener('click',e=>{
   if(e.target.classList.contains('remove-batch'))e.target.closest('.batch-line')?.remove();
 });
}

};
window.WorkspaceModules.packing(document);
