/* Private administrator chat. No generated HTML, remote links or model actions. */
(function () {
  'use strict';
  function init() {
    const root = document.getElementById('scalyn-ai');
    if (!root) return;
    const list = root.querySelector('[data-ai-sessions]');
    const messages = root.querySelector('[data-ai-messages]');
    const status = root.querySelector('[data-ai-status]');
    const compose = root.querySelector('[data-ai-compose]');
    const question = document.getElementById('scalyn-ai-question');
    let current = '', busy = false;
    function node(tag, text) { const el = document.createElement(tag); el.textContent = text; return el; }
    async function request(action, extra = {}) {
      const response = await fetch(root.dataset.endpoint, { method:'POST', credentials:'same-origin', cache:'no-store', headers:{'Content-Type':'application/json','X-WP-Nonce':root.dataset.nonce}, body:JSON.stringify({action, uuid:current, ...extra}), signal:AbortSignal.timeout(60000) });
      if (!response.ok) throw new Error('AI request unavailable. Check settings, limits and configuration; reload to refresh authorization. No automatic retry was made.');
      const result = await response.json();
      if (!result.success) throw new Error('AI operation unavailable.');
      return result.data;
    }
    async function refresh() {
      const data = await request('list');
      list.replaceChildren();
      root.querySelector('[data-ai-usage]').textContent = 'AI requests today (UTC): ' + data.usage + ' / 100';
      for (const session of data.sessions) {
        const button = node('button', session.title); button.type='button'; button.className='button'; button.setAttribute('aria-pressed', String(session.uuid === current));
        button.addEventListener('click', () => perform(async () => { current=session.uuid; render(await request('read')); await refresh(); }));
        list.appendChild(button);
      }
    }
    function render(session) {
      messages.replaceChildren();
      for (const message of session.messages) {
        const box = node('article', ''); box.className='scalyn-ai-message';
        box.appendChild(node('h3', message.role === 'user' ? 'You' : 'Plugin assistant — advisory'));
        if (message.role === 'user') box.appendChild(node('p', message.text));
        else {
          const answer=message.answer;
          for (const [key, label] of [['observed','Observed evidence'],['possible_causes','Possible causes'],['next_steps','Recommended steps']]) {
            if (answer[key]) { box.appendChild(node('h4',label)); box.appendChild(node('p',answer[key])); }
          }
          box.appendChild(node('p', 'Evidence captured (UTC): ' + message.captured_at_utc + ' · Revision: ' + message.revision + ' · Recorded answer, not live monitoring.'));
          if (message.context_truncated) box.appendChild(node('p','Earlier conversation context was omitted to stay within limits.'));
          const destinations={help:'providers',configuration:'providers',diagnostics:'diagnostics',health:'diagnostics',activity:'logs'};
          for (const ref of answer.references || []) {
            if (!destinations[ref]) continue;
            const link=node('a',ref); link.href='admin.php?page=scalyn-mail-relay-'+destinations[ref]; link.className='button'; box.appendChild(link);
          }
          if (answer.suggest_diagnostics) box.appendChild(node('p','A fresh diagnostic run may help. Use Run diagnostics below and review its separate confirmation.'));
        }
        messages.appendChild(box);
      }
    }
    async function perform(work) {
      if (busy) return;
      busy=true; status.textContent='Working…';
      const controls=Array.from(root.querySelectorAll('[data-ai-action], [data-ai-sessions] button, [data-ai-compose] button'));
      const disabled=controls.map(el=>el.disabled); controls.forEach(el=>{el.disabled=true;});
      try { await work(); status.textContent='Ready.'; }
      catch (error) { status.textContent=error.name==='TimeoutError' ? 'Request timed out. Its outcome may be unknown; reload the conversation before manually trying again.' : error.message; }
      finally { controls.forEach((el,i)=>{el.disabled=disabled[i];}); busy=false; }
    }
    root.querySelectorAll('[data-ai-action]').forEach(button=>button.addEventListener('click',()=>perform(async()=>{
      const action=button.dataset.aiAction;
      if (action==='create') { current=(await request('create')).uuid; render(await request('read')); await refresh(); }
      else if (action==='diagnostics') {
        const snapshot=await request('evidence');
        if (!window.confirm('Run diagnostics for the current configuration? Checks contact DNS and, for SMTP, the configured server. No test email is sent and no settings are changed.')) return;
        const result=await request('diagnostics',{confirmed:true,revision:snapshot.revision});
        messages.appendChild(node('p',result.message+' Recorded result: '+(result.completed?'completed':'failed or unavailable')+'.'));
      } else {
        if (!current) throw new Error('Choose a conversation first.');
        if (action==='delete') { if (!window.confirm('Permanently delete this private chat?')) return; await request('delete'); current=''; messages.replaceChildren(); await refresh(); }
        if (action==='rename') { const title=window.prompt('Conversation title (up to 80 characters)'); if (title===null) return; await request('rename',{title}); await refresh(); }
      }
    })));
    compose.addEventListener('submit', event=>{ event.preventDefault(); perform(async()=>{
      if (!current) current=(await request('create')).uuid;
      const session=await request('send',{text:question.value}); render(session); question.value=''; await refresh();
    }); });
    perform(refresh);
  }
  if (document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
}());
