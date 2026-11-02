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
 const path=new URL(`../../resources/js/Components/${name}.vue`,import.meta.url);const {descriptor}=parse(await readFile(path,'utf8'));
 let script=compileScript(descriptor,{id:name+'-interaction',inlineTemplate:true}).content;
 script=script.replace(/import ApprovalReview from ['"]\.\/ApprovalReview.vue['"];?/,`const ApprovalReview={props:['approval'],setup:()=>()=>null};`);
 script=script.replace(/from (['"])([^'"]+)\1/g,(_,quote,source)=>`from ${quote}${source.startsWith('.')?new URL(source+'.ts',path).href:import.meta.resolve(source)}${quote}`);
 return(await import('data:text/javascript;base64,'+Buffer.from(ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText).toString('base64'))).default;
}
before(async()=>{
 [Form,Recovery]=await Promise.all(['BiDashboardCreationForm','DashboardCreationRecovery'].map(compile));
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 [Review,Manual,Operations]=await Promise.all(['ApprovalReview','BiManualAction','BiOperations'].map(async name=>(await server.ssrLoadModule(`/resources/js/Components/${name}.vue`)).default));
});
after(async()=>server?.close());
function all(item,predicate){return[...(predicate(item)?[item]:[]),...item.children.flatMap(child=>all(child,predicate))];}
function find(item,predicate){return all(item,predicate)[0];}
function text(item){return item.kind==='comment'?'':item.text+item.children.map(text).join('');}
function node(kind,text=''){
 const item={kind,tagName:kind.toUpperCase(),text,props:{},children:[],parent:null,isConnected:true,addEventListener(){},removeEventListener(){},getClientRects(){return[{}];},getRootNode(){return globalThis.document;},focus(){globalThis.document.activeElement=this;}};
 Object.defineProperty(item,'options',{get(){return item.children.filter(child=>child.kind==='option');}});
 Object.defineProperty(item,'value',{get(){return item.kind==='select'?item.options[item.selectedIndex]?.value??'':item.props.value??'';},set(value){item.props.value=value;}});return item;
}
const renderer=createRenderer({createElement:kind=>node(kind),createText:text=>node('text',text),createComment:text=>node('comment',text),setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},patchProp:(item,key,previous,next)=>item.props[key]=next,insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,insertStaticContent:(text,parent,anchor)=>{const item=node('static',text);const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return[item,item];}});
const reviewId='00000000-0000-4000-8000-000000000011';
function descriptor(resource='app:11',state='draft'){
 return{resource,name:'Aplicação de teste',version:'a'.repeat(64),state,desk_id:state==='published'?'45':null,dashboard_count:1,fields:[{id:501,label:'Valor',table_name:'Vendas',numeric:true,date:false,groupable:true},{id:502,label:'Região',table_name:'Vendas',numeric:false,date:false,groupable:true},{id:503,label:'Data',table_name:'Vendas',numeric:false,date:true,groupable:true}],aggregations:{SUM:'Total de valores que podem ser somados',AVG:'Média de observações individuais',COUNT:'Quantidade de valores preenchidos',MIN:'Menor valor',MAX:'Maior valor'}};
}
function approval(status='pending'){
 return{id:reviewId,type:'manual',project_id:null,status,expires_at:'2099-01-01T00:00:00Z',fingerprint:'f'.repeat(64),payload:{origin:'manual',action:{action:'create_dashboard',resource:'app:11',name:'Resumo comercial'},revision:{tenant_id:'100',resource:'app:11',dashboard_creation:{name:'Resumo comercial',app_name:'Aplicação de teste',dashboard_count_before:1,widget_count:3,selection:{metric:{label:'Valor',table_name:'Vendas'},group:{label:'Região',table_name:'Vendas'},metric_title:'Valor total',group_title:'Região',aggregation_label:'Total de valores que podem ser somados',chart_type:'bar'},preview:{metric_title:'Valor total',group_title:'Região',format:'currency',chart_type:'bar',value:2100,rows:[{label:'Norte',value:1500},{label:'Sul',value:600}],checked_at:'2026-11-02T12:00:00Z',truncated:false,scope:'Amostra consultada com o acesso da sua conta. Os filtros iniciais podem mudar o painel.'},coverage:{verified:true,source_count:1}},impact:'Os dashboards existentes serão preservados. Esta ação não publica a aplicação.'},...(status==='pending'?{}:{dashboard_creation_attempt:{resource:'app:11',started_at:'2026-11-02T12:00:00Z'}})}};
}
function receipt(item,verified=true,resource='app:11'){return{...item,status:'completed',payload:{...item.payload,execution:{result:{resource,confirmed_name:'Aplicação de teste',configuration_verified:verified,dashboard_creation:{verified,dashboard_id:22,name:item.payload.action.name,state:'draft',widget_ids:[101,102,103]}}}}};}
function mount({component=Form,onRequest=null,outcome='completed',verified=true,state='draft',initialApproval=null,receiptResource='app:11',fields=null}={}){
 const previous=Object.fromEntries(['document','Document','ShadowRoot','HTMLElement'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));Object.defineProperty(globalThis,'document',{configurable:true,writable:true,value:{activeElement:null,hidden:true}});for(const key of ['Document','ShadowRoot','HTMLElement'])Object.defineProperty(globalThis,key,{configurable:true,writable:true,value:class{}});
 const root=node('root'),calls=[],events=[],errors=[];let current=initialApproval??approval();const props=ref(component===Recovery?{approval:current}:{tenant:'100',tenantName:'Homologação',resource:{id:'11',ref:'app:11',kind:'app',name:'Aplicação de teste'}});
 const request=async(path,method,data)=>{
  calls.push({path,method,data:data===undefined?undefined:JSON.parse(JSON.stringify(data))});let response;
  if(path.endsWith('/dashboard-builder')){response=descriptor('app:'+path.split('/')[4],state);if(fields!==null)response.fields=fields;}
  else if(path.endsWith('/dashboard-builder/actions')){current=approval();current.payload.action.name=data.name;current.payload.revision.dashboard_creation.name=data.name;response={approval:current};}
  else if(path.endsWith('/decide')){current={...current,status:data.decision};response=current;}
  else if(path.endsWith('/dashboard-creation-verification')){current=receipt(current,verified,receiptResource);response={approval:current};}
  else {current=outcome==='completed'?receipt(current,verified,receiptResource):{...current,status:outcome,payload:{...current.payload,dashboard_creation_attempt:{resource:'app:11',started_at:'2026-11-02T12:00:00Z'},execution:{message:'Conferência pendente.'}}};response={approval:current};}
  response=JSON.parse(JSON.stringify(response));return onRequest?onRequest(path,method,data,response):response;
 };
 const app=renderer.createApp({setup:()=>()=>h(component,{...props.value,request,onUpdated:()=>events.push('updated'),onNotice:value=>events.push(['notice',value]),onCreated:value=>events.push(['created',value]),onDuplicate:()=>events.push('duplicate'),onSetup:()=>events.push('setup'),onConnect:()=>events.push('connect'),onConfirmed:value=>events.push(['confirmed',value])})});app.config.errorHandler=error=>errors.push(error.message);app.mount(root);
 return{root,calls,events,errors,props,button:label=>find(root,item=>item.kind==='button'&&text(item).trim()===label),field:(suffix,value)=>{const field=find(root,item=>String(item.props.id??'').endsWith('-'+suffix));assert.ok(field,'Campo '+suffix+' deve existir');field.props['onUpdate:modelValue'](value);},close:()=>{app.unmount();for(const[key,description]of Object.entries(previous)){if(description)Object.defineProperty(globalThis,key,description);else delete globalThis[key];}}};
}
async function settled(){await nextTick();await yieldEventLoop();await nextTick();}
async function chooseFields(view){view.field('name','Resumo comercial');view.field('calculation','SUM');view.field('metric','501');view.field('group','502');view.field('format','currency');await settled();}
async function prepare(view){await chooseFields(view);find(view.root,item=>item.kind==='form').props.onSubmit({preventDefault(){}});await settled();}
async function click(view,label){const button=view.button(label);assert.ok(button,'Botão '+label+' deve existir');if(typeof button.props.onClick==='function')button.props.onClick();else{let form=button.parent;while(form&&form.kind!=='form')form=form.parent;assert.ok(form,'Botão deve ter formulário');form.props.onSubmit({preventDefault(){}});}await settled();}
function render(component,props){return renderToString(createSSRApp(component,props));}

test('the builder submits chosen business fields and never writes before an explicit decision',async()=>{
 const view=mount();try{await settled();assert.equal(view.button('Conferir prévia e criação').props.disabled,true);await prepare(view);assert.deepEqual(view.calls.map(call=>call.path),['/catalog/100/apps/11/dashboard-builder','/catalog/100/apps/11/dashboard-builder/actions']);assert.deepEqual({...view.calls[1].data,request_id:'nonce'},{request_id:'nonce',version:'a'.repeat(64),name:'Resumo comercial',metric_id:501,group_id:502,aggregation:'SUM',grain:'AGP',format:'currency',metric_title:null});assert.ok(view.button('Autorizar criação do dashboard'));assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a verified creation opens the exact new dashboard instead of the first existing one',async()=>{
 const view=mount();try{await settled();await prepare(view);await click(view,'Autorizar criação do dashboard');assert.match(text(view.root),/Dashboard privado criado/);await click(view,'Ver dashboard interativo');assert.deepEqual(view.events.filter(item=>Array.isArray(item)&&item[0]==='created'),[['created',{resource:'app:11',dashboard_id:22}]]);assert.equal(view.calls.filter(call=>call.path.endsWith('/decide')).length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a completed status without a matching verified resource cannot offer an interactive dashboard',async()=>{
 for(const options of [{verified:false},{receiptResource:'app:12'}]){const view=mount(options);try{await settled();await prepare(view);await click(view,'Autorizar criação do dashboard');assert.equal(Boolean(view.button('Ver dashboard interativo')),false);assert.equal(view.events.some(item=>Array.isArray(item)&&item[0]==='created'),false);}finally{view.close();}}
});
test('date grouping sends the selected period and counting text always uses an integer format',async()=>{
 const view=mount();try{await settled();await chooseFields(view);view.field('calculation','COUNT');await settled();view.field('metric','502');view.field('group','503');await settled();view.field('grain','YEAR');await settled();assert.equal(find(view.root,item=>String(item.props.id??'').endsWith('-format')).props.disabled,true);find(view.root,item=>item.kind==='form').props.onSubmit({preventDefault(){}});await settled();assert.equal(view.calls[1].data.aggregation,'COUNT');assert.equal(view.calls[1].data.metric_id,502);assert.equal(view.calls[1].data.grain,'YEAR');assert.equal(view.calls[1].data.format,'integer');assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a lost preparation response retries the same immutable proposal',async()=>{
 let lost=false;const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/actions')&&!lost){lost=true;throw new Error('Resposta perdida.');}return response;}});try{await settled();await prepare(view);assert.equal(find(view.root,item=>item.kind==='fieldset').props.disabled,true);view.field('name','Outra proposta');await click(view,'Conferir esta solicitação');const requests=view.calls.filter(call=>call.path.endsWith('/actions'));assert.deepEqual(requests[0].data,requests[1].data);assert.equal(view.calls.some(call=>call.path.endsWith('/decide')),false);}finally{view.close();}
});
test('an uncertain approval is checked without another decision or creation',async()=>{
 const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/decide'))throw new Error('Decisão sem resposta.');return response;}});try{await settled();await prepare(view);await click(view,'Autorizar criação do dashboard');assert.equal(view.button('Autorizar criação do dashboard').props.disabled,true);await click(view,'Conferir solicitação');assert.ok(view.button('Ver dashboard interativo'));assert.equal(view.calls.filter(call=>call.path.endsWith('/decide')).length,1);assert.equal(view.calls.filter(call=>call.path.endsWith('/actions')).length,1);}finally{view.close();}
});
test('a failed sent creation offers read-only reconciliation instead of another proposal',async()=>{
 const view=mount({outcome:'failed'});try{await settled();await prepare(view);await click(view,'Autorizar criação do dashboard');assert.ok(view.button('Conferir criação'));assert.equal(Boolean(view.button('Editar proposta')),false);await click(view,'Conferir criação');assert.ok(view.button('Ver dashboard interativo'));assert.equal(view.calls.at(-1).path,`/bi-actions/${reviewId}/dashboard-creation-verification`);assert.equal(view.calls.filter(call=>call.path.endsWith('/actions')).length,1);}finally{view.close();}
});
test('published applications offer a private copy and applications without fields offer setup in a project',async()=>{
 for(const options of [{state:'published'},{fields:[]}]){const view=mount(options);try{await settled();assert.equal(Boolean(find(view.root,item=>item.kind==='form')),false);if(options.state){await click(view,'Duplicar para minha mesa');assert.deepEqual(view.events,['duplicate']);}else{await click(view,'Preparar dados no projeto');assert.deepEqual(view.events,['setup']);}assert.equal(view.calls.length,1);}finally{view.close();}}
});
test('a rejected proposal never asks for execution and can reopen fields for editing',async()=>{
 const view=mount();try{await settled();await prepare(view);await click(view,'Recusar');assert.match(text(view.root),/Criação recusada/);assert.equal(view.calls.length,3);await click(view,'Editar proposta');assert.ok(find(view.root,item=>item.kind==='form'));assert.equal(view.calls.at(-1).path,'/catalog/100/apps/11/dashboard-builder');assert.equal(view.calls.filter(call=>call.path.endsWith('/decide')).length,1);}finally{view.close();}
});
test('late field responses cannot replace another tenant or resource',async()=>{
 let release;const pending=new Promise(resolve=>release=resolve);let oldResponse;const view=mount({onRequest:(path,method,data,response)=>{if(path==='/catalog/100/apps/11/dashboard-builder'){oldResponse=response;return pending;}return response;}});try{await settled();view.props.value={tenant:'200',tenantName:'Outro',resource:{id:'22',kind:'app'}};await settled();release(oldResponse);await settled();await chooseFields(view);find(view.root,item=>item.kind==='form').props.onSubmit({preventDefault(){}});await settled();assert.equal(view.calls.at(-1).path,'/catalog/200/apps/22/dashboard-builder/actions');assert.deepEqual(view.errors,[]);}finally{release?.(oldResponse);view.close();}
});
test('the real review displays data and safeguards while escaping business labels',async()=>{
 const item=approval();item.payload.revision.dashboard_creation.preview.rows[0].label='<img src=x onerror=alert(1)>';const html=await render(Review,{approval:item,tenants:[{id:'100',name:'Homologação'}]});assert.match(html,/Prévia dos dados consultados/);assert.match(html,/2\.100,00/);assert.match(html,/Atualização dos dados conferida/);assert.match(html,/filtros iniciais/);assert.match(html,/&lt;img/);assert.doesNotMatch(html,/<img src=x/);assert.match(html,/1 dashboard existente será preservado/);assert.match(html,/Aplicação de teste/);
});
test('sample bars keep the zero position and sign when values cross zero',async()=>{
 const item=approval();item.payload.revision.dashboard_creation.preview.rows=[{label:'Negativo',value:-2},{label:'Positivo',value:6}];const html=await render(Review,{approval:item,tenants:[]});assert.match(html,/left:0%;width:25%/);assert.match(html,/left:25%;width:75%/);assert.match(html,/Negativo/);assert.match(html,/-R\$/);assert.match(html,/2,00/);
});
test('the action is offered only for applications and activity preserves creation recovery',async()=>{
 const props={tenant:'100',tenantName:'Homologação',resource:{id:'11',kind:'app',name:'Visão'},desks:[],request:async()=>{throw new Error('Sem chamadas durante renderização.');}};assert.match(await render(Manual,props),/Novo dashboard/);assert.doesNotMatch(await render(Manual,{...props,resource:{id:'12',kind:'flow'}}),/Novo dashboard/);const failed=approval('failed');const html=await render(Operations,{approvals:[failed],projects:[],tenants:[],request:props.request});assert.match(html,/Conferir criação/);assert.match(html,/não repete a criação/);const completed=await render(Operations,{approvals:[receipt(failed)],projects:[],tenants:[],request:props.request});assert.match(completed,/Dashboard privado criado/);assert.match(completed,/Resumo comercial/);
});
test('activity recovery refuses a receipt for another dashboard proposal',async()=>{
 const item=approval('failed');const view=mount({component:Recovery,initialApproval:item,onRequest:(path,method,data,response)=>{response.approval.payload.execution.result.dashboard_creation.name='Outro painel';return response;}});try{await settled();await click(view,'Conferir criação');assert.match(text(view.root),/ainda não foi confirmada/);assert.equal(view.events.some(item=>Array.isArray(item)&&item[0]==='confirmed'),false);}finally{view.close();}
});

test('a verified native refusal reopens editing without displaying a successful creation',async()=>{
 const view=mount({outcome:'failed',onRequest:(path,method,data,response)=>path.endsWith('/dashboard-creation-verification')?{approval:{...response.approval,status:'failed',payload:{...response.approval.payload,execution:{message:'O BI recusou esta criação antes de salvar.'},dashboard_creation_resolution:{state:'rejected',native_rejection_verified:true}}}}:response});
 try{await settled();await prepare(view);await click(view,'Autorizar criação do dashboard');await click(view,'Conferir criação');assert.ok(view.button('Editar proposta'));assert.equal(Boolean(view.button('Ver dashboard interativo')),false);assert.equal(Boolean(view.button('Conferir criação')),false);assert.match(text(view.root),/Criação recusada/);assert.equal(view.events.some(item=>Array.isArray(item)&&item[0]==='notice'),false);await click(view,'Editar proposta');assert.ok(find(view.root,item=>item.kind==='form'));assert.equal(view.calls.filter(call=>call.path.endsWith('/actions')).length,1);}finally{view.close();}
});
test('activity accepts only a matching read-verified refusal and hides the resolved recovery action',async()=>{
 const item=approval('failed');const view=mount({component:Recovery,initialApproval:item,onRequest:(path,method,data,response)=>({...response,approval:{...item,payload:{...item.payload,dashboard_creation_resolution:{state:'rejected',native_rejection_verified:true}}}})});
 try{await settled();await click(view,'Conferir criação');assert.equal(view.events.filter(item=>Array.isArray(item)&&item[0]==='confirmed').length,1);view.props.value={approval:view.events[0][1].approval};await settled();assert.equal(Boolean(view.button('Conferir criação')),false);assert.equal(view.calls.length,1);}finally{view.close();}
});

test('a definite preparation refusal keeps the choices editable and does not pretend a review was saved',async()=>{
 let refused=false;const view=mount({onRequest:(path,method,data,response)=>{if(path.endsWith('/actions')&&!refused){refused=true;throw Object.assign(new Error('O serviço de consulta recusou acesso.'),{status:422});}return response;}});
 try{await settled();await prepare(view);assert.match(text(view.root),/serviço de consulta recusou acesso/);assert.equal(find(view.root,item=>item.kind==='fieldset').props.disabled,false);assert.equal(Boolean(view.button('Conferir esta solicitação')),false);assert.doesNotMatch(text(view.root),/A proposta está guardada/);assert.equal(find(view.root,item=>String(item.props.id??'').endsWith('-name')).props.value,'Resumo comercial');view.field('name','Resumo revisado');await click(view,'Conferir prévia e criação');assert.ok(view.button('Autorizar criação do dashboard'));const sent=view.calls.filter(call=>call.path.endsWith('/actions'));assert.equal(sent.length,2);assert.notEqual(sent[0].data.request_id,sent[1].data.request_id);assert.equal(sent[1].data.name,'Resumo revisado');assert.equal(sent[1].data.metric_id,sent[0].data.metric_id);assert.equal(view.calls.some(call=>call.path.endsWith('/decide')),false);assert.deepEqual(view.errors,[]);}finally{view.close();}
});


test('missing personal MCP offers authorization in Connections and keeps the unavailable form hidden',async()=>{
 const view=mount({onRequest:(path,method,data,response)=>path.endsWith('/dashboard-builder')?{...response,creation_available:false,creation_unavailable_reason:'Autorize as consultas deste ambiente na página Conexões antes de usar o MCP.'}:response});
 try{await settled();assert.match(text(view.root),/Autorize a consulta dos dados/);assert.equal(text(globalThis.document.activeElement),'Autorize a consulta dos dados');assert.match(text(view.root),/Autorize as consultas deste ambiente/);assert.equal(Boolean(find(view.root,item=>item.kind==='form')),false);assert.equal(Boolean(view.button('Duplicar para minha mesa')),false);assert.equal(Boolean(view.button('Conferir prévia e criação')),false);await click(view,'Autorizar consultas em Conexões');assert.deepEqual(view.events,['connect']);assert.equal(view.calls.length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});

test('project authorship remains available without a personal MCP grant',async()=>{
 const view=mount({onRequest:(path,method,data,response)=>path.endsWith('/dashboard-builder')?{...response,creation_available:false}:response});
 try{await settled();await click(view,'Continuar no projeto');assert.deepEqual(view.events,['setup']);assert.equal(view.calls.length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});

test('a published application can be duplicated before authorizing MCP for its private copy',async()=>{
 const view=mount({state:'published',onRequest:(path,method,data,response)=>path.endsWith('/dashboard-builder')?{...response,creation_available:false}:response});
 try{await settled();assert.match(text(view.root),/Crie o novo painel em uma cópia/);assert.equal(Boolean(find(view.root,item=>item.kind==='form')),false);await click(view,'Duplicar para minha mesa');assert.deepEqual(view.events,['duplicate']);assert.equal(view.calls.length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
