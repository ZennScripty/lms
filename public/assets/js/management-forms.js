'use strict';
(function(){
 var hi=document.documentElement.lang==='hi';
 function success(message){
  var old=document.querySelector('[data-success-dialog]');if(old)old.remove();var previous=document.activeElement,dialog=document.createElement('dialog');dialog.className='success-modal';dialog.dataset.successDialog='';dialog.setAttribute('aria-labelledby','success-modal-title');
  var title=document.createElement('h2');title.id='success-modal-title';title.textContent=hi?'सफल!':'Success';var text=document.createElement('p');text.textContent=message;var button=document.createElement('button');button.type='button';button.className='btn btn-primary';button.textContent=hi?'ठीक है':'OK';button.addEventListener('click',function(){dialog.close();});dialog.append(title,text,button);document.body.append(dialog);dialog.addEventListener('close',function(){dialog.remove();if(previous&&previous.isConnected)previous.focus();});if(typeof dialog.showModal==='function'){dialog.showModal();button.focus();}else{dialog.setAttribute('open','');}
 }
 var messages=Array.from(document.querySelectorAll('[data-success-message]')).map(function(el){return el.textContent.trim();});if(messages.length)success(messages.join('\n'));
 document.addEventListener('tlmti:success',function(e){if(e.detail&&e.detail.message)success(String(e.detail.message));});
 document.querySelectorAll('[data-management-form]').forEach(function(form){
  var kind=form.dataset.managementForm,subject=form.querySelector('[name=subject_id]'),module=form.querySelector('[name=module_id]'),role=form.querySelector('[name=role_id]'),centre=form.querySelector('[name=centre_id]'),batch=form.querySelector('[name=batch_id]'),editing=form.dataset.editing==='1',last={};
  function filterModules(){if(!module||!subject)return;Array.from(module.options).forEach(function(o){var valid=!o.value||o.dataset.subject===subject.value;o.hidden=!valid;o.disabled=!valid;if(o.selected&&!valid)module.value='';});}
  function filterQuestions(){if(kind!=='tests'||!subject)return;form.querySelectorAll('[data-question-subject]').forEach(function(label){var valid=label.dataset.questionSubject===subject.value&&(!module||!module.value||!label.dataset.questionModule||label.dataset.questionModule===module.value);label.hidden=!valid;var input=label.querySelector('input');input.disabled=!valid;if(!valid)input.checked=false;});}
  function titles(force){
   if(!subject||!['tests','questions'].includes(kind))return;var s=subject.selectedOptions[0],m=module&&module.selectedOptions[0];if(!s||!s.value)return;
   [['en',kind==='tests'?'title':'question_en'],['hi',kind==='tests'?'title_hi':'question_hi']].forEach(function(pair){var lang=pair[0],field=form.querySelector('[name='+pair[1]+']');if(!field)return;var parts=[s.textContent.trim()];if(m&&m.value)parts.push(m.dataset[lang==='hi'?'titleHi':'titleEn']||m.textContent.trim());var value=parts.join(' — ');if(force||!editing&&(!field.value||field.value===last[pair[1]])){field.value=value;last[pair[1]]=value;}});
  }
  if(subject){subject.addEventListener('change',function(){filterModules();filterQuestions();titles(false);});}if(module)module.addEventListener('change',function(){filterQuestions();titles(false);});
  if(['tests','questions'].includes(kind)&&subject){var reset=document.createElement('button');reset.type='button';reset.className='btn';reset.textContent=hi?'विषय/मॉड्यूल से शीर्षक भरें':'Use subject/module title';reset.addEventListener('click',function(){titles(true);});var actions=form.querySelector('.form-actions');if(actions)actions.prepend(reset);}
  function studentBatch(){if(!role||!batch)return;var selected=role.selectedOptions[0],student=selected&&selected.dataset.role==='student';batch.closest('label').hidden=!student;batch.disabled=!student;batch.required=!!student;Array.from(batch.options).forEach(function(o){var valid=!o.value||!centre||o.dataset.centre===centre.value;o.hidden=!valid;o.disabled=!valid;if(o.selected&&!valid)batch.value='';});}
  if(role)role.addEventListener('change',studentBatch);if(centre)centre.addEventListener('change',studentBatch);
  filterModules();filterQuestions();titles(false);studentBatch();
 });
})();
