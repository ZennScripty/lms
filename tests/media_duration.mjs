import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
let changed, media, timeout, revoked=0;
const duration={value:'',required:false}, status={textContent:''};
const input={files:[],closest:()=>({querySelector:s=>s==='[data-media-duration]'?duration:status}),addEventListener:(_e,f)=>changed=f};
const context={document:{documentElement:{lang:'en'},querySelectorAll:()=>[input],createElement:()=>media={removeAttribute(){},load(){}}},URL:{createObjectURL:()=> 'blob:test',revokeObjectURL:()=>revoked++},Number,String,NaN,setTimeout:f=>(timeout=f,1),clearTimeout(){}};
vm.runInNewContext(fs.readFileSync(new URL('../public/assets/js/media-duration.js',import.meta.url),'utf8'),context);
let passed=0;function check(ok,label){assert.ok(ok,label);console.log('PASS '+label);passed++;}
input.files=[{type:'audio/wav',name:'short.wav'}];changed();media.duration=2;media.onloadedmetadata();check(duration.value==='2'&&duration.required,'audio metadata populates required duration');check(revoked===1,'completed metadata releases object URL');
input.files=[{type:'video/mp4',name:'clip.mp4'}];changed();media.duration=3.4;media.onloadedmetadata();check(duration.value==='4','fractional duration rounds up');
input.files=[{type:'video/mp4',name:'bad.mp4'}];changed();media.duration=Infinity;media.onloadedmetadata();check(duration.value===''&&status.textContent.includes('Enter'),'invalid duration requests manual entry');
input.files=[{type:'audio/mpeg',name:'slow.mp3'}];changed();timeout();check(duration.required&&status.textContent.includes('Enter'),'timeout preserves manual fallback');
input.files=[{type:'video/mp4',name:'old.mp4'}];changed();const old=media;input.files=[{type:'application/pdf',name:'notes.pdf'}];changed();old.duration=40;old.onloadedmetadata();check(duration.value===''&&!duration.required,'stale callback cannot change replacement file');
input.files=[{type:'',name:'clip.mp4'}];changed();media.duration=4;media.onloadedmetadata();check(duration.value==='4','known extension handles missing browser MIME');
input.files=[];changed();check(duration.value===''&&!duration.required,'cleared file resets duration');
console.log(`${passed} media duration checks passed`);
