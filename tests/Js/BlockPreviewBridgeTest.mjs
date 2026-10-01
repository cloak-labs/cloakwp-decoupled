import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import vm from "node:vm";

const bridgePath = new URL("../../js/block-preview.js", import.meta.url);

function previewHtml(
  editorKey,
  protocolKey,
  blockData,
  previewInitialHeight = "compact",
) {
  const tokenPayload = Buffer.from(
    JSON.stringify({
      previewKey: protocolKey,
      pathname: "/about/",
      exp: 2_000_000_000,
    }),
  ).toString("base64url");

  return `
    <div
      class="decoupled-block-preview-ctnr"
      data-cloakwp-preview-key="${editorKey}"
      data-cloakwp-preview-origin="https://frontend.test"
      data-cloakwp-preview-initial-height="${previewInitialHeight}"
    >
      <iframe
        class="block-preview-iframe"
        data-cloakwp-preview-key="${editorKey}"
        src="https://frontend.test/preview-block?token=${tokenPayload}.signature"
      ></iframe>
      <script type="application/json" class="cloakwp-block-data">${JSON.stringify(blockData)}</script>
    </div>
  `;
}

async function createBridgeHarness({
  editorKey,
  protocolKey,
  blockData,
  previewInitialHeight = "compact",
}) {
  const timers = [];
  const messageListeners = [];
  const filters = new Map();
  const actions = new Map();
  const clickListeners = [];
  const previewMessages = [];
  const requests = [];
  let serializedData = null;

  class FakeXHR {
    open() {}
    send() {}
    complete() {
      this.readyState = 4;
      this.onreadystatechange();
    }
  }

  class FakeIframe {}

  const previewWindow = {
    postMessage(payload, targetOrigin) {
      previewMessages.push({ payload, targetOrigin });
    },
  };

  const iframe = new FakeIframe();
  iframe.src = "https://frontend.test/preview-block?token=signed";
  iframe.contentWindow = previewWindow;
  iframe.style = {};
  iframe.parentNode = { style: {} };
  iframe.getAttribute = (name) =>
    name === "data-cloakwp-preview-key" ? editorKey : null;
  iframe.closest = () => ({
    getAttribute(name) {
      if (name === "data-cloakwp-preview-origin") {
        return "https://frontend.test";
      }
      if (name === "data-cloakwp-preview-initial-height") {
        return previewInitialHeight;
      }
      return null;
    },
  });

  const document = {
    defaultView: null,
    head: { appendChild() {} },
    documentElement: { appendChild() {} },
    createElement() {
      return { id: "", textContent: "" };
    },
    getElementById() {
      return null;
    },
    querySelectorAll(selector) {
      return selector.includes("iframe") ? [iframe] : [];
    },
    querySelector(selector) {
      if (
        selector.includes("decoupled-block-preview-ctnr") &&
        selector.includes(editorKey)
      ) {
        return null;
      }
      if (selector.includes("iframe") && selector.includes(editorKey)) {
        return iframe;
      }
      return null;
    },
    addEventListener(type, listener) {
      if (type === "click") clickListeners.push(listener);
    },
  };
  iframe.ownerDocument = document;
  const dataScript = { textContent: JSON.stringify(blockData) };
  const form = {};
  const originalQuerySelector = document.querySelector;
  document.querySelector = (selector) =>
    selector.includes("acf-block-fields") && serializedData ? form : originalQuerySelector(selector);
  const clientId = editorKey.replace(/^block_/, "");
  const root = {
    getAttribute: (name) => name === "data-cloakwp-preview-key" ? editorKey : null,
    closest: () => ({ getAttribute: () => clientId }),
    querySelector: (selector) => selector.includes("iframe") ? iframe : dataScript,
  };
  const button = {
    disabled: false,
    title: "Refresh preview",
    attributes: new Map(),
    closest: (selector) => selector === ".decoupled-block-preview-ctnr" ? root : button,
    classList: { contains: () => true },
    setAttribute(name, value) { this.attributes.set(name, value); },
    removeAttribute(name) { this.attributes.delete(name); },
  };
  const attributes = { name: blockData.name, data: { field_gallery: [1] }, _acf_context: { postId: 13 } };
  const jquery = () => ({ length: 1, data: () => null, parent: () => ({ length: 0 }) });
  jquery.ajax = (options) => {
    const callbacks = {};
    const request = {
      options,
      done(fn) { callbacks.done = fn; return this; },
      fail(fn) { callbacks.fail = fn; return this; },
      always(fn) { callbacks.always = fn; return this; },
      complete(response) {
        this.xhr.complete();
        callbacks.done(response);
        callbacks.always();
      },
      reject() { callbacks.fail(); callbacks.always(); },
      xhr: new FakeXHR(),
    };
    request.xhr.open("POST", options.url);
    request.xhr.send(new URLSearchParams(options.data).toString());
    requests.push(request);
    return request;
  };

  const window = {
    document,
    innerHeight: 900,
    location: { href: "https://wp.test/wp-admin/post.php?post=13" },
    jQuery: jquery,
    wp: { data: { select: () => ({ getBlock: () => ({ attributes }) }) } },
    addEventListener(type, listener) {
      if (type === "message") messageListeners.push(listener);
    },
  };
  window.top = window;
  document.defaultView = window;

  const acf = {
    addFilter(name, callback) {
      filters.set(name, callback);
    },
    addAction(name, callback) { actions.set(name, callback); },
    get: () => "https://wp.test/wp-admin/admin-ajax.php",
    prepareForAjax: (data) => data,
    serialize: () => serializedData,
  };

  const source = await readFile(bridgePath, "utf8");
  const instrumentedSource = source.replace(
    /\}\)\(\);\s*$/,
    `
      globalThis.__cloakwpPreviewTest = {
        applyOptimisticPathUpdate,
        mergeStaleServerData,
      };
    })();
    `,
  );
  assert.notEqual(instrumentedSource, source, "bridge test instrumentation failed");

  const context = vm.createContext({
    URL,
    HTMLIFrameElement: FakeIframe,
    XMLHttpRequest: FakeXHR,
    acf,
    atob(value) {
      return Buffer.from(value, "base64").toString("utf8");
    },
    clearInterval() {},
    clearTimeout() {},
    console,
    document,
    globalThis: null,
    setInterval() {
      throw new Error("ACF hooks should register synchronously");
    },
    setTimeout(callback) {
      timers.push(callback);
      return timers.length;
    },
    window,
  });
  context.globalThis = context;

  vm.runInContext(instrumentedSource, context, {
    filename: bridgePath.pathname,
  });

  const renderPreview = filters.get("blocks/preview/render");
  assert.ok(renderPreview, "preview render filter was not registered");
  renderPreview(
    previewHtml(editorKey, protocolKey, blockData, previewInitialHeight),
    true,
  );

  assert.equal(messageListeners.length, 1);
  messageListeners[0]({
    data: {
      type: "cloakwp-preview-ready",
      previewKey: protocolKey,
    },
    origin: "https://frontend.test",
    source: previewWindow,
  });

  const readyMessages = previewMessages.slice();
  previewMessages.length = 0;

  return {
    flushTimers() {
      while (timers.length) timers.shift()();
    },
    iframe,
    button,
    dataScript,
    attributes,
    requests,
    renderPreview,
    refresh() {
      clickListeners[0]({ target: button, preventDefault() {}, stopPropagation() {} });
    },
    setSerializedData(data) { serializedData = data; },
    remountCachedShell() {
      dataScript.textContent = JSON.stringify(blockData);
      actions.get("render_block_preview")({ find: () => [root] }, attributes);
    },
    startServerRequest() {
      const xhr = new FakeXHR();
      xhr.open("POST", "https://wp.test/wp-admin/admin-ajax.php");
      xhr.send("action=acf%2Fajax%2Ffetch-block");
      return xhr;
    },
    ready(nextProtocolKey = protocolKey) {
      messageListeners[0]({
        data: { type: "cloakwp-preview-ready", previewKey: nextProtocolKey },
        origin: "https://frontend.test", source: previewWindow,
      });
    },
    optimisticUpdate: context.__cloakwpPreviewTest.applyOptimisticPathUpdate,
    mergeStaleServerData: context.__cloakwpPreviewTest.mergeStaleServerData,
    previewMessages,
    readyMessages,
  };
}

test("keeps optimistic text and takes server galleries when merging a stale preview", async () => {
  const harness = await createBridgeHarness({
    editorKey: "block_images",
    protocolKey: "block_images_signed",
    blockData: {
      name: "acf/images",
      data: { heading: "Gallery", manual_images: [] },
    },
  });

  const merged = harness.mergeStaleServerData(
    { heading: "Gallery updated", manual_images: [] },
    {
      heading: "Gallery",
      manual_images: [{ url: "https://cdn.test/a.jpg" }],
    },
  );

  assert.equal(merged.heading, "Gallery updated");
  assert.equal(merged.manual_images.length, 1);
  assert.equal(merged.manual_images[0].url, "https://cdn.test/a.jpg");
});

test("refresh sends uncached fetch-block with current form values and reloads the iframe", async () => {
  const harness = await createBridgeHarness({
    editorKey: "block_images", protocolKey: "block_images_signed",
    blockData: { name: "acf/images", data: { images: [{ url: "https://cdn.test/removed.jpg" }] } },
  });
  harness.setSerializedData({ field_gallery: [2, 3] });
  harness.refresh();
  harness.refresh();
  assert.equal(harness.requests.length, 1, "ignore clicks while refresh is running");
  const request = harness.requests[0];
  assert.equal(request.options.data.action, "acf/ajax/fetch-block");
  assert.equal(request.options.data.clientId, "images");
  assert.deepEqual(JSON.parse(request.options.data.block).data, { field_gallery: [2, 3] });
  assert.deepEqual(JSON.parse(request.options.data.context), { postId: 13 });
  assert.equal(request.options.cache, false);
  assert.equal(harness.button.disabled, true);

  request.complete({ data: { preview: previewHtml("block_images", "block_images_signed", {
    name: "acf/images", data: { images: [{ url: "https://cdn.test/new.jpg" }] },
  }) } });
  assert.match(harness.iframe.src, /token=/);
  assert.equal(harness.button.disabled, false);
  harness.ready();
  assert.equal(harness.previewMessages.at(-1).payload.blockData.data.images[0].url, "https://cdn.test/new.jpg");
  assert.equal(JSON.parse(harness.dataScript.textContent).data.images[0].url, "https://cdn.test/new.jpg");
  harness.remountCachedShell();
  harness.ready();
  assert.equal(harness.previewMessages.at(-1).payload.blockData.data.images[0].url, "https://cdn.test/new.jpg");
});

test("refresh accepts a cleared gallery without preserving removed images", async () => {
  const harness = await createBridgeHarness({
    editorKey: "block_images", protocolKey: "block_images_signed",
    blockData: { name: "acf/images", data: { images: [{ url: "https://cdn.test/removed.jpg" }] } },
  });
  harness.refresh();
  harness.requests[0].complete({ data: { preview: previewHtml("block_images", "block_images_signed", {
    name: "acf/images", data: { images: [] },
  }) } });
  harness.ready();
  assert.equal(harness.previewMessages.at(-1).payload.blockData.data.images.length, 0);
});

test("refresh drops the preload alias when the new token uses the editor key", async () => {
  const editorKey = "block_images";
  const harness = await createBridgeHarness({
    editorKey, protocolKey: "block_preload_hash",
    blockData: { name: "acf/images", data: { images: [] } },
  });
  harness.refresh();
  harness.requests[0].complete({ data: { preview: previewHtml(editorKey, editorKey, {
    name: "acf/images", data: { images: [{ url: "https://cdn.test/new.jpg" }] },
  }) } });
  harness.ready(editorKey);
  assert.equal(harness.previewMessages.at(-1).payload.previewKey, editorKey);
  assert.equal(harness.previewMessages.at(-1).payload.blockData.data.images[0].url, "https://cdn.test/new.jpg");
});

test("an older background response cannot overwrite a completed manual refresh", async () => {
  const harness = await createBridgeHarness({
    editorKey: "block_images", protocolKey: "block_images_signed",
    blockData: { name: "acf/images", data: { images: [{ url: "https://cdn.test/removed.jpg" }] } },
  });
  const oldRequest = harness.startServerRequest();
  harness.refresh();
  harness.requests[0].complete({ data: { preview: previewHtml("block_images", "block_images_signed", {
    name: "acf/images", data: { images: [{ url: "https://cdn.test/new.jpg" }] },
  }) } });
  harness.ready();
  oldRequest.complete();
  harness.renderPreview(previewHtml("block_images", "block_images_signed", {
    name: "acf/images", data: { images: [{ url: "https://cdn.test/removed.jpg" }] },
  }), false);
  harness.ready();
  assert.equal(harness.previewMessages.at(-1).payload.blockData.data.images[0].url, "https://cdn.test/new.jpg");
});

test("refresh retains text edited while its request was in flight", async () => {
  const harness = await createBridgeHarness({
    editorKey: "block_images", protocolKey: "block_images_signed",
    blockData: { name: "acf/images", data: { heading: "Gallery", images: [] } },
  });
  harness.refresh();
  harness.optimisticUpdate("block_images", ["heading"], "New heading", "test");
  harness.requests[0].complete({ data: { preview: previewHtml("block_images", "block_images_signed", {
    name: "acf/images", data: { heading: "Gallery", images: [{ url: "https://cdn.test/new.jpg" }] },
  }) } });
  harness.ready();
  const data = harness.previewMessages.at(-1).payload.blockData.data;
  assert.equal(data.heading, "New heading");
  assert.equal(data.images[0].url, "https://cdn.test/new.jpg");
});

test("failed refresh leaves the button available to retry", async () => {
  const harness = await createBridgeHarness({
    editorKey: "block_images", protocolKey: "block_images_signed",
    blockData: { name: "acf/images", data: { images: [] } },
  });
  harness.refresh();
  harness.requests[0].reject();
  assert.equal(harness.button.disabled, false);
  assert.equal(harness.button.attributes.has("aria-busy"), false);
  harness.refresh();
  assert.equal(harness.requests.length, 2);
});

test("sends an optimistic ACF field update when the signed and editor preview keys differ", async () => {
  const editorKey = "block_11111111-2222-3333-4444-555555555555";
  const protocolKey = "block_server_generated_hash";
  const harness = await createBridgeHarness({
    editorKey,
    protocolKey,
    blockData: {
      name: "acf/hero",
      data: { h1: "About Us" },
    },
  });

  harness.optimisticUpdate(editorKey, ["h1"], "About Us updated", "test");
  harness.flushTimers();

  const update = harness.previewMessages.find(
    ({ payload }) => payload.type === "cloakwp-preview-update",
  );
  assert.ok(update);
  assert.equal(update.targetOrigin, "https://frontend.test");
  assert.equal(update.payload.previewKey, protocolKey);
  assert.equal(
    update.payload.blockData.data.h1,
    "About Us updated",
  );
});

test("uses a compact initial height for ordinary blocks", async () => {
  const harness = await createBridgeHarness({
    editorKey: "block_eyebrow",
    protocolKey: "block_eyebrow_signed",
    blockData: {
      name: "acf/eyebrow",
      data: { eyebrow_text: "Services" },
    },
  });

  assert.equal(harness.iframe.style.height, "150px");
  assert.equal(harness.iframe.parentNode.style.height, "150px");
  assert.equal(
    harness.readyMessages.at(-1).payload.previewUsesViewportHeight,
    false,
  );
});

test("uses the editor viewport height only for opted-in blocks", async () => {
  const harness = await createBridgeHarness({
    editorKey: "block_hero",
    protocolKey: "block_hero_signed",
    blockData: {
      name: "acf/hero",
      data: { h1: "About Us" },
    },
    previewInitialHeight: "viewport",
  });

  assert.equal(harness.iframe.style.height, "730px");
  assert.equal(harness.iframe.parentNode.style.height, "730px");
  assert.equal(
    harness.readyMessages.at(-1).payload.previewUsesViewportHeight,
    true,
  );
});

test("requests height again when the initial observer report is missed", async () => {
  const harness = await createBridgeHarness({
    editorKey: "block_delayed",
    protocolKey: "block_delayed_signed",
    blockData: {
      name: "acf/eyebrow",
      data: { eyebrow_text: "Delayed" },
    },
  });

  harness.flushTimers();

  const heightRequests = harness.previewMessages.filter(
    ({ payload }) => payload.type === "cloakwp-preview-get-height",
  );
  assert.equal(heightRequests.length, 3);
  assert.ok(
    heightRequests.every(
      ({ payload }) => payload.previewKey === "block_delayed_signed",
    ),
  );
});
