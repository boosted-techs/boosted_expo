'use strict';
const themeButton=document.getElementById('theme-btn');
themeButton?.addEventListener('click',()=>{const v=document.documentElement.dataset.theme==='dark'?'light':'dark';document.documentElement.dataset.theme=v;try{localStorage.setItem('expo-theme',v)}catch(e){}});
const side=document.getElementById('sidebar'),screen=document.getElementById('scrim'),menu=document.getElementById('menu-btn');
const desktopNavigation=window.matchMedia('(min-width:1100px)');
function showMenu(open){
 if(!side || !screen || !menu)return;
 side.inert=!open && !desktopNavigation.matches;
 side.classList.toggle('open',open);
 screen.hidden=!open;
 menu.setAttribute('aria-expanded',String(open));
 menu.setAttribute('aria-label',open?'Close navigation':'Open navigation');
 document.body.classList.toggle('navigation-open',open);
 const main=document.querySelector('.main'),header=document.querySelector('.topbar');
 if(main)main.inert=open;
 if(header)header.inert=open;
 if(open)side.querySelector('a')?.focus();
}
menu?.addEventListener('click',()=>showMenu(!side.classList.contains('open')));
side?.addEventListener('click',event=>{if(!desktopNavigation.matches && event.target.closest('a[href]'))showMenu(false)});
screen?.addEventListener('click',()=>{showMenu(false);menu?.focus()});
side?.addEventListener('click',event=>{if(event.target.closest('[data-navigation-close]')){showMenu(false);menu?.focus()}});
const navigationGroups=()=>[...document.querySelectorAll('.nav-workgroup')];
side?.addEventListener('toggle',event=>{const group=event.target;if(group.matches?.('.nav-workgroup')&&group.open&&desktopNavigation.matches)navigationGroups().forEach(other=>{if(other!==group)other.open=false});},true);
function closeDropdowns(){navigationGroups().forEach(group=>{group.open=false})}
document.addEventListener('click',event=>{
 if(desktopNavigation.matches && !event.target.closest('.nav-workgroup'))closeDropdowns();
});
document.addEventListener('focusin',event=>{
 if(desktopNavigation.matches && !event.target.closest('.nav-workgroup'))closeDropdowns();
});
document.addEventListener('keydown',event=>{
 if(event.key==='Escape'){
  const openGroup=navigationGroups().find(group=>group.open && group.contains(document.activeElement));
  closeDropdowns();
  if(openGroup)openGroup.querySelector('summary')?.focus();
  if(side?.classList.contains('open')){showMenu(false);menu?.focus()}
 }
 if(event.key==='Tab' && side?.classList.contains('open')){
  const focusable=[...side.querySelectorAll('a[href],summary,button')].filter(element=>element.getClientRects().length);
  const first=focusable[0],last=focusable[focusable.length-1];
  if(event.shiftKey && document.activeElement===first){event.preventDefault();last?.focus()}
  else if(!event.shiftKey && document.activeElement===last){event.preventDefault();first?.focus()}
 }
});
function syncNavigation(){
 showMenu(false);
 navigationGroups().forEach(group=>{group.open=!desktopNavigation.matches && group.classList.contains('is-current')});
}
desktopNavigation.addEventListener('change',syncNavigation);
syncNavigation();
window.WorkspaceSyncNavigation=syncNavigation;

// Delegated confirmation replaces inline onclick/onsubmit JavaScript.
document.addEventListener('submit',event=>{
 const form=event.target.closest('form[data-confirm]');
 if(form && !window.confirm(form.dataset.confirm))event.preventDefault();
});
document.addEventListener('click',event=>{
 const button=event.target.closest('button[data-confirm]');
 if(button && !window.confirm(button.dataset.confirm))event.preventDefault();
});

// Expand report evidence for printing and restore the reader's current view afterward.
const printOpenedDetails = new Set();
window.addEventListener('beforeprint', () => {
  document.querySelectorAll('details[data-print-details]').forEach(detail => {
    if (!detail.open) { printOpenedDetails.add(detail); detail.open = true; }
  });
});
window.addEventListener('afterprint', () => {
  printOpenedDetails.forEach(detail => { detail.open = false; });
  printOpenedDetails.clear();
});
