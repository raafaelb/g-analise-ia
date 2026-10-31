import {readFile} from 'node:fs/promises';
import test from 'node:test';
import assert from 'node:assert/strict';
import {parse,compileScript} from '@vue/compiler-sfc';
import ts from 'typescript';
import {createRenderer,h,nextTick,ref} from 'vue';

const path=new URL('../../resources/js/Components/ResourceInspector.vue',import.meta.url);
const {descriptor}=parse(await readFile(path,'utf8'));
let script=compileScript(descriptor,{id:'inspector-regression',inlineTemplate:true}).content;
script=script.replace(/from (['"])(vue|lucide-vue-next)\1/g,(_,quote,name)=>`from ${quote}${import.meta.resolve(name)}${quote}`);
const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
const Inspector=(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;
function node(kind,text=''){return {kind,text,props:{},children:[],parent:null};}
const renderer=createRenderer({
 createElement:kind=>node(kind),createText:text=>node('text',text),createComment:text=>node('comment',text),
 setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},
 patchProp:(item,key,previous,next)=>item.props[key]=next,
 insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},
 remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},
 parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,
 insertStaticContent:(text,parent,anchor)=>{const item=node('static',text);const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return [item,item];},
});
function text(item){return item.text+item.children.map(text).join('');}
function find(item,predicate){if(predicate(item))return item;return item.children.map(child=>find(child,predicate)).find(Boolean);}
function mount(request){const root=node('root'),repaint=ref(0),resource=ref('table:90');const app=renderer.createApp({setup:()=>()=>{repaint.value;return h(Inspector,{tenant:'923',resource:{kind:'table',id:resource.value.slice(6),ref:resource.value},request});}});app.mount(root);return {root,repaint,resource,close:()=>app.unmount(),inspect:()=>find(root,item=>item.kind==='button'&&text(item).includes('Amostra dos dados')).props.onClick()};}
test('a pending table preview and its result survive unrelated parent repaints with the same resource',async()=>{
 let resolve,calls=0;const view=mount(()=>{calls++;return new Promise(done=>resolve=done);});
 try{const pending=view.inspect();view.repaint.value++;await nextTick();resolve({checked_at:'2026-10-31T15:00:00Z',table:{columns:['STATUS'],rows:[{STATUS:'record-confirmed'}]}});await pending;await nextTick();assert.match(text(view.root),/record-confirmed/);view.repaint.value++;await nextTick();assert.match(text(view.root),/record-confirmed/);assert.equal(calls,1);}finally{view.close();}
});
test('changing the actual table discards the response of the previously selected resource',async()=>{
 let resolve;const view=mount(()=>new Promise(done=>resolve=done));
 try{const pending=view.inspect();view.resource.value='table:91';await nextTick();resolve({checked_at:'2026-10-31T15:00:00Z',table:{columns:['STATUS'],rows:[{STATUS:'old-private-result'}]}});await pending;await nextTick();assert.doesNotMatch(text(view.root),/old-private-result/);}finally{view.close();}
});
