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
let Form,Recovery,server,Review,Manual,Operations;
async function compile(name){
 const path=new URL(`../../resources/js/Components/${name}.vue`,import.meta.url);
 const {descriptor}=parse(await readFile(path,'utf8'));
 let script=compileScript(descriptor,{id:name+'-interaction',inlineTemplate:true}).content;
 script=script.replace(/import ApprovalReview from ['"]\.\/ApprovalReview.vue['"];?/,`const ApprovalReview={props:['approval'],setup:()=>()=>null};`);
 script=script.replace(/from (['"])([^'"]+)\1/g,(_,quote,source)=>`from ${quote}${source.startsWith('.')?new URL(source+'.ts',path).href:import.meta.resolve(source)}${quote}`);
 const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
 return(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;
}
before(async()=>{
 [Form,Recovery]=await Promise.all(['BiAppSettingsForm','AppSettingsRecovery'].map(compile));
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 [Review,Manual,Operations]=await Promise.all(['ApprovalReview','BiManualAction','BiOperations'].map(async name=>(await server.ssrLoadModule(`/resources/js/Components/${name}.vue`)).default));
});
after(async()=>server?.close());
function all(item,predicate){return[...(predicate(item)?[item]:[]),...item.children.flatMap(child=>all(child,predicate))];}
function find(item,predicate){return all(item,predicate)[0];}
function text(item){return item.text+item.children.map(text).join('');}
function node(kind,text=''){
 const item={kind,tagName:kind.toUpperCase(),text,props:{},children:[],parent:null,isConnected:true,addEventListener(){},removeEventListener(){},getClientRects(){return[{}];},getRootNode(){return globalThis.document;},focus(){globalThis.document.activeElement=this;}};
 Object.defineProperty(item,'value',{get(){return item.props.value??'';},set(value){item.props.value=value;}});
 return item;
}
const renderer=createRenderer({createElement:kind=>node(kind),createText:text=>node('text',text),createComment:text=>node('comment',text),setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},patchProp:(item,key,previous,next)=>item.props[key]=next,insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,insertStaticContent:(text,parent,anchor)=>{const item=node('static',text);const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return[item,item];}});
const reviewId='00000000-0000-4000-8000-000000000011';
function approval(status='pending'){
 return {id:reviewId,type:'manual',project_id:null,status,expires_at:'2099-01-01T00:00:00Z',fingerprint:'f'.repeat(64),payload:{origin:'manual',action:{action:'update_app_details',resource:'app:11',app_changes:{nome:'Nova visão'}},revision:{tenant_id:'100',resource:'app:11',app_details:{name:'Visão atual',state:'draft',desk_id:null},changes:[{target:'Aplicação',field:'Nome',before:'Visão atual',after:'Nova visão'},{target:'Aplicação',field:'Descrição',before:'Descrição antiga',after:'Descrição nova'}],impact:'Os dashboards e as fontes serão preservados.'},...(status==='pending'?{}:{app_settings_attempt:{resource:'app:11',started_at:'2026-11-01T12:00:00Z'}})}};
}
function receipt(item,verified=true){return{...item,status:'completed',payload:{...item.payload,execution:{result:{resource:item.payload.action.resource,confirmed_name:'Nova visão',changed_fields:1,configuration_verified:verified}}}};}
function mount({component=Form,onRequest=null,outcome='completed',verified=true,publication='draft',initialApproval=null,sourceName='Visão atual',sourceDescription='Descrição antiga'}={}){
 const previous=Object.fromEntries(['document','Document','ShadowRoot','HTMLElement'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 Object.defineProperty(globalThis,'document',{configurable:true,writable:true,value:{activeElement:null,hidden:true}});
 for(const key of ['Document','ShadowRoot','HTMLElement'])Object.defineProperty(globalThis,key,{configurable:true,writable:true,value:class{}});
 const root=node('root'),calls=[],events=[],errors=[];let current=initialApproval??approval();
 const props=ref(component===Recovery?{approval:current}:{tenant:'100',tenantName:'Homologação',resource:{id:'11',ref:'app:11',kind:'app',name:'Visão atual'}});
 const request=async(path,method,data)=>{
  calls.push({path,method,data:data===undefined?undefined:JSON.parse(JSON.stringify(data))});
  let result;
  if(path.endsWith('/settings')){const id=path.split('/')[4];result={resource:'app:'+id,name:id==='11'?sourceName:'Outro ambiente',description:sourceDescription,version:'a'.repeat(64),state:publication,desk_id:publication==='published'?'45':null};}
  else if(path.endsWith('/settings/actions')){current=approval();current.payload.action.app_changes={...(data.name?{nome:data.name}:{}),...('description'in data?{descricao:data.description}:{})};result={approval:current};}
  else if(path.endsWith('/decide')){current={...current,status:data.decision};result=current;}
  else if(path.endsWith('/app-settings-verification')){current=receipt(current,verified);result={approval:current};}
  else {current=outcome==='completed'?receipt(current,verified):{...current,status:outcome,payload:{...current.payload,app_settings_attempt:{resource:'app:11',started_at:'2026-11-01T12:00:00Z'},execution:{message:'Conferência pendente.'}}};result={approval:current};}
  const response=structuredClone(result);return onRequest?onRequest(path,method,data,response):response;
 };
 const app=renderer.createApp({setup:()=>()=>h(component,{...props.value,request,onUpdated:()=>events.push('updated'),onNotice:value=>events.push(['notice',value]),onSaved:value=>events.push(['saved',value]),onConfirmed:value=>events.push(['confirmed',value])})});
 app.config.errorHandler=error=>errors.push(error.message);app.mount(root);
 return {root,calls,events,errors,props,button:label=>find(root,item=>item.kind==='button'&&text(item).trim()===label),setName:value=>find(root,item=>item.kind==='input').props['onUpdate:modelValue'](value),setDescription:value=>find(root,item=>item.kind==='textarea').props['onUpdate:modelValue'](value),close:()=>{app.unmount();for(const[key,descriptor]of Object.entries(previous)){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}}};
}
async function settled(){await nextTick();await yieldEventLoop();await nextTick();}
async function prepare(view){view.setName('Nova visão');await settled();find(view.root,item=>item.kind==='form').props.onSubmit({preventDefault(){}});await settled();}
async function click(view,label){const button=view.button(label);if(typeof button.props.onClick==='function')button.props.onClick();else{let form=button.parent;while(form&&form.kind!=='form')form=form.parent;assert.ok(form,'O botão deve estar vinculado a um formulário');form.props.onSubmit({preventDefault(){}});}await settled();}

test('opening the editor reads current settings and submits only changed fields before any decision',async()=>{
 const view=mount();try{await settled();assert.equal(view.button('Conferir alterações').props.disabled,true);await prepare(view);assert.equal(view.calls.length,2);assert.equal(view.calls[0].method,undefined);assert.equal(view.calls[1].path,'/catalog/100/apps/11/settings/actions');assert.deepEqual(Object.keys(view.calls[1].data).sort(),['name','request_id','version']);assert.equal(view.calls[1].data.name,'Nova visão');assert.ok(view.button('Autorizar alteração'));assert.equal(view.calls.some(call=>call.path.endsWith('/decide')),false);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('approval is explicit and only a verified matching receipt offers the saved application',async()=>{
 const view=mount();try{await settled();await prepare(view);await click(view,'Autorizar alteração');assert.match(text(view.root),/Aplicação atualizada/);assert.ok(view.button('Ver aplicação atualizada'));await click(view,'Ver aplicação atualizada');assert.deepEqual(view.events.filter(item=>Array.isArray(item)&&item[0]==='saved'),[['saved','app:11']]);assert.equal(view.calls.filter(call=>call.path.endsWith('/decide')).length,1);assert.equal(view.events.filter(item=>Array.isArray(item)&&item[0]==='notice').length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a completed status without verification is not presented as confirmed settings',async()=>{
 const view=mount({verified:false});try{await settled();await prepare(view);await click(view,'Autorizar alteração');assert.equal(view.button('Ver aplicação atualizada'),undefined);assert.match(text(view.root),/A alteração precisa de conferência/);assert.equal(view.events.some(item=>Array.isArray(item)&&item[0]==='notice'),false);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('rejecting the review does not request execution status or verification',async()=>{
 const view=mount();try{await settled();await prepare(view);await click(view,'Recusar');assert.match(text(view.root),/Solicitação recusada/);assert.equal(view.calls.length,3);assert.deepEqual(view.calls.at(-1).data,{decision:'rejected',fingerprint:'f'.repeat(64)});assert.equal(view.button('Ver aplicação atualizada'),undefined);}finally{view.close();}
});
test('a lost preparation response is recovered using the identical request and immutable proposal',async()=>{
 let lost=false;const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/settings/actions')&&!lost){lost=true;throw new Error('Resposta perdida.');}return response;}});
 try{await settled();await prepare(view);assert.match(text(view.root),/Resposta perdida/);assert.equal(find(view.root,item=>item.kind==='fieldset').props.disabled,true);view.setName('Proposta posterior');await click(view,'Conferir esta solicitação');const submitted=view.calls.filter(call=>call.path.endsWith('/settings/actions'));assert.deepEqual(submitted[0].data,submitted[1].data);assert.ok(view.button('Autorizar alteração'));assert.equal(view.calls.some(call=>call.path.endsWith('/decide')),false);}finally{view.close();}
});
test('an uncertain decision is checked by reading without submitting another approval',async()=>{
 const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/decide'))throw new Error('Decisão sem resposta.');return response;}});
 try{await settled();await prepare(view);await click(view,'Autorizar alteração');assert.equal(view.button('Autorizar alteração').props.disabled,true);await click(view,'Conferir solicitação');assert.ok(view.button('Ver aplicação atualizada'));assert.equal(view.calls.filter(call=>call.path.endsWith('/decide')).length,1);assert.equal(view.calls.at(-1).method,undefined);}finally{view.close();}
});
test('a failed sent edit is verified without preparing or authorizing another write',async()=>{
 const view=mount({outcome:'failed'});try{await settled();await prepare(view);await click(view,'Autorizar alteração');assert.ok(view.button('Conferir resultado'));await click(view,'Conferir resultado');assert.ok(view.button('Ver aplicação atualizada'));assert.equal(view.calls.filter(call=>call.path.endsWith('/settings/actions')).length,1);assert.equal(view.calls.filter(call=>call.path.endsWith('/decide')).length,1);assert.equal(view.calls.at(-1).path,`/bi-actions/${reviewId}/app-settings-verification`);}finally{view.close();}
});
test('published applications disclose that the existing shared application will change',async()=>{
 const view=mount({publication:'published'});try{await settled();assert.match(text(view.root),/aplicação está publicada/);assert.match(text(view.root),/visíveis aos usuários da mesma mesa/);assert.equal(view.calls.length,1);}finally{view.close();}
});
test('a stale settings response cannot replace the newly selected environment or application',async()=>{
 let release;const old=new Promise(resolve=>release=resolve);let firstResponse;
 const view=mount({onRequest:(path,method,data,response)=>{if(path==='/catalog/100/apps/11/settings'){firstResponse=response;return old;}return response;}});
 try{await settled();view.props.value={tenant:'200',tenantName:'Outro',resource:{id:'22',ref:'app:22',kind:'app'}};await settled();release(firstResponse);await settled();assert.equal(find(view.root,item=>item.kind==='input').props.value,'Outro ambiente');assert.equal(view.calls.at(-1).path,'/catalog/200/apps/22/settings');assert.deepEqual(view.errors,[]);}finally{release?.(firstResponse);view.close();}
});
function render(component,props){return renderToString(createSSRApp(component,props));}
test('the real review names the application and escapes current and proposed descriptions',async()=>{
 const item=approval();item.payload.revision.app_details.state='published';item.payload.revision.app_details.desk_id='45';item.payload.revision.changes[1].after='<img src=x onerror=alert(1)>';
 const html=await render(Review,{approval:item,tenants:[{id:'100',name:'Homologação'}]});assert.match(html,/Nome e descrição da aplicação/);assert.match(html,/Aplicação publicada · Mesa #45/);assert.match(html,/Visão atual/);assert.match(html,/Nova visão/);assert.match(html,/&lt;img/);assert.doesNotMatch(html,/<img src=x/);
});
test('the action is offered for applications and remains blocked during a pending deletion',async()=>{
 const props={tenant:'100',tenantName:'Homologação',resource:{id:'11',kind:'app',name:'Visão'},desks:[],request:async()=>{throw new Error('Sem chamadas durante renderização.');}};
 assert.match(await render(Manual,props),/Editar nome e descrição/);assert.doesNotMatch(await render(Manual,{...props,resource:{id:'2',kind:'flow',name:'Carga'}}),/Editar nome e descrição/);assert.match(await render(Manual,{...props,management:{deletion_pending:true}}),/<button[^>]*disabled[^>]*>[\s\S]*?Editar nome e descrição<\/button>/);
});
test('activity exposes recovery for a failed sent edit and preserves the verified receipt',async()=>{
 const item=approval('failed');const request=async()=>{throw new Error('Sem chamadas durante renderização.');};const props={projects:[],tenants:[{id:'100',name:'Homologação'}],request};
 const failed=await render(Operations,{...props,approvals:[item]});assert.match(failed,/Conferir resultado/);assert.match(failed,/não reenvia a edição/);assert.match(failed,/Visão atual/);
 const completed=await render(Operations,{...props,approvals:[receipt(item)]});assert.match(completed,/Nome e descrição conferidos/);assert.match(completed,/Nova visão/);assert.doesNotMatch(completed,/Conferir resultado<\/button>/);
});
test('recovery rejects a response for another review or an unverified resource',async()=>{
 const item=approval('failed');const view=mount({component:Recovery,initialApproval:item,onRequest:(path,method,data,response)=>({...response,approval:{...response.approval,id:'another-review'}})});
 try{await settled();await click(view,'Conferir resultado');assert.match(text(view.root),/resultado desta alteração ainda não foi confirmado/);assert.equal(view.events.some(item=>Array.isArray(item)&&item[0]==='confirmed'),false);assert.equal(view.calls.length,1);}finally{view.close();}
});

test('clearing the description submits null without including an unchanged name',async()=>{
 const view=mount();try{await settled();view.setDescription('');await settled();find(view.root,item=>item.kind==='form').props.onSubmit({preventDefault(){}});await settled();assert.equal(view.calls.at(-1).data.description,null);assert.equal('name'in view.calls.at(-1).data,false);assert.ok(view.button('Autorizar alteração'));}finally{view.close();}
});
test('a verified receipt for another application cannot become a saved result',async()=>{
 const view=mount({onRequest:(path,method,data,response)=>{if(path.startsWith('/bi-actions/')&&method===undefined)response.approval.payload.execution.result.resource='app:22';return response;}});
 try{await settled();await prepare(view);await click(view,'Autorizar alteração');assert.equal(view.button('Ver aplicação atualizada'),undefined);assert.equal(view.events.some(item=>Array.isArray(item)&&item[0]==='notice'),false);}finally{view.close();}
});

test('unchanged whitespace in source metadata does not enable or silently expand a proposal',async()=>{
 const view=mount({sourceName:' Visão atual ',sourceDescription:' Descrição antiga '});try{await settled();assert.equal(view.button('Conferir alterações').props.disabled,true);await prepare(view);assert.equal('description'in view.calls.at(-1).data,false);assert.equal(view.calls.at(-1).data.name,'Nova visão');}finally{view.close();}
});
test('an existing long description is preserved while only the name is edited',async()=>{
 const view=mount({sourceDescription:'D'.repeat(4100)});try{await settled();await prepare(view);assert.equal(view.calls.length,2);assert.equal('description'in view.calls.at(-1).data,false);assert.ok(view.button('Autorizar alteração'));}finally{view.close();}
});
test('an existing long name is preserved while only the description is edited',async()=>{
 const view=mount({sourceName:'N'.repeat(120)});try{await settled();view.setDescription('Descrição nova');await settled();find(view.root,item=>item.kind==='form').props.onSubmit({preventDefault(){}});await settled();assert.equal(view.calls.length,2);assert.equal('name'in view.calls.at(-1).data,false);assert.equal(view.calls.at(-1).data.description,'Descrição nova');}finally{view.close();}
});
