/* Text-to-speech using the browser SpeechSynthesis API. Voices depend on the device/OS. */
(function (w) {
  'use strict';
  var synth = w.speechSynthesis;

  function voices() {
    return new Promise(function (resolve) {
      var v = synth.getVoices();
      if (v.length) { resolve(v); return; }
      var done = false;
      function fin() { if (!done) { done = true; resolve(synth.getVoices()); } }
      synth.addEventListener('voiceschanged', fin, { once: true });
      setTimeout(fin, 1200);
    });
  }
  function pick(list, locale) {
    var l = locale.toLowerCase(), base = l.split('-')[0];
    return list.filter(function (v) { return v.lang.toLowerCase().replace('_', '-') === l; })[0]
      || list.filter(function (v) { return v.lang.toLowerCase().replace('_', '-').split('-')[0] === base; })[0] || null;
  }
  function chunks(text) {
    var parts = text.replace(/\s+/g, ' ').match(/[^.!?।]+[.!?।]*/g) || [text], out = [], cur = '';
    parts.forEach(function (p) { if ((cur + p).length > 180) { if (cur) { out.push(cur); } cur = p; } else { cur += p; } });
    if (cur) { out.push(cur); }
    return out;
  }

  function speak(text, locale, notice) {
    if (!synth || !w.SpeechSynthesisUtterance) { showNotice(notice, document.documentElement.lang === 'hi' ? 'इस ब्राउज़र में आवाज़ की सुविधा उपलब्ध नहीं है।' : 'Text-to-speech is not supported in this browser.'); return Promise.resolve(false); }
    synth.cancel();
    return voices().then(function (list) {
      var voice = pick(list, locale);
      showNotice(notice, voice ? '' : (document.documentElement.lang === 'hi' ? 'इस भाषा की आवाज़ उपलब्ध नहीं है। डिफ़ॉल्ट आवाज़ का उपयोग हो रहा है।' : 'No voice installed for this language; using the default voice.'));
      chunks(text).forEach(function (c) {
        var u = new SpeechSynthesisUtterance(c);
        u.lang = voice ? voice.lang : locale; if (voice) { u.voice = voice; }
        synth.speak(u);
      });
      return true;
    });
  }
  function showNotice(el, msg) { if (!el) { return; } el.textContent = msg; el.hidden = !msg; }

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-tts-target]');
    if (!btn) { return; }
    var target = document.getElementById(btn.getAttribute('data-tts-target'));
    var notice = btn.parentNode.querySelector('[data-tts-notice]');
    if (synth && (synth.speaking || synth.pending)) { synth.cancel(); btn.setAttribute('aria-pressed', 'false'); return; }
    if (target) { btn.setAttribute('aria-pressed', 'true'); speak(target.textContent, btn.getAttribute('data-tts-lang') || 'en', notice); }
  });
  w.addEventListener('beforeunload', function () { if (synth) { synth.cancel(); } });
  w.TlmtiTTS = { speak: speak };
}(window));
