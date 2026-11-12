const assert = require('node:assert/strict');
const { test } = require('node:test');
const { readFileSync } = require('node:fs');
const { resolve } = require('node:path');
const vm = require('node:vm');

const source = readFileSync(resolve(__dirname, '../../assets/js/admin.js'), 'utf8');

test('report provider is enabled and required only for specific scope without losing its selection', () => {
  let change;
  const scope = { value: 'all', addEventListener(name, callback) { assert.equal(name, 'change'); change = callback; } };
  const provider = { value: 'brevo' };
  const elements = { 'report-provider-scope': scope, 'report-provider': provider };
  vm.runInNewContext(source, { document: { readyState: 'complete', getElementById: id => elements[id], querySelector: () => null } });
  assert.equal(provider.disabled, true); assert.equal(provider.required, false);
  scope.value = 'specific'; change();
  assert.equal(provider.disabled, false); assert.equal(provider.required, true);
  scope.value = 'unattributed'; change();
  assert.equal(provider.disabled, true); assert.equal(provider.required, false);
  assert.equal(provider.value, 'brevo');
});

test('audit page size automatically submits only on change and scrolls to records', () => {
  let change, submissions = 0;
  const form = { action: 'https://site.test/wp-admin/admin.php', requestSubmit() { submissions++; } };
  const select = { form, addEventListener(name, handler) { assert.equal(name, 'change'); change = handler; } };
  vm.runInNewContext(source, { URL, document: { readyState: 'complete', getElementById: id => id === 'scalyn-audit-per-page' ? select : null, querySelector: () => null } });
  assert.equal(submissions, 0); change();
  assert.equal(submissions, 1);
  assert.equal(new URL(form.action).hash, '#scalyn-audit-records');
});

test('page size submits its associated filter form only on change', () => {
  let change, submissions = 0;
  const form = { action: 'https://site.test/wp-admin/admin.php', requestSubmit() { submissions++; } };
  const select = { form, addEventListener(name, handler) { assert.equal(name, 'change'); change = handler; } };
  vm.runInNewContext(source, { URL, document: { readyState: 'complete', getElementById: id => id === 'scalyn-filter-per-page' ? select : null, querySelector: () => null } });
  assert.equal(submissions, 0);
  change();
  assert.equal(submissions, 1);
  assert.equal(new URL(form.action).hash, '#scalyn-log-results-heading');
});

test('routing toggle disables rules without losing draft selections or saving', () => {
  let change;
  const toggle = { checked: false, addEventListener(name, callback) { assert.equal(name, 'change'); change = callback; } };
  const rules = { disabled: false, draft: 'saved-configuration' };
  const notice = { hidden: true };
  const elements = { 'scalyn-routing-enabled': toggle, 'scalyn-routing-rules': rules, 'scalyn-routing-disabled': notice };
  vm.runInNewContext(source, { document: { readyState: 'complete', getElementById: id => elements[id], querySelector: () => null } });
  assert.equal(rules.disabled, true);
  assert.equal(notice.hidden, false);
  toggle.checked = true; change();
  assert.equal(rules.disabled, false);
  assert.equal(notice.hidden, true);
  toggle.checked = false; change();
  assert.equal(rules.disabled, true);
  assert.equal(rules.draft, 'saved-configuration');
});

test('wizard health check posts with nonce and returns to step six', async () => {
  let activate, request, refreshed;
  const button = {
    dataset: { scalynAction: 'run-diagnostics', endpoint: 'https://site.test/wp-json/scalyn-mail-relay/v1/diagnostics/run' },
    disabled: false, textContent: 'Run Health Check',
    setAttribute() {}, addEventListener(name, callback) { activate = callback; },
  };
  const current = 'https://site.test/wp-admin/admin.php?page=scalyn-mail-relay-wizard&step=6';
  vm.runInNewContext(source, {
    document: { readyState: 'complete', getElementById: () => button, querySelector: () => null },
    URL, AbortController,
    fetch: async (url, options) => { request = { url, options }; return { ok: true, text: async () => '{"success":true}' }; },
    window: { scalynMailRelaySettings: { restNonce: 'test-nonce' }, location: { href: current, replace: url => { refreshed = url; } }, AbortController, setTimeout, clearTimeout },
  });
  activate({ preventDefault() {} });
  assert.equal(button.disabled, true);
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(request.options.method, 'POST');
  assert.equal(request.options.headers['X-WP-Nonce'], 'test-nonce');
  assert.equal(new URL(refreshed).searchParams.get('step'), '6');
  assert.equal(new URL(refreshed).searchParams.get('page'), 'scalyn-mail-relay-wizard');
});

// Small DOM doubles exercise request/fallback behavior. Actual dialog focus,
// keyboard semantics and layout are checked in WordPress during manual QA.
function setup(fetchResponse = async () => ({ ok: true, text: async () => 'safe view' }), supported = true) {
  const events = {};
  const content = { children: [], replaceChildren(...children) { this.children = children; } };
  const loading = { hidden: true };
  const error = { hidden: true };
  const fullPage = {};
  const close = { addEventListener(name, handler) { events.closeClick = handler; } };
  const dialog = {
    open: false,
    showModal: supported ? function () { this.open = true; } : undefined,
    close() { this.open = false; events.close(); },
    addEventListener(name, handler) { events[name] = handler; },
    querySelector(selector) {
      return {
        '[data-scalyn-timeline-content]': content,
        '[data-scalyn-loading]': loading,
        '[data-scalyn-load-error]': error,
        '[data-scalyn-full-page]': fullPage,
        '[data-scalyn-close]': close,
      }[selector];
    },
  };
  const link = {
    href: 'https://site.test/wp-admin/admin.php?page=scalyn-mail-relay-logs&message_uuid=example',
    isConnected: true,
    focused: false,
    focus() { this.focused = true; },
    addEventListener(name, handler) { events.click = handler; },
  };
  let stripped;
  const document = {
    readyState: 'complete',
    getElementById() { return null; },
    querySelector() { return dialog; },
    querySelectorAll() { return [link]; },
    importNode(node) { return node; },
  };
  class Parser {
    parseFromString(text) {
      return { querySelector: () => text === 'login' ? null : {
        children: [{ textContent: text }],
        querySelectorAll(selector) { stripped = selector; return []; },
      } };
    }
  }
  vm.runInNewContext(source, {
    document, URL, AbortController, DOMParser: Parser,
    fetch: fetchResponse,
    window: { location: { href: 'https://site.test/wp-admin/admin.php', origin: 'https://site.test' }, AbortController, setTimeout, clearTimeout },
  });
  return { events, dialog, link, content, loading, error, fullPage, stripped: () => stripped };
}

function click(overrides = {}) {
  return { button: 0, prevented: false, preventDefault() { this.prevented = true; }, ...overrides };
}

test('unsupported dialogs preserve ordinary timeline navigation', () => {
  assert.equal(setup(undefined, false).events.click, undefined);
});

test('modified clicks and other origins are not intercepted', async () => {
  const ui = setup(() => assert.fail('Must not fetch'));
  for (const modifier of [{ ctrlKey: true }, { metaKey: true }, { shiftKey: true }, { altKey: true }, { button: 1 }]) {
    const event = click(modifier);
    await ui.events.click(event);
    assert.equal(event.prevented, false);
  }
  ui.link.href = 'https://other.test/admin.php';
  const event = click();
  await ui.events.click(event);
  assert.equal(event.prevented, false);
});

test('loads the protected view without caching and strips non-content nodes', async () => {
  const ui = setup(async (url, options) => {
    assert.equal(options.credentials, 'same-origin');
    assert.equal(options.cache, 'no-store');
    assert.equal(options.signal.aborted, false);
    return { ok: true, text: async () => 'Accepted is not delivery' };
  });
  const event = click();
  await ui.events.click(event);
  assert.equal(event.prevented, true);
  assert.equal(ui.dialog.open, true);
  assert.equal(ui.loading.hidden, true);
  assert.equal(ui.error.hidden, true);
  assert.equal(ui.content.children[0].textContent, 'Accepted is not delivery');
  assert.equal(ui.fullPage.href, ui.link.href);
  assert.match(ui.stripped(), /script/);
  assert.match(ui.stripped(), /data-scalyn-full-page-only/);
  ui.events.closeClick();
  assert.equal(ui.dialog.open, false);
  assert.equal(ui.link.focused, true);
  assert.equal(ui.content.children.length, 0);
});

test('HTTP errors, login redirects and missing views offer the full-page fallback', async () => {
  for (const response of [{ ok: false }, { ok: true, redirected: true }, { ok: true, text: async () => 'login' }]) {
    const ui = setup(async () => response);
    await ui.events.click(click());
    assert.equal(ui.error.hidden, false);
    assert.equal(ui.loading.hidden, true);
    assert.equal(ui.content.children.length, 0);
    assert.equal(ui.fullPage.href, ui.link.href);
  }
});

test('network failure does not put exception text in the dialog', async () => {
  const ui = setup(async () => { throw new Error('private server response'); });
  await ui.events.click(click());
  assert.equal(ui.error.hidden, false);
  assert.equal(ui.content.children.length, 0);
});

test('closing a pending request aborts it and prevents stale content being displayed', async () => {
  let finish;
  let signal;
  const ui = setup((url, options) => {
    signal = options.signal;
    return new Promise(resolve => { finish = resolve; });
  });
  const pending = ui.events.click(click());
  ui.events.closeClick();
  assert.equal(signal.aborted, true);
  finish({ ok: true, text: async () => 'late response' });
  await pending;
  assert.equal(ui.content.children.length, 0);
  assert.equal(ui.dialog.open, false);
});
