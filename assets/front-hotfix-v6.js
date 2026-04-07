(function () {
  try {
    const cleanUrl = new URL(window.location.href);
    ["fbclid", "fbPixelId", "ttclid", "ttPixelId", "kwaiId", "mgSkyPixelId", "afId"].forEach((k) => cleanUrl.searchParams.delete(k));
    if (cleanUrl.href !== window.location.href) {
      history.replaceState(null, "", cleanUrl.toString());
    }
  } catch (e) {}

  // Libera DevTools / contexto caso exista algum bloqueio externo injetado
  try {
    window.oncontextmenu = null;
    window.onkeydown = null;
    document.oncontextmenu = null;
    document.onkeydown = null;
    document.onselectstart = null;
    document.ondragstart = null;
  } catch (e) {}

  function tagRegisterButtons() {
    const all = Array.from(document.querySelectorAll('button, a, div, ion-button, span'));
    for (const el of all) {
      const txt = (el.textContent || '').trim().toLowerCase();
      if (!txt) continue;
      if ((txt === 'registro' || txt === 'sign up') && el.classList.contains('register-text')) {
        el.classList.add('front-register-trigger-wrapper');
      }
      if ((txt === 'registro' || txt === 'sign up') && el.closest('.submit.register-btn-warpper')) {
        el.closest('.submit.register-btn-warpper').classList.add('popup-register-submit-wrapper');
      }
      if (txt.includes('crie uma conta de jogo') || txt.includes('create account')) {
        const wrap = el.closest('.content, .form-wrapper, ion-content, .submit.register-btn-warpper');
        if (wrap) wrap.classList.add('popup-register-context');
      }
    }
  }

  function hideApkCta() {
    const selectors = [
      '.download-entry', '.download-btn', '.install-btn', '.install-guide', '.apk-download', '.pwa-download',
      '[data-entry="download"]', '.download', 'a[href*=".apk"]'
    ];
    selectors.forEach((sel) => {
      document.querySelectorAll(sel).forEach((el) => {
        if (el.closest('.front-register-trigger-wrapper') || el.closest('.popup-register-submit-wrapper')) return;
        const text = (el.textContent || '').toLowerCase();
        if (sel === '.download' && !text.includes('download') && !text.includes('baixar')) return;
        el.style.setProperty('display', 'none', 'important');
      });
    });
  }

  const obs = new MutationObserver(function () {
    tagRegisterButtons();
    hideApkCta();
  });
  obs.observe(document.documentElement, { childList: true, subtree: true });
  tagRegisterButtons();
  hideApkCta();
})();
