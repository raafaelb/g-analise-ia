import {readFile} from 'node:fs/promises';
import test from 'node:test';
import assert from 'node:assert/strict';
import {parse,compileScript} from '@vue/compiler-sfc';
import ts from 'typescript';
import {createRenderer,h,nextTick,ref} from 'vue';

async function componentUrl(name,bindings={}){
 const {descriptor}=parse(await readFile(new URL(`../../resources/js/Components/${name}.vue`,import.meta.url),'utf8'));
 let script=compileScript(descriptor,{id:name+'-regression',inlineTemplate:true}).content;
 script=script.replace(/from (['"])([^'"]+)\1/g,(_,quote,dependency)=>{const target=bindings[dependency]??(['vue','lucide-vue-next'].includes(dependency)?import.meta.resolve(dependency):null);assert.ok(target,`Resolve the actual component dependency ${dependency}`);return `from ${quote}${target}${quote}`;});
 const code=ts.transpileModule(script,{compilerOptions:{module:ts.ModuleKind.ESNext,target:ts.ScriptTarget.ESNext}}).outputText;
 return 'data:text/javascript;base64,'+Buffer.from(code).toString('base64');
}
const messageUrl=await componentUrl('BiExecutionMessage',{'../lib/execution-message':new URL('../../resources/js/lib/execution-message.ts',import.meta.url).href});
const Inspector=(await import(await componentUrl('ResourceInspector',{'./BiExecutionMessage.vue':messageUrl,'../lib/sample-values':new URL('../../resources/js/lib/sample-values.ts',import.meta.url).href}))).default;
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
function mount(request,kind='table',download){const root=node('root'),repaint=ref(0),resource=ref(kind+':90');const app=renderer.createApp({setup:()=>()=>{repaint.value;return h(Inspector,{tenant:'923',resource:{kind:resource.value.split(':')[0],id:resource.value.split(':')[1],ref:resource.value},request,download});}});app.mount(root);return {root,repaint,resource,close:()=>app.unmount(),inspect:()=>find(root,item=>item.kind==='button'&&text(item).includes('Amostra')).props.onClick(),history:()=>find(root,item=>item.kind==='button'&&text(item).includes('Histórico de cargas')).props.onClick(),events:()=>find(root,item=>item.kind==='button'&&text(item).includes('Ocorrências')).props.onClick(),export:()=>find(root,item=>item.kind==='button'&&text(item).includes('Exportar XLSX')).props.onClick()};}
test('a pending table preview and its result survive unrelated parent repaints with the same resource',async()=>{
 let resolve,calls=0;const view=mount(()=>{calls++;return new Promise(done=>resolve=done);});
 try{const pending=view.inspect();view.repaint.value++;await nextTick();resolve({checked_at:'2026-10-31T15:00:00Z',table:{columns:['STATUS'],rows:[{STATUS:'record-confirmed'}]}});await pending;await nextTick();assert.match(text(view.root),/record-confirmed/);view.repaint.value++;await nextTick();assert.match(text(view.root),/record-confirmed/);assert.equal(calls,1);}finally{view.close();}
});
test('changing the actual table discards the response of the previously selected resource',async()=>{
 let resolve;const view=mount(()=>new Promise(done=>resolve=done));
 try{const pending=view.inspect();view.resource.value='table:91';await nextTick();resolve({checked_at:'2026-10-31T15:00:00Z',table:{columns:['STATUS'],rows:[{STATUS:'old-private-result'}]}});await pending;await nextTick();assert.doesNotMatch(text(view.root),/old-private-result/);}finally{view.close();}
});


test('cadastro sample values stay plain text and use the authorized resource endpoint',async()=>{
 const requests=[];const view=mount(async(...args)=>{requests.push(args);return {table:{columns:['META'],column_labels:{META:'Meta (R$)'},rows:[{META:'<script>alert(1)</script>'}]}};},'writeback');
 try{await view.inspect();await nextTick();assert.equal(requests[0][0],'/catalog/923/resources/writeback/90/inspect/data');assert.match(text(view.root),/Meta \(R\$\)/);assert.match(text(view.root),/<script>alert\(1\)<\/script>/);assert.equal(find(view.root,item=>Object.hasOwn(item.props,'innerHTML')),undefined);}finally{view.close();}
});
test('a cadastro export is explicit and its pending delivery is discarded after changing resource',async()=>{
 let resolve;const downloads=[];const view=mount(async()=>({}),'writeback',(...args)=>{downloads.push(args);return new Promise(done=>resolve=done);});
 try{const pending=view.export();await nextTick();assert.equal(downloads.length,1);assert.equal(downloads[0][0],'/catalog/923/writebacks/90/export');assert.equal(downloads[0][1],'cadastro-90.xlsx');assert.equal(downloads[0][2](),true);assert.equal(find(view.root,item=>item.kind==='button'&&text(item).includes('Preparando planilha')).props.disabled,true);view.resource.value='writeback:91';await nextTick();assert.equal(downloads[0][2](),false);resolve();await pending;await nextTick();assert.equal(find(view.root,item=>item.kind==='button'&&text(item).includes('Exportar XLSX')).props.disabled,false);}finally{view.close();}
});
test('a native file denial is visible in the same resource without opening another page',async()=>{
 const view=mount(async()=>({}),'writeback',async()=>{throw new Error('O BI recusou o download desta planilha. Confirme a permissão de exportar.');});
 try{await view.export();await nextTick();const alert=find(view.root,item=>item.props.role==='alert');assert.match(text(alert),/permissão de exportar/);assert.equal(find(view.root,item=>item.kind==='button'&&text(item).includes('Exportar XLSX')).props.disabled,false);}finally{view.close();}
});

test('flow history keeps zero counters and labels them as processing rather than stored records',async()=>{
 const requests=[];const view=mount(async(...args)=>{requests.push(args);return {history:[{id:'synthetic-run',state:'succeeded',started_at:'2026-10-31T10:00:00Z',finished_at:'2026-10-31T10:01:00Z',duration_ms:60000,rows_in:0,rows_out:0,message:null}]};},'flow');
 try{await view.history();await nextTick();const value=text(view.root);assert.equal(requests[0][0],'/catalog/923/resources/flow/90/inspect/history');assert.match(value,/Linhas de entrada/);assert.match(value,/Linhas de saída/);assert.match(value,/Contadores do processamento informados pelo BI/);assert.doesNotMatch(value,/Linhas gravadas/);assert.equal(find(view.root,item=>item.kind==='dd'&&text(item)==='0')?.kind,'dd');}finally{view.close();}
});
test('unknown flow counters are not displayed as zero or as a physical table count',async()=>{
 const view=mount(async()=>({history:[{id:'synthetic-run',state:'succeeded',started_at:'2026-10-31T10:00:00Z',finished_at:'2026-10-31T10:01:00Z',duration_ms:null,rows_in:null,rows_out:null,message:null}]}),'flow');
 try{await view.history();await nextTick();const value=text(view.root);assert.match(value,/Concluída com sucesso/);assert.doesNotMatch(value,/Linhas de entrada|Linhas de saída|Linhas gravadas|Contadores do processamento/);}finally{view.close();}
});


test('technical load failures show a readable summary and collapsed details while keeping the failed state',async()=>{
 const message='java.lang.IllegalStateException: Falha da fonte\n    at synthetic.reader.Extract.run(Extract.java:10)';
 const view=mount(async()=>({history:[{id:'synthetic-failure',state:'failed',started_at:null,finished_at:null,duration_ms:null,rows_in:null,rows_out:null,message}]}),'flow');
 try{await view.history();await nextTick();assert.match(text(view.root),/O BI informou uma falha nesta execução/);const details=find(view.root,item=>item.kind==='details');assert.ok(details);assert.equal(details.props.open,undefined);assert.match(text(details),/Ver detalhes técnicos/);assert.match(text(details),/synthetic.reader/);assert.match(text(view.root),/Falha/);}finally{view.close();}
});


test('agent occurrences use the same readable message and remain scoped to the authorized resource endpoint',async()=>{
 const requests=[];const view=mount(async(...args)=>{requests.push(args);return {events:[{name:'Ocorrência sintética',state:'ERROR',expected_at:null,started_at:null,recovered_at:null,message:'Traceback (most recent call last):\n    synthetic reader failed'}]};},'agent');
 try{await view.events();await nextTick();assert.equal(requests.length,1);assert.equal(requests[0][0],'/catalog/923/resources/agent/90/inspect/events');assert.match(text(view.root),/O BI informou uma falha nesta execução/);const details=find(view.root,item=>item.kind==='details');assert.ok(details);assert.equal(details.props.open,undefined);assert.match(text(details),/synthetic reader failed/);}finally{view.close();}
});


test('the readable sample preserves decimal precision and can switch to original values without another request',async()=>{
 let calls=0;const view=mount(async()=>{calls++;return {table:{columns:['VALOR','DATA','CODIGO'],column_labels:{VALOR:'Valor'},column_types:{VALOR:'Number',DATA:'Date',CODIGO:'String'},rows:[{VALOR:'1234.123456789',DATA:'2026-11-03T00:00:00.000Z',CODIGO:'001234'}]}};});
 try{
  await view.inspect();await nextTick();
  assert.match(text(view.root),/1\.234,123456789/);assert.match(text(view.root),/03\/11\/2026/);assert.match(text(view.root),/001234/);
  const sample=find(view.root,item=>item.props.role==='region'&&item.props['aria-label']==='Dados da amostra');assert.equal(sample.props.tabindex,'0');
  const value=find(view.root,item=>item.kind==='td'&&item.props.title==='1234.123456789');assert.ok(value);
  const toggle=find(view.root,item=>item.kind==='button'&&text(item)==='Exibir valores originais');assert.equal(toggle.props['aria-pressed'],false);
  toggle.props.onClick();await nextTick();
  assert.match(text(view.root),/1234\.123456789/);assert.match(text(view.root),/2026-11-03T00:00:00\.000Z/);assert.equal(toggle.props['aria-pressed'],true);
  toggle.props.onClick();await nextTick();assert.match(text(view.root),/1\.234,123456789/);assert.equal(calls,1);
 }finally{view.close();}
});

test('unknown metadata leaves numeric-looking strings untouched and does not offer a misleading format toggle',async()=>{
 const view=mount(async()=>({table:{columns:['CODIGO','VALOR'],rows:[{CODIGO:'001234',VALOR:'1234.000000000'}]}}));
 try{await view.inspect();await nextTick();assert.match(text(view.root),/1234\.000000000/);assert.match(text(view.root),/001234/);assert.equal(find(view.root,item=>item.kind==='button'&&text(item)==='Exibir valores originais'),undefined);}finally{view.close();}
});

test('a sample column named like an inherited object property is rendered as data',async()=>{
 const view=mount(async()=>({table:{columns:['constructor'],column_labels:{},column_types:{},rows:[{constructor:'1234.000000000'}]}}));
 try{await view.inspect();await nextTick();assert.match(text(view.root),/constructor/);assert.match(text(view.root),/1234\.000000000/);assert.doesNotMatch(text(view.root),/function Object/);}finally{view.close();}
});
