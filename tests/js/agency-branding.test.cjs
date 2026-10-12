const assert = require('node:assert/strict');
const { test } = require('node:test');
const { readFileSync } = require('node:fs');
const vm = require('node:vm');
const source = readFileSync(require('node:path').resolve(__dirname, '../../assets/js/agency-branding.js'), 'utf8');

function setup(attachment) {
  const callbacks = {};
  const input = { value: '0' }, feedback = { textContent: '' };
  const choose = { dataset: { title: 'Choose logo', select: 'Use logo', invalid: 'Invalid image', selected: 'Save to apply' }, addEventListener: (name, fn) => { callbacks.choose = fn; } };
  const clear = { dataset: { cleared: 'Removed; save to apply' }, addEventListener: (name, fn) => { callbacks.clear = fn; } };
  const elements = { 'scalyn-logo-choose': choose, 'scalyn-logo-clear': clear, 'scalyn-brand-logo_id': input, 'scalyn-logo-feedback': feedback };
  let opens = 0, frames = 0;
  const frame = { on: (name, fn) => { callbacks.select = fn; }, open: () => { opens++; }, state: () => ({ get: () => ({ first: () => attachment ? ({ toJSON: () => attachment }) : null }) }) };
  vm.runInNewContext(source, { document: { readyState: 'complete', getElementById: id => elements[id] }, window: { wp: { media: opts => { assert.equal(opts.multiple, false); frames++; return frame; } } } });
  return { callbacks, input, feedback, opens: () => opens, frames: () => frames };
}

test('raster selection updates only the attachment ID; picker is reused', () => {
  const ui = setup({ id: 42, mime: 'image/png', title: '<script>not rendered</script>' });
  ui.callbacks.choose(); ui.callbacks.select(); ui.callbacks.choose();
  assert.equal(ui.input.value, '42'); assert.equal(ui.feedback.textContent, 'Save to apply');
  assert.equal(ui.frames(), 1); assert.equal(ui.opens(), 2);
  ui.callbacks.clear(); assert.equal(ui.input.value, '0'); assert.equal(ui.feedback.textContent, 'Removed; save to apply');
});
test('active and malformed media is rejected without changing the logo', () => {
  for (const attachment of [{ id: 42, mime: 'image/svg+xml' }, { id: -1, mime: 'image/png' }, { id: '42', mime: 'image/png' }, null]) {
    const ui = setup(attachment); ui.callbacks.choose(); ui.callbacks.select();
    assert.equal(ui.input.value, '0'); assert.equal(ui.feedback.textContent, 'Invalid image');
  }
});
test('unrelated pages do not initialize media UI', () => {
  vm.runInNewContext(source, { document: { readyState: 'complete', getElementById: () => null } });
});
