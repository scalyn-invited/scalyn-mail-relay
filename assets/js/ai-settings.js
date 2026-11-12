/* Model-first presentation. PHP independently enforces every setup requirement. */
(function () {
  'use strict';
  function init() {
    const model=document.querySelector('[data-ai-model]');
    if(!model) return;
    const panels=Array.from(document.querySelectorAll('[data-ai-provider-settings]'));
    const enable=document.querySelector('[data-ai-enable]');
    const verify=document.querySelector('[data-ai-verify]');
    const status=document.querySelector('[data-ai-setup-status]');
    function update(event) {
      const option=model.selectedOptions[0];
      const provider=option ? option.dataset.aiProvider : '';
      let ready=false, saved=false;
      for(const panel of panels) {
        const active=panel.dataset.aiProviderSettings===provider;
        panel.hidden=!active; panel.disabled=!active;
        if(!active) continue;
        const key=panel.querySelector('[data-ai-key]');
        const remove=panel.querySelector('[data-ai-remove]');
        const consent=panel.querySelector('[data-ai-consent]');
        const verified=JSON.parse(panel.dataset.verifiedModels);
        saved=panel.dataset.keySaved==='1' && !remove.checked && !key.value;
        ready=saved && consent.checked && verified.includes(model.value) && model.dataset.encryption==='1';
      }
      if(event && event.target===model) enable.checked=false;
      enable.disabled=!ready;
      if(!ready) enable.checked=false;
      verify.disabled=!saved || model.dataset.encryption!=='1';
      status.textContent=ready ? 'Selected model access and provider consent are verified. Explicitly enable chat and save. Changing providers requires a new chat.' : 'Chat stays disabled until this provider key is saved, selected model access is verified and provider consent is recorded. Save setup first, then verify access. Model verification does not prove available inference quota.';
    }
    model.addEventListener('change',update);
    for(const panel of panels) for(const input of panel.querySelectorAll('input')) input.addEventListener('input',update);
    update();
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
}());
