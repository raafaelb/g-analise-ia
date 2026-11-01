import {readFile} from 'node:fs/promises';
import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import {parse,compileScript} from '@vue/compiler-sfc';
import ts from 'typescript';
import {createRenderer,h,nextTick,toRaw} from 'vue';

let server,Catalog;
before(async()=>{
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 const path=new URL('../../resources/js/Components/BiCatalog.vue',import.meta.url);
 const {descriptor}=parse(await readFile(path,'utf8'));
 const compiled=compileScript(descriptor,{id:'catalog-preference-regression',inlineTemplate:true});
 const sources=[...new Set(Object.values(compiled.imports).map(item=>item.source).filter(source=>source.endsWith('.vue')))];
 const modules=await Promise.all(sources.map(async source=>[source,(await server.ssrLoadModule('/resources/js/Components/'+source.slice(2))).default]));
 globalThis.__catalogRegressionModules=Object.fromEntries(modules);
 try{
  let script=compiled.content.replace(/import ([A-Za-z_$][\w$]*) from (['"])(\.\/[^'"]+\.vue)\2;?/g,(_,name,_quote,source)=>`const ${name}=globalThis.__catalogRegressionModules[${JSON.stringify(source)}];`);
  script=script.replace(/from (['"])([^'"]+)\1/g,(_,quote,source)=>`from ${quote}${source.startsWith('.')?new URL(source+'.ts',path).href:import.meta.resolve(source)}${quote}`);
  const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
  Catalog=(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;
 }finally{delete globalThis.__catalogRegressionModules;}
});
after(async()=>server?.close());

function node(kind,text=''){
 const item={kind,tagName:kind.toUpperCase(),text,props:{},children:[],parent:null,addEventListener(){},removeEventListener(){},getRootNode(){return globalThis.document;},getClientRects(){return [{}];},scrollIntoView(options){this.scrollOptions=options;},focus(){globalThis.document.activeElement=this;}};
 Object.defineProperty(item,'options',{get(){return item.children.filter(child=>child.kind==='option');}});
 Object.defineProperty(item,'value',{get(){return item.props.value??'';},set(value){item.props.value=value;}});
 return item;
}
const renderer=createRenderer({
 createElement:kind=>node(kind),createText:text=>node('text',text),createComment:text=>node('comment',text),
 setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},
 patchProp:(item,key,previous,next)=>item.props[key]=next,
 insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},
 remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},
 parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,
 insertStaticContent:(text,parent,anchor)=>{const item=node('static',text);const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return [item,item];},
});
function find(item,predicate){if(predicate(item))return item;return item.children.map(child=>find(child,predicate)).find(Boolean);}
function text(item){return item.text+item.children.map(text).join('');}
function mount(storageDescriptor,{apps=[]}={}){
 const previous=Object.fromEntries(['window','document','localStorage','Document','ShadowRoot'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 const windowStub={addEventListener(){},removeEventListener(){},matchMedia(){return {matches:true};}};
 Object.defineProperty(windowStub,'localStorage',{configurable:true,...storageDescriptor});
 Object.defineProperty(globalThis,'window',{configurable:true,writable:true,value:windowStub});
 Object.defineProperty(globalThis,'document',{configurable:true,writable:true,value:{activeElement:null}});
 Object.defineProperty(globalThis,'Document',{configurable:true,writable:true,value:class {}});
 Object.defineProperty(globalThis,'ShadowRoot',{configurable:true,writable:true,value:class {}});
 Object.defineProperty(globalThis,'localStorage',{configurable:true,...storageDescriptor});
 const requests=[],errors=[],root=node('root');
 const kinds=['app','bi-desk','dw-desk','table','flow','schedule','agent','writeback','deck','presentation','investigation','credential','variables'];
 const snapshot={tenant:{id:'923',name:'Ambiente de teste',read_only:false},resources:Object.fromEntries(kinds.map(kind=>[kind,[]])),capabilities:Object.fromEntries(kinds.map(kind=>[kind,{status:'available',count:0}])),tags:[],warnings:[],synced_at:'2026-10-31T12:00:00Z'};
 snapshot.resources.app=apps;snapshot.capabilities.app.count=apps.length;
 const app=renderer.createApp({setup:()=>()=>h(Catalog,{tenants:[{id:'923',name:'Ambiente de teste'}],connected:true,userId:17,role:'admin',request:async(...args)=>{requests.push(args);return snapshot;}})});
 app.config.errorHandler=error=>errors.push(error.message);app.mount(root);
 return {root,requests,errors,changeServerApps:apps=>{snapshot.resources.app=apps;snapshot.capabilities.app.count=apps.length;},choose:()=>find(root,item=>item.props['aria-label']==='Tenant do catálogo').props['onUpdate:modelValue']('923'),close:()=>{app.unmount();for(const [key,descriptor]of Object.entries(previous)){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}}};
}
async function settled(){await nextTick();await nextTick();await nextTick();}

test('blocked browser storage does not prevent selecting a tenant and loading its catalog',async()=>{
 const view=mount({get(){throw new Error('Storage access denied');}});
 try{view.choose();await settled();assert.deepEqual(view.errors,[]);assert.equal(view.requests.length,1);assert.equal(view.requests[0][0],'/catalog/923');assert.match(text(view.root),/Nenhum recurso nesta área/);}finally{view.close();}
});

test('a preference write rejected by the browser quota still allows the catalog request',async()=>{
 const view=mount({value:{getItem(){return null;},setItem(){throw new Error('Quota exceeded');}}});
 try{view.choose();await settled();assert.deepEqual(view.errors,[]);assert.equal(view.requests.length,1);assert.equal(view.requests[0][0],'/catalog/923');}finally{view.close();}
});

test('the saved authorized tenant still opens automatically when preferences are available',async()=>{
 const view=mount({value:{getItem(key){return key==='g-analise:tenant:17'?'923':null;},setItem(){}}});
 try{await settled();assert.deepEqual(view.errors,[]);assert.equal(view.requests.length,1);assert.equal(view.requests[0][0],'/catalog/923');}finally{view.close();}
});


test('changing the catalog layout preserves the query, saves the preference and does not reload BI data',async()=>{
 const values=new Map([['g-analise:tenant:17','923'],['g-analise:catalog-view:17','list']]);
 const storage={getItem:key=>values.get(key)??null,setItem:(key,value)=>values.set(key,value)};
 const view=mount({value:storage});
 try{await settled();assert.equal(find(view.root,item=>item.props['aria-label']==='Exibir em lista').props['aria-pressed'],true);find(view.root,item=>item.props['aria-label']==='Buscar no catálogo').props['onUpdate:modelValue']('estoque');await settled();find(view.root,item=>item.props['aria-label']==='Exibir em cartões').props.onClick();await settled();assert.equal(find(view.root,item=>item.props['aria-label']==='Buscar no catálogo').value,'estoque');assert.equal(find(view.root,item=>item.props['aria-label']==='Exibir em cartões').props['aria-pressed'],true);assert.equal(values.get('g-analise:catalog-view:17'),'grid');assert.equal(view.requests.length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
 const reopened=mount({value:storage});
 try{await settled();assert.equal(find(reopened.root,item=>item.props['aria-label']==='Exibir em cartões').props['aria-pressed'],true);assert.equal(reopened.requests.length,1);assert.deepEqual(reopened.errors,[]);}finally{reopened.close();}
});

test('the layout can still change for the current page when saving its preference fails',async()=>{
 const view=mount({value:{getItem(){return null;},setItem(){throw new Error('Quota exceeded');}}});
 try{view.choose();await settled();find(view.root,item=>item.props['aria-label']==='Exibir em lista').props.onClick();await settled();assert.equal(find(view.root,item=>item.props['aria-label']==='Exibir em lista').props['aria-pressed'],true);assert.equal(view.requests.length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});


function syntheticApp(id){return {id:String(id),ref:'app:'+id,kind:'app',name:'Painel de teste '+id,description:'Descrição sintética',state:'published',desk_id:null,tags:[],dashboard_count:1,dashboards:[]};}
function resourceButtons(item){return [item,...item.children.flatMap(resourceButtons)].filter(child=>child.kind==='button'&&child.props.class==='catalog-resource');}

test('pagination moves to the new results heading without another BI request',async()=>{
 const view=mount({value:{getItem:key=>key==='g-analise:tenant:17'?'923':null,setItem(){}}},{apps:Array.from({length:19},(_,index)=>syntheticApp(index+1))});
 try{await settled();assert.equal(resourceButtons(view.root).length,18);await find(view.root,item=>item.kind==='button'&&text(item).trim()==='Próxima').props.onClick();await settled();assert.equal(resourceButtons(view.root).length,1);assert.match(text(resourceButtons(view.root)[0]),/Painel de teste 19/);const heading=find(view.root,item=>item.kind==='h2'&&text(item)==='Aplicações');assert.ok(toRaw(globalThis.document.activeElement)===heading,'the results heading should receive keyboard focus');assert.equal(heading.scrollOptions?.block,'start');assert.equal(view.requests.length,1);assert.deepEqual(view.errors,[]);}finally{view.close();}
});

test('refreshing a smaller catalog restores a valid page instead of leaving an empty result area',async()=>{
 const view=mount({value:{getItem:key=>key==='g-analise:tenant:17'?'923':null,setItem(){}}},{apps:Array.from({length:19},(_,index)=>syntheticApp(index+1))});
 try{await settled();await find(view.root,item=>item.kind==='button'&&text(item).trim()==='Próxima').props.onClick();await settled();view.changeServerApps([syntheticApp(99)]);await find(view.root,item=>item.kind==='button'&&text(item).trim()==='Atualizar catálogo').props.onClick();await settled();assert.equal(resourceButtons(view.root).length,1);assert.match(text(resourceButtons(view.root)[0]),/Painel de teste 99/);assert.equal(view.requests.length,2);assert.equal(view.requests[1][0],'/catalog/923?refresh=1');assert.deepEqual(view.errors,[]);}finally{view.close();}
});
