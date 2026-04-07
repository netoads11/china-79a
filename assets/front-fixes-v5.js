(function () {
  'use strict';

  var STRIP_QUERY_KEYS = ['fbPixelId', 'fbclid', 'meta_pixel', 'metaPixel', 'facebook_pixel', 'facebookads'];

  function sanitizeQuery() {
    try {
      var url = new URL(window.location.href);
      var changed = false;
      STRIP_QUERY_KEYS.forEach(function (key) {
        if (url.searchParams.has(key)) {
          url.searchParams.delete(key);
          changed = true;
        }
      });
      if (changed) {
        var qs = url.searchParams.toString();
        history.replaceState({}, document.title, url.pathname + (qs ? '?' + qs : '') + url.hash);
      }
    } catch (e) {}
  }

  function disableMetaGlobals() {
    function noop() { return false; }
    noop.callMethod = noop;
    noop.push = noop;
    noop.loaded = false;
    noop.version = 'disabled';
    noop.queue = [];

    window.fbPixelId = '';
    window.fbq = noop;
    window._fbq = noop;
  }

  function normalizeAppConfig() {
    try {
      var cfg = window.__APP_CONFIG__ || {};
      var domainInfo = cfg.domainInfo && cfg.domainInfo.result && cfg.domainInfo.result.data && cfg.domainInfo.result.data.json && cfg.domainInfo.result.data.json.info;
      var channelConfig = cfg.channelInfo && cfg.channelInfo.result && cfg.channelInfo.result.data && cfg.channelInfo.result.data.json && cfg.channelInfo.result.data.json.config;
      var tenantInfo = cfg.tenantInfo && cfg.tenantInfo.result && cfg.tenantInfo.result.data && cfg.tenantInfo.result.data.json;

      var frontConfig = {
        android: {
          downloadBtn: false,
          guideInstall: false,
          popupType: 'NORMAL',
          showGiftAmountType: 0,
          showGiftAmount: 0,
          showGiftMaxAmount: 0,
          popupTime: 'HOME',
          popupInterval: 0,
          installType: 'PWA',
          installUrl: ''
        },
        ios: {
          downloadBtn: false,
          guideInstall: false,
          popupType: 'NORMAL',
          showGiftAmountType: 0,
          showGiftAmount: 0,
          showGiftMaxAmount: 0,
          popupTime: 'HOME',
          popupInterval: 0,
          installType: 'DESK',
          installUrl: '',
          iosPackageId: 0,
          iosAddressType: 'normal'
        }
      };

      if (domainInfo && domainInfo.apkDownloadUrlConfig) {
        domainInfo.apkDownloadUrlConfig.list = [];
        domainInfo.apkDownloadUrlConfig.isOpen = false;
        domainInfo.apkDownloadUrlConfig.isOpenDownloadPageJumpForIos = false;
      }

      if (channelConfig) {
        channelConfig.pointType = 'Normal';
        channelConfig.pointParams = '';
        channelConfig.frontConfig = JSON.stringify(frontConfig);
      }

      if (tenantInfo) {
        tenantInfo.homeAppDownloadGuideSwitch = false;
      }
    } catch (e) {}
  }

  function removeMetaNodes(root) {
    (root || document).querySelectorAll('script[src*="connect.facebook.net"], img[src*="facebook.com/tr"], noscript img[src*="facebook.com/tr"]').forEach(function (node) {
      node.remove();
    });
  }

  function hideApkUi(root) {
    var scope = root || document;
    [
      '.pwa-header-wrap',
      '.download-pwa',
      '.btn-download',
      'ion-modal#modal-download-loading',
      'ion-modal[id*="download-loading"]',
      'img[src*="/gif/download-btn"]',
      'img[src*="download-btn.gif"]'
    ].forEach(function (selector) {
      scope.querySelectorAll(selector).forEach(function (node) {
        var target = node.closest('ion-modal, button, a, div, section, ion-card') || node;
        target.style.setProperty('display', 'none', 'important');
      });
    });
  }

  function getText(node) {
    return String((node && node.textContent) || '').replace(/\s+/g, ' ').trim();
  }

  function separateRegisterClasses(root) {
    var scope = root || document;
    var registerRe = /^(registro|registrar|register|cadastro|sign\s*up)$/i;

    scope.querySelectorAll('.header .register-btn-warpper, ion-menu .primaryBtn .register-btn-warpper').forEach(function (wrapper) {
      if (wrapper.closest('ion-modal#login-modal')) return;
      var button = wrapper.querySelector('.register, button, ion-button');
      var text = getText(button || wrapper);
      if (!registerRe.test(text)) return;
      wrapper.classList.remove('register-btn-warpper');
      wrapper.classList.add('front-register-trigger-wrapper');
      if (button) button.classList.add('front-register-trigger-button');
    });

    scope.querySelectorAll('ion-modal#login-modal .register-btn-warpper').forEach(function (wrapper) {
      var submit = wrapper.querySelector('button[type="submit"], ion-button[type="submit"], .button-native[type="submit"]') ||
        Array.from(wrapper.querySelectorAll('button, ion-button')).find(function (btn) {
          return registerRe.test(getText(btn));
        });
      if (!submit) return;
      wrapper.classList.remove('register-btn-warpper');
      wrapper.classList.add('popup-register-submit-wrapper');
      submit.classList.add('popup-register-submit-button');
    });
  }

  function allowDevTools() {
    try {
      var url = new URL(window.location.href);
      if (url.searchParams.get('check') !== '0') {
        url.searchParams.set('check', '0');
        var qs = url.searchParams.toString();
        history.replaceState({}, document.title, url.pathname + (qs ? '?' + qs : '') + url.hash);
      }
    } catch (e) {}

    document.addEventListener('keydown', function (e) {
      var key = String(e.key || '').toUpperCase();
      if (key === 'F12' || ((e.ctrlKey || e.metaKey) && e.shiftKey && ['I', 'J', 'C'].includes(key))) {
        e.stopImmediatePropagation();
      }
    }, true);

    document.addEventListener('contextmenu', function (e) {
      e.stopImmediatePropagation();
    }, true);
  }



  function cleanupServiceWorkers() {
    try {
      if (!('serviceWorker' in navigator)) return;
      if (typeof window.isSamsungBrowser === 'function' && window.isSamsungBrowser()) return;
      navigator.serviceWorker.getRegistrations().then(function (regs) {
        regs.forEach(function (reg) {
          reg.unregister();
        });
      }).catch(function () {});
      if (window.caches && typeof window.caches.keys === 'function') {
        window.caches.keys().then(function (keys) {
          keys.forEach(function (key) {
            window.caches.delete(key);
          });
        }).catch(function () {});
      }
    } catch (e) {}
  }

  function bootstrap(root) {
    disableMetaGlobals();
    normalizeAppConfig();
    removeMetaNodes(root || document);
    hideApkUi(root || document);
    separateRegisterClasses(root || document);
    allowDevTools();
  }

  sanitizeQuery();
  cleanupServiceWorkers();
  bootstrap(document);

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      bootstrap(document);
    }, { once: true });
  }

  var observer = new MutationObserver(function (mutations) {
    mutations.forEach(function (mutation) {
      mutation.addedNodes.forEach(function (node) {
        if (node && node.nodeType === 1) {
          bootstrap(node);
        }
      });
    });
  });

  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
