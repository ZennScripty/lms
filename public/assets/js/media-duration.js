'use strict';
(function () {
  var hi = document.documentElement.lang === 'hi';
  document.querySelectorAll('[data-media-upload]').forEach(function (input) {
    var form = input.closest('form'), duration = form.querySelector('[data-media-duration]');
    var status = form.querySelector('[data-duration-status]'), cancel = function () {};
    if (!duration) return;
    input.addEventListener('change', function () {
      cancel(); duration.value = ''; duration.required = false;
      var file = input.files && input.files[0];
      if (!file || !(/^(audio|video)\//.test(file.type) || /\.(mp4|mp3|ogg|wav)$/i.test(file.name))) {
        if (status) status.textContent = hi ? 'इस फ़ाइल के लिए अवधि आवश्यक नहीं है।' : 'Duration is not required for this file.';
        return;
      }
      duration.required = true;
      if (status) status.textContent = hi ? 'अवधि पता की जा रही है…' : 'Detecting duration…';
      var media = document.createElement('video'), url = URL.createObjectURL(file), timer, finished = false;
      function finish(value) {
        if (finished) return; finished = true; clearTimeout(timer);
        media.removeAttribute('src'); media.load(); URL.revokeObjectURL(url);
        if (input.files[0] !== file) return;
        if (Number.isFinite(value) && value > 0 && value <= 86400) {
          duration.value = String(Math.ceil(value));
          if (status) status.textContent = hi ? 'अवधि अपने आप भर दी गई है। आवश्यकता हो तो बदलें।' : 'Duration detected automatically. You can adjust it if needed.';
        } else if (status) status.textContent = hi ? 'अवधि नहीं मिली। सेकंड में अवधि दर्ज करें।' : 'Could not detect duration. Enter the duration in seconds.';
      }
      cancel = function () { if (!finished) { finished = true; clearTimeout(timer); media.removeAttribute('src'); media.load(); URL.revokeObjectURL(url); } };
      media.preload = 'metadata'; media.onloadedmetadata = function () { finish(media.duration); };
      media.onerror = function () { finish(NaN); };
      timer = setTimeout(function () { finish(NaN); }, 12000); media.src = url;
    });
  });
})();
