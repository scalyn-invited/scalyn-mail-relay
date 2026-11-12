const assert = require('node:assert/strict');
const { test } = require('node:test');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').resolve(__dirname, '../../assets/js/provider-tabs.js'), 'utf8');

function setup(hash = '', keys = ['providers', 'email-routing', 'saved-configurations'], loading = false, shared = false) {
  const events = {}, ready = {}, history = [];
  const document = { readyState: loading ? 'loading' : 'complete', activeElement: null, querySelectorAll: () => [], addEventListener: (name, fn) => { ready[name] = fn; } };
  const element = id => ({ id, attrs: {}, events: {}, setAttribute(name, value) { this.attrs[name] = value; }, addEventListener(name, fn) { this.events[name] = fn; }, focus() { document.activeElement = this; } });
  const tabs = keys.map(key => Object.assign(element('scalyn-tab-' + key), { dataset: { providerTab: key } }));
  const panels = keys.map(key => Object.assign(element('scalyn-panel-' + key), { draft: 'unsaved', contains: value => value === key }));
  const list = Object.assign(element('scalyn-provider-tabs'), { querySelectorAll: () => tabs });
  document.getElementById = id => id === list.id ? list : id === 'legacy-anchor' ? keys[1] : panels.find(panel => panel.id === id);
  const form = Object.assign(element('shared'), { dataset: { tabSharedForm: keys.slice(1).join(' ') }, action: 'http://example.test/admin.php?page=settings' });
  document.querySelectorAll = () => shared ? [form] : [];
  const window = { location: { hash, href: 'http://example.test/admin.php?page=settings' }, history: { pushState: (state, title, value) => { history.push(value); window.location.hash = value; } }, addEventListener: (name, fn) => { events[name] = fn; } };
  vm.runInNewContext(source, { document, window, URL });
  const fire = (index, name = 'click', extra = {}) => { const event = { button: 0, preventDefault() { this.prevented = true; }, ...extra }; tabs[index].events[name](event); return event; };
  return { tabs, panels, form, list, window, document, events, history, ready, fire };
}

test('legacy anchors select their containing panel', () => {
  const ui = setup('#legacy-anchor');
  assert.equal(ui.panels[1].hidden, false);
});
test('shared settings form stays intact, retains its fields and returns to the current tab', () => {
  const ui = setup('', ['dkim', 'privacy-retention', 'monitoring'], false, true);
  assert.equal(ui.form.hidden, true);
  ui.fire(2);
  assert.equal(ui.form.hidden, false);
  assert.equal(ui.panels[1].draft, 'unsaved');
  ui.form.events.submit();
  assert.equal(ui.form.action, 'http://example.test/admin.php?page=settings#monitoring');
  ui.form.events.invalid({ target: 'privacy-retention' });
  assert.equal(ui.panels[1].hidden, false);
  assert.equal(ui.window.location.hash, '#privacy-retention');
});

test('default and unknown hashes select Providers; deep links select their panel', () => {
  for (const [hash, index] of [['', 0], ['#unknown', 0], ['#providers', 0], ['#email-routing', 1], ['#saved-configurations', 2]]) {
    const ui = setup(hash);
    assert.equal(ui.list.attrs.role, 'tablist');
    ui.tabs.forEach((tab, n) => {
      assert.equal(tab.attrs['aria-selected'], String(n === index));
      assert.equal(tab.tabIndex, n === index ? 0 : -1);
      assert.equal(ui.panels[n].hidden, n !== index);
      assert.equal(tab.attrs['aria-controls'], ui.panels[n].id);
      assert.equal(ui.panels[n].attrs['aria-labelledby'], tab.id);
    });
    assert.equal(ui.history.length, 0);
  }
});
test('clicks update history without rebuilding forms or discarding drafts', () => {
  const ui = setup();
  assert.equal(ui.fire(1).prevented, true);
  ui.fire(1); ui.fire(2); ui.fire(0);
  assert.deepEqual(ui.history, ['#email-routing', '#saved-configurations', '#providers']);
  assert.equal(ui.panels[1].draft, 'unsaved');
  assert.equal(ui.document.activeElement, ui.tabs[0]);
});
test('Back/Forward and external hash changes restore selection', () => {
  const ui = setup();
  ui.fire(2);
  ui.window.location.hash = '#email-routing'; ui.events.popstate();
  assert.equal(ui.panels[1].hidden, false);
  ui.window.location.hash = '#providers'; ui.events.hashchange();
  assert.equal(ui.panels[0].hidden, false);
});
test('keyboard navigation wraps, focuses and supports Home End and Space', () => {
  const ui = setup();
  for (const [from, key, to] of [[0, 'ArrowLeft', 2], [2, 'ArrowRight', 0], [0, 'End', 2], [2, 'Home', 0], [1, ' ', 1]]) {
    assert.equal(ui.fire(from, 'keydown', { key }).prevented, true);
    assert.equal(ui.document.activeElement, ui.tabs[to]);
    assert.equal(ui.panels[to].hidden, false);
  }
});
test('modified clicks and unrelated keys retain native behavior', () => {
  const ui = setup();
  assert.equal(ui.fire(1, 'click', { ctrlKey: true }).prevented, undefined);
  assert.equal(ui.fire(1, 'keydown', { key: 'Tab' }).prevented, undefined);
  assert.equal(ui.history.length, 0);
});
test('restricted users fall back to their available Providers tab', () => {
  const ui = setup('#email-routing', ['providers']);
  assert.equal(ui.panels[0].hidden, false);
  assert.equal(ui.tabs[0].attrs['aria-selected'], 'true');
});
test('deferred initialization and unrelated pages are safe', () => {
  const ui = setup('', undefined, true);
  assert.equal(ui.list.attrs.role, undefined);
  ui.ready.DOMContentLoaded();
  assert.equal(ui.list.attrs.role, 'tablist');
  vm.runInNewContext(source, { document: { readyState: 'complete', getElementById: () => null } });
});
