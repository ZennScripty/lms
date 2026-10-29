'use strict';
(function(){
 var form=document.querySelector('[data-registration]');if(!form)return;
 var type=form.querySelector('[data-registration-type]'),centre=form.querySelector('[data-registration-centre]'),batch=form.querySelector('[data-registration-batch]'),batchWrap=form.querySelector('[data-registration-batch-wrap]'),subjectWrap=form.querySelector('[data-registration-subject-wrap]'),subjects=form.querySelector('[data-registration-subjects]'),status=form.querySelector('[data-registration-status]'),retry=form.querySelector('[data-registration-retry]'),submit=form.querySelector('[data-registration-submit]'),photo=form.querySelector('[data-registration-photo]'),preview=form.querySelector('[data-registration-preview]'),password=form.elements.namedItem('password'),confirmation=form.elements.namedItem('confirmation'),sequence=0,controller=null;
 function selectedSubjects(){return Array.from(subjects.querySelectorAll('input:checked')).map(function(i){return i.value;});}
 function clear(){batch.replaceChildren(new Option(form.dataset.select,''));subjects.replaceChildren();}
 async function load(keep){
  var savedBatch=keep?batch.value:'',savedSubjects=keep?selectedSubjects():[],mine=++sequence,isTeacher=type.value==='teacher';if(controller)controller.abort();controller=new AbortController();
  clear();batchWrap.hidden=isTeacher;subjectWrap.hidden=!isTeacher;batch.disabled=isTeacher||!centre.value;batch.required=!isTeacher;submit.disabled=true;retry.hidden=true;status.textContent='';subjectWrap.setAttribute('aria-busy','false');
  if(!centre.value){return;}batch.disabled=true;status.textContent=form.dataset.loading;subjectWrap.setAttribute('aria-busy','true');
  try{
   var response=await fetch('register.php?action=options&account_type='+encodeURIComponent(type.value)+'&centre_id='+encodeURIComponent(centre.value),{signal:controller.signal,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}}),data=await response.json();
   if(mine!==sequence)return;if(!response.ok||!data.ok)throw new Error(data.error||form.dataset.error);
   data.options.forEach(function(o){if(isTeacher){var label=document.createElement('label'),input=document.createElement('input');label.className='check';input.type='checkbox';input.name='subject_ids[]';input.value=String(o.id);input.checked=savedSubjects.includes(String(o.id));label.append(input,document.createTextNode(o.name));subjects.append(label);}else{batch.add(new Option(o.name,String(o.id),false,String(o.id)===savedBatch));}});
   batch.disabled=isTeacher||!data.options.length;submit.disabled=!data.options.length;status.textContent=data.options.length?'':form.dataset.empty;
  }catch(e){if(e.name!=='AbortError'&&mine===sequence){status.textContent=form.dataset.error;retry.hidden=false;}}
  finally{if(mine===sequence)subjectWrap.setAttribute('aria-busy','false');}
 }
 type.addEventListener('change',function(){load(false);});centre.addEventListener('change',function(){load(false);});retry.addEventListener('click',function(){load(false);});
 function passwords(){confirmation.setCustomValidity(confirmation.value&&password.value!==confirmation.value?form.dataset.passwordMismatch:'');}
 password.addEventListener('input',passwords);confirmation.addEventListener('input',passwords);
 photo.addEventListener('change',function(){photo.setCustomValidity('');preview.hidden=true;preview.removeAttribute('src');var file=photo.files[0];if(!file)return;if(file.size>2097152||!['image/jpeg','image/png','image/webp'].includes(file.type)){photo.setCustomValidity(form.dataset.uploadError);photo.reportValidity();return;}var reader=new FileReader();reader.onload=function(){if(photo.files[0]===file){preview.src=reader.result;preview.hidden=false;}};reader.readAsDataURL(file);});
 form.addEventListener('submit',function(e){passwords();if(type.value==='teacher'&&!selectedSubjects().length){e.preventDefault();status.textContent=form.dataset.subjectRequired;subjects.querySelector('input')?.focus();return;}if(!form.reportValidity()){e.preventDefault();return;}submit.disabled=true;form.setAttribute('aria-busy','true');});
 load(true);
})();
