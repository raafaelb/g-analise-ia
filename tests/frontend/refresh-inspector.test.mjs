import {readFile} from 'node:fs/promises';
import test from 'node:test';
import assert from 'node:assert/strict';
import {parse,compileScript} from '@vue/compiler-sfc';
import ts from 'typescript';
import {createRenderer,h,nextTick,ref} from 'vue';

const {descriptor}=parse(await readFile(new URL('../../resources/js/Components/RefreshInspector.vue',import.meta.url),'utf8'));
let script=compileScript(descriptor,{id:'refresh-inspector-regression',inlineTemplate:true}).content;
script=script.replace(/from (['"])(vue|lucide-vue-next)\1/g,(_,quote,name)=>`from ${quote}${import.meta.resolve(name)}${quote}`);
const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
const Inspector=(await import('data:text/javascript;base64,'+Buffer.from(code).toString('base64'))).default;
function node(kind,text=''){return {kind,text,tagName:kind.toUpperCase(),props:{},children:[],parent:null,events:{},addEventListener(name,fn){this.events[name]=fn;},removeEventListener(name){delete this.events[name];},get options(){return this.children.filter(child=>child.kind==='option');}};}
const renderer=createRenderer({
 createElement:kind=>node(kind),createText:text=>node('text',text),createComment:text=>node('comment',text),
 setText:(item,text)=>item.text=text,setElementText:(item,text)=>{item.text=text;item.children=[];},
 patchProp:(item,key,previous,next)=>{item.props[key]=next;if(key==='value'){item.value=next;item._value=next;}if(key==='multiple')item.multiple=next;},
 insert:(item,parent,anchor=null)=>{if(item.parent){const old=item.parent.children.indexOf(item);if(old>=0)item.parent.children.splice(old,1);}const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;},
 remove:item=>{if(item.parent){const index=item.parent.children.indexOf(item);if(index>=0)item.parent.children.splice(index,1);}item.parent=null;},
 parentNode:item=>item.parent,nextSibling:item=>item.parent?.children[item.parent.children.indexOf(item)+1]??null,
 insertStaticContent:(text,parent,anchor)=>{const item=node('static',text);const index=parent.children.indexOf(anchor);if(index<0)parent.children.push(item);else parent.children.splice(index,0,item);item.parent=parent;return [item,item];},
});
function text(item){return item.text+item.children.map(text).join('');}
function find(item,predicate){if(predicate(item))return item;return item.children.map(child=>find(child,predicate)).find(Boolean);}
function report(name){return {configured:true,last_executions_successful:true,verified:true,timezone:'America/Sao_Paulo',unresolved_tables:[],sources:[{flow_id:'20',name,tables:[],schedules:[],configured:true,status:'daily_configured',recommendation:'preserve_existing',last_execution:null}]};}
function deferred(){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};}
function mount(request){
 const root=node('root'),projects=ref([{id:'a',tenant_id:'923',source_ref:'app:10',name:'Projeto A'},{id:'b',tenant_id:'923',source_ref:'app:11',name:'Projeto B'}]);let updates=0;
 const app=renderer.createApp({setup:()=>()=>h(Inspector,{projects:projects.value,request,onUpdated:()=>updates++})});app.mount(root);
 const button=()=>find(root,item=>item.kind==='button'&&(/Verificar atualizações|Conferindo fontes/.test(text(item))));
 return {root,projects,button,updates:()=>updates,close:()=>app.unmount(),choose:async id=>{find(root,item=>item.kind==='select').props['onUpdate:modelValue'](id);await nextTick();},inspect:()=>button().props.onClick()};
}

test('changing projects discards the previous pending coverage response',async()=>{
 const task=deferred();const view=mount(()=>task.promise);
 try{await view.choose('a');const pending=view.inspect();await view.choose('b');task.resolve(report('OLD-COVERAGE-A'));await pending;await nextTick();assert.doesNotMatch(text(view.root),/OLD-COVERAGE-A/);assert.equal(view.updates(),0);assert.equal(view.button().props.disabled,false);}finally{view.close();}
});
test('returning to the same project cannot replace a newer report with an older response',async()=>{
 const old=deferred(),recent=deferred();let calls=0;const view=mount(()=>++calls===1?old.promise:recent.promise);
 try{await view.choose('a');const first=view.inspect();await view.choose('b');await view.choose('a');const second=view.inspect();recent.resolve(report('CURRENT-COVERAGE'));await second;old.resolve(report('STALE-COVERAGE'));await first;await nextTick();assert.match(text(view.root),/CURRENT-COVERAGE/);assert.doesNotMatch(text(view.root),/STALE-COVERAGE/);assert.equal(view.updates(),1);}finally{view.close();}
});
test('an old error does not clear the loading state or add an error to the current project',async()=>{
 const old=deferred(),recent=deferred();let calls=0;const view=mount(()=>++calls===1?old.promise:recent.promise);
 try{await view.choose('a');const first=view.inspect();await view.choose('b');const second=view.inspect();old.reject(new Error('OLD-PRIVATE-ERROR'));await first;await nextTick();assert.doesNotMatch(text(view.root),/OLD-PRIVATE-ERROR/);assert.equal(view.button().props.disabled,true);recent.resolve(report('CURRENT-COVERAGE'));await second;await nextTick();assert.equal(view.button().props.disabled,false);}finally{view.close();}
});
test('ordinary project list refreshes preserve an in-flight coverage result for the same binding',async()=>{
 const task=deferred();const view=mount(()=>task.promise);
 try{await view.choose('a');const pending=view.inspect();view.projects.value=view.projects.value.map(project=>({...project,name:project.name+' atualizado'}));await nextTick();task.resolve(report('SAME-BINDING'));await pending;await nextTick();assert.match(text(view.root),/SAME-BINDING/);assert.equal(view.updates(),1);}finally{view.close();}
});
for(const [label,change] of [['application',projects=>projects.map(project=>project.id==='a'?{...project,source_ref:'app:99'}:project)],['tenant',projects=>projects.map(project=>project.id==='a'?{...project,tenant_id:'999'}:project)],['project removal',projects=>projects.filter(project=>project.id!=='a')]]){
 test(`changing the ${label} invalidates an old result even with the same project selection`,async()=>{
  const task=deferred();const view=mount(()=>task.promise);
  try{await view.choose('a');const pending=view.inspect();view.projects.value=change(view.projects.value);await nextTick();task.resolve(report('OLD-BINDING'));await pending;await nextTick();assert.doesNotMatch(text(view.root),/OLD-BINDING/);assert.equal(view.updates(),0);}finally{view.close();}
 });
}
test('repeated clicks cannot start duplicate checks for the same selected project',async()=>{
 const task=deferred();let calls=0;const view=mount(()=>{calls++;return task.promise;});
 try{await view.choose('a');const first=view.inspect();const second=view.inspect();assert.equal(calls,1);task.resolve(report('ONE-CHECK'));await Promise.all([first,second]);assert.equal(view.updates(),1);}finally{view.close();}
});
test('a failed read clears an earlier successful report and a retry can recover',async()=>{
 let calls=0;const view=mount(async()=>{calls++;if(calls===2)throw new Error('Metadados não atualizados; tente novamente.');return report(calls===1?'FIRST-REPORT':'RECOVERED-REPORT');});
 try{await view.choose('a');await view.inspect();await nextTick();assert.match(text(view.root),/FIRST-REPORT/);await view.inspect();await nextTick();assert.doesNotMatch(text(view.root),/FIRST-REPORT/);assert.match(text(view.root),/Metadados não atualizados/);await view.inspect();await nextTick();assert.match(text(view.root),/RECOVERED-REPORT/);assert.doesNotMatch(text(view.root),/Metadados não atualizados/);}finally{view.close();}
});
test('multiple unidentified bindings remain separate readable warnings',async()=>{
 const view=mount(async()=>({...report('Fonte conhecida'),configured:false,verified:false,unresolved_tables:[{table_id:null,reason:'Primeiro vínculo a conferir'},{table_id:null,reason:'Segundo vínculo a conferir'}]}));
 try{await view.choose('a');await view.inspect();await nextTick();const value=text(view.root);assert.equal(value.match(/Fonte não identificada/g)?.length,2);assert.match(value,/Primeiro vínculo a conferir/);assert.match(value,/Segundo vínculo a conferir/);assert.doesNotMatch(value,/Tabela #null|Tabela #undefined/);}finally{view.close();}
});
test('successful recent loads do not appear fully verified without a correlated test',async()=>{
 const view=mount(async()=>({...report('Fonte conhecida'),verified:false}));
 try{await view.choose('a');await view.inspect();await nextTick();const summary=find(view.root,item=>item.props.class?.['inspection-summary']||item.props.class?.includes?.('inspection-summary'));assert.match(text(view.root),/teste pendente/);assert.doesNotMatch(text(view.root),/Atualização diária verificada/);assert.doesNotMatch(String(summary?.props.class),/success/);}finally{view.close();}
});
test('a changed schedule does not keep its old active label in the report',async()=>{
 const data=report('Fonte conhecida');data.configured=false;data.verified=false;data.sources[0].configured=false;data.sources[0].status='configuration_changed';data.sources[0].schedules=[{id:'100',name:'Madrugada',active:true,configuration_current:false,frequency:'Diariamente',timezone:'America/Sao_Paulo'}];
 const view=mount(async()=>data);
 try{await view.choose('a');await view.inspect();await nextTick();const item=find(view.root,node=>node.kind==='li');assert.match(text(item),/Configuração a conferir/);assert.doesNotMatch(text(item),/Ativa/);}finally{view.close();}
});
