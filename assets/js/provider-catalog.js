/* Read-only provider navigation. Existing protected wizard actions stay unchanged. */
(function () {
  'use strict';
  function init() {
    const catalog = document.querySelector('.scalyn-provider-catalog');
    const grid = document.querySelector('.scalyn-provider-grid');
    const dialog = document.getElementById('scalyn-provider-details');
    if (!catalog || !grid || !dialog || typeof dialog.showModal !== 'function') return;
    const tiles = Array.from(catalog.querySelectorAll('[data-provider-index]'));
    const cards = Array.from(grid.querySelectorAll('.scalyn-provider-card'));
    if (tiles.length !== cards.length) return;
    const content = dialog.querySelector('[data-provider-detail-content]');
    const close = dialog.querySelector('[data-provider-close]');
    let opener, card, marker, previousOverflow;
    tiles.forEach((tile, index) => tile.addEventListener('click', () => {
      opener = tile;
      card = cards[index];
      marker = document.createComment('provider-card');
      grid.insertBefore(marker, card);
      content.appendChild(card);
      document.getElementById('scalyn-provider-details-title').textContent = card.querySelector('h2').textContent;
      previousOverflow = document.body.style.overflow;
      document.body.style.overflow = 'hidden';
      dialog.showModal();
      close.focus();
    }));
    close.addEventListener('click', () => dialog.close());
    dialog.addEventListener('click', event => {
      if (event.target !== dialog) return;
      const box = dialog.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) dialog.close();
    });
    dialog.addEventListener('close', () => {
      if (card && marker) {
        card.querySelectorAll('.scalyn-help-text').forEach(help => { help.open = false; });
        grid.insertBefore(card, marker);
        marker.remove();
      }
      document.body.style.overflow = previousOverflow;
      if (opener && opener.isConnected) opener.focus();
    });
    // Tab/hash navigation cannot leave a hidden panel's modal open.
    window.addEventListener('hashchange', () => { if (dialog.open) dialog.close(); });
    grid.hidden = true;
    catalog.hidden = false;
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
