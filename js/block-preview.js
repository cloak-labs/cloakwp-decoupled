/**
 * CloakWP block preview — editor-side bridge to the frontend iframe.
 *
 * In the Gutenberg editor, each CloakWP ACF block shows a live preview of the
 * real frontend UI inside an iframe (see block-preview.php). When you edit a
 * field, ACF normally re-fetches the whole preview HTML via AJAX (~2–3s) and
 * replaces the markup — which would remount the iframe and feel slow.
 *
 * This script prevents that and makes previews feel instant:
 *
 * 1. Keep the iframe alive
 *    When ACF tries to swap in new preview HTML, we return the previous HTML
 *    instead (once the iframe is live). The iframe stays mounted; only the
 *    data inside it changes.
 *
 * 2. Push data with postMessage (not inline <script>)
 *    ACF's block render path often strips or skips inline scripts, so we don't
 *    rely on them. The decoupled frontend iframe announces itself with
 *    { type: "cloakwp-preview-ready", previewKey }. We remember that window and
 *    postMessage updated block JSON to it whenever fields change.
 *
 * 3. Optimistic updates
 *    On typing / changing fields, we patch the last-known block data locally
 *    and postMessage immediately — no waiting for ACF's AJAX. Nesting is
 *    resolved by walking the ACF parent chain into a single JSON path
 *    (groups, clones, repeater row indices, flexible layouts) so any depth
 *    works without per-shape special cases. Media fields that only have
 *    attachment IDs client-side are left alone until AJAX returns.
 *    Repeater row *removals* splice the pending array immediately; *additions*
 *    wait for fetch-block (new rows are empty until the user fills them in).
 *
 * 4. Ignore stale AJAX responses
 *    ACF still runs fetch-block in the background. Each request is stamped
 *    with an "epoch" at send time. If you typed again before that request
 *    finished, we drop its result so an older server payload can't overwrite
 *    a newer optimistic edit (e.g. add a letter, delete it, then see the
 *    letter flash back).
 *
 * Pending payloads are kept after send so iframe remounts / double-mounts
 * can re-handshake with another "ready" and still get the latest data.
 *
 * Important: once an iframe shell is cached for a previewKey, we always return
 * that same HTML string from `blocks/preview/render` — including before the
 * ready handshake completes. ACF setHtml no-ops on identical strings, which is
 * what keeps the iframe alive across fetch-block. Returning a newer/stub HTML
 * while the iframe is still loading tears it down and leaves new blocks blank
 * until a full editor reload.
 */
(function () {
  "use strict";

  /** @type {Map<string, string>} previewKey -> last HTML accepted by setHtml */
  const htmlCache = new Map();

  /** @type {Set<string>} */
  const readyKeys = new Set();

  /**
   * @typedef {{ blockData: unknown, isPageDark: boolean }} PendingPayload
   * @type {Map<string, PendingPayload>}
   */
  const pendingByKey = new Map();

  /** @type {Map<string, Window>} */
  const readySourcesByKey = new Map();

  /** @type {Map<string, number>} previewKey -> optimistic epoch (monotonic) */
  const lastOptimisticEpochByKey = new Map();

  /**
   * Global optimistic epoch. Bumped on every accepted optimistic edit.
   * Each fetch-block captures the epoch at send; on completion we compare
   * against the latest optimistic epoch for that preview key.
   * (FIFO start-times break when responses finish out of order.)
   */
  let optimisticEpoch = 0;

  /**
   * Meta for completed fetch-block XHRs, pushed in onreadystatechange(4)
   * immediately before ACF's handler so blocks/preview/render can shift the
   * matching request (not a start-time FIFO).
   * @type {{ epoch: number }[]}
   */
  const fetchBlockCompletedMeta = [];

  let initialized = false;

  if (typeof XMLHttpRequest !== "undefined") {
    const xhrOpen = XMLHttpRequest.prototype.open;
    const xhrSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url) {
      this.__cloakwpUrl = typeof url === "string" ? url : String(url);
      return xhrOpen.apply(this, arguments);
    };
    XMLHttpRequest.prototype.send = function (body) {
      const url = this.__cloakwpUrl || "";
      const bodyStr = typeof body === "string" ? body : "";
      const isFetchBlock =
        url.indexOf("fetch-block") !== -1 ||
        bodyStr.indexOf("fetch-block") !== -1 ||
        bodyStr.indexOf("acf%2Fajax%2Ffetch-block") !== -1;
      if (isFetchBlock) {
        // Capture epoch/time on THIS xhr; push onto completed queue when RS=4
        // (before ACF processes the body) so out-of-order completions stay matched.
        const meta = {
          epoch: optimisticEpoch,
        };
        const xhr = this;
        const prevRsc = xhr.onreadystatechange;
        xhr.onreadystatechange = function () {
          if (xhr.readyState === 4) {
            fetchBlockCompletedMeta.push(meta);
          }
          if (typeof prevRsc === "function") {
            return prevRsc.apply(this, arguments);
          }
        };
      }
      return xhrSend.apply(this, arguments);
    };
  }

  /**
   * @returns {Document[]}
   */
  function collectEditorDocuments() {
    /** @type {Document[]} */
    const docs = [];
    /** @type {Set<Document>} */
    const seen = new Set();

    /**
     * @param {Document | null | undefined} doc
     */
    function add(doc) {
      if (!doc || seen.has(doc)) return;
      seen.add(doc);
      docs.push(doc);
      const iframes = doc.querySelectorAll("iframe");
      for (let i = 0; i < iframes.length; i++) {
        try {
          add(iframes[i].contentDocument);
        } catch {
          /* cross-origin */
        }
      }
    }

    add(document);
    return docs;
  }

  /**
   * @param {string} key
   * @returns {Element | null}
   */
  function findPreviewRootByKey(key) {
    if (!key) return null;
    const selector =
      '.decoupled-block-preview-ctnr[data-cloakwp-preview-key="' +
      key.replace(/"/g, '\\"') +
      '"]';
    const docs = collectEditorDocuments();
    for (let i = 0; i < docs.length; i++) {
      const el = docs[i].querySelector(selector);
      if (el) return el;
    }
    return null;
  }

  /**
   * @param {string} key
   * @returns {HTMLIFrameElement | null}
   */
  function findPreviewIframeByKey(key) {
    if (!key) return null;
    const selector =
      'iframe[data-cloakwp-preview-key="' + key.replace(/"/g, '\\"') + '"]';
    const docs = collectEditorDocuments();
    for (let i = 0; i < docs.length; i++) {
      const el = docs[i].querySelector(selector);
      if (el instanceof HTMLIFrameElement) return el;
    }
    return null;
  }

  /**
   * @returns {HTMLIFrameElement[]}
   */
  function findAllPreviewIframes() {
    /** @type {HTMLIFrameElement[]} */
    const out = [];
    const docs = collectEditorDocuments();
    for (let i = 0; i < docs.length; i++) {
      const list = docs[i].querySelectorAll("iframe[data-cloakwp-preview-key]");
      for (let j = 0; j < list.length; j++) {
        if (list[j] instanceof HTMLIFrameElement) out.push(list[j]);
      }
    }
    return out;
  }

  /**
   * @param {HTMLIFrameElement} iframe
   * @returns {string}
   */
  function getPreviewOrigin(iframe) {
    const root = iframe.closest(".decoupled-block-preview-ctnr");
    const configured =
      root && root.getAttribute("data-cloakwp-preview-origin");
    if (configured) return configured;
    try {
      return new URL(iframe.src, window.location.href).origin;
    } catch {
      return "";
    }
  }

  /**
   * Resolve an exact iframe binding. A source cannot claim another preview key.
   *
   * @param {Window} source
   * @param {string} key
   * @returns {{ iframe: HTMLIFrameElement, origin: string } | null}
   */
  function resolvePreviewBinding(source, key) {
    if (!source || !key) return null;
    const iframe = findPreviewIframeByKey(key);
    if (!iframe || iframe.contentWindow !== source) return null;
    const origin = getPreviewOrigin(iframe);
    return origin ? { iframe, origin, key } : null;
  }

  /**
   * @param {string} html
   * @returns {string | null}
   */
  function parsePreviewKey(html) {
    const match = html.match(/data-cloakwp-preview-key=["']([^"']+)["']/);
    return match ? match[1] : null;
  }

  /**
   * @param {string} html
   * @param {string} className
   * @returns {unknown | null}
   */
  function parseJsonScript(html, className) {
    const re = new RegExp(
      '<script[^>]*class=["\'][^"\']*' +
        className +
        '[^"\']*["\'][^>]*>([\\s\\S]*?)<\\/script>',
      "i",
    );
    const match = html.match(re);
    if (!match) return null;
    try {
      return JSON.parse(match[1]);
    } catch {
      return null;
    }
  }

  /**
   * @param {string} html
   * @returns {unknown | null}
   */
  function parseBlockDataAttr(html) {
    const match = html.match(/data-cloakwp-block-data=["']([^"']+)["']/);
    if (!match) return null;
    try {
      return JSON.parse(atob(match[1]));
    } catch {
      return null;
    }
  }

  /**
   * @param {string} html
   * @returns {boolean}
   */
  function parseIsPageDark(html) {
    const attr = html.match(/data-cloakwp-is-page-dark=["']([01])["']/);
    if (attr) return attr[1] === "1";
    const meta = parseJsonScript(html, "cloakwp-preview-meta");
    if (meta && typeof meta === "object" && "isPageDark" in meta) {
      return !!meta.isPageDark;
    }
    return false;
  }

  /**
   * @param {string} html
   * @returns {unknown | null}
   */
  function parseBlockDataFromHtml(html) {
    return parseJsonScript(html, "cloakwp-block-data") || parseBlockDataAttr(html);
  }

  /**
   * @param {Element | null | undefined} root
   * @returns {PendingPayload | null}
   */
  function readPendingFromRoot(root) {
    if (!root || typeof root.getAttribute !== "function") return null;

    let blockData = null;
    const b64 = root.getAttribute("data-cloakwp-block-data");
    if (b64) {
      try {
        blockData = JSON.parse(atob(b64));
      } catch {
        blockData = null;
      }
    }

    if (!blockData) {
      const el = root.querySelector("script.cloakwp-block-data");
      if (el) {
        try {
          blockData = JSON.parse(el.textContent || "null");
        } catch {
          blockData = null;
        }
      }
    }

    if (!blockData || typeof blockData !== "object") return null;

    return {
      blockData,
      isPageDark: root.getAttribute("data-cloakwp-is-page-dark") === "1",
    };
  }

  /**
   * @param {string} key
   * @returns {PendingPayload | null}
   */
  function bootstrapPendingFromDom(key) {
    const root = findPreviewRootByKey(key);
    const pending = readPendingFromRoot(root);
    if (!pending) return null;
    pendingByKey.set(key, pending);
    return pending;
  }

  /**
   * Height of the editor surface that owns the preview iframe — a stable
   * external reference for viewport-tied frontend content (100vh / 100svh).
   * Prefer the canvas/owner window over the (often collapsed) preview iframe.
   *
   * @param {Window} [source]
   * @returns {number}
   */
  function getEditorPreviewViewportHeight(source) {
    let raw = 0;
    if (source) {
      const iframes = findAllPreviewIframes();
      for (let i = 0; i < iframes.length; i++) {
        if (iframes[i].contentWindow !== source) continue;
        const owner = iframes[i].ownerDocument
          ? iframes[i].ownerDocument.defaultView
          : null;
        const fromOwner = owner && owner.innerHeight ? owner.innerHeight : 0;
        if (fromOwner > 0) {
          raw = fromOwner;
          return Math.max(200, Math.round(raw - editorChromePx(owner)));
        }
      }
    }
    if (raw <= 0) raw = window.innerHeight || 0;
    return Math.max(200, Math.round(raw - editorChromePx(window)));
  }

  /**
   * Size a preview iframe to one editor screen before the frontend's first
   * height report. Without this, the browser default (~150px) wins and
   * min-height viewport heroes measure at their natural content height (~600px)
   * — a self-consistent trap auto-sizing cannot escape from below.
   *
   * @param {HTMLIFrameElement | null | undefined} iframe
   */
  function ensureInitialPreviewIframeHeight(iframe) {
    if (!iframe) return;
    const key = iframe.getAttribute("data-cloakwp-preview-key");
    if (key && contentSizedPreviewKeys.has(key)) return;

    const h = getEditorPreviewViewportHeight(iframe.contentWindow);
    if (h <= 0) return;

    const height = h + "px";
    if (iframe.style.height === height) return;
    iframe.style.height = height;
    const parent = iframe.parentNode;
    if (parent && parent.style) parent.style.height = height;
  }

  /**
   * @param {Window} source
   * @param {unknown} blockData
   * @param {boolean} isPageDark
   * @param {string} [key]
   */
  function sendUpdateToSource(source, blockData, isPageDark, key) {
    const binding = resolvePreviewBinding(source, key || "");
    if (!binding || typeof source.postMessage !== "function") return;

    // Always send — the frontend needs an external "one screen" reference for
    // viewport-tied content (100vh heroes), whose height is otherwise
    // self-referential inside an auto-sized iframe.
    const previewViewportHeight = getEditorPreviewViewportHeight(source);
    source.postMessage(
      {
        type: "cloakwp-preview-update",
        previewKey: binding.key,
        blockData: blockData || null,
        bodyClassName: isPageDark ? "dark dark:darker" : undefined,
        previewViewportHeight:
          previewViewportHeight > 0 ? previewViewportHeight : undefined,
      },
      binding.origin,
    );
  }

  /**
   * Rebroadcast the editor viewport reference when the editor window resizes,
   * so pinned viewport-tied previews track the new "one screen" height.
   */
  let editorResizeTimer = null;
  function broadcastEditorViewportHeight() {
    if (editorResizeTimer) clearTimeout(editorResizeTimer);
    editorResizeTimer = setTimeout(function () {
      editorResizeTimer = null;
      readySourcesByKey.forEach(function (source, key) {
        const binding = resolvePreviewBinding(source, key);
        if (!binding) return;
        const h = getEditorPreviewViewportHeight(source);
        if (h > 0 && source && typeof source.postMessage === "function") {
          source.postMessage(
            {
              type: "cloakwp-preview-update",
              previewKey: binding.key,
              previewViewportHeight: h,
            },
            binding.origin,
          );
        }
      });
    }, 300);
  }

  /**
   * Send pending for key to a source. Keeps pending for later ready/remounts.
   * @param {string} key
   * @param {Window} source
   * @returns {boolean}
   */
  function deliverPendingToSource(key, source) {
    let pending = pendingByKey.get(key);
    if (!pending) {
      pending = bootstrapPendingFromDom(key);
    }
    if (!pending) return false;
    sendUpdateToSource(source, pending.blockData, pending.isPageDark, key);
    return true;
  }

  /**
   * @param {string} key
   * @param {unknown} blockData
   * @param {boolean} isPageDark
   * @param {{ authoritative?: boolean }} [opts]
   */
  function storeAndFlush(key, blockData, isPageDark, opts) {
    const authoritative = !!(opts && opts.authoritative);

    // Stale fetch-block: request's optimistic-epoch is older than the latest
    // local edit. Comparing per-request meta (not FIFO start times) so
    // out-of-order XHR completion can't apply an older body after a newer one.
    if (authoritative) {
      const meta = fetchBlockCompletedMeta.length
        ? fetchBlockCompletedMeta.shift()
        : null;
      const fetchEpoch =
        meta && typeof meta.epoch === "number" ? meta.epoch : -1;
      const lastEpoch = lastOptimisticEpochByKey.get(key) || 0;

      // Fetch must have started at the latest optimistic epoch. Capture-phase
      // input/change bumps the epoch BEFORE ACF opens fetch-block, so the
      // matching response has fetchEpoch === lastEpoch.
      const isStale =
        lastEpoch > 0 &&
        (fetchEpoch < 0 || fetchEpoch < lastEpoch);

      if (isStale) {
        return false;
      }
    }

    let nextBlockData = blockData;
    if (
      authoritative &&
      blockData &&
      typeof blockData === "object" &&
      blockData.data &&
      typeof blockData.data === "object"
    ) {
      const prev = pendingByKey.get(key);
      const prevData =
        prev &&
        prev.blockData &&
        typeof prev.blockData === "object" &&
        prev.blockData.data &&
        typeof prev.blockData.data === "object"
          ? prev.blockData.data
          : null;
      if (prevData) {
        nextBlockData = Object.assign({}, blockData, {
          data: mergeAuthoritativeData(prevData, blockData.data),
        });
      }
    }

    pendingByKey.set(key, { blockData: nextBlockData, isPageDark });

    const source = readySourcesByKey.get(key);
    if (source) {
      sendUpdateToSource(source, nextBlockData, isPageDark, key);
      return true;
    }

    return false;
  }

  /**
   * Estimated WP admin chrome (toolbar / sidebar) on the top wp-admin window.
   * Do not subtract this from a Gutenberg canvas iframe — its innerHeight is
   * already the editing surface.
   */
  const EDITOR_CHROME_ESTIMATE_PX = 170;

  /**
   * Toolbar/sidebar chrome only exists on the top wp-admin window. The
   * Gutenberg canvas iframe's innerHeight is already the editing surface —
   * subtracting 170 there undersizes 100vh heroes by a large slice.
   *
   * @param {Window | null | undefined} owner
   * @returns {number}
   */
  function editorChromePx(owner) {
    if (!owner) return EDITOR_CHROME_ESTIMATE_PX;
    try {
      if (window.top && owner !== window.top) return 0;
    } catch {
      /* cross-origin top */
    }
    return EDITOR_CHROME_ESTIMATE_PX;
  }

  /**
   * Preview keys that have received a content-driven height report from the
   * iframe. Until then, {@link ensureInitialPreviewIframeHeight} keeps the
   * iframe at one editor screen so min-height heroes can bind on first measure.
   * @type {Set<string>}
   */
  const contentSizedPreviewKeys = new Set();

  /** @type {Map<string, ReturnType<typeof setTimeout>>} */
  const optimisticTimers = new Map();

  /**
   * Resolve preview key for an ACF block form wrapper.
   * @param {string} blockId
   * @returns {string | null}
   */
  function resolvePreviewKey(blockId) {
    if (!blockId) return null;
    if (readySourcesByKey.has(blockId)) return blockId;
    if (pendingByKey.has(blockId)) return blockId;
    const withPrefix = blockId.indexOf("block_") === 0 ? blockId : "block_" + blockId;
    if (readySourcesByKey.has(withPrefix) || pendingByKey.has(withPrefix)) {
      return withPrefix;
    }
    // Single live preview: use it (common while editing one block).
    if (readySourcesByKey.size === 1) {
      return readySourcesByKey.keys().next().value;
    }
    return null;
  }

  /**
   * Layout-only ACF types — present as `.acf-field` ancestors but not in
   * CloakWP block JSON. Skip them while walking the parent chain.
   * @type {Record<string, true>}
   */
  const LAYOUT_FIELD_TYPES = {
    tab: true,
    accordion: true,
    message: true,
  };

  /**
   * Nesting containers that appear in block JSON (and layout chrome we walk
   * through). Anything else means we've left the field tree.
   * @type {Record<string, true>}
   */
  const NESTING_FIELD_TYPES = {
    group: true,
    clone: true,
    repeater: true,
    flexible_content: true,
    tab: true,
    accordion: true,
    message: true,
  };

  /**
   * Walk DOM `.acf-field` ancestors from a field up to the block root, skipping
   * layout chrome. Uses the DOM (not only `field.parent()`) so group/clone
   * wrappers still appear even when ACF hasn't instantiated them as Field
   * objects yet — that gap previously flattened `cards[0].card_data.title`
   * into `cards[0].title` and broke optimistic updates inside groups.
   *
   * Returns a root→leaf chain of field-like objects (`get("type"|"name")`, `$el`).
   *
   * @param {object} field
   * @returns {object[]}
   */
  function getFieldAncestorChain(field) {
    const $ = window.jQuery;
    if (!field || !field.$el || !$) return [];

    /**
     * @param {JQuery} $el
     * @returns {object}
     */
    function asFieldLike($el) {
      const type = $el.attr("data-type") || $el.data("type") || "";
      const name = $el.attr("data-name") || $el.data("name") || "";
      let instance = null;
      if (typeof acf !== "undefined" && typeof acf.getField === "function") {
        try {
          instance = acf.getField($el);
        } catch (e) {
          instance = null;
        }
      }
      if (instance && typeof instance.get === "function") return instance;
      return {
        $el: $el,
        get: function (key) {
          if (key === "type") return type;
          if (key === "name") return name;
          return null;
        },
      };
    }

    /** @type {object[]} */
    const upward = [];
    let $el = field.$el;
    let guard = 0;
    while ($el && $el.length && guard++ < 40) {
      const type = $el.attr("data-type") || $el.data("type") || "";
      const name = $el.attr("data-name") || $el.data("name") || "";
      if (type && !LAYOUT_FIELD_TYPES[type] && name && String(name).charAt(0) !== "_") {
        upward.push(asFieldLike($el));
      }

      const $parent = $el.parent().closest(".acf-field");
      if (!$parent.length) break;
      const ptype = $parent.attr("data-type") || $parent.data("type") || "";
      if (!ptype || !NESTING_FIELD_TYPES[ptype]) break;
      $el = $parent;
    }

    upward.reverse();
    return upward;
  }

  /**
   * Index of the repeater row / flexible layout under `complexField` that
   * contains `field`. Uses the complex field's own rows (not merely the
   * closest `.acf-row`) so nested repeaters resolve correctly.
   *
   * @param {object} field
   * @param {object} complexField
   * @returns {number} row index or -1
   */
  function getRowIndexInComplex(field, complexField) {
    if (
      !field ||
      !field.$el ||
      !complexField ||
      !complexField.$el ||
      typeof acf === "undefined"
    ) {
      return -1;
    }
    const $ = window.jQuery;
    if (!$) return -1;

    const complexType =
      typeof complexField.get === "function" ? complexField.get("type") : null;
    const rowSelector =
      complexType === "flexible_content" ? ".layout" : ".acf-row";
    const complexEl = complexField.$el[0];
    if (!complexEl) return -1;

    const $rows = complexField.$el
      .find(rowSelector)
      .not(".acf-clone, .acf-deleted, .clones .layout")
      .filter(function () {
        // Only rows that belong directly to this complex field — not rows of
        // a nested repeater/flexible inside one of our rows.
        const $owner = $(this).closest(
          '.acf-field[data-type="repeater"], .acf-field[data-type="flexible_content"]',
        );
        return $owner[0] === complexEl;
      });

    const fieldEl = field.$el[0];
    for (let i = 0; i < $rows.length; i++) {
      if ($rows[i] === fieldEl || $.contains($rows[i], fieldEl)) {
        return i;
      }
    }
    return -1;
  }

  /**
   * Full path under `blockData.data` for any nested field.
   * Walks the ACF parent chain once — groups, clones, repeaters, and
   * flexible layouts all contribute segments (repeaters/flexible insert a
   * numeric row index after their name).
   *
   * Examples:
   *   title                          → ["title"]
   *   ken_burns.enable               → ["ken_burns","enable"]
   *   cards[0].is_page               → ["cards",0,"is_page"]
   *   cards[0].card_data.title       → ["cards",0,"card_data","title"]
   *   cards[0].card_data.link.enabled → ["cards",0,"card_data","link","enabled"]
   *   social_proof.stats[1].value    → ["social_proof","stats",1,"value"]
   *
   * @param {object} field
   * @returns {(string|number)[]}
   */
  function resolveFieldDataPath(field) {
    const chain = getFieldAncestorChain(field);
    if (!chain.length) return [];

    /** @type {(string|number)[]} */
    const path = [];
    for (let i = 0; i < chain.length; i++) {
      const f = chain[i];
      const type = typeof f.get === "function" ? f.get("type") : null;
      const name = typeof f.get === "function" ? f.get("name") : null;
      if (!name || name.charAt(0) === "_") continue;

      if (type === "repeater" || type === "flexible_content") {
        path.push(name);
        const child = chain[i + 1];
        if (!child) return [];
        const rowIndex = getRowIndexInComplex(child, f);
        if (rowIndex < 0) return [];
        path.push(rowIndex);
      } else {
        path.push(name);
      }
    }
    return path;
  }

  /**
   * Path to a repeater/flexible array itself (no row index) — used for row
   * splice on remove. Includes group/clone parents above the complex field.
   *
   * @param {object} complexField
   * @returns {(string|number)[]}
   */
  function resolveComplexFieldPath(complexField) {
    const chain = getFieldAncestorChain(complexField);
    if (!chain.length) return [];

    const complexEl =
      complexField && complexField.$el && complexField.$el[0]
        ? complexField.$el[0]
        : null;

    /** @type {(string|number)[]} */
    const path = [];
    for (let i = 0; i < chain.length; i++) {
      const f = chain[i];
      const type = typeof f.get === "function" ? f.get("type") : null;
      const name = typeof f.get === "function" ? f.get("name") : null;
      if (!name || name.charAt(0) === "_") continue;

      const fEl = f.$el && f.$el[0] ? f.$el[0] : null;
      const isTarget = f === complexField || (complexEl && fEl === complexEl);

      if (type === "repeater" || type === "flexible_content") {
        path.push(name);
        if (isTarget) break;
        const child = chain[i + 1];
        if (!child) return [];
        const rowIndex = getRowIndexInComplex(child, f);
        if (rowIndex < 0) return [];
        path.push(rowIndex);
      } else {
        path.push(name);
      }
    }
    return path;
  }

  /**
   * Fallback when ACF parent chain doesn't match JSON nesting: find an array
   * keyed by repeaterName anywhere under data.
   *
   * @param {Record<string, unknown>} data
   * @param {string} repeaterName
   * @returns {(string|number)[] | null}
   */
  function findArrayKeyPath(data, repeaterName) {
    if (!data || typeof data !== "object" || Array.isArray(data)) return null;
    if (Array.isArray(data[repeaterName])) return [repeaterName];
    const keys = Object.keys(data);
    for (let i = 0; i < keys.length; i++) {
      const k = keys[i];
      const v = data[k];
      if (!v || typeof v !== "object" || Array.isArray(v)) continue;
      const sub = findArrayKeyPath(v, repeaterName);
      if (sub) return [k].concat(sub);
    }
    return null;
  }

  /**
   * @param {Record<string, unknown> | unknown[]} obj
   * @param {(string|number)[]} path
   * @returns {unknown}
   */
  function getAtPath(obj, path) {
    let cur = obj;
    for (let i = 0; i < path.length; i++) {
      if (cur == null || typeof cur !== "object") return undefined;
      cur = cur[path[i]];
    }
    return cur;
  }

  /**
   * Immutable nested set. Supports object keys and numeric array indices
   * so a single path can address `cards[0].card_data.title`.
   *
   * @param {Record<string, unknown> | unknown[] | null | undefined} obj
   * @param {(string|number)[]} path
   * @param {unknown} value
   * @returns {Record<string, unknown> | unknown[]}
   */
  function setAtPath(obj, path, value) {
    if (!path.length) return /** @type {any} */ (value);

    const head = path[0];
    const rest = path.slice(1);
    const emptyChild = function () {
      return typeof rest[0] === "number" ? [] : {};
    };

    if (Array.isArray(obj)) {
      const index = typeof head === "number" ? head : parseInt(String(head), 10);
      if (isNaN(index) || index < 0) return obj.slice();
      const copy = obj.slice();
      const child = copy[index];
      copy[index] = rest.length
        ? setAtPath(
            child != null && typeof child === "object" ? child : emptyChild(),
            rest,
            value,
          )
        : value;
      return copy;
    }

    const base =
      obj && typeof obj === "object" && !Array.isArray(obj) ? obj : {};
    const child = base[head];
    const next = rest.length
      ? setAtPath(
          child != null && typeof child === "object" ? child : emptyChild(),
          rest,
          value,
        )
      : value;
    const out = Object.assign({}, base);
    out[head] = next;
    return out;
  }

  /**
   * Resolve pending repeater/flexible rows array + path to that array.
   *
   * @param {object} complexField
   * @param {Record<string, unknown>} prevData
   * @returns {{ dataPath: (string|number)[], prevRows: unknown[] | undefined }}
   */
  function resolveRepeaterRowsInData(complexField, prevData) {
    let dataPath = resolveComplexFieldPath(complexField);
    let prevRows = getAtPath(prevData, dataPath);
    if (!Array.isArray(prevRows)) {
      const repeaterName =
        typeof complexField.get === "function"
          ? complexField.get("name")
          : null;
      if (repeaterName) {
        const found = findArrayKeyPath(prevData, repeaterName);
        if (found) {
          dataPath = found;
          prevRows = getAtPath(prevData, dataPath);
        }
      }
    }
    return {
      dataPath: dataPath,
      prevRows: Array.isArray(prevRows) ? prevRows : undefined,
    };
  }

  /**
   * Optimistic splice when an ACF repeater row is removed.
   * Hooked to `acf.remove`, which fires once on the row while it's still in
   * the DOM (before the collapse animation finishes).
   *
   * @param {JQuery|HTMLElement|null|undefined} el
   */
  function optimisticRemoveRepeaterRow(el) {
    const $ = window.jQuery;
    if (
      !$ ||
      typeof acf === "undefined" ||
      typeof acf.getField !== "function"
    ) {
      return;
    }
    const $el = el && el.jquery ? el : $(el);
    if (!$el || !$el.length) return;

    // Only repeater rows — not flexible layouts, not nested field wraps.
    if (!$el.hasClass("acf-row") || $el.hasClass("acf-clone")) return;

    const $fieldEl = $el.closest('.acf-field[data-type="repeater"]');
    if (!$fieldEl.length) return;

    const repeater = acf.getField($fieldEl);
    if (!repeater) return;

    // Include the row being removed (still in DOM at this point).
    const $rows = $el.parent().children(".acf-row").not(".acf-clone");
    const rowIndex = $rows.index($el);
    if (rowIndex < 0) return;

    const blockId = getBlockIdFromField(repeater);
    const key = resolvePreviewKey(blockId);
    const pending = key ? pendingByKey.get(key) : null;
    const prevData =
      pending &&
      pending.blockData &&
      pending.blockData.data &&
      typeof pending.blockData.data === "object"
        ? pending.blockData.data
        : null;
    if (!prevData) return;

    const resolved = resolveRepeaterRowsInData(repeater, prevData);
    if (!resolved.prevRows || rowIndex >= resolved.prevRows.length) return;

    const nextRows = resolved.prevRows.slice();
    nextRows.splice(rowIndex, 1);
    applyOptimisticPathUpdate(
      blockId,
      resolved.dataPath,
      nextRows,
      "acf.remove.row",
    );
  }

  /**
   * @param {object} field
   * @returns {string}
   */
  function getBlockIdFromField(field) {
    if (!field || !field.$el) return "";
    const $wrap = field.$el.closest(".acf-block-fields");
    if ($wrap && $wrap.length) {
      return $wrap.attr("data-block-id") || "";
    }
    const $block = field.$el.closest("[data-block]");
    return ($block && $block.length && $block.attr("data-block")) || "";
  }

  /**
   * Normalize true_false-ish scalars so 0/"0"/false and 1/"1"/true compare equal.
   * Prevents a second change_field from bumping the optimistic epoch after capture
   * already applied the same toggle — which would mark the in-flight fetch-block stale.
   *
   * @param {unknown} v
   * @returns {0 | 1 | undefined}
   */
  function normalizeToggleScalar(v) {
    if (v === true || v === 1 || v === "1") return 1;
    if (v === false || v === 0 || v === "0") return 0;
    return undefined;
  }

  /**
   * @param {unknown} a
   * @param {unknown} b
   * @returns {boolean}
   */
  function valuesEqual(a, b) {
    if (a === b) return true;
    if (a == null || b == null) return a === b;
    const toggleA = normalizeToggleScalar(a);
    const toggleB = normalizeToggleScalar(b);
    if (toggleA !== undefined && toggleB !== undefined) {
      return toggleA === toggleB;
    }
    if (typeof a !== "object" || typeof b !== "object") return false;
    try {
      return JSON.stringify(a) === JSON.stringify(b);
    } catch (e) {
      return false;
    }
  }

  /**
   * Media fields hidden by ACF conditional logic often come back empty (or as
   * bare attachment IDs) from fetch-block. Don't clobber richer pending values.
   * @param {unknown} v
   * @returns {boolean}
   */
  function isSparseMediaValue(v) {
    if (v == null || v === false || v === "") return true;
    if (Array.isArray(v)) {
      if (v.length === 0) return true;
      // Bare WP attachment IDs — not CloakWP-formatted image objects.
      if (typeof v[0] === "number") return true;
      if (typeof v[0] === "string" && /^\d+$/.test(v[0])) return true;
    }
    return false;
  }

  /**
   * @param {unknown} v
   * @returns {boolean}
   */
  function isRichMediaValue(v) {
    if (!Array.isArray(v) || !v.length) {
      return !!(v && typeof v === "object" && !Array.isArray(v) && (v.url || v.src));
    }
    const first = v[0];
    return !!(first && typeof first === "object" && (first.url || first.src));
  }

  const MEDIA_FIELD_NAMES = {
    images: true,
    image: true,
    gallery: true,
    files: true,
    video: true,
    thumbnail: true,
    poster: true,
  };

  /**
   * @param {Record<string, unknown>} pendingData
   * @param {Record<string, unknown>} incomingData
   * @returns {Record<string, unknown>}
   */
  function mergeAuthoritativeData(pendingData, incomingData) {
    const merged = Object.assign({}, incomingData);
    for (const key in MEDIA_FIELD_NAMES) {
      if (!Object.prototype.hasOwnProperty.call(pendingData, key)) continue;
      if (
        isSparseMediaValue(merged[key]) &&
        isRichMediaValue(pendingData[key])
      ) {
        merged[key] = pendingData[key];
      }
    }
    return merged;
  }

  /**
   * Push field edits to the live iframe immediately — don't wait for ACF's
   * ~2.5s admin-ajax fetch-block. Authoritative JSON still arrives later via
   * blocks/preview/render (skipped when stale vs last optimistic edit).
   *
   * @param {string} blockId
   * @param {(string|number)[]} path nested keys under blockData.data
   * @param {unknown} value
   * @param {string} [via]
   */
  function applyOptimisticPathUpdate(blockId, path, value, via) {
    const key = resolvePreviewKey(blockId);
    if (!key || !path || !path.length) {
      return;
    }

    const pending = pendingByKey.get(key);
    if (
      !pending ||
      !pending.blockData ||
      typeof pending.blockData !== "object" ||
      !pending.blockData.data ||
      typeof pending.blockData.data !== "object"
    ) {
      return;
    }

    const prevData = pending.blockData.data;
    const prevVal = getAtPath(prevData, path);
    if (valuesEqual(prevVal, value)) {
      return;
    }

    // Never clobber a real pending value with an empty group.val().
    if (value === "" && prevVal != null && prevVal !== "") {
      return;
    }

    // Repeater/flexible .val() is a row COUNT — never replace an array with it.
    if (Array.isArray(prevVal) && !Array.isArray(value)) {
      return;
    }

    // Group objects in pending JSON must not be replaced by scalars (e.g. group
    // fields expose the first sub-input via the default Field.val()).
    if (
      prevVal &&
      typeof prevVal === "object" &&
      !Array.isArray(prevVal) &&
      (value === null ||
        value === false ||
        typeof value !== "object" ||
        Array.isArray(value))
    ) {
      return;
    }

    const blockData = Object.assign({}, pending.blockData, {
      data: setAtPath(prevData, path, value),
    });
    optimisticEpoch += 1;
    lastOptimisticEpochByKey.set(key, optimisticEpoch);
    pendingByKey.set(key, {
      blockData: blockData,
      isPageDark: pending.isPageDark,
    });

    const existing = optimisticTimers.get(key);
    if (existing) clearTimeout(existing);

    optimisticTimers.set(
      key,
      setTimeout(function () {
        optimisticTimers.delete(key);
        const current = pendingByKey.get(key);
        const source = readySourcesByKey.get(key);
        if (!current || !source) return;
        sendUpdateToSource(source, current.blockData, current.isPageDark, key);
      }, 16),
    );
  }

  /**
   * @param {string} blockId
   * @param {string} fieldName
   * @param {unknown} value
   * @param {string} [via]
   */
  function applyOptimisticFieldUpdate(blockId, fieldName, value, via) {
    applyOptimisticPathUpdate(blockId, fieldName ? [fieldName] : [], value, via);
  }

  /**
   * Merge the correct field name/val into pending block data and postMessage.
   * Path is derived entirely from the ACF parent chain (see resolveFieldDataPath)
   * so group/repeater/flexible nesting of any depth is handled uniformly —
   * never call repeater/flexible .val() (those return row counts only).
   *
   * @param {object|null|undefined} field
   * @param {string} via
   */
  function optimisticFromField(field, via) {
    if (!field || typeof field.get !== "function") return;

    const leafName = field.get("name");
    if (!leafName || leafName.charAt(0) === "_") return;

    const leafType = field.get("type");

    // These types don't expose usable leaf values via .val() for our pending
    // JSON shape (counts, first-subfield scalars, layout chrome, etc.).
    if (
      leafType === "gallery" ||
      leafType === "image" ||
      leafType === "file" ||
      leafType === "oembed" ||
      leafType === "repeater" ||
      leafType === "flexible_content" ||
      leafType === "group" ||
      leafType === "clone" ||
      leafType === "tab" ||
      leafType === "accordion" ||
      leafType === "message"
    ) {
      return;
    }

    let leafVal;
    try {
      leafVal = field.val();
    } catch (e) {
      return;
    }

    const blockId = getBlockIdFromField(field);
    const dataPath = resolveFieldDataPath(field);
    if (!dataPath.length) return;

    applyOptimisticPathUpdate(blockId, dataPath, leafVal, via);
  }

  /**
   * @param {JQuery|HTMLElement|null|undefined} el
   * @param {string} via
   */
  function optimisticFromDomEvent(el, via) {
    if (!el || typeof acf === "undefined" || typeof acf.getField !== "function") {
      return;
    }
    const $ = window.jQuery;
    if (!$) return;
    const $el = el.jquery ? el : $(el);
    const $fieldEl = $el.closest(".acf-field");
    if (!$fieldEl.length) return;

    const field = acf.getField($fieldEl);
    if (!field) return;
    optimisticFromField(field, via);
  }

  /**
   * @param {MessageEvent} event
   * @returns {{ previewKey: string } | null}
   */
  function parseReadyMessage(event) {
    let parsed = event.data;
    try {
      if (typeof parsed === "string") {
        parsed = JSON.parse(parsed);
      }
    } catch {
      /* not JSON ready */
      return null;
    }
    if (parsed && parsed.type === "cloakwp-preview-ready") {
      return {
        previewKey:
          typeof parsed.previewKey === "string" ? parsed.previewKey : "",
      };
    }
    return null;
  }

  /**
   * Apply a content-height report from a preview iframe.
   * No +1 fudge — that feedback-loops with content measurement (+1px/edit).
   *
   * @param {MessageEvent} event
   * @returns {boolean}
   */
  function applyIframeHeightMessage(event) {
    let payload = event.data;
    if (typeof payload === "string") {
      try {
        payload = JSON.parse(payload);
      } catch {
        return false;
      }
    }
    if (
      !payload ||
      payload.type !== "cloakwp-preview-height" ||
      typeof payload.previewKey !== "string" ||
      typeof payload.height !== "number"
    ) {
      return false;
    }

    const source = event.source;
    if (!source) return false;

    const binding = resolvePreviewBinding(source, payload.previewKey);
    if (!binding || event.origin !== binding.origin) return false;

    const next = Math.round(payload.height);
    if (!Number.isFinite(next) || next < 0) return false;
    // Empty preview `#root` reports a ~20px floor. Applying it collapses the
    // iframe and marks it "content-sized", so the editor-viewport bootstrap
    // cannot recover — viewport-tied heroes then trap at that min height.
    if (next < 48) return true;

    const iframe = binding.iframe;
    const height = next + "px";
    if (iframe.style.height === height) return true;
    iframe.style.height = height;
    const parent = iframe.parentNode;
    if (parent && parent.style) parent.style.height = height;
    contentSizedPreviewKeys.add(binding.key);
    return true;
  }

  function onWindowMessage(event) {
    if (applyIframeHeightMessage(event)) return;

    const ready = parseReadyMessage(event);
    if (!ready) return;

    const source = event.source;
    if (!source || typeof source.postMessage !== "function") return;

    const key = ready.previewKey;
    const binding = resolvePreviewBinding(source, key);
    if (!binding || event.origin !== binding.origin) return;

    readyKeys.add(key);
    readySourcesByKey.set(key, source);
    ensureInitialPreviewIframeHeight(binding.iframe);
    deliverPendingToSource(key, source);
  }

  function registerAcfHooks() {
    if (typeof acf === "undefined" || typeof acf.addFilter !== "function") {
      return false;
    }
    if (initialized) return true;
    initialized = true;

    window.addEventListener("message", onWindowMessage);
    window.addEventListener("resize", broadcastEditorViewportHeight);

    // Optimistic preview: don't wait for ~2.5s fetch-block.
    // resolveFieldDataPath walks the ACF parent chain (groups/clones/repeaters/
    // flexible) into one JSON path — never repeater.val() (row count only).
    // Row remove: splice pending immediately. Row add: wait for fetch-block.
    //
    // ACF fires `change_field` (not `change`) when a field value changes — including
    // programmatic updates like Link (wpLink → jQuery .trigger). Native capture
    // below covers typing; this covers Link / other non-native updates.
    acf.addAction(
      "change_field",
      function (field) {
        try {
          optimisticFromField(field, "acf.change_field");
        } catch (e) {
          /* ignore */
        }
      },
      1,
    );

    // Fires once per removed element while it's still in the DOM (acf.remove).
    acf.addAction("remove", function ($el) {
      try {
        optimisticRemoveRepeaterRow($el);
      } catch (e) {
        /* ignore */
      }
    });

    // Capture phase: bump optimistic epoch BEFORE ACF starts fetch-block so
    // the XHR stamps fetchEpoch === lastEpoch (strict stale guard above).
    // Paired with change_field above — capture covers native typing; change_field
    // covers ACF/model updates (Link picker, etc.).
    function onCaptureInput(e) {
      const t = e && e.target;
      if (!t || !t.closest) return;
      if (!t.closest(".acf-field")) return;
      try {
        optimisticFromDomEvent(t, "dom.input.capture");
      } catch (err) {
        /* ignore */
      }
    }
    function onCaptureChange(e) {
      const t = e && e.target;
      if (!t || !t.closest) return;
      if (!t.closest(".acf-field")) return;
      try {
        optimisticFromDomEvent(t, "dom.change.capture");
      } catch (err) {
        /* ignore */
      }
    }
    const editorDocs = collectEditorDocuments();
    for (let i = 0; i < editorDocs.length; i++) {
      try {
        editorDocs[i].addEventListener("input", onCaptureInput, true);
        editorDocs[i].addEventListener("change", onCaptureChange, true);
      } catch (err) {
        /* ignore */
      }
    }

    acf.addFilter("blocks/preview/render", function (html, isPreload) {
      if (!html) return html;

      const key = parsePreviewKey(html);
      if (!key) return html;

      const blockData = parseBlockDataFromHtml(html);
      const isPageDark = parseIsPageDark(html);
      // brand-new inserts can legitimately have null/empty JSON — still mount
      // the iframe shell; postMessage will deliver data on the first real edit.
      const hasBlockData = !!blockData && typeof blockData === "object";

      const hasIframe =
        typeof html === "string" && html.indexOf("block-preview-iframe") !== -1;

      // Seed the iframe-shell cache once. Never cache JSON-only stubs (older PHP
      // skipped the iframe on AJAX) — that would lock a block into no-preview.
      // Never overwrite an existing shell: ACF setHtml no-ops only when the
      // returned string is identical to state.html; remounting kills the handshake.
      if (hasIframe && !htmlCache.has(key)) {
        htmlCache.set(key, html);
      }

      // Preload only: seed pending so the iframe ready handshake can flush.
      // For live updates, storeAndFlush owns pending — setting it here before the
      // stale check caused pending to diverge from the preview (old hero_style
      // reappeared on the next optimistic H1 keystroke).
      if (isPreload) {
        if (hasBlockData) {
          pendingByKey.set(key, { blockData, isPageDark });
        }
        return htmlCache.has(key) ? htmlCache.get(key) : html;
      }

      if (hasBlockData) {
        storeAndFlush(key, blockData, isPageDark, { authoritative: true });
      }

      // Once we have an iframe shell (ready or still loading), always return it.
      // Waiting for readySourcesByKey caused a race: fetch-block often completes
      // before the Next.js iframe handsakes, and returning stub/new HTML tore
      // down the loading iframe — new blocks then stayed blank until reload.
      if (htmlCache.has(key)) {
        return htmlCache.get(key);
      }

      return html;
    });

    acf.addAction("render_block_preview", function ($el) {
      const root =
        ($el &&
          typeof $el.find === "function" &&
          $el.find(".decoupled-block-preview-ctnr")[0]) ||
        ($el &&
          $el[0] &&
          typeof $el[0].querySelector === "function" &&
          $el[0].querySelector(".decoupled-block-preview-ctnr")) ||
        null;

      if (root) {
        const key = root.getAttribute("data-cloakwp-preview-key");
        const fromDom = readPendingFromRoot(root);
        if (key && fromDom) {
          pendingByKey.set(key, fromDom);
        }

        const pending = key ? pendingByKey.get(key) : null;

        if (key && pending) {
          storeAndFlush(key, pending.blockData, pending.isPageDark);
        }
      }

      const visible = findAllPreviewIframes();
      if (visible.length === 0) return;

      visible.forEach(function (frame) {
        ensureInitialPreviewIframeHeight(frame);
      });

      const visibleKeys = new Set(
        visible.map(function (frame) {
          return frame.getAttribute("data-cloakwp-preview-key");
        }),
      );

      htmlCache.forEach(function (_html, key) {
        if (!visibleKeys.has(key)) {
          htmlCache.delete(key);
          readyKeys.delete(key);
          pendingByKey.delete(key);
          readySourcesByKey.delete(key);
          contentSizedPreviewKeys.delete(key);
        }
      });
    });

    return true;
  }

  if (!registerAcfHooks()) {
    const started = Date.now();
    const timer = setInterval(function () {
      if (registerAcfHooks() || Date.now() - started > 15000) {
        clearInterval(timer);
      }
    }, 50);
  }
})();
