'use strict';
(function(){
 const type=document.getElementById('doc-type'), columns=document.getElementById('packing-columns');
 const apply=()=>{columns.hidden=type.value!=='packing_list';};
 if(type&&columns){type.addEventListener('change',apply);apply();}
})();
