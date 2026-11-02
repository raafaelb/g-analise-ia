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
let Form;
async function compile(name){
 const path=new URL(`../../resources/js/Components/${name}.vue`,import.meta.url);
 const {descriptor}=parse(await readFile(path,'utf8'));
 let script=compileScript(descriptor,{id:name+'-interaction',inlineTemplate:true}).content;
 script=script.replace(/import ApprovalReview from ['"]\.\/ApprovalReview.vue['"];?/,`const ApprovalReview={props:['approval'],setup:()=>()=>null};`);
 script=script.replace(/import BiLiveViewer from ['\"]\.\/BiLiveViewer.vue['\"];?/,`const BiLiveViewer={props:['dashboard'],setup:(props)=>()=>h('div',{'data-testid':'live-viewer'},String(props.dashboard??''))};`);
 script=script.replace(/import AppCreationRecovery from ['\"]\.\/AppCreationRecovery.vue['\"];?/,`const AppCreationRecovery={props:['approval'],setup:()=>()=>null};`);
 script=script.replace(/from (['"])([^'"]+)\1/g,(_,quote,source)=>`from ${quote}${source.startsWith('.')?new URL(source+'.ts',path).href:import.meta.resolve(source)}${quote}`);
 script="import {h} from '"+import.meta.resolve('vue')+"';\n"+script;
 const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
 return(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;
}
before(async()=>{Form=await compile('ProjectPreviewPane');});
function all(item,predicate){return[...(predicate(item)?[item]:[]),...item.children.flatMap(child=>all(child,predicate))];}
function find(item,predicate){return all(item,predicate)[0];}
function text(item){return item.text+item.children.map(text).join('');}
function node(kind,text=''){
 const item={kind,tagName:kind.toUpperCase(),text,props:{},children:[],parent:null,isConnected:true,addEventListener(){},removeEventListener(){},getClientRects(){return[{}];},getRootNode(){return globalThis.document;},focus(){globalThis.document.activeElement=this;}};
 Object.defineProperty(item,'value',{get(){return item.props.value??'';},set(value){item.props.value=value;}});
 return item;
}
const renderer=createRenderer({createElement:kind=>node(kind),createText:text=>node('text',text),createComment:text=>node('comment',text),setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},patchProp:(item,key,previous,next)=>item.props[key]=next,insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,insertStaticContent:(text,parent,anchor)=>{const item=node('static',text);const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return[item,item];}});
function mount({dashboards=[],available=true,failed=false}={}){
 const previous=Object.fromEntries(['window','document'].map(key=>[key,Object.getOwnPropertyDescriptor(globalThis,key)]));
 Object.defineProperty(globalThis,'window',{configurable:true,writable:true,value:{location:{search:''}}});
 Object.defineProperty(globalThis,'document',{configurable:true,writable:true,value:{hidden:true}});
 const root=node('root'),calls=[],errors=[];const project=ref({id:'own-project',source_ref:'app:12',tenant_id:'100',name:'Nova aplicação',preview_revision:null});let currentDashboards=dashboards;
 const request=async path=>{calls.push(path);if(failed)throw new Error('Consulta indisponível.');return {interactive_available:true,native_app_url:'https://example.test/app/12',dashboards:currentDashboards,dashboards_available:available};};
 const app=renderer.createApp({setup:()=>()=>h(Form,{project:project.value,artifacts:[],visible:true,request})});app.config.errorHandler=error=>errors.push(error.message);app.mount(root);
 return{root,calls,errors,project,setDashboards(value){currentDashboards=value;project.value={...project.value,preview_revision:'confirmed-app-change'};},live:()=>find(root,item=>item.props?.['data-testid']==='live-viewer'),close(){app.unmount();for(const[key,value]of Object.entries(previous)){if(value)Object.defineProperty(globalThis,key,value);else delete globalThis[key];}}};
}
async function settled(){await nextTick();await yieldEventLoop();await nextTick();}
test('an application with a confirmed empty dashboard list does not mount the native viewer',async()=>{
 const view=mount();try{await settled();assert.equal(Boolean(view.live()),false);assert.match(text(view.root),/ainda não tem dashboards/);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('the first confirmed dashboard replaces the empty guidance with the interactive viewer',async()=>{
 const view=mount();try{await settled();assert.equal(Boolean(view.live()),false);view.setDashboards([{id:42,name:'Resultado'}]);await settled();assert.deepEqual(view.errors,[]);assert.equal(view.calls.length,2,text(view.root));assert.ok(view.live(),text(view.root));assert.equal(text(view.live()),'42');}finally{view.close();}
});
test('an unavailable dashboard listing does not falsely claim that an application is empty',async()=>{
 const view=mount({available:false});try{await settled();assert.ok(view.live());assert.doesNotMatch(text(view.root),/ainda não tem dashboards/);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
test('a failed context query keeps the viewer closed and displays the actual error',async()=>{
 const view=mount({failed:true});try{await settled();assert.equal(Boolean(view.live()),false);assert.match(text(view.root),/Consulta indisponível/);assert.doesNotMatch(text(view.root),/ainda não tem dashboards/);assert.deepEqual(view.errors,[]);}finally{view.close();}
});
