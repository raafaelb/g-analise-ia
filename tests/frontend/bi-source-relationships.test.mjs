import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import {createSSRApp} from 'vue';
import {renderToString} from '@vue/server-renderer';

let server;
let Relationships;
before(async()=>{
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 Relationships=(await server.ssrLoadModule('/resources/js/Components/BiSourceRelationships.vue')).default;
});
after(async()=>server?.close());

function report(overrides={}){
 return {relationships:[{id:'fk-one',name:'orders_customer_fk',from:{schema:'public',table:'orders'},to:{schema:'public',table:'customers'},columns:[{source:'customer_id',target:'id',position:1}],column_count:1,complete:true,validated:true,enabled:null}],scope_warning:'Somente vínculos declarados e visíveis. Uma lista vazia não comprova ausência de vínculos.',offset:0,page_size:200,next_offset:null,truncated:false,search:null,schema:null,...overrides};
}
async function render(data,busy=false,error=''){
 return renderToString(createSSRApp(Relationships,{report:data,schema:'',busy,error}));
}

test('a declared relationship shows the direction and paired fields without claiming business cardinality',async()=>{
 const html=await render(report());
 assert.match(html,/Esta tabela/);
 assert.match(html,/Referência nesta tabela/);
 assert.match(html,/customer_id/);
 assert.match(html,/customers/);
 assert.match(html,/Validado na fonte/);
 assert.doesNotMatch(html,/1:N|1:1|Muitos para um/);
});

test('partial composite pairs and disabled unvalidated links remain visibly different',async()=>{
 const data=report();
 data.relationships[0].complete=false;
 data.relationships[0].column_count=2;
 data.relationships.push({...data.relationships[0],id:'fk-two',complete:false,enabled:false,validated:false});
 const html=await render(data);
 assert.match(html,/Campos incompletos/);
 assert.match(html,/antes de combinar as tabelas/);
 assert.match(html,/Desativado na fonte/);
 assert.match(html,/Não validado na fonte/);
 assert.match(html,/também não está validado/);
});

test('large pages initially show a bounded list and keep page navigation explicit',async()=>{
 const data=report({truncated:true,next_offset:200});
 data.relationships=Array.from({length:22},(_,i)=>({...data.relationships[0],id:`fk-${i}`,name:`visible-${i}`}));
 const html=await render(data);
 assert.match(html,/visible-19/);
 assert.doesNotMatch(html,/visible-20/);
 assert.match(html,/Mostrar mais vínculos desta página · 2 restantes/);
 assert.match(html,/Página 1/);
 assert.match(html,/Próxima/);
});

test('provider identifiers are escaped in schema, table, constraint and field names',async()=>{
 const data=report();
 data.relationships[0].name='<script>alert(1)</script>';
 data.relationships[0].from.table='<img src=x onerror=alert(2)>';
 data.relationships[0].from.schema='<svg onload=alert(3)>';
 data.relationships[0].columns[0].source='<iframe src=evil>';
 const html=await render(data);
 assert.match(html,/&lt;script/);
 assert.match(html,/&lt;img/);
 assert.match(html,/&lt;svg/);
 assert.match(html,/&lt;iframe/);
 assert.doesNotMatch(html,/<script>alert\(1\)|<img src=x|<svg onload|<iframe src=evil/);
});


test('an in-flight refresh is announced beside the still visible previous result',async()=>{
 const html=await render(report(),true);
 assert.match(html,/aria-busy="true"/);
 assert.match(html,/Consultando a fonte/);
 assert.match(html,/O resultado anterior continua visível/);
 assert.match(html,/orders/);
});
test('a recoverable error is presented in the result panel without hiding its previous data',async()=>{
 const html=await render(report(),false,'A rede falhou. O resultado anterior permanece identificado.');
 assert.match(html,/role="alert"/);
 assert.match(html,/A rede falhou/);
 assert.match(html,/orders/);
});
