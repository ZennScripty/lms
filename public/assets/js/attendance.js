'use strict';
(function(){
 var card=document.querySelector('[data-daily-attendance]');if(!card)return;
 var busy=false,refreshing=false,cameraActive=false,verified=false,stopCamera=function(){};
 function feedback(text,error){var area=card.querySelector('[data-attendance-feedback]');area.textContent=text||'';area.classList.toggle('is-error',!!error);area.setAttribute('role',error?'alert':'status');}
 function controls(disabled){card.querySelectorAll('button').forEach(function(button){
  if(disabled){if(!button.hasAttribute('data-was-disabled'))button.dataset.wasDisabled=button.disabled?'1':'0';button.disabled=true;}
  else if(button.hasAttribute('data-was-disabled')){button.disabled=button.dataset.wasDisabled==='1';delete button.dataset.wasDisabled;}
 });}
 function retry(show){card.querySelector('[data-attendance-refresh]').hidden=!show;}
 function install(result,message,focus){
  if(String(result.attendance.user_id)!==card.dataset.userId){verified=false;controls(true);feedback(card.dataset.stale,true);return false;}
  stopCamera();cameraActive=false;var holder=document.createElement('div');holder.innerHTML=result.html;var next=holder.querySelector('[data-daily-attendance]');
  if(!next)throw new Error(card.dataset.error);card.replaceWith(next);card=next;bind();verified=true;feedback(message,false);
  if(focus){var target=card.querySelector('[type=submit]:enabled')||card.querySelector('h2');target.focus();}return true;
 }
 async function fetchStatus(force){
  if(busy||refreshing||cameraActive)return;
  if(!navigator.onLine){verified=false;controls(true);feedback(card.dataset.offline,true);retry(true);card.querySelector('[data-attendance-refresh]').disabled=false;return;}
  refreshing=true;
  try{
   var response=await fetch(card.dataset.endpoint+'?action=status',{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}),result=await response.json();
   if(!response.ok||!result.ok)throw new Error(result.error||card.dataset.error);
   if(String(result.attendance.user_id)!==card.dataset.userId){verified=false;controls(true);feedback(card.dataset.stale,true);retry(false);return;}
   if(force||!verified||result.attendance.revision!==card.dataset.revision){install(result,null,false);}
   else{var duration=card.querySelector('[data-attendance-duration]');if(duration&&result.attendance.check_in_time)duration.textContent=result.attendance.duration;}
   verified=true;controls(false);retry(false);
  }catch(e){verified=false;controls(true);feedback(e.message||card.dataset.error,true);retry(true);card.querySelector('[data-attendance-refresh]').disabled=false;}
  finally{refreshing=false;}
 }
 function bind(){
  var camera=card.querySelector('[data-attendance-camera]'),blob=null,stream=null,cameraGeneration=0;
  function stop(){cameraGeneration++;if(stream){stream.getTracks().forEach(function(t){t.stop();});stream=null;}}
  stopCamera=stop;
  if(camera){
   var video=camera.querySelector('video'),canvas=camera.querySelector('canvas'),preview=camera.querySelector('img'),start=camera.querySelector('[data-camera-start]'),capture=camera.querySelector('[data-camera-capture]'),submit=camera.querySelector('[type=submit]'),status=camera.querySelector('[data-camera-status]');
   start.addEventListener('click',async function(){
    if(busy||!verified)return;stop();var generation=cameraGeneration;cameraActive=true;blob=null;preview.hidden=true;submit.disabled=true;capture.disabled=true;start.disabled=true;status.textContent=camera.dataset.requesting;
    try{
     if(!navigator.mediaDevices||!navigator.mediaDevices.getUserMedia)throw new Error('camera');
     var opened=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:960},height:{ideal:720}},audio:false});
     if(generation!==cameraGeneration||!card.contains(camera)){opened.getTracks().forEach(function(t){t.stop();});return;}
     stream=opened;video.srcObject=stream;video.hidden=false;await video.play();capture.disabled=false;status.textContent=camera.dataset.ready;
    }catch(e){stop();video.hidden=true;cameraActive=false;status.textContent=camera.dataset.error;}
    finally{start.disabled=false;}
   });
   capture.addEventListener('click',function(){
    if(!stream||video.readyState<2||!video.videoWidth)return;
    canvas.width=Math.min(1280,video.videoWidth);canvas.height=Math.round(video.videoHeight*canvas.width/video.videoWidth);canvas.getContext('2d').drawImage(video,0,0,canvas.width,canvas.height);
    canvas.toBlob(function(image){if(!image){status.textContent=camera.dataset.error;return;}blob=image;preview.src=canvas.toDataURL('image/jpeg',.85);preview.hidden=false;video.hidden=true;submit.disabled=false;capture.disabled=true;status.textContent=camera.dataset.captured;stop();},'image/jpeg',.85);
   });
  }
  card.querySelector('[data-attendance-refresh]').addEventListener('click',function(){cameraActive=false;stop();fetchStatus(true);});
  card.querySelectorAll('[data-attendance-action]').forEach(function(form){form.addEventListener('submit',async function(e){
   e.preventDefault();if(busy||refreshing)return;if(!verified||!navigator.onLine){feedback(card.dataset.offline,true);fetchStatus(true);return;}if(form===camera&&!blob)return;
   busy=true;controls(true);card.setAttribute('aria-busy','true');feedback(card.dataset.loading,false);var data=new FormData(form);if(form===camera)data.set('selfie',blob,'selfie.jpg');
   try{
    var response=await fetch(form.action,{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}}),result=await response.json();
    if(!response.ok||!result.ok)throw new Error(result.error||card.dataset.error);
    install(result,result.message,true);
   }catch(e){feedback(e.message||card.dataset.error,true);controls(false);retry(true);}
   finally{busy=false;card.removeAttribute('aria-busy');}
  });});
 }
 bind();controls(true);fetchStatus(true);
 window.addEventListener('online',function(){fetchStatus(true);});window.addEventListener('offline',function(){verified=false;controls(true);feedback(card.dataset.offline,true);retry(true);card.querySelector('[data-attendance-refresh]').disabled=false;});
 window.addEventListener('focus',function(){fetchStatus(false);});
 document.addEventListener('visibilitychange',function(){if(!document.hidden)fetchStatus(false);});
 window.addEventListener('pagehide',function(){stopCamera();cameraActive=false;});
 window.addEventListener('pageshow',function(e){if(e.persisted){verified=false;fetchStatus(true);}});
 setInterval(function(){if(!document.hidden)fetchStatus(false);},60000);
})();
