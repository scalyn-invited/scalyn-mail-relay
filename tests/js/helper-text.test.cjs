const assert = require('node:assert/strict');
const { test } = require('node:test');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').resolve(__dirname, '../../assets/js/helper-text.js'), 'utf8');

function setup() {
  const events = {}, clicks = {}, docEvents = {};
  const document = { readyState: 'complete', activeElement: null, querySelectorAll: () => [help], addEventListener: (key, fn) => { docEvents[key] = fn; } };
  const button = { getBoundingClientRect: () => ({ left: 350, top: 600, bottom: 620 }), addEventListener: (key, fn) => { clicks[key] = fn; }, focus() { document.activeElement = button; } };
  const content = { style: {}, getBoundingClientRect: () => ({ width: 320, height: 150 }) };
  const help = { open: false, querySelector: selector => selector === 'summary' ? button : content, contains: el => el === button, addEventListener: (key, fn) => { events[key] = fn; } };
  const window = { innerWidth: 390, innerHeight: 700, addEventListener() {} };
  vm.runInNewContext(source, { document, window });
  return { help, button, content, events, clicks, docEvents, document };
}
test('help opens on hover and focus, closes when leaving', () => {
  const ui = setup();
  ui.events.mouseenter(); assert.equal(ui.help.open, true);
  assert.equal(ui.content.style.position, 'fixed');
  assert.equal(ui.content.style.left, '54px');
  assert.equal(ui.content.style.top, '450px');
  ui.events.mouseleave(); assert.equal(ui.help.open, false);
  ui.document.activeElement = ui.button;
  ui.events.focusin(); ui.events.mouseleave(); assert.equal(ui.help.open, true);
  ui.events.focusout({ relatedTarget: null }); assert.equal(ui.help.open, false);
});
test('click pins help; repeat click, Escape and outside click dismiss it', () => {
  const ui = setup();
  const event = { preventDefault() {} };
  ui.clicks.click(event); ui.events.mouseleave(); assert.equal(ui.help.open, true);
  ui.clicks.click(event); assert.equal(ui.help.open, false);
  ui.clicks.click(event); ui.events.keydown({ key: 'Escape', ...event });
  assert.equal(ui.help.open, false); assert.equal(ui.document.activeElement, ui.button);
  ui.clicks.click(event); ui.docEvents.click({ target: {} }); assert.equal(ui.help.open, false);
});
