const modulepreloadRel = "modulepreload";
const seen = {};
function toAssetPath(path) {
  return "/" + path;
}
export const _ = function preload(loader, deps, importerUrl) {
  let p = Promise.resolve();
  if (deps && deps.length > 0) {
    document.getElementsByTagName("link");
    const nonceMeta = document.querySelector("meta[property=csp-nonce]");
    const nonce = (nonceMeta == null ? void 0 : nonceMeta.nonce) || (nonceMeta == null ? void 0 : nonceMeta.getAttribute("nonce"));
    p = Promise.allSettled(
      deps.map((dep) => {
        dep = toAssetPath(dep);
        if (dep in seen) return;
        seen[dep] = true;
        const isCss = dep.endsWith(".css");
        const cssSelector = isCss ? '[rel="stylesheet"]' : "";
        if (document.querySelector(`link[href="${dep}"]${cssSelector}`)) return;
        const link = document.createElement("link");
        link.rel = isCss ? "stylesheet" : modulepreloadRel;
        if (!isCss) link.as = "script";
        link.crossOrigin = "";
        link.href = dep;
        if (nonce) link.setAttribute("nonce", nonce);
        document.head.appendChild(link);
        if (isCss) {
          return new Promise((resolve, reject) => {
            link.addEventListener("load", resolve);
            link.addEventListener("error", () => reject(new Error(`Unable to preload CSS for ${dep}`)));
          });
        }
      })
    );
  }
  function throwPreloadError(err) {
    const event = new Event("vite:preloadError", { cancelable: true });
    event.payload = err;
    window.dispatchEvent(event);
    if (!event.defaultPrevented) throw err;
  }
  return p.then((results) => {
    for (const result of results || []) {
      if (result.status === "rejected") throwPreloadError(result.reason);
    }
    return loader().catch(throwPreloadError);
  });
};
export const a = typeof globalThis !== "undefined"
  ? globalThis
  : typeof window !== "undefined"
    ? window
    : typeof global !== "undefined"
      ? global
      : typeof self !== "undefined"
        ? self
        : {};
export function g(mod) {
  return mod && mod.__esModule && Object.prototype.hasOwnProperty.call(mod, "default") ? mod.default : mod;
}
export function c(request) {
  throw new Error(`Could not dynamically require "${request}".`);
}
