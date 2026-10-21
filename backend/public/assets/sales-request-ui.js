'use strict';
const newLine=document.getElementById('add-request-product');
let nextLine=1;
newLine?.addEventListener('click',()=>{
 if(document.querySelectorAll('.product-request-line').length>=20)return;
 document.getElementById('request-products').insertAdjacentHTML('beforeend',
  document.getElementById('request-line-template').innerHTML.replaceAll('__I__',String(nextLine++)));
});
document.getElementById('request-products')?.addEventListener('click',e=>{
 if(e.target.classList.contains('remove-product'))e.target.closest('.product-request-line').remove();
});
