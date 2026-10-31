import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import {createSSRApp} from 'vue';
import {renderToString} from '@vue/server-renderer';

let server;
let Operations;
before(async()=>{
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 Operations=(await server.ssrLoadModule('/resources/js/Components/BiOperations.vue')).default;
});
after(async()=>server?.close());

function operation(status='completed'){
 return {id:'00000000-0000-4000-8000-000000000001',project_id:'p1',type:'manual',status,title:'Publicar fonte de dados',payload:{
  action:{action:'publish_flow',resource:'flow:2',publish_mode:'new',desk:10,copy_data:false},
  revision:{tenant_id:'100',info:{data:{nome:'ETL financeiro'}},publication:{kind:'flow',mode:'new',copy_data:false,source:{name:'ETL financeiro',resource:'flow:2'},target:{name:'ETL financeiro',resource:'flow:2'},destination:{id:10,name:'Dados financeiros',resource:'dw-desk:10'},tables:[],impact:'Nova definição compartilhada.',data_impact:'A publicação não executa uma carga.'}},
  publication_attempt:{started_at:'2026-10-31T10:00:00Z'},
  execution:{result:status==='completed'?{publication:{verified:true,kind:'flow',name:'ETL financeiro',resource:'flow:2',destination:{name:'Dados financeiros'},data_verified:false,requires_data_check:true,message:'Confira os dados e uma execução concluída antes de considerar a fonte atualizada.'}}:{}},
 }};
}

async function render(approval){
 return renderToString(createSSRApp(Operations,{approvals:[approval],projects:[{id:'p1',name:'Dados'}],tenants:[{id:'100',name:'Homologação'}],request:async()=>{throw new Error('O histórico não deve consultar o BI ao renderizar.');}}));
}

test('a confirmed flow publication keeps its review and the remaining data checks visible',async()=>{
 const html=await render(operation());
 assert.match(html,/Publicação conferida/);
 assert.match(html,/Dados financeiros/);
 assert.match(html,/Confira os dados e uma execução concluída/);
 assert.match(html,/<details[^>]*class="operation-review"/);
 assert.match(html,/Ver revisão desta operação/);
 assert.doesNotMatch(html,/Conferir publicação<\/button>/);
});

test('an uncertain flow publication offers verification without claiming confirmation',async()=>{
 const html=await render(operation('failed'));
 assert.match(html,/Conferir publicação/);
 assert.match(html,/não envia outra publicação/);
 assert.doesNotMatch(html,/Publicação conferida/);
});

test('provider names are escaped in both the receipt and the retained review',async()=>{
 const approval=operation();
 approval.payload.execution.result.publication.name='<img src=x onerror=alert(1)>';
 approval.payload.revision.publication.source.name='<script>alert(2)</script>';
 const html=await render(approval);
 assert.match(html,/&lt;img/);
 assert.match(html,/&lt;script/);
 assert.doesNotMatch(html,/<img src=x|<script>alert\(2\)/);
});
