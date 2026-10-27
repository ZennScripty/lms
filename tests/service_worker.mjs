import fs from 'node:fs';
import vm from 'node:vm';
const origin='http://localhost/lms/public/';
const handlers={},stores=new Map();let online=true,serverUser='11';let count=0;
const caches={async open(name){if(!stores.has(name))stores.set(name,new Map());const store=stores.get(name);return{async put(req,res){store.set(typeof req==='string'?req:req.url,res.clone());},async match(req,options={}){const url=typeof req==='string'?req:req.url;const found=store.get(url);if(found)return found.clone();if(options.ignoreSearch){for(const [key,res]of store){if(key.split('?')[0]===url.split('?')[0])return res.clone();}}},async addAll(urls){for(const url of urls)store.set(url,new Response('shell'));}};},async keys(){return[...stores.keys()];},async delete(name){return stores.delete(name);}};
const self={registration:{scope:origin},location:{origin:'http://localhost'},clients:{claim:async()=>{}},skipWaiting:async()=>{},addEventListener(type,fn){handlers[type]=fn;}};
const fetch=async(req)=>{if(!online)throw new Error('offline');const url=typeof req==='string'?req:req.url;if(url.includes('assets/'))return new Response('asset');return new Response('student '+serverUser,{headers:{'X-TLMTI-User':serverUser,'X-TLMTI-Role':'student'}});};
vm.runInNewContext(fs.readFileSync(new URL('../public/sw.js',import.meta.url),'utf8'),{self,caches,fetch,URL,Response,Headers,Promise,JSON,Number,Math,String});
async function event(type,data={}){const work=[];let response;handlers[type]({...data,waitUntil(p){work.push(p);},respondWith(p){response=p;}});const result=response?await response:undefined;await Promise.all(work);return result;}
function check(ok,label){if(!ok)throw new Error(label);count++;console.log('PASS '+label);}
await event('install');await event('activate');
await event('message',{data:{type:'SET_USER',userId:'11',role:'student'}});
const nav={url:origin+'lesson.php?id=7',method:'GET',mode:'navigate',headers:new Headers()};
await event('fetch',{request:nav});online=false;let response=await event('fetch',{request:nav});check(await response.text()==='student 11','downloaded navigation available offline');
response=await event('fetch',{request:{...nav,url:origin+'lesson.php?id=999'}});check(response.status===503,'unvisited page does not pretend to be downloaded');
const oldAttendance=await caches.open('tlmti-user-pages-11');await oldAttendance.put(origin+'attendance.php',new Response('stale attendance'));
response=await event('fetch',{request:{...nav,url:origin+'attendance.php'}});check(response.status===503,'daily attendance never uses a stale offline page');
const content=await caches.open('tlmti-content-user-11');await content.put(origin+'media.php?id=3',new Response('0123456789',{headers:{'Content-Type':'video/mp4'}}));
response=await event('fetch',{request:{url:origin+'media.php?id=3',method:'GET',mode:'cors',headers:new Headers({Range:'bytes=2-5'})}});check(response.status===206&&await response.text()==='2345','offline media supports byte ranges');
await event('message',{data:{type:'SET_USER',userId:'22',role:'student'}});response=await event('fetch',{request:nav});check(response.status===503,'account switch cannot read another user cache');
online=true;serverUser='22';await event('fetch',{request:nav});online=false;response=await event('fetch',{request:nav});check(await response.text()==='student 22','second account has its own cached content');
await event('message',{data:{type:'SET_USER',userId:'',role:''}});response=await event('fetch',{request:nav});check(response.status===503,'logout clears private downloaded content');
console.log(`${count} service worker tests passed`);
