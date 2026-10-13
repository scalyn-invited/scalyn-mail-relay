/* Presentation-only navigation; all writes remain in the protected PHP forms. */
(function () {
  'use strict';
  function init() {
    const list = document.getElementById('scalyn-provider-tabs');
    if (!list) return;
    const tabs = Array.from(list.querySelectorAll('[data-provider-tab]'));
    const panels = tabs.map(tab => document.getElementById('scalyn-panel-' + tab.dataset.providerTab));
    if (!tabs.length || panels.some(panel => !panel)) return;
    const sharedForms = Array.from(document.querySelectorAll('[data-tab-shared-form]'));
    list.setAttribute('role', 'tablist');
    tabs.forEach((tab, index) => {
      tab.setAttribute('role', 'tab');
      tab.setAttribute('aria-controls', panels[index].id);
      panels[index].setAttribute('role', 'tabpanel');
      panels[index].setAttribute('aria-labelledby', tab.id);
    });
    function activate(key, focus) {
      let selected = tabs.findIndex(tab => tab.dataset.providerTab === key);
      if (selected < 0) selected = 0;
      const keySelected = tabs[selected].dataset.providerTab;
      tabs.forEach((tab, index) => {
        const active = index === selected;
        tab.setAttribute('aria-selected', String(active));
        tab.tabIndex = active ? 0 : -1;
        if (!active && panels[index].contains(document.activeElement)) focus = true;
        panels[index].hidden = !active;
      });
      sharedForms.forEach(form => { form.hidden = !form.dataset.tabSharedForm.split(' ').includes(keySelected); });
      if (focus) tabs[selected].focus();
    }
    function fromLocation() {
      const key = window.location.hash.slice(1);
      const target = document.getElementById(key);
      const containing = target ? panels.findIndex(panel => panel.contains(target)) : -1;
      activate(containing >= 0 ? tabs[containing].dataset.providerTab : key, false);
    }
    function select(tab) {
      const hash = '#' + tab.dataset.providerTab;
      if (window.location.hash !== hash) window.history.pushState(null, '', hash);
      activate(tab.dataset.providerTab, true);
    }
    tabs.forEach((tab, index) => {
      tab.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        select(tab);
      });
      tab.addEventListener('keydown', event => {
        let next;
        if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
        else if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = tabs.length - 1;
        else if (event.key === ' ') next = index;
        else return;
        event.preventDefault();
        select(tabs[next]);
      });
    });
    window.addEventListener('hashchange', fromLocation);
    window.addEventListener('popstate', fromLocation);
    sharedForms.forEach(form => {
      // Hidden fields stay enabled and submitted as part of the existing atomic form.
      form.addEventListener('invalid', event => {
        const index = panels.findIndex(panel => panel.contains(event.target));
        if (index >= 0) select(tabs[index]);
      }, true);
      form.addEventListener('submit', () => {
        const action = new URL(form.action, window.location.href);
        action.hash = window.location.hash;
        form.action = action.toString();
      });
    });
    fromLocation();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
}());
