/* Student study, test drafts and explicitly downloaded local media. */
(function(w,d){
 'use strict';
 var uid=d.body.getAttribute('data-user-id'),hi=d.documentElement.lang==='hi';
 function msg(en,hindi){return hi?hindi:en;}
 function uuid(){return crypto.randomUUID?crypto.randomUUID():([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g,function(c){return(c^crypto.getRandomValues(new Uint8Array(1))[0]&15>>c/4).toString(16);});}
 function text(selector,value){var el=d.querySelector(selector);if(el){el.textContent=value;}}
 var upload=d.querySelector('[data-media-upload]'),durationInput=d.querySelector('[data-media-duration]');
 if(upload&&durationInput){upload.addEventListener('change',function(){durationInput.value='';var file=upload.files[0];if(!file){return;}var timed=/^(video|audio)\//.test(file.type);durationInput.required=timed;if(!timed){return;}var probe=d.createElement(file.type.indexOf('video/')===0?'video':'audio'),url=URL.createObjectURL(file);probe.preload='metadata';probe.addEventListener('loadedmetadata',function(){if(Number.isFinite(probe.duration)&&probe.duration>0){durationInput.value=Math.ceil(probe.duration);}URL.revokeObjectURL(url);});probe.addEventListener('error',function(){URL.revokeObjectURL(url);});probe.src=url;});}
 var study=d.querySelector('[data-study]');
 if(study){
   var lessonId=Number(study.dataset.lessonId),required=Number(study.dataset.required),saved=Number(study.dataset.seconds),pendingSeconds=0,completed=study.dataset.completed==='1'||study.dataset.readonly==='1',completionQueued=false,player=study.querySelector('[data-study-player]'),lastPosition=0,completeBtn=study.querySelector('[data-study-complete]');
   var lastTick=performance.now(),currentSave=Promise.resolve();
   if(player){player.addEventListener('loadedmetadata',function(){var position=Number(study.dataset.position);if(position>0&&position<player.duration){player.currentTime=position;}lastPosition=player.currentTime;});}
   function status(s){text('[data-study-message]',s);}
   function update(){text('[data-study-seconds]',Math.floor(saved+pendingSeconds));if(completeBtn&&!completed){completeBtn.disabled=completionQueued||saved+pendingSeconds<required;}}
   setInterval(function(){var now=performance.now(),delta=Math.min(2,Math.max(0,(now-lastTick)/1000));lastTick=now;if(completed||d.hidden){return;}if(player){var moved=player.currentTime-lastPosition;lastPosition=player.currentTime;if(!player.paused&&!player.ended&&moved>0&&moved<=delta*(player.playbackRate||1)+1){pendingSeconds+=Math.min(moved,delta);}}else{pendingSeconds+=delta;}update();},1000);
   function save(complete){
     var seconds=Math.floor(pendingSeconds);if(completed||(!seconds&&!complete)){return currentSave;}pendingSeconds-=seconds;
     var payload={lesson_id:lessonId,seconds:seconds,position:player?Math.floor(player.currentTime):0,complete:complete,cycle_no:Number(study.dataset.cycle),open_token:study.dataset.openToken};
     currentSave=currentSave.then(function(){return TlmtiSync.submit({page:'lesson.php',type:'study',entity_type:'lesson',entity_id:lessonId,payload:payload});}).then(function(event){
       if(event.status==='synced'){if(!completed){status(msg('Progress saved.','प्रगति सहेजी गई।'));}}
       else if(event.status==='rejected'){completionQueued=false;status(event.last_error);if(completeBtn){completeBtn.disabled=false;}}
       else{saved+=seconds;status(msg('Saved on this device. Connect to sync.','इस डिवाइस पर सहेजा गया। सिंक के लिए इंटरनेट से जुड़ें।'));if(complete){completionQueued=true;completeBtn.disabled=true;}}
       update();
     }).catch(function(){pendingSeconds+=seconds;status(msg('Could not save yet. Please try again.','अभी सहेजा नहीं जा सका। फिर प्रयास करें।'));update();});return currentSave;
   }
   w.addEventListener('tlmti:synced',function(e){(e.detail&&e.detail.results||[]).forEach(function(result){var data=result.data;if(!data||Number(data.lesson_id)!==lessonId||Number(data.cycle_no)!==Number(study.dataset.cycle)){return;}saved=Number(data.seconds);if(data.completed){completed=true;completeBtn.disabled=true;completeBtn.textContent=msg('Completed','पूर्ण');text('[data-study-state]',msg('Completed','पूर्ण'));status(msg('Lesson completed. Open Modules to continue.','पाठ पूरा हुआ। आगे बढ़ने के लिए मॉड्यूल खोलें।'));}update();});});
   setInterval(function(){save(false);},15000);
   if(player){player.addEventListener('pause',function(){save(false);});player.addEventListener('seeked',function(){lastPosition=player.currentTime;});}
   d.addEventListener('visibilitychange',function(){if(d.hidden){save(false);}lastTick=performance.now();});
   completeBtn.addEventListener('click',function(){completeBtn.disabled=true;save(true);});update();
 }
 var form=d.querySelector('[data-student-test]');
 if(form){
   var attempt=form.dataset.attemptId,key='student-test-'+uid+'-'+attempt,panels=Array.from(form.querySelectorAll('[data-question-panel]')),index=0,prev=form.querySelector('[data-test-prev]'),next=form.querySelector('[data-test-next]'),submit=form.querySelector('[data-test-submit]'),deadline=Number(form.dataset.deadline),queued=false;
   function answers(){var value={};form.querySelectorAll('input[type=radio]:checked').forEach(function(input){var match=input.name.match(/^answers\[(\d+)\]$/);if(match){value[match[1]]=Number(input.value);}});return value;}
   function show(){panels.forEach(function(panel,i){panel.hidden=i!==index;});text('[data-question-number]',index+1);prev.hidden=index===0;next.hidden=index===panels.length-1;submit.hidden=index!==panels.length-1;}
   prev.addEventListener('click',function(){index=Math.max(0,index-1);show();});next.addEventListener('click',function(){index=Math.min(panels.length-1,index+1);show();});
   if(w.TlmtiDB){TlmtiDB.getDraft(key).then(function(draft){if(!draft){return;}Object.keys(draft.data||{}).forEach(function(q){var input=form.querySelector('input[name="answers['+q+']"][value="'+Number(draft.data[q])+'"]');if(input){input.checked=true;}});}).catch(function(){});form.addEventListener('change',function(){TlmtiDB.saveDraft(key,answers()).catch(function(){});});}
   function tick(){if(!deadline){return;}var left=Math.max(0,deadline-Math.floor(Date.now()/1000));text('[data-test-clock]',String(Math.floor(left/60)).padStart(2,'0')+':'+String(left%60).padStart(2,'0'));if(!left&&!queued){queued=true;text('[data-student-test-message]',msg('Time expired. The server will record this attempt.','समय समाप्त हुआ। सर्वर यह प्रयास दर्ज करेगा।'));form.requestSubmit(submit);}}
   form.addEventListener('submit',function(e){if(!queued&&!w.confirm(msg('Submit your answers? Unanswered questions receive zero marks.','उत्तर जमा करें? खाली प्रश्नों के शून्य अंक मिलेंगे।'))){e.preventDefault();return;}if(navigator.onLine){submit.disabled=true;return;}e.preventDefault();queued=true;submit.disabled=true;TlmtiSync.submit({page:'tests.php',type:'test_submit',entity_type:'attempt',entity_id:Number(attempt),payload:{attempt_id:Number(attempt),answers:answers()}}).then(function(){form.querySelectorAll('input[type=radio]').forEach(function(input){input.disabled=true;});text('[data-student-test-message]',msg('Answers queued offline. Final result appears after server sync. Timed tests must reach the server before the deadline.','उत्तर ऑफ़लाइन सहेजे गए। सर्वर सिंक के बाद अंतिम परिणाम मिलेगा। समयबद्ध टेस्ट समय सीमा से पहले सर्वर पर पहुँचना चाहिए।'));}).catch(function(){submit.disabled=false;queued=false;});});
   w.addEventListener('tlmti:synced',function(e){(e.detail&&e.detail.results||[]).forEach(function(result){if(result.data&&Number(result.data.attempt_id)===Number(attempt)&&result.status!=='rejected'){TlmtiDB.deleteDraft(key);w.location.href='tests.php?result='+attempt;}});});
   show();if(deadline){setInterval(tick,1000);tick();}
 }
 var result=d.querySelector('[data-result-attempt]');if(result&&w.TlmtiDB){TlmtiDB.deleteDraft('student-test-'+uid+'-'+result.dataset.resultAttempt).catch(function(){});}
 var downloads=d.querySelector('[data-student-downloads]');
 if(downloads&&w.caches){
   var cacheName='tlmti-content-user-'+uid,boxes=Array.from(downloads.querySelectorAll('[data-download-url]'));
   function note(s){text('[data-download-message]',s);}
   downloads.querySelector('[data-download-select]').addEventListener('click',function(){boxes.forEach(function(b){b.checked=true;});});
   downloads.querySelector('[data-download-clear]').addEventListener('click',function(){if(w.confirm(msg('Remove downloaded content from this device? Pending activity is kept.','इस डिवाइस से डाउनलोड हटाएँ? लंबित गतिविधि सुरक्षित रहेगी।'))){caches.delete(cacheName).then(function(){note(msg('Downloads removed.','डाउनलोड हटा दिए गए।'));});}});
   downloads.querySelector('[data-download-start]').addEventListener('click',function(){
     if(!navigator.onLine){note(msg('Connect to the internet to download lessons.','पाठ डाउनलोड करने के लिए इंटरनेट से जुड़ें।'));return;}
     var chosen=boxes.filter(function(b){return b.checked;});if(!chosen.length){note(msg('Select at least one lesson.','कम से कम एक पाठ चुनें।'));return;}
     var button=this;button.disabled=true;var done=0;
     caches.open(cacheName).then(function(cache){return chosen.reduce(function(chain,b){return chain.then(function(){var urls=[b.dataset.downloadUrl];if(b.dataset.mediaUrl){urls.push(b.dataset.mediaUrl);}return urls.reduce(function(p,url){return p.then(function(){return fetch(url,{credentials:'same-origin',cache:'no-store'}).then(function(response){if(!response.ok||response.redirected||response.headers.get('X-TLMTI-User')!==uid){throw new Error(msg('Sign in again before downloading.','डाउनलोड से पहले फिर लॉग इन करें।'));}return cache.put(new URL(url,location.href).href,response);});});},Promise.resolve()).then(function(){done++;note(done+' / '+chosen.length+' '+msg('lessons downloaded.','पाठ डाउनलोड हुए।'));});});},Promise.resolve());}).catch(function(error){note(msg('Download stopped: ','डाउनलोड रुका: ')+error.message);}).finally(function(){button.disabled=false;});
   });
 }
}(window,document));
