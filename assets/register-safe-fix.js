(function () {
  function textOf(el) {
    return ((el && (el.innerText || el.textContent)) || '').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  function isPopup(el) {
    return !!(el && el.closest && el.closest('ion-modal, [role="dialog"], .modal, .popup, .dialog, .van-popup, .van-dialog, .el-dialog, .q-dialog, [class*="modal"], [class*="popup"], [class*="dialog"]'));
  }

  function tagRegisterButtons(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var nodes = scope.querySelectorAll('button, a, ion-button, div, span');
    nodes.forEach(function (el) {
      var text = textOf(el);
      if (!text) return;
      var isRegister = (
        text === 'registro' ||
        text === 'registrar' ||
        text === 'cadastro' ||
        text.includes('crie uma conta') ||
        text.includes('create account') ||
        text.includes('sign up') ||
        text.includes('register')
      );
      if (!isRegister) return;
      el.classList.add('is-register-button');
      var wrapper = el.closest('button, a, ion-button, [class*="btn"], [class*="button"], [class*="register"]') || el;
      wrapper.classList.add('is-register-button-wrapper');
      if (isPopup(el)) {
        el.classList.add('btn-register-popup', 'register-context-popup');
        wrapper.classList.add('popup-register-submit-wrapper', 'register-context-popup');
      } else {
        el.classList.add('btn-register-front', 'register-context-front');
        wrapper.classList.add('front-register-trigger-wrapper', 'register-context-front');
      }
    });
  }

  function hideApkUi(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var nodes = scope.querySelectorAll('button, a, div, span, p, strong');
    nodes.forEach(function (el) {
      var text = textOf(el);
      if (!text) return;
      var isApk = (
        text === 'apk' ||
        text.includes('baixar app') ||
        text.includes('download app') ||
        text.includes('instalar app') ||
        text.includes('guia de instalação') ||
        text.includes('guide install') ||
        text.includes('instalar apk')
      );
      if (!isApk) return;
      var box = el.closest('ion-modal, [role="dialog"], .modal, .popup, .dialog, .van-popup, .van-dialog, [class*="banner"], [class*="download"], [class*="install"], [class*="apk"]') || el;
      box.classList.add('apk-install-hidden');
    });
  }

  function patchConfig() {
    try {
      var cfg = window.__APP_CONFIG__;
      var channel = cfg && cfg.channelInfo && cfg.channelInfo.result && cfg.channelInfo.result.data && cfg.channelInfo.result.data.json && cfg.channelInfo.result.data.json.config;
      if (!channel) return;
      channel.pointType = 'None';
      channel.pointParams = '';
      var front = {};
      try { front = JSON.parse(channel.frontConfig || '{}'); } catch (e) { front = {}; }
      front.android = Object.assign({}, front.android || {}, { downloadBtn: false, guideInstall: false, popupType: 'NONE' });
      front.ios = Object.assign({}, front.ios || {}, { downloadBtn: false, guideInstall: false, popupType: 'NONE' });
      channel.frontConfig = JSON.stringify(front);
    } catch (e) {}
  }

  function run(root) {
    patchConfig();
    tagRegisterButtons(root);
    hideApkUi(root);
  }

  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    e.stopImmediatePropagation();
    return false;
  }, true);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { run(document); }, { once: true });
  } else {
    run(document);
  }

  new MutationObserver(function (mutations) {
    mutations.forEach(function (m) {
      m.addedNodes.forEach(function (node) {
        if (node && node.nodeType === 1) run(node);
      });
    });
  }).observe(document.documentElement, { childList: true, subtree: true });
})();
