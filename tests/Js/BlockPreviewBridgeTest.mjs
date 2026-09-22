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
  const previewMessages = [];

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
    addEventListener() {},
  };
  iframe.ownerDocument = document;

  const window = {
    document,
    innerHeight: 900,
    location: { href: "https://wp.test/wp-admin/post.php?post=13" },
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
    addAction() {},
  };

  const source = await readFile(bridgePath, "utf8");
  const instrumentedSource = source.replace(
    /\}\)\(\);\s*$/,
    `
      globalThis.__cloakwpPreviewTest = {
        applyOptimisticPathUpdate,
      };
    })();
    `,
  );
  assert.notEqual(instrumentedSource, source, "bridge test instrumentation failed");

  const context = vm.createContext({
    URL,
    HTMLIFrameElement: FakeIframe,
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
    optimisticUpdate: context.__cloakwpPreviewTest.applyOptimisticPathUpdate,
    previewMessages,
    readyMessages,
  };
}

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
