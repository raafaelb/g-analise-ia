import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
import {createSSRApp} from 'vue';
import {renderToString} from '@vue/server-renderer';
let server,progress,state,Review;
before(async()=>{
 server=await createServer({configFile:false,plugins:[vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 const module=await server.ssrLoadModule('/resources/js/lib/flow-wizard.ts');progress=module.wizardProgress;state=module.wizardReviewState;
 Review=(await server.ssrLoadModule('/resources/js/Components/FlowWizardReview.vue')).default;
});
after(async()=>server?.close());
const row=(action,status,extra={})=>({status,expires_at:'2099-01-01T00:00:00Z',payload:{action:{action},...extra}});
test('a queued or executing step blocks advancing until completion',()=>{
 const reviews=[row('create_source_flow','completed'),row('test_source_flow','approved')];
 assert.equal(progress(reviews).next,undefined);assert.equal(progress(reviews).active,reviews[1]);
 reviews[1].status='completed';assert.equal(progress(reviews).next.action,'create_source_table');
});
test('a failed creation with a recorded attempt cannot offer a repeat, but a rejected unsent review can',()=>{
 const failed=row('create_source_table','failed',{flow_wizard_attempt:{started_at:'2026-10-31T12:00:00Z'}});
 const before=[row('create_source_flow','completed'),row('test_source_flow','completed')];
 assert.equal(progress([...before,failed]).uncertain,true);assert.equal(progress([...before,failed]).next,undefined);
 assert.equal(progress([...before,row('create_source_table','rejected')]).next.action,'create_source_table');
});
test('expired or malformed pending reviews cannot be offered for authorization',()=>{
 for(const expiry of ['2026-01-01T00:00:00Z','bad-date',null])assert.equal(state({...row('create_source_flow','pending'),expires_at:expiry},Date.parse('2026-10-31T00:00:00Z')),'expired');
 const previous={...row('test_source_flow','pending'),expires_at:'2026-01-01T00:00:00Z'};
 assert.equal(progress([row('create_source_flow','completed'),previous]).next.action,'test_source_flow');
});
test('completed load offers no automatic reload or publication',()=>{
 assert.equal(progress(['create_source_flow','test_source_flow','create_source_table','load_source_table'].map(action=>row(action,'completed'))).next,undefined);
});
const value={step:'load_source_table',table:'table:90',source:{name:'Fonte <script>alert(1)</script>',schema:'public',table:'orders',columns:[{name:'amount',type:'numeric'}],visible_column_count:5,row_limit:100,agent:{name:'Agente local'}},impact:'Substitui apenas a tabela própria.'};
test('the review discloses limited sample and target while escaping names',async()=>{
 const html=await renderToString(createSSRApp(Review,{value}));assert.match(html,/carga de teste/);assert.match(html,/amostra precisa de revisão/);assert.match(html,/table:90/);assert.match(html,/1 de 5 campos visíveis/);assert.doesNotMatch(html,/<script>alert/);
});
test('unlimited extraction clearly discloses the full selected record volume',async()=>{
 const html=await renderToString(createSSRApp(Review,{value:{...value,source:{...value.source,row_limit:null}}}));assert.match(html,/todos os registros dos campos escolhidos/);assert.doesNotMatch(html,/carga de teste|Até 100/);
});
