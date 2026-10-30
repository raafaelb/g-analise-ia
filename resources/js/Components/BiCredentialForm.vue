<script setup lang="ts">
import {computed,nextTick,onMounted,onUnmounted,ref} from 'vue';
import {AlertCircle,Check,CheckCircle2,Database,LoaderCircle,ShieldCheck,X} from 'lucide-vue-next';
import ApprovalReview from './ApprovalReview.vue';
import {createRequestGuard} from '../lib/async-response';
type Item=Record<string,any>;
const props=defineProps<{tenant:string;tenantName:string;resource?:Item|null;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{close:[];updated:[];saved:[resource:string];notice:[message:string]}>();
const options=ref<Item|null>(null);const driver=ref('postgres');const name=ref('');const agent=ref('');const values=ref<Item>({});const loading=ref(true);const busy=ref(false);const error=ref('');const editable=ref(true);const help=ref('');const review=ref<Item|null>(null);const now=ref(Date.now());const nameInput=ref<HTMLInputElement|null>(null);const requestId=crypto.randomUUID();const guard=createRequestGuard();const fieldPrefix='credential-'+crypto.randomUUID();
const definition=computed<Item|undefined>(()=>options.value?.types.find((item:Item)=>item.id===driver.value));
const fields=computed<Item[]>(()=>definition.value?.fields??[]);
const editing=computed(()=>!!props.resource);
const running=computed(()=>['approved','executing'].includes(review.value?.status));
const pending=computed(()=>review.value?.status==='pending'&&new Date(review.value.expires_at).getTime()>now.value);
const result=computed<Item>(()=>review.value?.payload?.execution?.result??{});
let timer:ReturnType<typeof setInterval>|undefined;let polling=false;let completionSent=false;
function resetFields(){values.value=Object.fromEntries(fields.value.map(field=>[field.key,field.default??'']));}
function changeDriver(){if(!editing.value)resetFields();}
function clearSecrets(){for(const field of fields.value)if(field.secret)values.value[field.key]='';}
async function load(){
 const current=guard.next();loading.value=true;error.value='';
 try{const metadata=await props.request(`/catalog/${props.tenant}/credentials/options`);if(!current())return;options.value=metadata;
  if(props.resource){const data=await props.request(`/catalog/${props.tenant}/credentials/${props.resource.id}/configuration`);if(!current())return;editable.value=data.editable;help.value=data.message??'';driver.value=data.driver??'';name.value=data.name;agent.value=String(data.agent_id??'');values.value=data.values??{};}
  else{resetFields();if(metadata.agents.length===1)agent.value=String(metadata.agents[0].id);}
  await nextTick();nameInput.value?.focus();
 }catch(e){if(current())error.value=(e as Error).message;}finally{if(current())loading.value=false;}
}
async function prepare(){
 if(busy.value||loading.value||!editable.value||!agent.value)return;
 const current=guard.current();busy.value=true;error.value='';
 try{const response=await props.request(`/catalog/${props.tenant}/credentials/actions`,'POST',{action:editing.value?'update_credential':'create_credential',request_id:requestId,resource:props.resource?.ref??null,driver:driver.value,name:name.value.trim(),agent:Number(agent.value),values:values.value});if(!current())return;review.value=response.approval;clearSecrets();emit('updated');}
 catch(e){if(current())error.value=(e as Error).message;}finally{if(current())busy.value=false;}
}
function notifyCompletion(){if(review.value?.status!=='completed'||completionSent)return;completionSent=true;emit('updated');emit('notice',result.value.message??'Configuração salva. Teste a conexão para conferir o acesso.');}
async function refreshStatus(){
 if(polling||!running.value||!review.value)return;const current=guard.current();const id=review.value.id;polling=true;
 try{const response=await props.request(`/bi-actions/${id}`);if(!current()||review.value?.id!==id)return;review.value=response.approval;error.value='';notifyCompletion();if(review.value?.status==='failed')emit('updated');}
 catch(e){if(current())error.value=(e as Error).message;}finally{polling=false;}
}
async function decide(decision:'approved'|'rejected'){
 if(!pending.value||busy.value||!review.value)return;const current=guard.current();busy.value=true;error.value='';
 try{const response=await props.request(`/approvals/${review.value.id}/decide`,'POST',{decision,fingerprint:review.value.fingerprint});if(!current())return;review.value=response;emit('updated');if(decision==='approved')await refreshStatus();}
 catch(e){if(current())error.value=(e as Error).message;}finally{if(current())busy.value=false;}
}
onMounted(()=>{load();timer=setInterval(()=>{now.value=Date.now();if(!document.hidden)refreshStatus();},2200);});
onUnmounted(()=>{guard.invalidate();clearSecrets();if(timer)clearInterval(timer);});
</script>
<template>
 <div class="credential-form-content">
  <div class="credential-intro"><span class="project-icon blue"><Database :size="23"/></span><div><h2>{{editing?'Editar conexão de dados':'Adicionar nova conexão'}}</h2><p>{{tenantName}} · {{editing?'A alteração será conferida antes de salvar.':'Conecte uma fonte para usar nos seus ETLs e dashboards.'}}</p></div></div>
  <ol class="credential-steps" aria-label="Etapas da configuração"><li :class="{active:!review}"><span>1</span>Configurar fonte</li><li :class="{active:!!review}"><span>2</span>Conferir e salvar</li></ol>
  <div v-if="loading" class="credential-loading" role="status"><LoaderCircle :size="22" class="spin"/><p>Conferindo tipos de banco e agentes disponíveis…</p></div>
  <template v-else-if="!review">
   <p v-if="help" class="credential-note">{{help}}</p>
   <form v-if="options&&editable" autocomplete="off" @submit.prevent="prepare">
    <div class="credential-grid"><div class="credential-field wide"><label :for="fieldPrefix+'-name'">Nome da conexão</label><input :id="fieldPrefix+'-name'" ref="nameInput" v-model="name" required maxlength="100" :disabled="busy" placeholder="Ex.: Banco de vendas"></div>
     <div class="credential-field"><label :for="fieldPrefix+'-driver'">Tipo de banco</label><select :id="fieldPrefix+'-driver'" v-model="driver" :disabled="busy||editing" required @change="changeDriver"><option v-for="item in options.types" :key="item.id" :value="item.id">{{item.label}}</option></select></div>
     <div class="credential-field"><label :for="fieldPrefix+'-agent'">Agente de execução</label><select :id="fieldPrefix+'-agent'" v-model="agent" :disabled="busy||editing" required><option value="">Selecione um agente</option><option v-for="item in options.agents" :key="item.id" :value="String(item.id)">{{item.name}}</option></select><small>{{editing?'Esta configuração mantém o agente atual.':'Selecione o componente do G-Analise que tem acesso aos seus dados.'}}</small></div>
     <div v-for="field in fields" :key="field.key" class="credential-field" :class="{wide:field.secret||field.kind==='textarea',checkbox:field.kind==='checkbox'}">
      <template v-if="field.kind==='checkbox'"><label :for="fieldPrefix+'-'+field.key"><input :id="fieldPrefix+'-'+field.key" v-model="values[field.key]" type="checkbox" :disabled="busy">{{field.label}}</label></template>
      <template v-else><label :for="fieldPrefix+'-'+field.key">{{field.label}}</label><textarea v-if="field.kind==='textarea'" :id="fieldPrefix+'-'+field.key" v-model="values[field.key]" :required="field.required" :disabled="busy" rows="4" autocomplete="off" :spellcheck="false"></textarea><select v-else-if="field.kind==='select'" :id="fieldPrefix+'-'+field.key" v-model="values[field.key]" :disabled="busy"><option v-for="item in field.options" :key="item.value" :value="item.value">{{item.label}}</option></select><input v-else :id="fieldPrefix+'-'+field.key" v-model="values[field.key]" :type="field.kind==='password'?'password':field.kind==='number'?'number':'text'" :min="field.min" :max="field.max" :required="field.required" :disabled="busy" :autocomplete="field.secret?'new-password':'off'"></template>
      <small v-if="field.key==='password'">{{editing?'Deixe em branco para manter a senha atual.':'Deixe em branco somente se o banco permitir acesso sem senha.'}}</small><small v-else-if="field.hint">{{field.hint}}</small>
     </div>
    </div>
    <p v-if="!options.agents.length" class="credential-note">Nenhum agente retornou nesta consulta. Confira suas permissões e a disponibilidade do ambiente.</p><p class="credential-privacy"><ShieldCheck :size="16"/>Os campos protegidos são usados somente para configurar a conexão no BI. Eles não são enviados à IA.</p>
    <div v-if="error" class="form-error" role="alert"><AlertCircle :size="16"/>{{error}}</div><div class="credential-footer"><button type="button" class="secondary-button" :disabled="busy" @click="emit('close')">Cancelar</button><button class="primary-button" :disabled="busy||!name.trim()||!agent"><LoaderCircle v-if="busy" :size="15" class="spin"/><ShieldCheck v-else :size="15"/>Conferir configuração</button></div>
   </form>
   <template v-else><p v-if="error" class="form-error" role="alert">{{error}}</p><div class="credential-footer"><button class="secondary-button" @click="emit('close')">Fechar</button><button v-if="error" class="primary-button" @click="load">Tentar novamente</button></div></template>
  </template>
  <template v-else>
   <template v-if="pending"><ApprovalReview :approval="review" :tenants="[{id:tenant,name:tenantName}]"/><div class="credential-footer"><button class="secondary-button" :disabled="busy" @click="decide('rejected')"><X :size="15"/>Recusar revisão</button><button class="primary-button" :disabled="busy" @click="decide('approved')"><LoaderCircle v-if="busy" :size="15" class="spin"/><Check v-else :size="15"/>{{editing?'Autorizar alteração':'Autorizar criação'}}</button></div></template>
   <div v-else-if="running" class="credential-result" role="status"><LoaderCircle :size="22" class="spin"/><div><strong>{{review.status==='approved'?'Configuração autorizada':'Salvando configuração no BI'}}</strong><p>Você pode acompanhar esta operação também em Atividade.</p></div></div>
   <div v-else-if="review.status==='completed'" class="credential-result success" role="status"><CheckCircle2 :size="24"/><div><strong>Configuração salva</strong><p>{{result.confirmed_name}}</p><p>{{result.message}}</p><button class="primary-button" @click="emit('saved',result.resource)">Abrir conexão</button></div></div>
   <div v-else class="credential-result attention" role="alert"><AlertCircle :size="22"/><div><strong>{{review.status==='failed'?'Esta configuração precisa de atenção':review.status==='rejected'?'Revisão recusada':'Esta revisão não está mais ativa'}}</strong><p>{{review.payload.execution?.message??(review.status==='rejected'?'Os campos privados desta revisão foram descartados.':'Preencha o formulário novamente para preparar outra revisão.') }}</p><p v-if="review.payload.credential_attempt?.created_resource">Conexão criada: {{review.payload.credential_attempt.created_resource}}. Abra esse recurso para continuar sem duplicar a criação.</p><button v-if="review.payload.credential_attempt?.created_resource" class="secondary-button" @click="emit('saved',review.payload.credential_attempt.created_resource)">Abrir conexão criada</button></div></div>
   <p v-if="error" class="form-error" role="alert">{{error}}</p><div v-if="!pending" class="credential-footer"><button class="secondary-button" @click="emit('close')">Fechar</button></div>
  </template>
 </div>
</template>
<style scoped>
.credential-intro{display:flex;gap:15px;align-items:flex-start}.credential-intro h2{font-size:22px;color:#243a5a;margin:2px 0 8px}.credential-intro p,.credential-note{font-size:12px;line-height:1.7;color:#6c7d96}.credential-steps{display:flex;gap:22px;list-style:none;margin:24px 0;padding:0;color:#8895a9;font-size:12px}.credential-steps li{display:flex;align-items:center;gap:8px}.credential-steps span{display:grid;place-items:center;width:23px;height:23px;background:#edf2f9;border-radius:50%;font-size:11px}.credential-steps .active{color:#1762d1;font-weight:600}.credential-steps .active span{background:#e4efff;color:#075bea}.credential-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.credential-field.wide{grid-column:1/-1}.credential-field label{display:block;font-size:12px;font-weight:600;color:#3e526e;margin-bottom:7px}.credential-field input:not([type=checkbox]),.credential-field select,.credential-field textarea{width:100%;background:#fff;color:#253d60}.credential-field small{display:block;font-size:11px;line-height:1.6;color:#7c8aa0;margin-top:6px}.credential-field.checkbox{padding-top:24px}.credential-field.checkbox label{display:flex;gap:9px;align-items:center}.credential-privacy{display:flex;gap:9px;align-items:flex-start;color:#5c7192;background:#f2f7fe;padding:12px;border-radius:8px;font-size:11px;line-height:1.7;margin-top:20px}.credential-privacy svg{flex-shrink:0;margin-top:2px}.credential-footer{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:23px}.credential-loading{padding:30px 12px;text-align:center;color:#7a8da7;font-size:12px}.credential-loading svg{margin:auto auto 12px}.credential-result{display:flex;gap:12px;border:1px solid #dce6f4;background:#f6faff;padding:18px;border-radius:9px;color:#45638b}.credential-result>svg{flex-shrink:0}.credential-result strong{font-size:14px}.credential-result p{font-size:12px;line-height:1.7;margin:7px 0 13px}.credential-result.success{border-color:#d3eadf;background:#f4fbf7;color:#287251}.credential-result.attention{border-color:#f0dfc5;background:#fffaf2;color:#855b2a}.credential-form-content .form-error{margin:15px 0}@media(max-width:620px){.credential-grid{grid-template-columns:1fr}.credential-field.checkbox{padding-top:0}.credential-intro h2{font-size:19px}.credential-steps{gap:14px;font-size:11px}.credential-footer>button{flex-grow:1}}
</style>
