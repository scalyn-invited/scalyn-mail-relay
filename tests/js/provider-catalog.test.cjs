const { test } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync(require('node:path').resolve(__dirname, '../../assets/js/provider-catalog.js'), 'utf8');
function setup(supported = true) {
  function element(extra = {}) {
    return Object.assign({ hidden: false, events: {}, addEventListener(n, f) { this.events[n] = f; }, focus() { this.focused = true; }, isConnected: true }, extra);
  }
  const tiles = ['SMTP', 'Brevo'].map((name, index) => element({ dataset: { search: name, configured: index ? 'configured' : 'unconfigured' } }));
  const cards = ['SMTP', 'Brevo'].map(name => element({ querySelector: () => ({ textContent: name }), querySelectorAll: () => [] }));
  const grid = element({ querySelectorAll: () => cards, insertBefore(node) { node.parent = 'grid'; } });
  const catalog = element({ hidden: true, querySelectorAll: () => tiles });
  const close = element();
  const content = { appendChild(node) { node.parent = 'dialog'; } };
  const dialog = element({ open: false, querySelector: selector => selector.includes('close') ? close : content, showModal: supported ? function() { this.open = true; } : undefined, close() { this.open = false; this.events.close(); }, getBoundingClientRect: () => ({ left: 500, right: 1000, top: 32, bottom: 800 }) });
  const search = element({ value: '' }), filter = element({ value: 'all' });
  const count = {}, empty = {}, title = {};
  const elements = { 'scalyn-provider-details': dialog, 'scalyn-provider-search': search, 'scalyn-provider-filter': filter, 'scalyn-provider-count': count, 'scalyn-provider-count-label': {textContent:'Providers shown'}, 'scalyn-provider-no-results': empty, 'scalyn-provider-details-title': title };
  const body = { style: {overflow: 'auto'} }, window = element();
  const document = { readyState:'complete', body, getElementById: id => elements[id], querySelector: selector => selector.includes('catalog') ? catalog : grid, createComment: () => ({remove() {this.removed = true;}}) };
  vm.runInNewContext(source, { document, window });
  return { tiles, cards, grid, catalog, dialog, close, search, filter, count, empty, title, body, window };
}
test('catalogue initializes with all tiles visible without filter controls', () => {
  const s = setup();
  assert.equal(s.catalog.hidden, false); assert.equal(s.grid.hidden, true);
  assert.ok(s.tiles.every(tile => !tile.hidden));
  assert.deepEqual(s.search.events, {});
  assert.deepEqual(s.filter.events, {});
});
test('details move the original card and restore it and focus on close', () => {
  const s = setup(); s.tiles[1].events.click();
  assert.equal(s.dialog.open, true); assert.equal(s.cards[1].parent, 'dialog');
  assert.equal(s.title.textContent, 'Brevo'); assert.equal(s.close.focused, true);
  assert.equal(s.body.style.overflow, 'hidden');
  s.close.events.click();
  assert.equal(s.cards[1].parent, 'grid'); assert.equal(s.tiles[1].focused, true);
  assert.equal(s.body.style.overflow, 'auto');
});
test('backdrop and hash navigation dismiss the panel', () => {
  const s = setup(); s.tiles[0].events.click();
  s.dialog.events.click({ target:s.dialog, clientX:100, clientY:100 });
  assert.equal(s.dialog.open, false);
  s.tiles[1].events.click(); s.window.events.hashchange(); assert.equal(s.dialog.open, false);
});
test('unsupported dialogs retain visible full cards and no unusable grid', () => {
  const s = setup(false); assert.equal(s.grid.hidden, false); assert.equal(s.catalog.hidden, true);
});
