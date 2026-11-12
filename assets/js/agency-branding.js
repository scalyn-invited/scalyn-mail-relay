/* Native WordPress media selection. Saving still requires the protected PHP form. */
(function () {
  'use strict';
  function init() {
    bindPicker('logo', 'logo_id');
    bindPicker('icon', 'menu_icon_id');
  }
  function bindPicker(kind, field) {
    const choose = document.getElementById('scalyn-' + kind + '-choose');
    const clear = document.getElementById('scalyn-' + kind + '-clear');
    const input = document.getElementById('scalyn-brand-' + field);
    const feedback = document.getElementById('scalyn-' + kind + '-feedback');
    if (!choose || !clear || !input || !feedback) return;
    let frame;
    choose.addEventListener('click', function () {
      if (!window.wp || typeof window.wp.media !== 'function') return;
      if (!frame) {
        frame = window.wp.media({
          title: choose.dataset.title,
          button: { text: choose.dataset.select },
          library: { type: 'image' },
          multiple: false,
        });
        frame.on('select', function () {
          const selected = frame.state().get('selection').first();
          const attachment = selected ? selected.toJSON() : null;
          if (!attachment || !Number.isInteger(attachment.id) || attachment.id < 1 ||
            !['image/png', 'image/jpeg', 'image/webp'].includes(attachment.mime)) {
            feedback.textContent = choose.dataset.invalid;
            return;
          }
          input.value = String(attachment.id);
          feedback.textContent = choose.dataset.selected;
        });
      }
      frame.open();
    });
    clear.addEventListener('click', function () {
      input.value = '0';
      feedback.textContent = clear.dataset.cleared;
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
