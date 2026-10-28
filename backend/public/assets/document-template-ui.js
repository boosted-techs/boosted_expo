window.WorkspaceModules ||= {};
window.WorkspaceModules.documentTemplates=root=>{


 const type=root.querySelector('#doc-type'), columns=root.querySelector('#packing-columns');
 const apply=()=>{columns.hidden=type.value!=='packing_list';};
 if(type&&columns&&!type.dataset.templateReady){type.dataset.templateReady='true';type.addEventListener('change',apply);apply();}

};
window.WorkspaceModules.documentTemplates(document);
