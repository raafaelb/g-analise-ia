import {readFile} from 'node:fs/promises';
import {before,test} from 'node:test';
import {setImmediate as yieldEventLoop} from 'node:timers/promises';
import assert from 'node:assert/strict';
import {parse,compileScript} from '@vue/compiler-sfc';
import ts from 'typescript';
import {createRenderer,h,nextTick,ref} from 'vue';
let Catalog,activeFixture;
const kinds=['app','bi-desk','dw-desk','table','flow','schedule','agent','writeback','deck','presentation','investigation','credential','variables'];
function resource(kind,id,name){return {id:String(id),ref:kind+':'+id,kind,name:name??kind+' de teste '+id,description:'Referência fictícia',state:'draft',desk_id:null,desk_known:true,tags:[],dashboards:[],dashboard_count:null,table_count:null,active:null};}
function stub(name){return {props:['resource','kind'],setup(props,{emit}){return()=>{
 const save=(kind,id)=>{const row=activeFixture.save(kind,id);emit('saved',row.ref);};
 if(name==='BiCredentialForm'||name==='BiCredentialActions')return h('button',{onClick:()=>save('credential',props.resource?.id??42)},'Concluir conexão de teste');
 if(name==='BiDeskForm')return h('button',{onClick:()=>save(props.kind,props.resource?.id??42)},'Concluir mesa de teste');
 if(name==='BiScheduleForm')return h('button',{onClick:()=>save('schedule',props.resource?.id??42)},'Concluir agenda de teste');
 if(name==='BiManualAction')return h('div',[h('button',{onClick:()=>{const row=activeFixture.save(props.resource.kind,44);emit('published',row.ref);}},'Concluir publicação de teste'),h('button',{onClick:()=>{activeFixture.remove(props.resource.kind,props.resource.id);emit('deleted');}},'Concluir exclusão de teste')]);
 if(name==='ResourceOverview')return h('button',{onClick:()=>emit('open',resource('credential',1))},'Abrir conexão relacionada de teste');
 return h('div');
 }}};}
before(async()=>{
 const path=new URL('../../resources/js/Components/BiCatalog.vue',import.meta.url);
 const {descriptor}=parse(await readFile(path,'utf8'));
 const compiled=compileScript(descriptor,{id:'catalog-refresh-regression',inlineTemplate:true});
 globalThis.__catalogRefreshChildren=Object.fromEntries(Object.values(compiled.imports).filter(item=>item.source.endsWith('.vue')).map(item=>[item.source,stub(item.source.split('/').at(-1).slice(0,-4))]));
 try{
  let script=compiled.content.replace(/import ([A-Za-z_$][\w$]*) from (['"])(\.\/[^'"]+\.vue)\2;?/g,(_,name,_quote,source)=>`const ${name}=globalThis.__catalogRefreshChildren[${JSON.stringify(source)}];`);
  script=script.replace(/from (['"])([^'"]+)\1/g,(_,quote,source)=>`from ${quote}${source.startsWith('.')?new URL(source+'.ts',path).href:import.meta.resolve(source)}${quote}`);
  const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
  Catalog=(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;
 }finally{delete globalThis.__catalogRefreshChildren;}
});
function all(item,predicate){return [...(predicate(item)?[item]:[]),...item.children.flatMap(child=>all(child,predicate))];}
function find(item,predicate){return all(item,predicate)[0];}
function text(item){return item.text+item.children.map(text).join('');}
function node(kind,text=''){
 const item={kind,tagName:kind.toUpperCase(),text,props:{},children:[],parent:null,isConnected:true,addEventListener(){},removeEventListener(){},getRootNode(){return globalThis.document;},getClientRects(){return [{}];},focus(){globalThis.document.activeElement=this;},querySelector(){return find(this,item=>item.kind==='button'&&String(item.props.class??'').includes('modal-close'));},querySelectorAll(){return all(this,item=>['button','input','select','textarea'].includes(item.kind));}};
 Object.defineProperty(item,'options',{get(){return item.children.filter(child=>child.kind==='option');}});
 Object.defineProperty(item,'value',{get(){return item.kind==='select'?item.options[item.selectedIndex]?.value??'':item.props.value??'';},set(value){item.props.value=value;}});
 return item;
}
const renderer=createRenderer({
 createElement:kind=>node(kind),createText:text=>node('text',text),createComment:text=>node('comment',text),
 setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},patchProp:(item,key,previous,next)=>item.props[key]=next,
 insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},
 remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,
 insertStaticContent:(text,parent,anchor)=>{const item=node('static',text);const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return[item,item];},
});
function mount({onRequest=null}={}){
 const previous=Object.fromEntries(['window','document','localStorage','Document','ShadowRoot','HTMLElement'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 Object.defineProperty(globalThis,'window',{configurable:true,writable:true,value:{addEventListener(){},removeEventListener(){},matchMedia(){return{matches:true};}}});
 Object.defineProperty(globalThis,'document',{configurable:true,writable:true,value:{activeElement:null}});
 for(const name of ['Document','ShadowRoot','HTMLElement'])Object.defineProperty(globalThis,name,{configurable:true,writable:true,value:class{}});
 Object.defineProperty(globalThis,'localStorage',{configurable:true,writable:true,value:{getItem:key=>key==='g-analise:tenant:17'?'923':null,setItem(){}}});
 const resources=new Map(kinds.map(kind=>[kind,[resource(kind,1)]]));
 const fixture={save(kind,id){const row=resource(kind,id,kind+' atualizado '+id);resources.set(kind,[...resources.get(kind).filter(item=>item.id!==String(id)),row]);return row;},remove(kind,id){resources.set(kind,resources.get(kind).filter(item=>item.id!==String(id)));}};
 activeFixture=fixture;
 const requests=[],errors=[],root=node('root'),connected=ref(true);
 const request=async(...args)=>{
  requests.push(args);const path=args[0];const tenant=path.split('/')[2].split('?')[0];const type=/\/sections\/([a-z-]+)/.exec(path)?.[1];const match=/\/resources\/([a-z-]+)\/([0-9]+)/.exec(path);
  let result;
  if(match){const row=resources.get(match[1]).find(item=>item.id===match[2])??resource(match[1],match[2]);result={resource:row,tenant:{id:tenant,name:'Ambiente '+tenant,read_only:false},details:{},overview:[],related:[],management:{},viewer_available:false};}
  else {const loaded=type?({app:['app','bi-desk'],'bi-desk':['bi-desk','app'],'dw-desk':['dw-desk','table','flow','writeback'],flow:['flow','dw-desk'],table:['table','dw-desk'],writeback:['writeback','dw-desk']}[type]??[type]):kinds;result={tenant:{id:tenant,name:'Ambiente '+tenant,read_only:false},...(type?{section:type}:{}),resources:Object.fromEntries(loaded.map(kind=>[kind,resources.get(kind)])),capabilities:Object.fromEntries(loaded.map(kind=>[kind,{status:'available',count:resources.get(kind).length}])),tags:[],warnings:[],synced_at:'2026-11-01T18:00:00Z'};}
  const response=structuredClone(result);return onRequest?onRequest(path,response):response;
 };
 const app=renderer.createApp({setup:()=>()=>h(Catalog,{tenants:[{id:'923',name:'Primeiro ambiente'},{id:'999',name:'Segundo ambiente'}],connected:connected.value,userId:17,role:'admin',request})});
 app.config.errorHandler=error=>errors.push(error.message);app.mount(root);
 const button=label=>find(root,item=>item.kind==='button'&&text(item).trim()===label);
 return {root,requests,errors,button,category:label=>find(root,item=>item.kind==='button'&&text(item).trim().startsWith(label)&&!String(item.props.class??'').includes('catalog-resource')),chooseTenant:value=>find(root,item=>item.props['aria-label']==='Tenant do catálogo').props['onUpdate:modelValue'](value),disconnect:()=>connected.value=false,openFirst:()=>find(root,item=>item.kind==='button'&&item.props.class==='catalog-resource').props.onClick(),close:()=>{app.unmount();activeFixture=null;for(const[key,descriptor]of Object.entries(previous)){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}}};
}
async function settled(){await nextTick();await yieldEventLoop();await nextTick();}
async function choose(view,category){view.category(category).props.onClick();await settled();}
async function create(view,category,label,complete){await choose(view,category);view.button(label).props.onClick();await settled();view.button(complete).props.onClick();await settled();}
function modal(view){return find(view.root,item=>item.props.role==='dialog');}
function freshRequests(view){return view.requests.map(args=>args[0]).filter(path=>path.includes('refresh=1'));}
for(const scenario of [{kind:'credential',category:'Conexões de dados',create:'Adicionar nova conexão',complete:'Concluir conexão de teste'},{kind:'bi-desk',category:'Mesas de aplicações',create:'Nova mesa de aplicações',complete:'Concluir mesa de teste'},{kind:'dw-desk',category:'Mesas de dados',create:'Nova mesa de dados',complete:'Concluir mesa de teste'},{kind:'schedule',category:'Agendas',create:'Nova agenda',complete:'Concluir agenda de teste'}]){
 test(`opening a saved ${scenario.kind} refreshes its category once and reopens its confirmed details`,async()=>{
  const view=mount();try{await settled();await create(view,scenario.category,scenario.create,scenario.complete);assert.deepEqual(freshRequests(view),['/catalog/923/sections/'+scenario.kind+'?refresh=1']);assert.match(text(modal(view)),new RegExp(scenario.kind+' atualizado 42'));assert.equal(view.requests.filter(args=>args[0]==='/catalog/923/sections/'+scenario.kind).length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
 });
}
test('a connection edited through another category opens in the refreshed connection category without a duplicate request',async()=>{
 const view=mount();try{await settled();view.openFirst();await settled();view.button('Abrir conexão relacionada de teste').props.onClick();await settled();view.button('Concluir conexão de teste').props.onClick();await settled();assert.deepEqual(freshRequests(view),['/catalog/923/sections/credential?refresh=1']);assert.match(text(modal(view)),/credential atualizado 1/);assert.equal(view.requests.filter(args=>args[0]==='/catalog/923/sections/credential').length,0);assert.ok(find(view.root,item=>item.kind==='h2'&&text(item)==='Conexões de dados'));assert.deepEqual(view.errors,[]);}finally{view.close();}
});
for(const [kind,category]of [['app','Aplicações'],['flow','Fontes / ETL']]){
 test(`a confirmed ${kind} publication refreshes only its category and opens the surviving resource`,async()=>{
  const view=mount();try{await settled();await choose(view,category);view.openFirst();await settled();view.button('Concluir publicação de teste').props.onClick();await settled();assert.deepEqual(freshRequests(view),['/catalog/923/sections/'+kind+'?refresh=1']);assert.match(text(modal(view)),new RegExp(kind+' atualizado 44'));assert.ok(view.requests.some(args=>args[0]==='/catalog/923/resources/'+kind+'/44'));assert.deepEqual(view.errors,[]);}finally{view.close();}
 });
}
test('a confirmed deletion refreshes the visible category and removes the deleted resource without reopening it',async()=>{
 const view=mount();try{await settled();view.openFirst();await settled();view.button('Concluir exclusão de teste').props.onClick();await settled();assert.deepEqual(freshRequests(view),['/catalog/923/sections/app?refresh=1']);assert.equal(modal(view),undefined);assert.equal(find(view.root,item=>item.kind==='button'&&item.props.class==='catalog-resource'),undefined);assert.match(text(view.root),/Nenhum recurso nesta área/);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a scoped refresh discards previously loaded unrelated counts while the explicit refresh button still requests all areas',async()=>{
 const view=mount();try{await settled();view.button('Atualizar catálogo').props.onClick();await settled();assert.match(text(view.category('Tabelas')),/1/);await create(view,'Conexões de dados','Adicionar nova conexão','Concluir conexão de teste');assert.match(text(view.category('Tabelas')),/Consultar/);assert.deepEqual(freshRequests(view),['/catalog/923?refresh=1','/catalog/923/sections/credential?refresh=1']);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a newer category selection supersedes the pending post-save refresh and prevents the old modal reopening',async()=>{
 let release;const pending=new Promise(resolve=>release=resolve);let deferred;
 const view=mount({onRequest:(path,response)=>{if(path.includes('refresh=1')){deferred=response;return pending;}return response;}});
 try{await settled();await create(view,'Conexões de dados','Adicionar nova conexão','Concluir conexão de teste');await choose(view,'Fontes / ETL');release(deferred);await settled();await settled();assert.equal(modal(view),undefined);assert.ok(find(view.root,item=>item.kind==='h2'&&text(item)==='Fontes / ETL'));assert.equal(view.requests.at(-1)[0],'/catalog/923/sections/flow');assert.deepEqual(view.errors,[]);}finally{release?.(deferred);view.close();}
});
test('changing tenant during a post-save refresh cannot open or copy the old environment resource',async()=>{
 let release;const pending=new Promise(resolve=>release=resolve);let deferred;
 const view=mount({onRequest:(path,response)=>{if(path.includes('refresh=1')){deferred=response;return pending;}return response;}});
 try{await settled();await create(view,'Agendas','Nova agenda','Concluir agenda de teste');view.chooseTenant('999');await settled();release(deferred);await settled();await settled();assert.equal(modal(view),undefined);assert.equal(view.requests.at(-1)[0],'/catalog/999/sections/schedule');assert.equal(view.requests.some(args=>args[0].startsWith('/catalog/999/resources/')),false);assert.deepEqual(view.errors,[]);}finally{release?.(deferred);view.close();}
});
test('a failed post-save read keeps retry scoped and never replays the already completed write',async()=>{
 let failed=false;
 const view=mount({onRequest:(path,response)=>{if(path.includes('refresh=1')&&!failed){failed=true;throw new Error('Consulta temporariamente indisponível.');}return response;}});
 try{await settled();await create(view,'Agendas','Nova agenda','Concluir agenda de teste');assert.equal(modal(view),undefined);assert.match(text(view.root),/Consulta temporariamente indisponível/);view.button('Tentar novamente').props.onClick();await settled();assert.equal(view.requests.at(-1)[0],'/catalog/923/sections/schedule');assert.equal(view.requests.every(args=>args[1]===undefined),true);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
