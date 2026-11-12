/* Private administrator chat. No generated HTML, remote links or model actions. */
(function () {
  'use strict';
  function init() {
    const root = document.getElementById('scalyn-ai');
    if (!root) return;
    const list = root.querySelector('[data-ai-sessions]');
    const messages = root.querySelector('[data-ai-messages]');
    const status = root.querySelector('[data-ai-status]');
    const loading = root.querySelector('[data-ai-busy]');
    const compose = root.querySelector('[data-ai-compose]');
    const conversationProvider = root.querySelector('[data-ai-conversation-provider]');
    const renameDialog = root.querySelector('[data-ai-rename-dialog]');
    const renameForm = root.querySelector('[data-ai-rename-form]');
    const renameTitle = root.querySelector('[data-ai-rename-title]');
    const renameError = root.querySelector('[data-ai-rename-error]');
    const deleteDialog = root.querySelector('[data-ai-delete-dialog]');
    const deleteTitle = root.querySelector('[data-ai-delete-title]');
    const question = document.getElementById('scalyn-ai-question');
    let current = '', currentTitle = '', busy = false;
    const providerNames = {openai:'OpenAI',gemini:'Google Gemini',claude:'Anthropic Claude'};
    function providerLabel(id, selected=false) {
      const name=Object.hasOwn(providerNames,id) ? providerNames[id] : '';
      return name ? name+(selected?' (selected)':'') : '';
    }
    function sessionProvider(session) {
      if (Object.hasOwn(providerNames,session.provider)) return session.provider;
      return Array.isArray(session.messages) && session.messages.length ? 'openai' : '';
    }
    const failureMessages = {
      ai_new_chat:'This conversation belongs to another AI provider. Start a new chat before using the selected provider. Earlier messages were not shared.',
      ai_network:'Could not connect to OpenAI. Check the server connection and try again manually. No automatic retry was made.',
      ai_key:'OpenAI rejected API access. Check the saved API key and its project permissions in AI Settings.',
      ai_quota:'OpenAI API quota is unavailable. Check API billing, credits and project spending limits. A ChatGPT subscription does not provide API credits.',
      ai_rate:'OpenAI rate-limited this request (HTTP 429). Wait before trying again and check API project rate limits or quota.',
      ai_model:'OpenAI could not use the selected model. Refresh models in AI Settings and explicitly select an available model.',
      ai_remote:'OpenAI could not complete this request. Check API service availability and your project settings. No automatic retry was made.',
      ai_incomplete:'OpenAI reached the reply limit before completing an answer. Try a shorter, focused plugin question. No partial answer or automatic retry was accepted.'
    };
    function node(tag, text) { const el = document.createElement(tag); el.textContent = text; return el; }
    async function request(action, extra = {}) {
      const response = await fetch(root.dataset.endpoint, { method:'POST', credentials:'same-origin', cache:'no-store', headers:{'Content-Type':'application/json','X-WP-Nonce':root.dataset.nonce}, body:JSON.stringify({action, uuid:current, ...extra}), signal:AbortSignal.timeout(60000) });
      let result;
      try { result = await response.json(); } catch (_) { throw new Error('AI request unavailable. Reload to refresh authorization. No automatic retry was made.'); }
      if (!result || typeof result !== 'object') throw new Error('AI request unavailable. Reload to refresh authorization. No automatic retry was made.');
      if (!response.ok || !result.success) {
        const labels={openai:'OpenAI',gemini:'Google Gemini',claude:'Anthropic Claude'};
        const label=Object.hasOwn(labels,result.provider)?labels[result.provider]:'OpenAI';
        throw new Error(Object.hasOwn(failureMessages, result.error_code) ? failureMessages[result.error_code].replaceAll('OpenAI',label) : 'AI request unavailable. Check settings, limits and configuration; reload to refresh authorization. No automatic retry was made.');
      }
      return result.data;
    }
    async function refresh() {
      const data = await request('list');
      list.replaceChildren();
      root.querySelector('[data-ai-usage]').textContent = 'AI requests today (UTC): ' + data.usage + ' / 100';
      for (const session of data.sessions) {
        if (session.uuid === current) currentTitle = session.title;
        const label=providerLabel(session.provider || root.dataset.activeProvider, !session.provider);
        const button = node('button', session.title+(label?' · '+label:'')); button.type='button'; button.className='button'; button.setAttribute('aria-pressed', String(session.uuid === current));
        button.addEventListener('click', () => perform(async () => { current=session.uuid; render(await request('read')); await refresh(); }));
        list.appendChild(button);
      }
    }
    function render(session) {
      messages.replaceChildren();
      currentTitle=session.title;
      const provider=sessionProvider(session);
      conversationProvider.textContent=providerLabel(provider || root.dataset.activeProvider, !provider);
      for (const message of session.messages) {
        const box = node('article', ''); box.className='scalyn-ai-message';
        box.appendChild(node('h3', message.role === 'user' ? 'You' : 'Plugin assistant — advisory'));
        if (message.role === 'user') box.appendChild(node('p', message.text));
        else {
          const answer=message.answer;
          if (message.provider && message.model) box.appendChild(node('p', 'Answered by: ' + message.provider + ' · ' + message.model));
          for (const [key, label] of [['possible_causes','Explanation / possible causes'],['next_steps','Recommended steps'],['observed','Observed evidence']]) {
            if (answer[key]) { box.appendChild(node('h4',label)); box.appendChild(node('p',answer[key])); }
          }
          box.appendChild(node('p', 'Evidence captured (UTC): ' + message.captured_at_utc + ' · Revision: ' + message.revision + ' · Recorded answer, not live monitoring.'));
          if (message.context_truncated) box.appendChild(node('p','Earlier conversation context was omitted to stay within limits.'));
          const destinations={help:'providers',configuration:'providers',diagnostics:'diagnostics',health:'diagnostics',activity:'logs'};
          for (const ref of answer.references || []) {
            if (!destinations[ref]) continue;
            const link=node('a',ref); link.href='admin.php?page=scalyn-mail-relay-'+destinations[ref]; link.className='button'; box.appendChild(link);
          }
          if (answer.suggest_diagnostics) box.appendChild(node('p','A fresh diagnostic run may help. Review the Diagnostics page for current findings.'));
        }
        messages.appendChild(box);
      }
    }
    async function perform(work) {
      if (busy) return;
      busy=true; status.textContent='';
      messages.setAttribute('aria-busy','true'); loading.hidden=false;
      const controls=Array.from(root.querySelectorAll('[data-ai-action], [data-ai-sessions] button, [data-ai-compose] button'));
      const disabled=controls.map(el=>el.disabled); controls.forEach(el=>{el.disabled=true;});
      try { await work(); status.textContent='Ready.'; }
      catch (error) { status.textContent=error.name==='TimeoutError' ? 'Request timed out. Its outcome may be unknown; reload the conversation before manually trying again.' : error.message; }
      finally { controls.forEach((el,i)=>{el.disabled=disabled[i];}); loading.hidden=true; messages.setAttribute('aria-busy','false'); busy=false; }
    }
    function openDialog(dialog) {
      if (typeof dialog.showModal !== 'function') { status.textContent='Chat popups are unavailable in this browser. Use a browser with dialog support.'; return false; }
      dialog.showModal();
      return true;
    }
    root.querySelectorAll('[data-ai-action]').forEach(button=>button.addEventListener('click',()=>{
      const action=button.dataset.aiAction;
      if (action==='create') { perform(async()=>{ current=(await request('create')).uuid; render(await request('read')); await refresh(); }); return; }
      if (busy) return;
      if (!current) { status.textContent='Choose a conversation first.'; return; }
      if (action==='rename') {
        renameTitle.value=currentTitle;
        renameError.hidden=true;
        if (openDialog(renameDialog)) renameTitle.focus();
      } else if (action==='delete') {
        deleteTitle.textContent=currentTitle;
        openDialog(deleteDialog);
      }
    }));
    root.querySelector('[data-ai-rename-cancel]').addEventListener('click',()=>renameDialog.close());
    root.querySelector('[data-ai-delete-cancel]').addEventListener('click',()=>deleteDialog.close());
    renameTitle.addEventListener('input',()=>{renameError.hidden=true;});
    renameForm.addEventListener('submit',event=>{
      event.preventDefault();
      const title=renameTitle.value.trim();
      if (!title) { renameError.hidden=false; renameTitle.focus(); return; }
      renameDialog.close();
      perform(async()=>{ await request('rename',{title}); await refresh(); });
    });
    root.querySelector('[data-ai-delete-confirm]').addEventListener('click',()=>{
      deleteDialog.close();
      perform(async()=>{ await request('delete'); current=''; currentTitle=''; messages.replaceChildren(); conversationProvider.textContent=''; await refresh(); });
    });
    compose.addEventListener('submit', event=>{ event.preventDefault(); perform(async()=>{
      if (!current) current=(await request('create')).uuid;
      const session=await request('send',{text:question.value}); render(session); question.value=''; await refresh();
    }); });
    perform(refresh);
  }
  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
}());
