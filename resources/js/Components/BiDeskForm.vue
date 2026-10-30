<script setup lang="ts">
import {computed,nextTick,onMounted,onUnmounted,ref} from 'vue';
import {AlertCircle,Check,CheckCircle2,FolderOpen,Layers3,LoaderCircle,Pencil,RefreshCw,ShieldCheck,X} from 'lucide-vue-next';
import ApprovalReview from './ApprovalReview.vue';
import {createRequestGuard} from '../lib/async-response';
import {conversationReviewState} from '../lib/conversation-approvals';
type Item=Record<string,any>;
const props=defineProps<{tenant:string;tenantName:string;kind:string;resource?:Item|null;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{close:[];updated:[];saved:[resource:string];notice:[message:string]}>();
const editing=computed(()=>!!props.resource);
const title=computed(()=>props.kind==='bi-desk'?'mesa de aplicações':'mesa de dados');
const ready=ref(!props.resource);const opened=ref(!props.resource);const loading=ref(false);const busy=ref(false);const error=ref('');const name=ref('');const description=ref('');const review=ref<Item|null>(null);const clock=ref(Date.now());const uncertain=ref(false);const requestId=ref(crypto.randomUUID());const nameInput=ref<HTMLInputElement|null>(null);
const guard=createRequestGuard();const fieldId='desk-'+crypto.randomUUID();let timer:ReturnType<typeof setInterval>|undefined;const polling=ref(false);let completionSent=false;
const status=computed(()=>review.value?conversationReviewState(review.value,clock.value):'');
const running=computed(()=>['approved','executing'].includes(status.value));
const result=computed<Item>(()=>review.value?.payload?.execution?.result??{});
const failure=computed(()=>review.value?.payload?.execution?.message??'A configuração não foi confirmada. Confira o catálogo antes de preparar outra operação.');

async function begin(){
 const current=guard.next();opened.value=true;loading.value=true;ready.value=false;error.value='';review.value=null;uncertain.value=false;completionSent=false;requestId.value=crypto.randomUUID();
 try{if(props.resource){const data=await props.request(`/catalog/${props.tenant}/desks/${props.kind}/${props.resource.id}/configuration`);if(!current())return;name.value=data.name;description.value=data.description??'';}
  else{name.value='';description.value='';}
  ready.value=true;
 }catch(e){if(current())error.value=(e as Error).message;}finally{if(current()){loading.value=false;await nextTick();nameInput.value?.focus();}}
}
async function prepare(){
 if(busy.value||loading.value||!ready.value||!name.value.trim())return;const current=guard.current();busy.value=true;error.value='';
 try{const response=await props.request(`/catalog/${props.tenant}/desks/${props.kind}/actions`,'POST',{action:editing.value?'update_desk':'create_desk',request_id:requestId.value,resource:props.resource?.ref??null,name:name.value.trim(),description:description.value});if(!current())return;review.value=response.approval;emit('updated');}
 catch(e){if(current())error.value=(e as Error).message;}finally{if(current())busy.value=false;}
}
function notifyCompletion(){if(status.value!=='completed'||completionSent)return;completionSent=true;emit('updated');emit('notice',result.value.message??'Mesa salva no G-Analise BI.');}
async function refreshStatus(force=false){
 if(polling.value||!review.value||(!force&&!running.value&&!uncertain.value))return;const current=guard.current();const id=review.value.id;polling.value=true;
 try{const response=await props.request(`/bi-actions/${id}`);if(!current()||review.value?.id!==id)return;review.value=response.approval;uncertain.value=false;error.value='';notifyCompletion();}
 catch(e){if(current())error.value=(e as Error).message;}finally{polling.value=false;}
}
async function decide(decision:'approved'|'rejected'){
 if(status.value!=='pending'||busy.value||uncertain.value||!review.value)return;const current=guard.current();busy.value=true;error.value='';
 try{const response=await props.request(`/approvals/${review.value.id}/decide`,'POST',{decision,fingerprint:review.value.fingerprint});if(!current())return;review.value=response;emit('updated');if(decision==='approved')await refreshStatus(true);}
 catch(e){if(current()){uncertain.value=true;error.value=(e as Error).message;}}
 finally{if(current())busy.value=false;}
}
function close(){guard.invalidate();if(editing.value)opened.value=false;else emit('close');}
onMounted(()=>{if(!editing.value)begin();timer=setInterval(()=>{clock.value=Date.now();if(!document.hidden)refreshStatus();},2200);});
onUnmounted(()=>{guard.invalidate();if(timer)clearInterval(timer);});
</script>

<template>
 <section class="desk-form" aria-label="Configurar mesa">
  <button v-if="!opened" class="secondary-button" @click="begin"><Pencil :size="15"/>Editar nome e descrição</button>
  <template v-else>
   <div class="desk-intro"><span class="project-icon blue"><FolderOpen v-if="kind==='bi-desk'" :size="23"/><Layers3 v-else :size="23"/></span><div><h2>{{editing?'Editar':'Nova'}} {{title}}</h2><p>{{tenantName}} · {{kind==='bi-desk'?'Organize as aplicações e seus dashboards.':'Organize as tabelas, ETLs e cadastros.'}}</p></div></div>
   <div v-if="loading" class="desk-loading" role="status"><LoaderCircle class="spin" :size="22"/>Conferindo a configuração atual…</div>
   <form v-else-if="!review&&ready" @submit.prevent="prepare">
    <div class="desk-field"><label :for="fieldId+'-name'">Nome da mesa</label><input :id="fieldId+'-name'" ref="nameInput" v-model="name" required maxlength="100" :disabled="busy" placeholder="Ex.: Comercial"></div>
    <div class="desk-field"><label :for="fieldId+'-description'">Descrição <span>opcional</span></label><textarea :id="fieldId+'-description'" v-model="description" maxlength="2000" rows="3" :disabled="busy" placeholder="Explique o que esta mesa vai reunir"></textarea><small>{{description.length}} / 2.000 caracteres</small></div>
    <p class="desk-note"><ShieldCheck :size="17"/><span>{{editing?'Esta edição mantém os recursos e a configuração de acesso da mesa.':'A mesa começará vazia. Para compartilhar uma aplicação ou fonte nela, será necessária uma publicação própria.'}}</span></p>
    <div v-if="error" class="form-error" role="alert"><AlertCircle :size="16"/>{{error}}</div>
    <div class="desk-footer"><button type="button" class="secondary-button" :disabled="busy" @click="close">Cancelar</button><button class="primary-button" :disabled="busy||!name.trim()"><LoaderCircle v-if="busy" class="spin" :size="15"/><ShieldCheck v-else :size="15"/>Conferir alterações</button></div>
   </form>
   <div v-else-if="!review" class="desk-load-error"><p class="form-error" role="alert">{{error}}</p><div class="desk-footer"><button class="secondary-button" @click="close">Fechar</button><button class="primary-button" @click="begin">Tentar novamente</button></div></div>
   <template v-else>
    <template v-if="status==='pending'"><h3 class="desk-review-title">Confira antes de autorizar</h3><ApprovalReview :approval="review" :tenants="[{id:tenant,name:tenantName}]"/><div class="desk-footer"><button class="secondary-button" :disabled="busy||uncertain" @click="decide('rejected')"><X :size="15"/>Recusar</button><button class="primary-button" :disabled="busy||uncertain" @click="decide('approved')"><LoaderCircle v-if="busy" class="spin" :size="15"/><Check v-else :size="15"/>{{editing?'Autorizar alteração':'Autorizar criação'}}</button></div></template>
    <div v-else-if="running" class="desk-result" role="status"><LoaderCircle class="spin" :size="24"/><div><strong>Salvando e conferindo a mesa</strong><p>A autorização foi registrada. Aguarde a confirmação no BI.</p></div></div>
    <div v-else-if="status==='completed'" class="desk-result success" role="status"><CheckCircle2 :size="24"/><div><strong>Mesa salva</strong><p>{{result.confirmed_name}}</p><small>{{result.resource}}</small></div></div>
    <div v-else class="desk-result" role="status"><AlertCircle :size="23"/><div><strong>{{status==='rejected'?'Solicitação recusada':status==='expired'?'Revisão expirada':status==='cancelled'?'Solicitação cancelada':'A configuração precisa de conferência'}}</strong><p>{{status==='failed'?failure:'Nenhuma nova execução será iniciada por esta revisão.'}}</p></div></div>
    <div v-if="error" class="form-error" role="alert"><AlertCircle :size="16"/>{{error}}</div>
    <p v-if="uncertain" class="desk-note">A decisão não pôde ser confirmada. Confira a solicitação antes de tentar decidir novamente.</p>
    <div class="desk-footer"><button v-if="uncertain||running" class="secondary-button" :disabled="polling" @click="refreshStatus(true)"><RefreshCw :size="15"/>Conferir solicitação</button><button v-if="status==='completed'" class="primary-button" @click="emit('saved',result.resource)">Ver mesa no catálogo</button><button class="secondary-button" :disabled="busy" @click="close">Fechar</button></div>
   </template>
  </template>
 </section>
</template>

<style scoped>
.desk-form{margin:22px 0}.desk-intro{display:flex;gap:14px;align-items:center;margin-bottom:24px}.desk-intro h2{font-size:21px;color:#233651;margin:0 0 7px}.desk-intro p{font-size:12px;line-height:1.7;color:#748198;margin:0}.desk-field{margin-bottom:20px}.desk-field label{display:block;font-size:12px;font-weight:600;color:#50627d;margin-bottom:8px}.desk-field label span{font-weight:400;color:#8692a5;margin-left:5px}.desk-field input,.desk-field textarea{display:block;box-sizing:border-box;width:100%;font:inherit;font-size:13px;color:#30496a;background:#fff;border:1px solid #dbe3ef;border-radius:8px;padding:12px;resize:vertical}.desk-field small{display:block;text-align:right;font-size:10px;color:#8996a9;margin-top:7px}.desk-note{display:flex;align-items:flex-start;gap:9px;background:#f0f5fd;color:#586f90;padding:13px;border-radius:9px;font-size:12px;line-height:1.8}.desk-note svg{flex-shrink:0;margin-top:3px}.desk-footer{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:22px}.desk-loading{display:flex;align-items:center;justify-content:center;gap:13px;min-height:160px;color:#748198;font-size:13px}.desk-result{display:flex;align-items:flex-start;gap:13px;background:#fff7e9;padding:19px;border-radius:10px;color:#85692c}.desk-result.success{background:#edf8f2;color:#2d7654}.desk-result strong{display:block;font-size:14px}.desk-result p{font-size:12px;line-height:1.8;margin:7px 0}.desk-result small{font-size:11px}.desk-review-title{font-size:16px;color:#30496a;margin-top:22px}@media(max-width:600px){.desk-footer{align-items:stretch;flex-direction:column}.desk-intro h2{font-size:19px}}
</style>
