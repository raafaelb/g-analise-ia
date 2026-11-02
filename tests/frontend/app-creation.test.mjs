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
let Form,Recovery,server,Review;
async function compile(name){
 const path=new URL(`../../resources/js/Components/${name}.vue`,import.meta.url);
 const {descriptor}=parse(await readFile(path,'utf8'));
 let script=compileScript(descriptor,{id:name+'-interaction',inlineTemplate:true}).content;
 script=script.replace(/import ApprovalReview from ['"]\.\/ApprovalReview.vue['"];?/,`const ApprovalReview={props:['approval'],setup:()=>()=>null};`);
 script=script.replace(/import AppCreationRecovery from ['\"]\.\/AppCreationRecovery.vue['\"];?/,`const AppCreationRecovery={props:['approval'],setup:()=>()=>null};`);
 script=script.replace(/from (['"])([^'"]+)\1/g,(_,quote,source)=>`from ${quote}${source.startsWith('.')?new URL(source+'.ts',path).href:import.meta.resolve(source)}${quote}`);
 const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
 return(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;
}
before(async()=>{
 [Form,Recovery]=await Promise.all(['BiAppCreationForm','AppCreationRecovery'].map(compile));
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 [Review]=await Promise.all(['ApprovalReview'].map(async name=>(await server.ssrLoadModule(`/resources/js/Components/${name}.vue`)).default));
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
const reviewId='00000000-0000-4000-8000-000000000012';
const projectId='00000000-0000-4000-8000-000000000022';
function approval(input={request_id:'request',name:'Minha análise',objective:'',tables:[]},status='pending'){
 return {id:reviewId,type:'manual',project_id:projectId,status,expires_at:'2099-01-01T00:00:00Z',fingerprint:'f'.repeat(64),payload:{origin:'manual',request_id:input.request_id,action:{action:'create_app',...input},revision:{tenant_id:'100',app_creation:{name:input.name,objective:input.objective,tables:input.tables.map(resource=>({resource,name:'Vendas'})),state:'draft'},impact:'Cria uma aplicação privada, sem gráficos ou cargas.'}}};
}
function completed(item,verified=true){return{...item,status:'completed',payload:{...item.payload,execution:{result:{resource:'app:12',confirmed_name:item.payload.action.name,configuration_verified:verified,app_creation:{verified,state:'draft',table_count:item.payload.action.tables.length,dashboard_count:0},message:'Aplicação privada conferida.'}}}};}
function mount({component=Form,onRequest=null,outcome='completed',verified=true,initialApproval=null}={}){
 const previous=Object.fromEntries(['document','Document','ShadowRoot','HTMLElement'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 Object.defineProperty(globalThis,'document',{configurable:true,writable:true,value:{activeElement:null,hidden:true}});
 for(const key of ['Document','ShadowRoot','HTMLElement'])Object.defineProperty(globalThis,key,{configurable:true,writable:true,value:class{}});
 const root=node('root'),calls=[],events=[],errors=[];let current=initialApproval??approval();
 const props=ref(component===Recovery?{approval:current}:{tenant:'100',tenantName:'Homologação'});
 const request=async(path,method,data)=>{
  calls.push({path,method,data:data===undefined?undefined:JSON.parse(JSON.stringify(data))});let result;
  if(path.endsWith('/sections/table'))result={tenant:{id:'100'},section:'table',capabilities:{table:{status:'available'}},resources:{table:Array.from({length:9},(_,i)=>({ref:'table:'+(500+i),name:'Tabela '+(i+1)}))}};
  else if(path.endsWith('/apps/actions')){current=approval(data);result={approval:current,project:{id:projectId,source_ref:null}};}
  else if(path.endsWith('/decide')){current={...current,status:data.decision};result=current;}
  else{current=outcome==='completed'?completed(current,verified):{...current,status:outcome,payload:{...current.payload,app_creation_attempt:{started_at:'2026-11-02T12:00:00Z',resource:'app:12'},execution:{message:'A criação precisa de conferência.'}}};result={approval:current,project:{id:projectId,source_ref:current.status==='completed'?'app:12':null}};}
  const response=JSON.parse(JSON.stringify(result));return onRequest?onRequest(path,method,data,response):response;
 };
 const app=renderer.createApp({setup:()=>()=>h(component,{...props.value,request,onUpdated:()=>events.push('updated'),onNotice:value=>events.push(['notice',value]),onProject:value=>events.push(['project',value]),onConfirmed:value=>events.push(['confirmed',value])})});
 app.config.errorHandler=error=>errors.push(error.message);app.mount(root);
 return {root,calls,events,errors,props,button:label=>find(root,item=>item.kind==='button'&&text(item).trim()===label),setName:value=>find(root,item=>item.kind==='input'&&item.props.placeholder==='Ex.: Visão financeira').props['onUpdate:modelValue'](value),close:()=>{app.unmount();for(const[key,descriptor]of Object.entries(previous)){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}}};
}
async function settled(){await nextTick();await yieldEventLoop();await nextTick();}
async function click(view,label){const button=view.button(label);assert.ok(button,label);if(typeof button.props.onClick==='function')button.props.onClick();else{let form=button.parent;while(form&&form.kind!=='form')form=form.parent;assert.ok(form);form.props.onSubmit({preventDefault(){}});}await settled();}
async function prepare(view){view.setName('Minha análise');await settled();assert.equal(view.button('Preparar criação').props.disabled,false);await click(view,'Preparar criação');}

test('opening requires no data query and preparation never authorizes creation automatically',async()=>{
 const view=mount();try{await settled();assert.equal(view.calls.length,0);await prepare(view);assert.deepEqual(view.errors,[]);assert.equal(view.calls.length,1,text(view.root));assert.deepEqual(Object.keys(view.calls[0].data).sort(),['name','objective','request_id','tables']);assert.deepEqual(view.calls[0].data.tables,[]);assert.ok(view.button('Autorizar criação'));assert.equal(view.calls.length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a verified creation offers the matching project only after explicit approval',async()=>{
 const view=mount();try{await settled();await prepare(view);await click(view,'Autorizar criação');assert.match(text(view.root),/Seu ponto de partida está pronto/);await click(view,'Continuar no projeto');assert.deepEqual(view.events.filter(item=>Array.isArray(item)&&item[0]==='project'),[['project',projectId]]);assert.equal(view.calls.filter(item=>item.path.endsWith('/decide')).length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a completed status without verification never offers the new project as confirmed',async()=>{
 const view=mount({verified:false});try{await settled();await prepare(view);await click(view,'Autorizar criação');assert.equal(view.button('Continuar no projeto'),undefined);assert.equal(view.events.some(item=>Array.isArray(item)&&item[0]==='notice'),false);assert.match(text(view.root),/precisa de conferência/);}finally{view.close();}
});
test('rejecting the review never polls for or sends a creation',async()=>{
 const view=mount();try{await settled();await prepare(view);await click(view,'Recusar');assert.match(text(view.root),/Criação recusada/);assert.equal(view.calls.length,2);assert.equal(view.calls.at(-1).data.decision,'rejected');assert.equal(view.button('Continuar no projeto'),undefined);}finally{view.close();}
});
test('lost preparation keeps the proposal immutable and reuses the same request id',async()=>{
 let lost=false;const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/apps/actions')&&!lost){lost=true;throw new Error('Resposta perdida.');}return response;}});
 try{await settled();await prepare(view);assert.equal(find(view.root,item=>item.kind==='fieldset').props.disabled,true);view.setName('Outro nome');await click(view,'Conferir esta solicitação');assert.deepEqual(view.calls[0].data,view.calls[1].data);assert.ok(view.button('Autorizar criação'));assert.equal(view.calls.some(item=>item.path.endsWith('/decide')),false);}finally{view.close();}
});
test('a lost approval response is checked without submitting the decision again',async()=>{
 let lost=false;const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/decide')&&!lost){lost=true;throw new Error('Decisão sem resposta.');}return response;}});
 try{await settled();await prepare(view);await click(view,'Autorizar criação');assert.ok(view.button('Conferir solicitação'));assert.equal(view.button('Autorizar criação').props.disabled,true);await click(view,'Conferir solicitação');assert.equal(view.calls.filter(item=>item.path.endsWith('/decide')).length,1);assert.ok(view.button('Continuar no projeto'));}finally{view.close();}
});
test('an unavailable table catalog still permits a creation without selected tables',async()=>{
 const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/sections/table'))throw new Error('Tabelas indisponíveis.');return response;}});
 try{await settled();await click(view,'Escolher tabelas agora');assert.match(text(view.root),/Tabelas indisponíveis/);await prepare(view);assert.deepEqual(view.calls.at(-1).data.tables,[]);assert.ok(view.button('Autorizar criação'));}finally{view.close();}
});
test('selecting eight tables prevents adding a ninth but preserves checked choices',async()=>{
 const view=mount();try{await settled();await click(view,'Escolher tabelas agora');let options=all(view.root,item=>item.kind==='input'&&item.props.type==='checkbox');assert.equal(options.length,9);options[0].props['onUpdate:modelValue'](Array.from({length:8},(_,i)=>'table:'+(500+i)));await settled();options=all(view.root,item=>item.kind==='input'&&item.props.type==='checkbox');assert.equal(options[8].props.disabled,true);assert.equal(options[0].props.disabled,false);await prepare(view);assert.equal(view.calls.at(-1).data.tables.length,8);}finally{view.close();}
});
test('a late preparation response after unmount cannot update or announce another screen',async()=>{
 let release;const view=mount({onRequest:(path,method,data,response)=>new Promise(resolve=>release=()=>resolve(response))});await settled();await prepare(view);const count=view.events.length;view.close();release();await settled();assert.equal(view.events.length,count);assert.deepEqual(view.errors,[]);
});
test('creation recovery is a single read check and rejects a receipt from a different project',async()=>{
 const initial=approval();initial.status='failed';initial.payload.app_creation_attempt={started_at:'2026-11-02T12:00:00Z',resource:'app:12'};
 const view=mount({component:Recovery,initialApproval:initial,onRequest:(path,method,data,response)=>({...response,project:{id:'another-project',source_ref:'app:12'}})});
 try{await settled();await click(view,'Conferir criação');assert.equal(view.calls.length,1);assert.equal(view.calls[0].path,`/bi-actions/${reviewId}/app-creation-verification`);assert.match(text(view.root),/ainda não foi confirmada/);assert.equal(view.events.some(item=>Array.isArray(item)&&item[0]==='confirmed'),false);}finally{view.close();}
});
test('review escapes the name, objective and table labels rather than rendering user HTML',async()=>{
 const item=approval({request_id:'request',name:'<script>unsafe</script>',objective:'<img src=x onerror=unsafe>',tables:['table:500']});item.payload.revision.app_creation.tables[0].name='<b>unsafe</b>';
 const html=await renderToString(createSSRApp({render:()=>h(Review,{approval:item,tenants:[{id:'100',name:'Teste'}]})}));assert.doesNotMatch(html,/<script>unsafe|<img src=x onerror|<b>unsafe/);assert.match(html,/&lt;script&gt;/);assert.match(html,/&lt;img/);
});
