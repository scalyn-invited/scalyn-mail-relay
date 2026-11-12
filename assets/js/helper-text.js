/* Contextual help only; never saves settings or sends requests. */
(function () {
  'use strict';
  function init() {
    document.querySelectorAll('.scalyn-help-text').forEach(help => {
      const button = help.querySelector('summary');
      const content = help.querySelector('.scalyn-help-text__content');
      let pinned = false;
      function position() {
        if (!help.open) return;
        const anchor = button.getBoundingClientRect();
        content.style.position = 'fixed';
        content.style.right = 'auto';
        const box = content.getBoundingClientRect();
        const left = Math.max(16, Math.min(anchor.left, window.innerWidth - box.width - 16));
        const below = anchor.bottom;
        const top = below + box.height <= window.innerHeight - 16 ? below : Math.max(16, anchor.top - box.height);
        content.style.left = left + 'px';
        content.style.top = top + 'px';
      }
      function show() { help.open = true; position(); }
      help.addEventListener('mouseenter', show);
      help.addEventListener('mouseleave', () => {
        if (!pinned && !help.contains(document.activeElement)) help.open = false;
      });
      help.addEventListener('focusin', show);
      help.addEventListener('focusout', event => {
        if (!pinned && !help.contains(event.relatedTarget)) help.open = false;
      });
      button.addEventListener('click', event => {
        event.preventDefault();
        pinned = !pinned;
        help.open = pinned;
        position();
      });
      help.addEventListener('keydown', event => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        pinned = false;
        help.open = false;
        button.focus();
      });
      document.addEventListener('click', event => {
        if (help.contains(event.target)) return;
        pinned = false;
        help.open = false;
      });
      window.addEventListener('resize', position);
      window.addEventListener('scroll', position, true);
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
