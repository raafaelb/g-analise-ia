import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import {createSSRApp} from 'vue';
import {renderToString} from '@vue/server-renderer';

let server,LoadStatus,Overview;
before(async()=>{
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 [LoadStatus,Overview]=await Promise.all(['BiLoadStatus','ResourceOverview'].map(async name=>(await server.ssrLoadModule(`/resources/js/Components/${name}.vue`)).default));
});
after(async()=>server?.close());
const request=async()=>{throw new Error('Apresentar um comprovante não deve executar consultas ou cargas.');};
function render(component,props){return renderToString(createSSRApp(component,props));}
function execution(counts){return {id:'synthetic-load',status:'succeeded',bindings:[{flow_id:'10',name:'Fonte de teste',context:'Total'}],proof:{evidence:'live_schedule_log',flows:[{flow_id:'10',context:'Total',status:'succeeded',...counts}]}};}

for(const count of [0,3085663]){
 test(`native output counter ${count} is identified without claiming stored records`,async()=>{
  const html=await render(LoadStatus,{execution:execution({rows_in:count,rows_out:count}),request});
  assert.ok(html.includes(`${count.toLocaleString('pt-BR')} linhas de saída`));
  assert.match(html,/Contadores do processamento informados pelo BI/);
  assert.match(html,/não comprovam a quantidade de registros na tabela/);
  assert.doesNotMatch(html,/linhas gravadas/);
 });
}
test('history with unknown counts does not turn them into zero or a stored-row count',async()=>{
 const item=execution({rows_in:null,rows_out:null});item.proof.evidence='schedule_history';
 const html=await render(LoadStatus,{execution:item,request});
 assert.match(html,/Atualização concluída/);
 assert.doesNotMatch(html,/linhas de saída|linhas de entrada|linhas gravadas|Contadores do processamento/);
});
test('step configuration is identified as metadata that can be inherited by a clone',async()=>{
 const html=await render(Overview,{overview:{facts:[],columns_available:false,nodes_available:true,nodes:[{id:'read',name:'Ler a fonte',type:'ExtractPostgreSQL',rows:0,executed_at:'2026-10-26T06:00:00Z'}]},related:[]});
 assert.match(html,/0 linhas informadas/);
 assert.match(html,/Data informada na etapa/);
 assert.match(html,/podem ter sido herdados/);
 assert.match(html,/não comprovam uma carga desta fonte/);
 assert.doesNotMatch(html,/Última execução informada/);
});
test('an unexecuted step does not gain a timestamp or execution proof',async()=>{
 const html=await render(Overview,{overview:{facts:[],columns_available:false,nodes_available:true,nodes:[{id:'read',name:'Ler a fonte',type:'ExtractPostgreSQL',rows:null,executed_at:null}]},related:[]});
 assert.match(html,/Ler a fonte/);
 assert.doesNotMatch(html,/Data informada na etapa|linhas informadas|podem ter sido herdados/);
});
