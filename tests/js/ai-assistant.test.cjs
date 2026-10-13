const {test}=require('node:test');
const assert=require('node:assert/strict');
const vm=require('node:vm');
const source=require('node:fs').readFileSync(require('node:path').resolve(__dirname,'../../assets/js/ai-assistant.js'),'utf8');
function setup(confirm=false){
 const calls=[];
 const el=(extra={})=>Object.assign({children:[],disabled:false,dataset:{},events:{},appendChild(node){this.children.push(node);},replaceChildren(...nodes){this.children=nodes;},setAttribute(){},addEventListener(name,handler){this.events[name]=handler;}},extra);
 const list=el(),messages=el(),status=el(),compose=el(),question=el({value:'Explain plugin diagnostics'}),usage=el();
 const buttons=['create','rename','delete','diagnostics'].map(action=>el({dataset:{aiAction:action}}));
 const elements={'[data-ai-sessions]':list,'[data-ai-messages]':messages,'[data-ai-status]':status,'[data-ai-compose]':compose,'[data-ai-usage]':usage};
 const root=el({dataset:{endpoint:'https://site.test/wp-json/scalyn-mail-relay/v1/ai',nonce:'test-nonce'},querySelector:s=>elements[s],querySelectorAll:s=>s==='[data-ai-action]'?buttons:buttons});
 const document={readyState:'complete',getElementById:id=>id==='scalyn-ai'?root:question,createElement:()=>el()};
 const fetch=async(url,args)=>{const input=JSON.parse(args.body);calls.push({url,args,input});return {ok:true,json:async()=>({success:true,data:input.action==='list'?{sessions:[],usage:0}:input.action==='create'?{uuid:'test-session'}:input.action==='evidence'?{revision:'test-revision'}:input.action==='diagnostics'?{completed:true,message:'Recorded run; no test email sent.'}:{title:'New',messages:[]}})};};
 vm.runInNewContext(source,{document,fetch,AbortSignal,window:{confirm:()=>confirm,prompt:()=>null}});
 return {calls,buttons,status,messages,compose,question};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('page initialization loads private sessions but never sends inference or diagnostics',async()=>{const s=setup();await tick();assert.deepEqual(s.calls.map(c=>c.input.action),['list']);assert.equal(s.calls[0].args.cache,'no-store');assert.equal(s.calls[0].args.headers['X-WP-Nonce'],'test-nonce');});
test('cancelling diagnostic confirmation never calls the execution endpoint',async()=>{const s=setup(false);await tick();await s.buttons[3].events.click();assert.deepEqual(s.calls.map(c=>c.input.action),['list','evidence']);});
test('diagnostic execution includes explicit consent and the freshly read revision',async()=>{const s=setup(true);await tick();await s.buttons[3].events.click();assert.equal(s.calls[2].input.confirmed,true);assert.equal(s.calls[2].input.revision,'test-revision');});
test('rendering uses text nodes, not executable model HTML',()=>{assert.doesNotMatch(source,/innerHTML|insertAdjacentHTML|eval\(/);assert.match(source,/textContent = text/);});
test('unrelated pages make no requests',()=>{let calls=0;vm.runInNewContext(source,{document:{readyState:'complete',getElementById:()=>null},fetch:()=>{calls++;}});assert.equal(calls,0);});
