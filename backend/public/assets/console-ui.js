'use strict';
const themeButton=document.getElementById('theme-btn');
themeButton?.addEventListener('click',()=>{const v=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=v;try{localStorage.setItem('expo-theme',v)}catch(e){}});
const side=document.getElementById('sidebar'),screen=document.getElementById('scrim'),menu=document.getElementById('menu-btn');
function showMenu(open){side.classList.toggle('open',open);screen.hidden=!open;menu.setAttribute('aria-expanded',String(open))}
menu.addEventListener('click',()=>showMenu(!side.classList.contains('open')));
screen.addEventListener('click',()=>showMenu(false));
document.addEventListener('keydown',e=>{if(e.key==='Escape')showMenu(false)});

// Delegated confirmation replaces inline onclick/onsubmit JavaScript.
document.addEventListener('submit',event=>{
 const form=event.target.closest('form[data-confirm]');
 if(form && !window.confirm(form.dataset.confirm))event.preventDefault();
});
document.addEventListener('click',event=>{
 const button=event.target.closest('button[data-confirm]');
 if(button && !window.confirm(button.dataset.confirm))event.preventDefault();
});
