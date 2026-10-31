import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import {createSSRApp} from 'vue';
import {renderToString} from '@vue/server-renderer';

let server;
let SourceSchema;
before(async()=>{
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 SourceSchema=(await server.ssrLoadModule('/resources/js/Components/BiSourceSchema.vue')).default;
});
after(async()=>server?.close());
function report(overrides={}){
 return {tables:[{schema:'public',name:'orders',visible_column_count:2,columns_complete:false,columns:[{name:'amount',type:'numeric',position:1}]}],search_supported:true,pagination_supported:true,offset:0,page_size:500,next_offset:500,truncated:true,schema:'public',search:'amount',...overrides};
}
async function render(data,busy=false,error=''){return renderToString(createSSRApp(SourceSchema,{report:data,schema:'public',busy,error}));}

test('remote structure exposes applied scope, pages and partial columns without claiming business records',async()=>{
 const html=await render(report());
 assert.match(html,/Buscar na fonte/);
 assert.match(html,/Schema consultado: public/);
 assert.match(html,/Busca: amount/);
 assert.match(html,/Página 1/);
 assert.match(html,/Campos parciais/);
 assert.match(html,/1 de 2 campos visíveis/);
 assert.match(html,/Nenhum registro de negócio é consultado/);
 assert.match(html,/Uma tabela pode continuar|uma tabela pode continuar/);
});

test('unsupported native reports offer only a local filter and disclose field limits',async()=>{
 const data=report({search_supported:false,pagination_supported:false,next_offset:null,search:null});
 data.tables[0]={name:'ORDERS',schema:'FINANCE',columns_truncated:true,columns:[{name:'ID',type:'NUMBER'}]};
 const html=await render(data);
 assert.match(html,/Filtrar este resultado/);
 assert.match(html,/não oferece paginação remota/);
 assert.match(html,/A lista de campos foi limitada/);
 assert.doesNotMatch(html,/Buscar na fonte|Páginas da estrutura|campos visíveis/);
});

test('large structure reports bound initial tables and fields while offering explicit local expansion',async()=>{
 const data=report();
 const columns=Array.from({length:30},(_,i)=>({name:`field_${i}`,type:'text'}));
 data.tables=Array.from({length:22},(_,i)=>({schema:'public',name:`table_${i}`,columns,visible_column_count:30,columns_complete:true}));
 const html=await render(data);
 assert.match(html,/table_19/);
 assert.doesNotMatch(html,/table_20|field_25/);
 assert.match(html,/field_24/);
 assert.match(html,/Mostrar mais tabelas deste resultado · 2 restantes/);
 assert.match(html,/Mostrar mais campos · 5 restantes neste resultado/);
});

test('provider structure names and types are escaped instead of rendered as instructions or HTML',async()=>{
 const data=report();
 data.tables[0].name='<img src=x onerror=alert(1)>';
 data.tables[0].schema='<svg onload=alert(2)>';
 data.tables[0].columns[0]={name:'<script>alert(3)</script>',type:'<iframe src=evil>'};
 const html=await render(data);
 assert.match(html,/&lt;img/);assert.match(html,/&lt;svg/);assert.match(html,/&lt;script/);assert.match(html,/&lt;iframe/);
 assert.doesNotMatch(html,/<img src=x|<svg onload|<script>alert|<iframe src=evil/);
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
