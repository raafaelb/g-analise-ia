import {readFile} from 'node:fs/promises';
import {after,before,test} from 'node:test';
import {setImmediate as yieldEventLoop} from 'node:timers/promises';
import assert from 'node:assert/strict';
import {parse,compileScript} from '@vue/compiler-sfc';
import ts from 'typescript';
import {createRenderer,createSSRApp,h,nextTick,ref} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
let Form,Recovery,server,Review,Operations;
async function compile(name){
 const path=new URL(`../../resources/js/Components/${name}.vue`,import.meta.url);
 const {descriptor}=parse(await readFile(path,'utf8'));
 let script=compileScript(descriptor,{id:name+'-interaction',inlineTemplate:true}).content;
 script=script.replace(/import VariableRecovery from ['"]\.\/VariableRecovery.vue['"];?/,`const VariableRecovery={props:['approval'],setup:()=>()=>null};`);
 script=script.replace(/import ApprovalReview from ['"]\.\/ApprovalReview.vue['"];?/,`const ApprovalReview={props:['approval'],setup:()=>()=>null};`);
 script=script.replace(/from (['"])([^'"]+)\1/g,(_,quote,source)=>`from ${quote}${source.startsWith('.')?new URL(source+'.ts',path).href:import.meta.resolve(source)}${quote}`);
 const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
 return(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;
}
before(async()=>{
 [Form,Recovery]=await Promise.all(['BiVariableForm','VariableRecovery'].map(compile));
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 [Review,Operations]=await Promise.all(['ApprovalReview','BiOperations'].map(async name=>(await server.ssrLoadModule(`/resources/js/Components/${name}.vue`)).default));
});
after(async()=>server?.close());
function all(item,predicate){return[...(predicate(item)?[item]:[]),...item.children.flatMap(child=>all(child,predicate))];}
function find(item,predicate){return all(item,predicate)[0];}
function text(item){return item.text+item.children.map(text).join('');}
function node(kind,text=''){
 const item={kind,tagName:kind.toUpperCase(),text,props:{},children:[],parent:null,isConnected:true,addEventListener(){},removeEventListener(){},getClientRects(){return[{}];},getRootNode(){return globalThis.document;},focus(){globalThis.document.activeElement=this;}};
 Object.defineProperty(item,'options',{get(){return item.children.filter(child=>child.kind==='option');}});
 Object.defineProperty(item,'value',{get(){return item.kind==='select'?item.options[item.selectedIndex]?.value??'':item.props.value??'';},set(value){item.props.value=value;}});
 return item;
}
const renderer=createRenderer({createElement:kind=>node(kind),createText:text=>node('text',text),createComment:text=>node('comment',text),setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},patchProp:(item,key,previous,next)=>item.props[key]=next,insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,insertStaticContent:(text,parent,anchor)=>{const item=node('static',text);const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return[item,item];}});
const reviewId='00000000-0000-4000-8000-000000000021';
function metadata(key='WINDOW_DAYS'){return{id:Buffer.from(key).toString('hex'),key,reference:'{{'+key+'}}',is_secret:false,description:'Janela atual',agent_overrides:[{agent_id:7}]};}
function approval(status='pending',key='QA_WINDOW'){
 const proposed={...metadata(key),is_secret:true,agent_overrides:[]};
 return{id:reviewId,type:'manual',project_id:null,status,fingerprint:'f'.repeat(64),expires_at:'2099-01-01T00:00:00Z',payload:{origin:'manual',action:{action:'create_variable',resource:'variables',key},revision:{tenant_id:'100',resource:'variables',variable:{key,previous:null,proposed,value_update_requested:true,value_action:'Definir novo valor padrão protegido',secret_values_submitted:true,overrides_changed:true,overrides:[]},impact:'Afeta os recursos que usam a referência neste tenant. Não executa cargas.'},...(status==='pending'?{}:{variable_attempt:{key,started_at:'2026-11-01T20:00:00Z',accepted_at:'2026-11-01T20:00:01Z'}})}};
}
function receipt(item,verified=true){return{...item,status:'completed',payload:{...item.payload,execution:{result:{resource:'variables',variable_key:item.payload.action.key,variable_id:Buffer.from(item.payload.action.key).toString('hex'),configuration_verified:verified,values_verified:false,secret_values_read_back:false,message:'Configuração conferida sem leitura dos segredos.'}}}};}
function mount({editing=false,onRequest=null,outcome='completed',verified=true,component=Form,initialApproval=null,secret=false}={}){
 const previous=Object.fromEntries(['document','Document','ShadowRoot','HTMLElement'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 Object.defineProperty(globalThis,'document',{configurable:true,writable:true,value:{activeElement:null,hidden:true}});
 for(const name of ['Document','ShadowRoot','HTMLElement'])Object.defineProperty(globalThis,name,{configurable:true,writable:true,value:class{}});
 const root=node('root'),calls=[],events=[],errors=[];let current=initialApproval??approval();
 const props=ref(component===Recovery?{approval:current}:{tenant:'100',tenantName:'Homologação',...(editing?{resource:{...metadata(),name:'WINDOW_DAYS',kind:'variables',ref:'{{WINDOW_DAYS}}'}}:{})});
 const request=async(path,method,data)=>{
  calls.push({path,method,data:data===undefined?undefined:JSON.parse(JSON.stringify(data))});let response;
  if(path.endsWith('/options')||path.endsWith('/configuration'))response={version:'a'.repeat(64),keys:['WINDOW_DAYS'],agents:[{id:7,name:'Agente local'},{id:9,name:'Outro agente'}],variable:path.endsWith('/configuration')?{...metadata(),is_secret:secret}:null,agents_message:null};
  else if(path.endsWith('/variables/actions')){current=approval('pending',data.key);current.payload.action.action=data.action;response={approval:current};}
  else if(path.endsWith('/decide')){current={...current,status:data.decision};response=current;}
  else if(path.endsWith('/variable-verification')){current=receipt(current,verified);response={approval:current};}
  else{current=outcome==='completed'?receipt(current,verified):{...current,status:outcome,payload:{...current.payload,variable_attempt:{key:current.payload.action.key,started_at:'2026-11-01T20:00:00Z'},execution:{message:'Resultado pendente.'}}};response={approval:current};}
  response=structuredClone(response);return onRequest?onRequest(path,method,data,response):response;
 };
 const app=renderer.createApp({setup:()=>()=>h(component,{...props.value,request,onUpdated:()=>events.push('updated'),onNotice:value=>events.push(['notice',value]),onSaved:value=>events.push(['saved',value]),onConfirmed:value=>events.push(['confirmed',value])})});
 app.config.errorHandler=error=>errors.push(error.message);app.mount(root);
 return{root,calls,events,errors,props,button:label=>find(root,item=>item.kind==='button'&&text(item).trim()===label),set(label,value){const target=find(root,item=>item.kind==='label'&&text(item).trim()===label);assert.ok(target,'Rótulo disponível: '+label);const field=target.props.for?find(root,item=>item.props.id===target.props.for):find(target,item=>item.kind==='input');assert.ok(field,'Campo vinculado: '+label);field.props['onUpdate:modelValue'](value);},close(){app.unmount();for(const[name,descriptor]of Object.entries(previous)){if(descriptor)Object.defineProperty(globalThis,name,descriptor);else delete globalThis[name];}}};
}
async function settled(){await nextTick();await yieldEventLoop();await nextTick();}
async function click(view,label){const button=view.button(label);assert.ok(button,'Botão disponível: '+label);if(typeof button.props.onClick==='function')button.props.onClick();else{let form=button.parent;while(form&&form.kind!=='form')form=form.parent;assert.ok(form);form.props.onSubmit({preventDefault(){}});}await settled();}
async function prepare(view){view.set('Nome da variável','QA_WINDOW');view.set('Valor padrão','  synthetic\nvalue\n');await settled();await click(view,'Conferir configuração');}

test('creation submits protected input only after the user asks for review and does not decide automatically',async()=>{
 const view=mount();try{await settled();await prepare(view);assert.equal(view.calls.length,2);const input=view.calls[1];assert.equal(input.path,'/catalog/100/variables/actions');assert.equal(input.data.value,'  synthetic\nvalue\n');assert.equal(input.data.is_secret,true);assert.equal(input.data.action,'create_variable');assert.ok(view.button('Autorizar criação'));assert.equal(view.calls.some(call=>call.path.endsWith('/decide')),false);assert.doesNotMatch(text(view.root),/synthetic/);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('editing a description omits stored values and agent overrides from the patch',async()=>{
 const view=mount({editing:true});try{await settled();assert.equal(view.button('Conferir configuração').props.disabled,true);view.set('Para que serve esta variável? opcional','Nova explicação');await settled();await click(view,'Conferir configuração');assert.deepEqual(Object.keys(view.calls[1].data).sort(),['action','description','key','request_id','version']);assert.equal(view.calls[1].data.description,'Nova explicação');assert.ok(view.button('Autorizar alteração'));assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a default value is replaced only after choosing its explicit checkbox',async()=>{
 const view=mount({editing:true});try{await settled();assert.throws(()=>view.set('Novo valor padrão','x'));view.set('Substituir valor padrão',true);await settled();view.set('Novo valor padrão','0');await click(view,'Conferir configuração');assert.equal(view.calls[1].data.value,'0');assert.equal('agent_overrides'in view.calls[1].data,false);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a new per-agent value is sent separately from the unchanged default',async()=>{
 const view=mount({editing:true});try{await settled();await click(view,'Adicionar valor por agente');view.set('Agente 2','9');view.set('Novo valor do agente','  override\n');await settled();await click(view,'Conferir configuração');assert.deepEqual(view.calls[1].data.agent_overrides,[{agent_id:7},{agent_id:9,value:'  override\n'}]);assert.equal('value'in view.calls[1].data,false);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('removing the last agent definition sends an explicit empty list',async()=>{
 const view=mount({editing:true});try{await settled();find(view.root,item=>item.kind==='button'&&item.props['aria-label']==='Remover definição do agente 7').props.onClick();await settled();await click(view,'Conferir configuração');assert.deepEqual(view.calls[1].data.agent_overrides,[]);assert.equal('value'in view.calls[1].data,false);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('an immutable preparation request can be recovered after a lost response',async()=>{
 let lost=false;const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/variables/actions')&&!lost){lost=true;throw new Error('Resposta perdida');}return response;}});try{await settled();await prepare(view);assert.equal(find(view.root,item=>item.kind==='fieldset').props.disabled,true);view.set('Valor padrão','later value');await click(view,'Consultar mesma solicitação');const calls=view.calls.filter(call=>call.path.endsWith('/variables/actions'));assert.deepEqual(calls[0].data,calls[1].data);assert.ok(view.button('Autorizar criação'));assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('approval offers the saved key only when the matching configuration has been verified',async()=>{
 const view=mount();try{await settled();await prepare(view);await click(view,'Autorizar criação');assert.match(text(view.root),/Variável configurada/);await click(view,'Ver variável no catálogo');assert.deepEqual(view.events.filter(item=>Array.isArray(item)&&item[0]==='saved'),[['saved','QA_WINDOW']]);assert.equal(view.calls.filter(call=>call.path.endsWith('/decide')).length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('completed status without a verification receipt does not announce success',async()=>{
 const view=mount({verified:false});try{await settled();await prepare(view);await click(view,'Autorizar criação');assert.equal(view.button('Ver variável no catálogo'),undefined);assert.equal(view.events.some(item=>Array.isArray(item)&&item[0]==='notice'),false);assert.match(text(view.root),/precisa de conferência/);}finally{view.close();}
});
test('an uncertain decision is checked through one read instead of another approval',async()=>{
 const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/decide'))throw new Error('Resposta interrompida');return response;}});try{await settled();await prepare(view);await click(view,'Autorizar criação');assert.equal(view.button('Autorizar criação'),undefined);await click(view,'Conferir solicitação');assert.equal(view.calls.filter(call=>call.path.endsWith('/decide')).length,1);assert.equal(view.calls.at(-1).method,undefined);assert.ok(view.button('Ver variável no catálogo'));assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('rejection clears the review without polling or presenting a successful configuration',async()=>{
 const view=mount();try{await settled();await prepare(view);await click(view,'Recusar');assert.match(text(view.root),/Revisão recusada/);assert.equal(view.calls.length,3);assert.equal(view.calls.at(-1).data.decision,'rejected');assert.equal(view.button('Ver variável no catálogo'),undefined);}finally{view.close();}
});
test('recovery checks a failed attempt by reading without resending input or starting AI',async()=>{
 const view=mount({component:Recovery,initialApproval:approval('failed')});try{await settled();await click(view,'Conferir resultado');assert.equal(view.calls.length,1);assert.equal(view.calls[0].path,'/bi-actions/'+reviewId+'/variable-verification');assert.equal(view.calls[0].data,undefined);assert.equal(view.events[0][0],'confirmed');assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a mismatched recovery receipt is not emitted as a confirmed variable',async()=>{
 const view=mount({component:Recovery,initialApproval:approval('failed'),onRequest:(path,method,data,response)=>{response.approval.payload.execution.result.variable_key='OTHER';return response;}});try{await settled();await click(view,'Conferir resultado');assert.match(text(view.root),/ainda não foi confirmado/);assert.equal(view.events.length,0);}finally{view.close();}
});
test('the review explains global scope, secret behavior and removed agent definitions without values',async()=>{
 const review=approval();review.payload.revision.variable.previous=metadata('QA_WINDOW');const html=await renderToString(createSSRApp(Review,{approval:review,tenants:[{id:'100',name:'Teste'}]}));assert.match(html,/Variáveis do tenant/);assert.match(html,/Definir novo valor padrão protegido/);assert.match(html,/Remover definição dos agentes: #7/);assert.match(html,/não devolve seus valores/);assert.doesNotMatch(html,/synthetic-global-secret/);
});
test('variable metadata is escaped in review and operation history',async()=>{
 const review=receipt(approval());review.title='<script>title</script>';review.payload.revision.variable.proposed.description='<img src=x onerror=alert(1)>';const html=await renderToString(createSSRApp(Review,{approval:review,tenants:[{id:'100',name:'Teste'}]}));assert.match(html,/&lt;img/);assert.doesNotMatch(html,/<img src=x/);const operations=await renderToString(createSSRApp(Operations,{approvals:[review],projects:[],tenants:[{id:'100',name:'Teste'}],request:async()=>({})}));assert.match(operations,/QA_WINDOW/);assert.match(operations,/Variável configurada/);assert.doesNotMatch(operations,/<script>title/);
});
