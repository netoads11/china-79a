(function () {
  try { window.isSamsungBrowser = function () { return false; }; } catch (e) {}

  try {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistrations().then(function (regs) {
        (regs || []).forEach(function (reg) { try { reg.unregister(); } catch (e) {} });
      });
    }
    if (window.caches && caches.keys) {
      caches.keys().then(function (keys) {
        (keys || []).forEach(function (key) { try { caches.delete(key); } catch (e) {} });
      });
    }
  } catch (e) {}

  function textOf(el) {
    return ((el && (el.innerText || el.textContent)) || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function isDialog(el) {
    return !!(el && el.closest && el.closest('[role="dialog"], ion-modal, .modal, .popup, .van-popup, .el-dialog, .dialog, .register-popup'));
  }

  function removeInstallUi(root) {
    var selectors = [
      '#pwa-compulsory-modal',
      '#pwa-re-domain-modal',
      '#ios-pwa-guide',
      '.facebook-tip-wrap',
      '[id*="pwa-compulsory"]',
      '[id*="install-guide"]',
      '[class*="install-guide"]',
      '[class*="download-guide"]',
      '[class*="facebook-tip"]',
      '[class*="apk-task"]',
      'a[href$=".apk"]',
      'a[href*=".apk?"]',
      'a[href*="apps.apple.com"]',
      'a[href*="play.google.com"]'
    ];
    selectors.forEach(function (sel) {
      (root.querySelectorAll ? root.querySelectorAll(sel) : []).forEach(function (el) {
        try {
          if (el.remove) el.remove();
          else el.style.display = 'none';
        } catch (e) {}
      });
    });

    (root.querySelectorAll ? root.querySelectorAll('button, a, div, span') : []).forEach(function (el) {
      var txt = textOf(el);
      if (!txt) return;
      var installHit = txt.includes('apk') || txt.includes('baixar app') || txt.includes('download app') || txt.includes('instalar app') || txt.includes('install the app') || txt.includes('app下载') || txt.includes('下载');
      if (installHit && !txt.includes('registro') && !txt.includes('register') && !txt.includes('crie uma conta')) {
        var box = el.closest ? el.closest('button, a, .modal, .popup, .van-popup, ion-modal, div') : null;
        if (box && !isDialog(box.closest ? box.closest('[role="dialog"], ion-modal, .modal, .popup, .van-popup') : null)) {
          try { box.remove(); } catch (e) { try { box.style.display = 'none'; } catch (e2) {} }
        }
      }
    });
  }

  function markRegisterButtons(root) {
    (root.querySelectorAll ? root.querySelectorAll('button, a, div[role="button"], span') : []).forEach(function (el) {
      var txt = textOf(el);
      if (!txt) return;
      var isRegister = txt === 'registro' || txt === 'register' || txt === 'crie uma conta' || txt === 'crie uma conta de jogo' || txt === 'cadastre-se';
      if (!isRegister) return;
      var target = el.closest ? (el.closest('button, a, div[role="button"]') || el) : el;
      if (isDialog(target)) target.classList.add('popup-register-submit-wrapper');
      else target.classList.add('front-register-trigger-wrapper');
    });
  }

  function patchHistory() {
    ['pushState', 'replaceState'].forEach(function (method) {
      var original = history[method];
      if (typeof original !== 'function') return;
      history[method] = function () {
        var out = original.apply(this, arguments);
        setTimeout(run, 30);
        return out;
      };
    });
    window.addEventListener('popstate', function () { setTimeout(run, 30); });
  }

  function run() {
    try {
      removeInstallUi(document);
      markRegisterButtons(document);
    } catch (e) {}
  }

  var mo = new MutationObserver(function () { run(); });
  document.addEventListener('DOMContentLoaded', function () {
    run();
    patchHistory();
    try { mo.observe(document.documentElement || document.body, { childList: true, subtree: true }); } catch (e) {}
  });
  run();
})();
