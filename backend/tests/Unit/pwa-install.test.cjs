const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('public/assets/pwa-install.js','utf8');
function browser({ua='Chrome desktop',standalone=false,blocked=false,snooze=0,secure=true}={}) {
  const events = {}, clicks = {};
  const elements = {};
  ['banner','dialog','guidance','button','later','open','close'].forEach(name => {
    elements[name] = {hidden:true,textContent:'',disabled:false,open:false,showModal(){this.open=true;},close(){this.open=false;},addEventListener(type,fn){ clicks[name] = fn; },querySelector(){return {focus(){}};}};
  });
  const map = new Map([['boosted-one-install-later-until',String(snooze)]]);
  const storage = {getItem(k){if(blocked)throw Error();return map.get(k);},setItem(k,v){if(blocked)throw Error();map.set(k,v);}};
  let registrations = 0;
  const navigator = {userAgent:ua,platform:'',maxTouchPoints:0,serviceWorker:{register(){registrations++;return Promise.resolve();}}};
  const context = {navigator,localStorage:storage,sessionStorage:storage,Date:{now:()=>1000},document:{readyState:'complete',addEventListener(){},querySelectorAll(selector){const name=selector.match(/data-install-(.+)\]/)[1];return [elements[name]];},querySelector(){return elements.dialog;}},window:{isSecureContext:secure,matchMedia(){return {matches:standalone,addEventListener(){}};},addEventListener(name,fn){events[name]=fn;}}};
  vm.runInNewContext(source,context);
  return {elements,events,clicks,map,registrations};
}
test('installation help opens only after a user request and closes without hiding the entry',()=>{
  const b=browser();assert.equal(b.elements.banner.hidden,false);assert.equal(b.elements.dialog.open,false);assert.equal(b.elements.button.hidden,true);
  b.clicks.open();assert.equal(b.elements.dialog.open,true);assert.match(b.elements.guidance.textContent,/browser menu/);
  b.clicks.close();assert.equal(b.elements.dialog.open,false);assert.equal(b.elements.banner.hidden,false);
});
test('native prompt is called only after a click and consumed once',async()=>{
  const b=browser();let calls=0;let prevented=false;
  b.events.beforeinstallprompt({preventDefault(){prevented=true;},async prompt(){calls++;},userChoice:Promise.resolve({outcome:'accepted'})});
  assert.equal(prevented,true);assert.equal(calls,0);assert.equal(b.elements.button.hidden,false);
  b.clicks.open();await b.clicks.button();await b.clicks.button();assert.equal(calls,1);assert.equal(b.elements.dialog.open,false);assert.equal(b.elements.button.hidden,true);
});
test('Not now closes the modal and snoozes the entry for seven days',()=>{
  const b=browser();b.clicks.open();b.clicks.later();assert.equal(b.elements.banner.hidden,true);assert.equal(b.elements.dialog.open,false);assert.equal(Number(b.map.get('boosted-one-install-later-until')),604801000);
  assert.equal(browser({snooze:604801000}).elements.banner.hidden,true);
});
test('standalone windows suppress installation invitations and native prompt',()=>{const b=browser({standalone:true});assert.equal(b.elements.banner.hidden,true);assert.equal(b.elements.button.hidden,true);});
test('iPhone and macOS Safari receive appropriate fallback guidance',()=>{
  assert.match(browser({ua:'iPhone Safari'}).elements.guidance.textContent,/Share, then Add to Home Screen/);
  assert.match(browser({ua:'Macintosh Safari'}).elements.guidance.textContent,/Add to Dock/);
});
test('blocked browser storage does not break login or current-page dismissal',()=>{const b=browser({blocked:true});assert.equal(b.elements.banner.hidden,false);b.clicks.later();assert.equal(b.elements.banner.hidden,true);});
test('dismissed native prompt snoozes and an installed event hides the invitation',async()=>{
  const b=browser();b.events.beforeinstallprompt({preventDefault(){},prompt:async()=>{},userChoice:Promise.resolve({outcome:'dismissed'})});await b.clicks.button();assert.equal(b.elements.banner.hidden,true);b.events.appinstalled();assert.equal(b.elements.banner.hidden,true);
});
test('service worker registers only in secure contexts',()=>{assert.equal(browser().registrations,1);assert.equal(browser({secure:false}).registrations,0);});
test('offline service worker serves a connection message without caching private data or writes',async()=>{
  const events={};let fetched=0;
  vm.runInNewContext(fs.readFileSync('public/service-worker.js','utf8'),{self:{addEventListener(n,fn){events[n]=fn;},skipWaiting(){return Promise.resolve();},clients:{claim(){return Promise.resolve();}}},fetch:async()=>{fetched++;throw Error('offline');},Response});
  let response;events.fetch({request:{method:'GET',mode:'navigate'},respondWith(p){response=p;}});
  const offline=await response;assert.equal(offline.status,503);assert.match(await offline.text(),/Changes are not saved while offline/);
  events.fetch({request:{method:'POST',mode:'navigate'},respondWith(){throw Error('writes must not be intercepted');}});assert.equal(fetched,1);
});
