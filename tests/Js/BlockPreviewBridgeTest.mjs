import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { test } from "node:test";
import vm from "node:vm";

const bridgePath = new URL("../../js/block-preview.js", import.meta.url);

function previewHtml(editorKey, protocolKey, blockData) {
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

async function createBridgeHarness({ editorKey, protocolKey, blockData }) {
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
      return name === "data-cloakwp-preview-origin"
        ? "https://frontend.test"
        : null;
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
  renderPreview(previewHtml(editorKey, protocolKey, blockData), true);

  assert.equal(messageListeners.length, 1);
  messageListeners[0]({
    data: {
      type: "cloakwp-preview-ready",
      previewKey: protocolKey,
    },
    origin: "https://frontend.test",
    source: previewWindow,
  });

  previewMessages.length = 0;

  return {
    flushTimers() {
      while (timers.length) timers.shift()();
    },
    optimisticUpdate: context.__cloakwpPreviewTest.applyOptimisticPathUpdate,
    previewMessages,
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

  assert.equal(harness.previewMessages.length, 1);
  assert.equal(harness.previewMessages[0].targetOrigin, "https://frontend.test");
  assert.equal(harness.previewMessages[0].payload.previewKey, protocolKey);
  assert.equal(
    harness.previewMessages[0].payload.blockData.data.h1,
    "About Us updated",
  );
});
