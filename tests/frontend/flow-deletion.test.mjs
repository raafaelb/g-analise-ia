import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import {createSSRApp} from 'vue';
import {renderToString} from '@vue/server-renderer';

let server,Manual,Review,Recovery,Operations,ChatReview;
before(async()=>{
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 [Manual,Review,Recovery,Operations,ChatReview]=await Promise.all(['BiManualAction','ApprovalReview','DeletionRecovery','BiOperations','ConversationApprovalCard'].map(async name=>(await server.ssrLoadModule(`/resources/js/Components/${name}.vue`)).default));
});
after(async()=>server?.close());
const request=async()=>{throw new Error('Renderizar a revisão não deve consultar ou modificar o BI.');};
const tenants=[{id:'100',name:'Homologação'}];
function approval(status='pending'){
 return {id:'00000000-0000-4000-8000-000000000001',project_id:'p1',status,type:'manual',title:'Excluir rascunho criado por este projeto',expires_at:'2099-01-01T00:00:00Z',payload:{action:{action:'delete_draft',resource:'flow:2'},revision:{tenant_id:'100',resource:'flow:2',info:{data:{nome:'Carga de teste'}},flow_deletion:{kind:'flow',resource:'flow:2',name:'Carga de teste',tables:[{resource:'table:600',name:'Destino de teste',column_count:3}],cascade:false,impact:'O ETL e suas tabelas serão excluídos, incluindo dados de teste. O checkpoint preserva configuração, não os dados.'}},deletion_attempt:{started_at:'2026-10-31T12:00:00Z'}}};
}
function render(component,props){return renderToString(createSSRApp(component,props));}

test('the flow catalog offers deletion only for a portal-managed draft',async()=>{
 const props={tenant:'100',tenantName:'Homologação',resource:{id:'2',kind:'flow',name:'Carga de teste'},desks:[],request};
 assert.match(await render(Manual,{...props,management:{can_delete_draft:true}}),/Excluir rascunho criado aqui/);
 assert.doesNotMatch(await render(Manual,{...props,management:{can_delete_draft:false}}),/Excluir rascunho criado aqui/);
});

test('pending deletion disables new actions and explains how to reconcile it',async()=>{
 const html=await render(Manual,{tenant:'100',tenantName:'Homologação',resource:{id:'2',kind:'flow',name:'Carga de teste'},desks:[],management:{deletion_pending:true,can_publish:false,can_delete_draft:false},request});
 assert.match(html,/<button[^>]*disabled[^>]*>[\s\S]*?Duplicar ETL<\/button>/);
 assert.match(html,/Use Conferir exclusão na conversa ou em Atividade/);
 assert.doesNotMatch(html,/Publicar ETL<\/button>|Excluir rascunho criado aqui<\/button>/);
});

test('the deletion review names each table and discloses removal of loaded data',async()=>{
 const html=await render(Review,{approval:approval(),tenants});
 assert.match(html,/Excluir este ETL e 1 tabela de destino/);
 assert.match(html,/Destino de teste/);
 assert.match(html,/table:600/);
 assert.match(html,/incluindo dados de teste/);
 assert.match(html,/não os dados/);
 assert.doesNotMatch(html,/A aplicação original e as fontes não são removidas/);
});

test('provider names are escaped in a destructive review',async()=>{
 const item=approval();item.payload.revision.flow_deletion.tables[0].name='<img src=x onerror=alert(1)>';
 const html=await render(Review,{approval:item,tenants});
 assert.match(html,/&lt;img/);
 assert.doesNotMatch(html,/<img src=x/);
});

test('recovery is offered only after a failed flow deletion was actually sent',async()=>{
 const failed=approval('failed');
 assert.match(await render(Recovery,{approval:failed,request}),/Conferir exclusão/);
 assert.match(await render(Recovery,{approval:failed,request}),/não exclui novamente/);
 for(const status of ['pending','approved','executing','completed']){
  assert.doesNotMatch(await render(Recovery,{approval:approval(status),request}),/Conferir exclusão/);
 }
 delete failed.payload.deletion_attempt;
 assert.doesNotMatch(await render(Recovery,{approval:failed,request}),/Conferir exclusão/);
});

test('uncertain deletion can be checked directly in the conversation and activity',async()=>{
 const item=approval('failed');item.type='execution';
 assert.match(await render(ChatReview,{approval:item,tenants,request}),/Conferir exclusão/);
 assert.match(await render(Operations,{approvals:[item],projects:[],tenants,request}),/Conferir exclusão/);
});

test('a verified deletion receipt remains visible in activity',async()=>{
 const item=approval('completed');item.payload.execution={result:{deletion:{verified:true,message:'ETL e tabelas excluídos e conferidos.'}}};
 const html=await render(Operations,{approvals:[item],projects:[],tenants,request});
 assert.match(html,/Exclusão conferida/);
 assert.match(html,/ETL e tabelas excluídos e conferidos/);
 assert.doesNotMatch(html,/Conferir exclusão<\/button>/);
});
