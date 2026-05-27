{{-- Capyrel Flash Toast Component
     Zero-dependency. Handles:
       - session('success'), session('error'), session('warning'), session('info')
       - window.CyToast(message, type, duration) — programmatic API
       - window event 'capyrel-toast' — dispatched by AJAX form handlers
     Dark mode: data-theme=dark | data-bs-theme=dark | .dark class on <html>
--}}
<div id="cy-toasts" aria-live="polite" aria-atomic="true"></div>
<style>
#cy-toasts{
  position:fixed;top:1.25rem;right:1.25rem;
  z-index:99999;display:flex;flex-direction:column;gap:.45rem;
  pointer-events:none;width:340px;max-width:calc(100vw - 2rem);
}
.cy-t{
  pointer-events:auto;position:relative;overflow:hidden;
  display:flex;align-items:flex-start;gap:.7rem;
  padding:.875rem 1rem .875rem .875rem;
  border-radius:14px;font-size:.875rem;line-height:1.4;font-weight:500;
  box-shadow:0 10px 40px rgba(0,0,0,.14),0 2px 8px rgba(0,0,0,.07);
  animation:cy-in .32s cubic-bezier(.16,1,.3,1) both;
}
.cy-t.cy-out{animation:cy-out .25s ease-in forwards}
@keyframes cy-in  {from{opacity:0;transform:translateX(110%) scale(.9)}to{opacity:1;transform:none}}
@keyframes cy-out {to  {opacity:0;transform:translateX(110%) scale(.9);max-height:0;margin:0;padding:0}}
.cy-t-success{background:#f0fdf4;border:1px solid #bbf7d0;color:#14532d}
.cy-t-error  {background:#fef2f2;border:1px solid #fecaca;color:#7f1d1d}
.cy-t-warning{background:#fffbeb;border:1px solid #fde68a;color:#78350f}
.cy-t-info   {background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a5f}
[data-theme=dark]    .cy-t-success,
[data-bs-theme=dark] .cy-t-success,
.dark                .cy-t-success{background:rgba(22,101,52,.28);border-color:rgba(74,222,128,.28);color:#86efac}
[data-theme=dark]    .cy-t-error,
[data-bs-theme=dark] .cy-t-error,
.dark                .cy-t-error  {background:rgba(127,29,29,.28);border-color:rgba(252,165,165,.28);color:#fca5a5}
[data-theme=dark]    .cy-t-warning,
[data-bs-theme=dark] .cy-t-warning,
.dark                .cy-t-warning{background:rgba(120,53,15,.28);border-color:rgba(253,211,77,.28);color:#fcd34d}
[data-theme=dark]    .cy-t-info,
[data-bs-theme=dark] .cy-t-info,
.dark                .cy-t-info   {background:rgba(30,58,138,.28);border-color:rgba(147,197,253,.28);color:#93c5fd}
.cy-t-icon{flex-shrink:0;width:1rem;height:1rem;margin-top:.15rem}
.cy-t-body{flex:1;min-width:0;word-break:break-word}
.cy-t-close{flex-shrink:0;cursor:pointer;opacity:.5;line-height:1;margin-top:-.05rem;background:none;border:none;padding:0;font-size:.9rem;color:inherit}
.cy-t-close:hover{opacity:1}
.cy-t-bar{position:absolute;bottom:0;left:0;height:3px;border-radius:0 0 14px 14px;animation:cy-bar var(--cy-dur,4000ms) linear forwards}
.cy-t-success .cy-t-bar{background:#22c55e}
.cy-t-error   .cy-t-bar{background:#ef4444}
.cy-t-warning .cy-t-bar{background:#f59e0b}
.cy-t-info    .cy-t-bar{background:#3b82f6}
@keyframes cy-bar{from{width:100%}to{width:0}}
</style>
<script>
(function(){
  var WRAP = document.getElementById('cy-toasts');
  var SVG = {
    success: '<svg class="cy-t-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>',
    error:   '<svg class="cy-t-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z"/></svg>',
    warning: '<svg class="cy-t-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 5a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 5zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>',
    info:    '<svg class="cy-t-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z" clip-rule="evenodd"/></svg>',
  };

  function dismiss(el, timer) {
    clearTimeout(timer);
    el.classList.add('cy-out');
    el.addEventListener('animationend', function(){ el.remove(); }, {once: true});
  }

  window.CyToast = function(msg, type, duration) {
    type = type || 'success';
    duration = duration || 4000;
    var el = document.createElement('div');
    el.className = 'cy-t cy-t-' + type;
    el.style.setProperty('--cy-dur', duration + 'ms');
    el.innerHTML = (SVG[type] || SVG.info) +
      '<span class="cy-t-body">' + msg + '</span>' +
      '<button class="cy-t-close" aria-label="Close">&#x2715;</button>' +
      '<div class="cy-t-bar"></div>';
    WRAP.appendChild(el);
    var timer = setTimeout(function(){ dismiss(el, timer); }, duration);
    el.querySelector('.cy-t-close').addEventListener('click', function(){ dismiss(el, timer); });
  };

  window.addEventListener('capyrel-toast', function(e) {
    var d = e.detail;
    if (!d) return;
    if (typeof d === 'string') { window.CyToast(d, 'success'); return; }
    window.CyToast(d.message || 'Done.', d.type || 'success', d.duration);
  });

  @if(session('success'))
  window.CyToast(@json(session('success')), 'success');
  @endif
  @if(session('error'))
  window.CyToast(@json(session('error')), 'error');
  @endif
  @if(session('warning'))
  window.CyToast(@json(session('warning')), 'warning');
  @endif
  @if(session('info'))
  window.CyToast(@json(session('info')), 'info');
  @endif
})();
</script>
