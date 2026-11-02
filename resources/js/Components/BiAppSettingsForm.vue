<script setup lang="ts">
import {computed,nextTick,onMounted,onUnmounted,ref,watch} from 'vue';
import {AlertCircle,Check,CheckCircle2,LoaderCircle,RefreshCw,ShieldCheck,X} from 'lucide-vue-next';
import ApprovalReview from './ApprovalReview.vue';
import {createRequestGuard} from '../lib/async-response';
import {conversationReviewState} from '../lib/conversation-approvals';
type Item=Record<string,any>;
type Settings={resource:string;name:string;description:string|null;version:string;state:string;desk_id:string|null};
const props=defineProps<{tenant:string;tenantName:string;resource:Item;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{close:[];updated:[];saved:[resource:string];notice:[message:string]}>();
const source=ref<Settings|null>(null);const name=ref('');const description=ref('');const loading=ref(false);const busy=ref(false);const polling=ref(false);const verifying=ref(false);const error=ref('');const review=ref<Item|null>(null);const uncertain=ref(false);const clock=ref(Date.now());const nameInput=ref<HTMLInputElement|null>(null);const reviewHeading=ref<HTMLElement|null>(null);const preparedInput=ref<Item|null>(null);
const fieldId='app-details-'+crypto.randomUUID();const guard=createRequestGuard();let timer:ReturnType<typeof setInterval>|undefined;let completionSent=false;let requestId=crypto.randomUUID();
const resourceRef=computed(()=>`app:${props.resource.id}`);
const status=computed(()=>review.value?conversationReviewState(review.value,clock.value):'');
const running=computed(()=>['approved','executing'].includes(status.value));
const result=computed<Item>(()=>review.value?.payload?.execution?.result??{});
const confirmed=computed(()=>status.value==='completed'&&result.value.configuration_verified===true&&result.value.resource===resourceRef.value);
const canVerify=computed(()=>!!review.value?.payload?.app_settings_attempt?.started_at&&['failed','cancelled','expired'].includes(status.value));
const patch=computed(()=>{const changes:Item={};if(source.value){const title=name.value.trim();if(name.value!==source.value.name&&title!==source.value.name)changes.name=title;const original=source.value.description??'';const text=description.value.trim();if(description.value!==original&&text!==original)changes.description=text===''?null:text;}return changes;});
const valid=computed(()=>!!source.value&&Object.keys(patch.value).length>0&&(!('name'in patch.value)||(patch.value.name.length>0&&patch.value.name.length<=100))&&(!('description'in patch.value)||(patch.value.description===null||patch.value.description.length<=4000))&&['draft','published'].includes(source.value.state));
async function begin(){
 const current=guard.next();loading.value=true;error.value='';review.value=null;source.value=null;preparedInput.value=null;uncertain.value=false;completionSent=false;requestId=crypto.randomUUID();
 try{const data=await props.request(`/catalog/${props.tenant}/apps/${props.resource.id}/settings`);if(!current())return;if(data.resource!==resourceRef.value||typeof data.name!=='string'||!(/^[a-f0-9]{64}$/.test(data.version??''))||(data.description!==null&&typeof data.description!=='string'))throw new Error('A configuração recebida não corresponde a esta aplicação. Tente novamente.');source.value=data;name.value=data.name;description.value=data.description??'';}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{if(current()){loading.value=false;await nextTick();nameInput.value?.focus();}}
}
async function prepare(){
 if(busy.value||(!preparedInput.value&&!valid.value))return;const current=guard.current();busy.value=true;error.value='';
 preparedInput.value??={request_id:requestId,version:source.value!.version,...patch.value};
 try{const response=await props.request(`/catalog/${props.tenant}/apps/${props.resource.id}/settings/actions`,'POST',preparedInput.value);if(!current())return;if(response.approval?.payload?.action?.resource!==resourceRef.value||response.approval?.payload?.action?.action!=='update_app_details')throw new Error('Não foi possível confirmar esta revisão. Consulte a solicitação antes de continuar.');review.value=response.approval;emit('updated');await nextTick();reviewHeading.value?.focus();}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{if(current())busy.value=false;}
}
function notifyCompletion(){if(!confirmed.value||completionSent)return;completionSent=true;emit('updated');emit('notice',result.value.message??'Nome e descrição salvos e conferidos.');}
async function refreshStatus(force=false){
 if(polling.value||!review.value||(!force&&!running.value&&!uncertain.value))return;const current=guard.current();const id=review.value.id;polling.value=true;
 try{const response=await props.request(`/bi-actions/${id}`);if(!current()||review.value?.id!==id)return;review.value=response.approval;uncertain.value=false;error.value='';notifyCompletion();}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{polling.value=false;}
}
async function decide(decision:'approved'|'rejected'){
 if(status.value!=='pending'||busy.value||uncertain.value||!review.value)return;const current=guard.current();busy.value=true;error.value='';
 try{const response=await props.request(`/approvals/${review.value.id}/decide`,'POST',{decision,fingerprint:review.value.fingerprint});if(!current())return;review.value=response;emit('updated');if(decision==='approved')await refreshStatus(true);}
 catch(e){if(current()){uncertain.value=true;error.value=(e as Error).message;}}
 finally{if(current())busy.value=false;}
}
async function verify(){
 if(!canVerify.value||verifying.value||!review.value)return;const current=guard.current();const id=review.value.id;verifying.value=true;error.value='';
 try{const response=await props.request(`/bi-actions/${id}/app-settings-verification`,'POST');if(!current()||review.value?.id!==id)return;review.value=response.approval;notifyCompletion();}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{if(current())verifying.value=false;}
}
function close(){guard.invalidate();emit('close');}
watch(()=>[props.tenant,props.resource.id],()=>{void begin();});
onMounted(()=>{void begin();timer=setInterval(()=>{clock.value=Date.now();if(!document.hidden)void refreshStatus();},2200);});
onUnmounted(()=>{guard.invalidate();if(timer)clearInterval(timer);});
</script>
<template>
 <section class="app-details-form" aria-label="Editar nome e descrição da aplicação" data-tour="app-details">
  <h3>Editar nome e descrição</h3><p class="app-details-intro">{{tenantName}} · Organize a identificação desta aplicação.</p>
  <div v-if="loading" class="app-details-progress" role="status"><LoaderCircle class="spin" :size="20"/>Conferindo a configuração atual…</div>
  <form v-else-if="!review&&source" @submit.prevent="prepare">
   <fieldset :disabled="busy||!!preparedInput"><div class="app-details-field"><label :for="fieldId+'-name'">Nome da aplicação</label><input :id="fieldId+'-name'" ref="nameInput" v-model="name" required maxlength="100" placeholder="Ex.: Visão financeira"></div><div class="app-details-field"><label :for="fieldId+'-description'">Descrição <span>opcional</span></label><textarea :id="fieldId+'-description'" v-model="description" rows="3" maxlength="4000" placeholder="Explique o objetivo e para quem esta aplicação foi criada"></textarea><small>{{description.length}} / 4.000 caracteres</small></div></fieldset>
   <p class="app-details-note"><ShieldCheck :size="16"/><span>{{source.state==='published'?'Esta aplicação está publicada. O nome e a descrição ficarão visíveis aos usuários da mesma mesa.':source.state==='draft'?'Esta aplicação está na sua mesa e continua privada.':'O estado de publicação não pôde ser confirmado. Reabra a configuração antes de editar.'}} Os dashboards e as fontes serão preservados. Confira os valores antes de autorizar.</span></p>
   <p v-if="error" class="form-error" role="alert">{{error}}</p><p v-if="preparedInput&&!review" class="app-details-hint">A proposta está guardada para recuperar a mesma revisão. Reabrir a configuração permite preparar outra proposta com os valores atuais.</p>
   <div class="app-details-footer"><button type="button" class="secondary-button" :disabled="busy" @click="close">Cancelar</button><button v-if="preparedInput" type="button" class="secondary-button" :disabled="busy" @click="begin">Reabrir configuração</button><button class="primary-button" :disabled="busy||(!preparedInput&&!valid)"><LoaderCircle v-if="busy" class="spin" :size="15"/><ShieldCheck v-else :size="15"/>{{preparedInput?'Conferir esta solicitação':'Conferir alterações'}}</button></div>
  </form>
  <div v-else-if="!review"><p class="form-error" role="alert">{{error}}</p><div class="app-details-footer"><button class="secondary-button" @click="close">Fechar</button><button class="primary-button" @click="begin">Tentar novamente</button></div></div>
  <template v-else>
   <template v-if="status==='pending'"><h4 ref="reviewHeading" tabindex="-1">Confira antes de autorizar</h4><ApprovalReview :approval="review" :tenants="[{id:tenant,name:tenantName}]"/><div class="app-details-footer"><button class="secondary-button" :disabled="busy||uncertain" @click="decide('rejected')"><X :size="15"/>Recusar</button><button class="primary-button" :disabled="busy||uncertain" @click="decide('approved')"><LoaderCircle v-if="busy" class="spin" :size="15"/><Check v-else :size="15"/>Autorizar alteração</button></div></template>
   <div v-else-if="running" class="app-details-progress" role="status"><LoaderCircle class="spin" :size="22"/><div><strong>Salvando e conferindo a aplicação</strong><p>Aguarde a confirmação no BI.</p></div></div>
   <div v-else-if="confirmed" class="app-details-progress success" role="status"><CheckCircle2 :size="22"/><div><strong>Aplicação atualizada</strong><p>{{result.confirmed_name}} · {{result.changed_fields}} {{result.changed_fields===1?'alteração confirmada':'alterações confirmadas'}}</p></div></div>
   <div v-else class="app-details-progress attention" role="status"><AlertCircle :size="22"/><div><strong>{{status==='rejected'?'Solicitação recusada':status==='expired'?'Revisão expirada':status==='cancelled'?'Solicitação cancelada':'A alteração precisa de conferência'}}</strong><p>{{status==='failed'?review.payload.execution?.message:'Consulte o estado atual antes de preparar outra alteração.'}}</p></div></div>
   <p v-if="error" class="form-error" role="alert">{{error}}</p><p v-if="uncertain" class="app-details-hint">A decisão não pôde ser confirmada. Confira a solicitação antes de decidir novamente.</p>
   <p v-if="canVerify" class="app-details-hint">Conferir resultado apenas consulta o que está salvo no BI. A edição não será reenviada.</p>
   <p v-if="confirmed" class="app-details-hint">Se estiver trabalhando nesta aplicação em um projeto, use Atualizar do BI antes de continuar as edições no projeto.</p>
   <div class="app-details-footer"><button v-if="uncertain||running" class="secondary-button" :disabled="polling" @click="refreshStatus(true)"><RefreshCw :size="15"/>Conferir solicitação</button><button v-if="canVerify" class="primary-button" :disabled="verifying" @click="verify"><LoaderCircle v-if="verifying" class="spin" :size="15"/><RefreshCw v-else :size="15"/>Conferir resultado</button><button v-if="confirmed" class="primary-button" @click="emit('saved',result.resource)">Ver aplicação atualizada</button><button class="secondary-button" :disabled="busy||verifying" @click="close">Fechar</button></div>
  </template>
 </section>
</template>
<style scoped>
.app-details-form{min-width:0;color:#30496a}.app-details-form h3{font-size:18px;margin:0 0 7px}.app-details-intro,.app-details-hint{font-size:12px;color:#75879f;line-height:1.8;margin:0 0 18px}.app-details-form fieldset{border:0;padding:0;margin:0;min-width:0}.app-details-field{margin-bottom:16px;min-width:0}.app-details-field label{display:block;font-size:12px;font-weight:600;color:#506781;margin-bottom:7px}.app-details-field label span{font-weight:400;color:#8393a9;margin-left:4px}.app-details-field input,.app-details-field textarea{box-sizing:border-box;width:100%;min-width:0;font:inherit;font-size:13px;color:#30496a;border:1px solid #dbe4f2;border-radius:8px;padding:11px;background:#fff}.app-details-field textarea{display:block;resize:vertical;min-height:84px;max-height:240px;line-height:1.7}.app-details-field small{display:block;color:#8b9cb3;font-size:10px;text-align:right;margin-top:5px}.app-details-note{display:flex;gap:9px;align-items:flex-start;padding:12px;border-radius:8px;background:#f1f6ff;color:#5a759b;font-size:12px;line-height:1.8}.app-details-note svg{flex-shrink:0;margin-top:3px}.app-details-footer{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:19px}.app-details-progress{display:flex;align-items:flex-start;gap:12px;padding:18px;border-radius:9px;background:#f2f6fd;color:#55769e;font-size:13px;line-height:1.7}.app-details-progress svg{flex-shrink:0;margin-top:2px}.app-details-progress p{font-size:12px;margin:6px 0 0}.app-details-progress.success{background:#eef8f2;color:#2d7855}.app-details-progress.attention{background:#fff8eb;color:#876831}.app-details-hint{margin:14px 0 0}.app-details-form :is(h4,p,strong){overflow-wrap:anywhere}
@media(max-width:600px){.app-details-field input,.app-details-field textarea{font-size:16px}.app-details-footer{flex-direction:column;align-items:stretch}.app-details-progress{padding:14px}}
</style>
