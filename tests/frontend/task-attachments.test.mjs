import {readFile} from 'node:fs/promises';
import {before,test} from 'node:test';
import {setImmediate as yieldEventLoop} from 'node:timers/promises';
import assert from 'node:assert/strict';
import {parse,compileScript} from '@vue/compiler-sfc';
import ts from 'typescript';
import {createRenderer,h,nextTick,ref} from 'vue';
import {taskAttachmentIds,moveTaskAttachment} from '../../resources/js/lib/task-attachments.ts';
let Picker;
before(async()=>{
 const path=new URL('../../resources/js/Components/TaskAttachmentPicker.vue',import.meta.url);
 const {descriptor}=parse(await readFile(path,'utf8'));
 const compiled=compileScript(descriptor,{id:'task-attachments-regression',inlineTemplate:true});
 const script=compiled.content.replace(/from (['"])([^'"]+)\1/g,(_,quote,source)=>`from ${quote}${source.startsWith('.')?new URL(source+'.ts',path).href:import.meta.resolve(source)}${quote}`);
 const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
 Picker=(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;
});
function node(kind,text=''){
 const item={kind,tagName:kind.toUpperCase(),text,props:{},children:[],parent:null,isConnected:true,addEventListener(){},removeEventListener(){},getClientRects(){return [{}];},getRootNode(){return globalThis.document;},focus(){globalThis.document.activeElement=this;}};
 item.querySelectorAll=()=>all(item,child=>child.kind==='button'||child.kind==='input');
 return item;
}
let body;
const renderer=createRenderer({
 createElement:kind=>node(kind),createText:text=>node('text',text),createComment:text=>node('comment',text),
 setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},
 patchProp:(item,key,previous,next)=>item.props[key]=next,
 insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},
 remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},
 parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,
 querySelector:selector=>selector==='body'?body:null,
 insertStaticContent:(text,parent,anchor)=>{const item=node('static',text);const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return [item,item];},
});
function all(item,predicate){return [...(predicate(item)?[item]:[]),...item.children.flatMap(child=>all(child,predicate))];}
function find(item,predicate){return all(item,predicate)[0];}
function text(item){return item.text+item.children.map(text).join('');}
function files(count=3){return Array.from({length:count},(_,index)=>({id:'file-'+String(index+1).padStart(2,'0'),name:'Referencia '+(index+1)+'.csv',kind:'attachment',created_at:'2026-11-01T18:00:00Z',metadata:{extraction:{status:'ready'}}}));}
function mount(items=files(),initial=null){
 const previous=Object.fromEntries(['document','Document','ShadowRoot'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 Object.defineProperty(globalThis,'document',{configurable:true,writable:true,value:{activeElement:null}});
 Object.defineProperty(globalThis,'Document',{configurable:true,writable:true,value:class {}});
 Object.defineProperty(globalThis,'ShadowRoot',{configurable:true,writable:true,value:class {}});
 body=node('body');const root=node('root'),choice=ref(initial),available=ref(items),updates=[],opened=[],errors=[];
 const app=renderer.createApp({setup:()=>()=>h(Picker,{files:available.value,modelValue:choice.value,'onUpdate:modelValue':value=>{updates.push(value);choice.value=value;},onOpened:value=>opened.push(value)})});
 app.config.errorHandler=error=>errors.push(error.message);app.mount(root);
 const button=name=>find(body,item=>item.kind==='button'&&(item.props['aria-label']===name||text(item).includes(name)));
 return {root,updates,opened,errors,body,choice,available,button,open:()=>{const trigger=find(root,item=>item.kind==='button');trigger.focus();trigger.props.onClick();},toggle:id=>find(body,item=>item.kind==='input'&&item.props['aria-label']===`Usar ${available.value.find(file=>file.id===id).name} neste pedido`).props.onChange(),close:()=>{app.unmount();for(const[key,descriptor]of Object.entries(previous)){if(descriptor)Object.defineProperty(globalThis,key,descriptor);else delete globalThis[key];}}};
}
async function settled(){await nextTick();await yieldEventLoop();await nextTick();}

test('default selection is chronological, ignores dashboard previews and is copied before an asynchronous send',()=>{
 const source=[...files(13).reverse(),{id:'preview',kind:'dashboard_export',name:'preview.png'}];
 assert.deepEqual(taskAttachmentIds(source,null),files(12).map(file=>file.id));
 const choice=['file-13','file-01'];const snapshot=taskAttachmentIds(source,choice);choice.push('file-02');assert.deepEqual(snapshot,['file-13','file-01']);
 assert.deepEqual(taskAttachmentIds(source,[]),[]);
});

test('reordering preserves the chosen set and leaves the source selection unchanged',()=>{
 const choice=['first','second','third'];assert.deepEqual(moveTaskAttachment(choice,'second',-1),['second','first','third']);assert.deepEqual(moveTaskAttachment(choice,'second',1),['first','third','second']);assert.deepEqual(moveTaskAttachment(choice,'first',-1),choice);assert.deepEqual(choice,['first','second','third']);
});

test('the picker commits an explicit empty selection only after the user applies it',async()=>{
 const view=mount();try{view.open();await settled();view.button('Sem anexos').props.onClick();await settled();assert.deepEqual(view.updates,[]);view.button('Usar seleção').props.onClick();await settled();assert.deepEqual(view.updates,[[]]);assert.deepEqual(view.opened,[true,false]);assert.equal(find(view.body,item=>item.props.role==='dialog'),undefined);assert.deepEqual(view.errors,[]);}finally{view.close();}
});

test('cancel discards changes and Escape returns focus to the picker control',async()=>{
 const view=mount();try{view.open();await settled();view.toggle('file-01');await settled();const dialog=find(view.body,item=>item.props.role==='dialog');let prevented=false;dialog.props.onKeydown({key:'Escape',stopPropagation(){},preventDefault(){prevented=true;}});await settled();assert.equal(prevented,true);assert.deepEqual(view.updates,[]);assert.match(globalThis.document.activeElement.props['aria-label'],/^Escolher arquivos/);assert.deepEqual(view.errors,[]);}finally{view.close();}
});

test('a thirteenth file can replace another without exceeding the limit',async()=>{
 const view=mount(files(13));try{view.open();await settled();const thirteenth=()=>find(view.body,item=>item.kind==='input'&&item.props['aria-label']==='Usar Referencia 13.csv neste pedido');assert.equal(thirteenth().props.disabled,true);view.toggle('file-01');await settled();assert.equal(thirteenth().props.disabled,false);view.toggle('file-13');await settled();view.button('Usar seleção').props.onClick();await settled();assert.deepEqual(view.updates,[['file-02','file-03','file-04','file-05','file-06','file-07','file-08','file-09','file-10','file-11','file-12','file-13']]);assert.deepEqual(view.errors,[]);}finally{view.close();}
});

test('search keeps checked files selected and priority controls change the committed image order',async()=>{
 const view=mount();try{view.open();await settled();view.button('Priorizar Referencia 3.csv').props.onClick();await settled();find(view.body,item=>item.kind==='input'&&item.props['aria-label']==='Buscar arquivo no projeto').props['onUpdate:modelValue']('Referencia 1');await settled();assert.equal(all(view.body,item=>item.kind==='input'&&item.props.type==='checkbox').length,1);view.button('Usar seleção').props.onClick();await settled();assert.deepEqual(view.updates,[['file-01','file-03','file-02']]);assert.deepEqual(view.errors,[]);}finally{view.close();}
});

test('a selected file that disappears prevents applying a misleading reduced selection',async()=>{
 const view=mount(files(),['file-01','file-02']);try{view.open();await settled();view.available.value=files().slice(1);await settled();assert.equal(view.button('Usar seleção').props.disabled,true);assert.match(text(view.body),/ficaram indisponíveis/);view.button('Usar padrão').props.onClick();await settled();view.button('Usar seleção').props.onClick();await settled();assert.deepEqual(view.updates,[['file-02','file-03']]);assert.deepEqual(view.errors,[]);}finally{view.close();}
});

test('filenames are rendered as text and the picker explains the shared visual limit and conversation history',async()=>{
 const items=files(1);items[0].name='<img src=x onerror=alert(1)>.csv';const view=mount(items);try{view.open();await settled();assert.match(text(view.body),/<img src=x onerror=alert\(1\)>\.csv/);assert.equal(all(view.body,item=>item.kind==='img').length,0);assert.match(text(view.body),/3 imagens ou páginas de PDF/);assert.match(text(view.body),/memória da conversa/);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
