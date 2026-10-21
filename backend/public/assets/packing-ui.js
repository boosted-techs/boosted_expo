'use strict';
const addBatch = document.getElementById('more-batch');
if(addBatch){
 let nextIndex=1;
 addBatch.addEventListener('click',()=>{
   if(nextIndex>=10)return;
   const target=document.getElementById('batch-lines');
   const html=document.getElementById('batch-line-template').innerHTML.replaceAll('__INDEX__',String(nextIndex++));
   target.insertAdjacentHTML('beforeend',html);
   if(nextIndex>=10)addBatch.disabled=true;
 });
 document.getElementById('batch-lines').addEventListener('click',e=>{
   if(e.target.classList.contains('remove-batch'))e.target.closest('.batch-line')?.remove();
 });
}
