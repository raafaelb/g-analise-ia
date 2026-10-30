<script setup lang="ts">
import {computed,onUnmounted,ref,watch} from 'vue';
import {AlertCircle,ArrowUpRight,CheckCircle2,Database,Link2,LoaderCircle,RefreshCw,ShieldCheck,Unplug} from 'lucide-vue-next';
import {createRequestGuard} from '../lib/async-response';
type Item=Record<string,any>;
const props=defineProps<{tenants:Item[];connections:Item[];configured:boolean;connected:boolean;lumoGeneration?:string;role:string;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{updated:[];notice:[message:string]}>();
const tenant=ref('');const app=ref('');const applications=ref<Item[]>([]);const loading=ref(false);const busy=ref('');const error=ref('');const success=ref('');
const applicationRequests=createRequestGuard();const actions=createRequestGuard();
const grants=computed(()=>props.connections.filter(c=>c.provider?.startsWith('horus_mcp:')&&c.status!=='disconnected'));
const canConnect=computed(()=>props.configured&&props.connected&&props.role!=='viewer');
const currentTenant=computed(()=>props.tenants.find(t=>String(t.id)===tenant.value));
function valid(grant:Item){return !grant.requires_reauthorization&&props.connected&&grant.status==='connected'&&grant.metadata?.lumo_generation===props.lumoGeneration&&props.tenants.some(t=>String(t.id)===String(grant.metadata?.tenant_id));}
async function loadApplications(){
 const current=applicationRequests.next();applications.value=[];app.value='';error.value='';success.value='';
 if(!tenant.value||!canConnect.value){loading.value=false;return;}loading.value=true;
 try{const result=await props.request('/connections/bi-mcp/'+tenant.value+'/applications');if(!current())return;applications.value=result.applications??[];}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{if(current())loading.value=false;}
}
async function authorize(){
 if(busy.value||!canConnect.value||!tenant.value||!app.value)return;const current=actions.next();busy.value='authorize';error.value='';success.value='';
 try{const result=await props.request('/connections/bi-mcp/'+tenant.value+'/authorize','POST',{app_id:Number(app.value)});if(current())window.location.assign(result.url);}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{if(current())busy.value='';}
}
async function manage(grant:Item,action:'verify'|'disconnect'){
 if(busy.value)return;const current=actions.next();busy.value=action+':'+grant.id;error.value='';success.value='';
 try{const path='/connections/bi-mcp/'+grant.metadata.tenant_id;const result=await props.request(path+(action==='verify'?'/verify':''),action==='verify'?'POST':'DELETE');if(!current())return;success.value=result.message;emit('notice',result.message);emit('updated');}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{if(current())busy.value='';}
}
watch(()=>[tenant.value,props.lumoGeneration,props.connected,props.configured],loadApplications);
onUnmounted(()=>{applicationRequests.invalidate();actions.invalidate();});
</script>
<template>
 <section class="mcp-connections" data-tour="bi-mcp-connection" aria-labelledby="mcp-title">
  <div class="mcp-heading"><span class="mcp-symbol"><Database :size="22"/></span><div><h2 id="mcp-title">Consultas de dados com IA</h2><p>Encontre indicadores e confira respostas com dados reais das suas aplicações.</p></div><span class="soft-badge"><ShieldCheck :size="14"/>Somente leitura</span></div>
  <p class="mcp-explanation">A conexão MCP permite que a IA consulte seus dados no G-Analise BI. Ela tem uma autorização própria por ambiente: na página oficial do BI, confira a conta e escolha o mesmo ambiente selecionado aqui. O BI aplica as permissões da conta que você autorizar.</p>
  <p v-if="!configured" class="mcp-empty">Aguardando configuração das consultas MCP pelo administrador.</p>
  <p v-else-if="!connected" class="mcp-empty">Conecte o G-Analise BI acima para escolher seu ambiente.</p>
  <p v-else-if="role==='viewer'" class="mcp-empty">Seu perfil permite visualizar o workspace. Procure a Inovadora para habilitar o uso da IA.</p>
  <form v-else class="mcp-form" @submit.prevent="authorize">
   <label>Ambiente<select v-model="tenant" :disabled="!!busy"><option value="">Escolha um ambiente</option><option v-for="item in tenants" :key="item.id" :value="String(item.id)">{{item.name}}</option></select></label>
   <label>Aplicação para conferir o acesso<select v-model="app" :disabled="!tenant||loading||!!busy"><option value="">{{loading?'Carregando aplicações…':'Escolha uma aplicação'}}</option><option v-for="item in applications" :key="item.id" :value="String(item.id)">{{item.name}}</option></select></label>
   <button class="primary-button" :disabled="!tenant||!app||loading||!!busy"><LoaderCircle v-if="busy==='authorize'" :size="16" class="spin"/><Link2 v-else :size="16"/>Autorizar consultas<ArrowUpRight :size="15"/></button>
   <p class="mcp-hint">A aplicação escolhida não será alterada. Ela serve para conferir seu acesso ao ambiente{{currentTenant?' '+currentTenant.name:''}}. Não informe senhas ou tokens na conversa.</p>
   <p v-if="tenant&&!loading&&!applications.length&&!error" class="mcp-hint">Nenhuma aplicação disponível para conferir neste ambiente.</p>
  </form>
  <p v-if="error" class="mcp-error" role="alert"><AlertCircle :size="16"/>{{error}}</p>
  <p v-if="success" class="mcp-success" role="status"><CheckCircle2 :size="16"/>{{success}}</p>
  <div v-if="grants.length" class="mcp-grants"><article v-for="grant in grants" :key="grant.id" class="mcp-grant"><span class="mcp-grant-icon"><Database :size="18"/></span><div class="mcp-grant-name"><strong>{{grant.metadata?.tenant_name||grant.account_label||'Ambiente BI'}}</strong><span>{{valid(grant)?'Consultas autorizadas · ambiente conferido':'Autorize novamente para recuperar as consultas'}}</span></div><span class="connection-status" :class="{connected:valid(grant)}"><span></span>{{valid(grant)?'Conectado':'Reconectar'}}</span><div class="mcp-grant-actions"><button v-if="valid(grant)&&canConnect" class="secondary-button" :disabled="!!busy" @click="manage(grant,'verify')"><LoaderCircle v-if="busy==='verify:'+grant.id" :size="14" class="spin"/><RefreshCw v-else :size="14"/>Conferir acesso</button><button class="secondary-button" :disabled="!!busy" :aria-label="'Desconectar consultas de '+(grant.metadata?.tenant_name||'ambiente BI')" @click="manage(grant,'disconnect')"><LoaderCircle v-if="busy==='disconnect:'+grant.id" :size="14" class="spin"/><Unplug v-else :size="14"/>Desconectar</button></div></article></div>
 </section>
</template>
<style scoped>
.mcp-connections{margin-top:24px;padding:26px;background:#fff;border:1px solid #e1e7f0;border-radius:13px}
.mcp-heading{display:flex;align-items:center;gap:13px;flex-wrap:wrap}.mcp-heading>div{flex:1;min-width:210px}.mcp-heading h2{font-size:18px;color:#304967;margin:0 0 7px}.mcp-heading p{font-size:12px;color:#74849d;margin:0;line-height:1.7}
.mcp-symbol,.mcp-grant-icon{display:flex;align-items:center;justify-content:center;flex-shrink:0;background:#edf4ff;border-radius:10px;color:#0861ee}.mcp-symbol{width:46px;height:46px}.mcp-grant-icon{width:34px;height:34px}
.mcp-explanation{font-size:12px;color:#74849d;line-height:1.9;margin:20px 0}.mcp-empty{font-size:12px;line-height:1.8;color:#74849d;padding:13px;background:#f7f9fd;border-radius:8px}
.mcp-form{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1.3fr) auto;gap:16px;align-items:end}
.mcp-form label{display:grid;gap:8px;font-size:12px;font-weight:600;color:#50627d;min-width:0}.mcp-form select{width:100%;min-width:0;box-sizing:border-box;height:44px;border:1px solid #dbe3ef;border-radius:8px;background:#fbfcff;color:#30496a;padding:9px 11px;font:inherit;font-size:12px}
.mcp-form select:focus-visible{outline:2px solid #0861ee;outline-offset:2px}.mcp-form select:disabled{color:#8b98ac;background:#f4f6fa}.mcp-form button{min-height:44px}
.mcp-hint{grid-column:1/-1;font-size:11px;line-height:1.8;color:#8191a8;margin:0}.mcp-error,.mcp-success{display:flex;align-items:flex-start;gap:9px;margin:18px 0 0;padding:13px;border-radius:8px;font-size:12px;line-height:1.8}.mcp-error svg,.mcp-success svg{flex-shrink:0;margin-top:3px}.mcp-error{color:#954d45;background:#fff3f0}.mcp-success{color:#307b5b;background:#eef8f2}
.mcp-grants{display:grid;gap:10px;margin-top:20px}.mcp-grant{display:flex;align-items:center;flex-wrap:wrap;gap:12px;border-top:1px solid #edf1f7;padding-top:17px}.mcp-grant-name{display:grid;gap:6px;flex:1;min-width:170px}.mcp-grant-name strong{font-size:12px;color:#405b7e}.mcp-grant-name>span{font-size:11px;line-height:1.7;color:#8191a8}.mcp-grant-actions{display:flex;gap:8px;flex-wrap:wrap}.mcp-grant-actions button{font-size:11px;padding:9px 11px}
@media(max-width:850px){.mcp-form{grid-template-columns:minmax(0,1fr)}.mcp-form>button{justify-self:start}.mcp-heading .soft-badge{margin-left:59px}.mcp-grant-actions{width:calc(100% - 46px);margin-left:46px}}
@media(max-width:500px){.mcp-connections{padding:19px}.mcp-heading>div{min-width:0}.mcp-form>button{width:100%}.mcp-grant-actions{margin-left:0;width:100%}.mcp-grant-actions button{flex:1}}
</style>
