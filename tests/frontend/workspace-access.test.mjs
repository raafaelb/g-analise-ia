import {after,before,test} from 'node:test';
import assert from 'node:assert/strict';
import {createSSRApp,h} from 'vue';
import {renderToString} from '@vue/server-renderer';
import {createServer} from 'vite';
import vue from '@vitejs/plugin-vue';
let server,Workspace,previousDocument;
before(async()=>{
 previousDocument=globalThis.document;
 globalThis.document={querySelector:()=>null};
 server=await createServer({configFile:false,plugins:[{
  name:'isolate-workspace-services',enforce:'pre',
  transform(source,id){
   if(!id.endsWith('/resources/js/Pages/Workspace.vue'))return;
   source=source.replace("import { Head, usePage } from '@inertiajs/vue3';",'const Head={setup:()=>()=>null}; const usePage=()=>({props:{}});');
   return source.replace(/^import (\w+) from (['"])([^'"\n]+\.vue)\2;/gm,(line,name)=>name==='CreationAccessNotice'?line:`const ${name}={setup:()=>()=>null};`);
  }
 },vue()],server:{middlewareMode:true,watch:null,hmr:false,ws:false},appType:'custom',logLevel:'error'});
 Workspace=(await server.ssrLoadModule('/resources/js/Pages/Workspace.vue')).default;
});
after(async()=>{await server?.close();if(previousDocument===undefined)delete globalThis.document;else globalThis.document=previousDocument;});
function bootstrap(role='editor',connections=[],configured=true){
 return {user:{id:999,name:'Autoria sintética',email:'synthetic@example.invalid',role,must_change_password:false,can_manage_users:false},projects:[],conversations:[],connections,approvals:[],schedules:[],activity:[],artifacts:[],runs:[],preview:false,integration:{openai_configured:configured,auth_mode:'oss_local',plan_usage_enabled:true,lumo_login_enabled:true}};
}
async function render(initial){return renderToString(createSSRApp({render:()=>h(Workspace,{initial})}));}

test('an offline author sees a project picker and the actionable missing account notice',async()=>{
 const html=await render(bootstrap());
 assert.match(html,/Escolher um projeto/);
 assert.match(html,/role="status" aria-label="Conecte sua conta ChatGPT"/);
 assert.match(html,/Abrir conexões/);
 assert.match(html,/aria-label="Novo projeto"/);
 assert.doesNotMatch(html,/role="dialog"/);
});

test('a read-only account sees consultation guidance without project or suggestion creation controls',async()=>{
 const html=await render(bootstrap('viewer'));
 assert.match(html,/Seu acesso é de consulta/);
 assert.match(html,/Explorar catálogo/);
 assert.match(html,/Escolher um projeto/);
 assert.doesNotMatch(html,/aria-label="Novo projeto"|aria-label="Criar projeto"|class="empty-project-card"|class="suggestion-grid"/);
});

test('valid connected accounts remain ready when only new ChatGPT sign-ins are disabled',async()=>{
 const html=await render(bootstrap('editor',[
  {id:'synthetic-openai',provider:'openai',status:'connected',requires_reauthorization:false,metadata:{plan_usage:true}},
  {id:'synthetic-bi',provider:'horus',status:'connected',metadata:{tenants:[{id:'1290',name:'Ambiente fictício'}]}}
 ],false));
 assert.doesNotMatch(html,/creation-access-notice/);
 assert.match(html,/Escolher um projeto/);
 assert.match(html,/aria-label="Novo projeto"/);
});
