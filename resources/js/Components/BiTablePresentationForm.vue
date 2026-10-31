<script setup lang="ts">
import {computed,nextTick,onMounted,onUnmounted,ref,watch} from 'vue';
import {AlertCircle,Check,CheckCircle2,ChevronLeft,ChevronRight,LoaderCircle,Pencil,RefreshCw,Search,ShieldCheck,Table2,X} from 'lucide-vue-next';
import ApprovalReview from './ApprovalReview.vue';
import {createRequestGuard} from '../lib/async-response';
import {conversationReviewState} from '../lib/conversation-approvals';
import {tablePresentationDraft,tablePresentationPatch,tablePresentationChangeCount,tablePresentationProblems,type TableColumn,type TablePresentation,type TablePresentationDraft} from '../lib/table-presentation';
type Item=Record<string,any>;
const props=defineProps<{tenant:string;tenantName:string;resource:Item;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{updated:[];saved:[result:Item];notice:[message:string]}>();
const opened=ref(false);const loading=ref(false);const busy=ref(false);const polling=ref(false);const error=ref('');const source=ref<TablePresentation|null>(null);const draft=ref<TablePresentationDraft|null>(null);const review=ref<Item|null>(null);const uncertain=ref(false);const clock=ref(Date.now());const requestId=ref(crypto.randomUUID());const search=ref('');const changedOnly=ref(false);const page=ref(1);const nameInput=ref<HTMLInputElement|null>(null);const fieldId='table-presentation-'+crypto.randomUUID();
const guard=createRequestGuard();let timer:ReturnType<typeof setInterval>|undefined;let completionSent=false;
const patch=computed(()=>source.value&&draft.value?tablePresentationPatch(source.value,draft.value):null);
const changeCount=computed(()=>patch.value?tablePresentationChangeCount(patch.value):0);
const problems=computed(()=>draft.value&&patch.value?tablePresentationProblems(draft.value,patch.value):[]);
const changedKeys=computed(()=>new Set(patch.value?.columns?.map(column=>column.key)??[]));
const originals=computed(()=>new Map(source.value?.columns.map(column=>[column.key,column])??[]));
const filtered=computed(()=>draft.value?.columns.filter(column=>(!changedOnly.value||changedKeys.value.has(column.key))&&(!search.value||[column.name,column.label,column.description].join(' ').toLocaleLowerCase('pt-BR').includes(search.value.toLocaleLowerCase('pt-BR'))))??[]);
const pages=computed(()=>Math.max(1,Math.ceil(filtered.value.length/25)));
const visible=computed(()=>filtered.value.slice((page.value-1)*25,page.value*25));
const status=computed(()=>review.value?conversationReviewState(review.value,clock.value):'');
const running=computed(()=>['approved','executing'].includes(status.value));
const result=computed<Item>(()=>review.value?.payload?.execution?.result??{});
const failure=computed(()=>review.value?.payload?.execution?.message??'Confira a tabela antes de preparar outra alteração.');
watch([search,changedOnly],()=>page.value=1);
watch(pages,value=>{if(page.value>value)page.value=value;});
function typeName(column:TableColumn){return ({Number:'Número',String:'Texto',Date:'Data'} as Record<string,string>)[column.type]??'Campo';}
function originalMask(key:string){return originals.value.get(key)?.mask??null;}
async function begin(){
 const current=guard.next();opened.value=true;loading.value=true;error.value='';review.value=null;source.value=null;draft.value=null;uncertain.value=false;completionSent=false;requestId.value=crypto.randomUUID();search.value='';changedOnly.value=false;page.value=1;
 try{const data=await props.request(`/catalog/${props.tenant}/tables/${props.resource.id}/presentation`);if(!current())return;source.value=data;draft.value=tablePresentationDraft(data);}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{if(current()){loading.value=false;await nextTick();nameInput.value?.focus();}}
}
async function prepare(){
 if(busy.value||!patch.value||!changeCount.value||problems.value.length)return;const current=guard.current();busy.value=true;error.value='';
 try{const response=await props.request(`/catalog/${props.tenant}/tables/${props.resource.id}/presentation/actions`,'POST',{request_id:requestId.value,...patch.value});if(!current())return;review.value=response.approval;emit('updated');}
 catch(e){if(current())error.value=(e as Error).message;}
 finally{if(current())busy.value=false;}
}
function notifyCompletion(){if(status.value!=='completed'||completionSent)return;completionSent=true;emit('updated');emit('notice',result.value.message??'Nomes e formatos salvos no G-Analise BI.');}
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
function close(){guard.invalidate();opened.value=false;}
onMounted(()=>{timer=setInterval(()=>{clock.value=Date.now();if(!document.hidden)refreshStatus();},2200);});
onUnmounted(()=>{guard.invalidate();if(timer)clearInterval(timer);});
</script>

<template>
 <section class="table-presentation-form" aria-label="Organizar nomes e formatos da tabela" data-tour="table-presentation">
  <button v-if="!opened" class="secondary-button" @click="begin"><Pencil :size="15"/>Organizar nomes e formatos</button>
  <template v-else>
   <header class="table-presentation-intro"><span class="project-icon blue"><Table2 :size="23"/></span><div><h3>Organizar nomes e formatos</h3><p>{{tenantName}} · Deixe os campos mais fáceis de entender.</p></div></header>
   <div v-if="loading" class="table-presentation-loading" role="status"><LoaderCircle class="spin" :size="22"/>Conferindo a configuração atual…</div>
   <form v-else-if="!review&&draft&&source" @submit.prevent="prepare">
    <fieldset :disabled="busy"><div class="table-presentation-field"><label :for="fieldId+'-name'">Nome da tabela</label><input :id="fieldId+'-name'" ref="nameInput" v-model="draft.name" required maxlength="200"></div><div class="table-presentation-field"><label :for="fieldId+'-description'">Descrição da tabela <span>opcional</span></label><textarea :id="fieldId+'-description'" v-model="draft.description" rows="2" maxlength="4000" placeholder="Explique quais informações esta tabela reúne"></textarea></div>
     <div class="table-columns-heading"><div><h4>Campos da tabela <span>{{source.columns.length}}</span></h4><p>Escolha nomes claros e o formato dos valores numéricos.</p></div><strong aria-live="polite">{{changeCount}} {{changeCount===1?'alteração':'alterações'}}</strong></div>
     <div class="table-columns-filters"><div class="table-column-search"><Search :size="15"/><input v-model="search" aria-label="Buscar campo da tabela" placeholder="Buscar campo…" type="search"></div><label><input v-model="changedOnly" type="checkbox">Somente campos alterados</label></div>
     <div class="table-columns-list"><article v-for="(column,index) in visible" :key="column.key" class="table-column-card" :class="{'is-changed':changedKeys.has(column.key)}"><header><span>{{column.name}}</span><small>{{typeName(column)}}</small><strong v-if="changedKeys.has(column.key)">Alterado</strong></header><div class="table-column-fields"><div class="table-presentation-field"><label :for="fieldId+'-label-'+index">Nome exibido</label><input :id="fieldId+'-label-'+index" v-model="column.label" required maxlength="200"></div><div class="table-presentation-field"><label :for="fieldId+'-format-'+index">Formato</label><select v-if="column.mask_editable" :id="fieldId+'-format-'+index" v-model="column.mask"><option v-if="originals.get(column.key)?.has_custom_mask" :value="originalMask(column.key)">Formato atual do BI</option><option v-for="option in source.mask_options" :key="option.value??'default'" :value="option.value">{{option.label}}</option></select><span v-else :id="fieldId+'-format-'+index" class="table-column-preserved">Formato da origem preservado</span></div><div class="table-presentation-field column-description"><label :for="fieldId+'-column-description-'+index">Descrição <span>opcional</span></label><textarea :id="fieldId+'-column-description-'+index" v-model="column.description" rows="2" maxlength="4000" placeholder="Explique o significado deste campo"></textarea></div></div></article></div>
     <p v-if="!filtered.length" class="table-column-empty">{{source.columns.length?'Nenhum campo corresponde a estes filtros. As demais alterações continuam guardadas no formulário.':'Esta tabela ainda não possui campos disponíveis.'}}</p>
     <nav v-if="pages>1" class="table-columns-pagination" aria-label="Páginas de campos"><span>{{filtered.length}} campos · Página {{page}} de {{pages}}</span><button type="button" class="secondary-button" :disabled="page===1" @click="page--"><ChevronLeft :size="14"/>Anterior</button><button type="button" class="secondary-button" :disabled="page===pages" @click="page++">Próxima<ChevronRight :size="14"/></button></nav>
    </fieldset>
    <p class="table-presentation-note"><ShieldCheck :size="17"/><span>Você verá as alterações antes de autorizar. Estes nomes e formatos são padrões da tabela; cada dashboard pode ter suas próprias escolhas de apresentação.</span></p>
    <ul v-if="problems.length" class="table-presentation-problems" role="alert"><li v-for="problem in problems" :key="problem">{{problem}}</li></ul>
    <div v-if="error" class="form-error" role="alert"><AlertCircle :size="16"/>{{error}}</div>
    <div class="table-presentation-footer"><button type="button" class="secondary-button" :disabled="busy" @click="close">Cancelar</button><button class="primary-button" :disabled="busy||!changeCount||!!problems.length"><LoaderCircle v-if="busy" class="spin" :size="15"/><ShieldCheck v-else :size="15"/>Conferir alterações</button></div>
   </form>
   <div v-else-if="!review"><p class="form-error" role="alert">{{error}}</p><div class="table-presentation-footer"><button class="secondary-button" @click="close">Fechar</button><button class="primary-button" @click="begin">Tentar novamente</button></div></div>
   <template v-else>
    <template v-if="status==='pending'"><h4 class="table-review-title">Confira antes de autorizar</h4><ApprovalReview :approval="review" :tenants="[{id:tenant,name:tenantName}]"/><div class="table-presentation-footer"><button class="secondary-button" :disabled="busy||uncertain" @click="decide('rejected')"><X :size="15"/>Recusar</button><button class="primary-button" :disabled="busy||uncertain" @click="decide('approved')"><LoaderCircle v-if="busy" class="spin" :size="15"/><Check v-else :size="15"/>Autorizar alterações</button></div></template>
    <div v-else-if="running" class="table-presentation-result" role="status"><LoaderCircle class="spin" :size="24"/><div><strong>Salvando e conferindo a tabela</strong><p>Aguarde a confirmação dos campos no BI.</p></div></div>
    <div v-else-if="status==='completed'" class="table-presentation-result success" role="status"><CheckCircle2 :size="24"/><div><strong>Nomes e formatos salvos</strong><p>{{result.confirmed_name}} · {{result.changed_fields}} {{result.changed_fields===1?'alteração confirmada':'alterações confirmadas'}}</p></div></div>
    <div v-else class="table-presentation-result" role="status"><AlertCircle :size="23"/><div><strong>{{status==='rejected'?'Solicitação recusada':status==='expired'?'Revisão expirada':status==='cancelled'?'Solicitação cancelada':'A alteração precisa de conferência'}}</strong><p>{{status==='failed'?failure:'Nenhuma nova execução será iniciada por esta revisão.'}}</p></div></div>
    <div v-if="error" class="form-error" role="alert"><AlertCircle :size="16"/>{{error}}</div><p v-if="uncertain" class="table-presentation-note">A decisão não pôde ser confirmada. Confira a solicitação antes de decidir novamente.</p>
    <div class="table-presentation-footer"><button v-if="uncertain||running" class="secondary-button" :disabled="polling" @click="refreshStatus(true)"><RefreshCw :size="15"/>Conferir solicitação</button><button v-if="status==='completed'" class="primary-button" @click="emit('saved',result)">Ver tabela atualizada</button><button class="secondary-button" :disabled="busy" @click="close">Fechar</button></div>
   </template>
  </template>
 </section>
</template>

<style scoped>
.table-presentation-form{margin:24px 0;color:#30496a;min-width:0}.table-presentation-intro{display:flex;gap:13px;align-items:center;margin-bottom:22px}.table-presentation-intro h3{font-size:20px;margin:0 0 7px}.table-presentation-intro p,.table-columns-heading p{font-size:12px;color:#76869e;margin:0;line-height:1.7}.table-presentation-form fieldset{min-width:0;margin:0;padding:0;border:0}.table-presentation-field{min-width:0;margin-bottom:17px}.table-presentation-field label{display:block;font-size:12px;font-weight:600;color:#50627d;margin-bottom:7px}.table-presentation-field label span{font-weight:400;color:#8692a5;margin-left:5px}.table-presentation-field input,.table-presentation-field textarea,.table-presentation-field select,.table-column-search{box-sizing:border-box;width:100%;min-width:0;font:inherit;font-size:13px;color:#30496a;background:#fff;border:1px solid #dbe3ef;border-radius:8px;padding:11px}.table-presentation-field textarea{display:block;resize:vertical}.table-columns-heading{display:flex;align-items:center;gap:14px;justify-content:space-between;margin:27px 0 16px}.table-columns-heading h4{font-size:15px;margin:0 0 7px}.table-columns-heading h4 span{font-size:11px;border-radius:5px;background:#eaf1fc;color:#6e85a7;padding:3px 6px;margin-left:7px}.table-columns-heading>strong{font-size:11px;color:#0861ee;white-space:nowrap}.table-columns-filters{display:flex;gap:14px;align-items:center;flex-wrap:wrap;margin-bottom:15px}.table-column-search{display:flex;align-items:center;gap:8px;flex:1;min-width:180px;padding:9px 11px}.table-column-search input{width:100%;min-width:0;border:0;background:transparent;color:inherit;font:inherit;outline:none}.table-column-search:focus-within{outline:2px solid #0861ee;outline-offset:2px}.table-columns-filters>label{display:flex;align-items:center;gap:7px;font-size:11px;color:#6c7e96;flex-shrink:0;white-space:nowrap;min-height:36px}.table-columns-filters>label input{width:15px;height:15px;flex:0 0 15px;margin:0;padding:0;accent-color:#0861ee}.table-columns-list{display:grid;gap:12px}.table-column-card{border:1px solid #e0e7f1;border-radius:10px;padding:15px;background:#fff;min-width:0}.table-column-card.is-changed{border-color:#a8c9ff;background:#f8fbff}.table-column-card>header{display:flex;align-items:center;gap:9px;margin-bottom:16px;min-width:0}.table-column-card>header>span{font-size:12px;overflow-wrap:anywhere;font-weight:600}.table-column-card>header>small{font-size:10px;color:#8190a4}.table-column-card>header>strong{font-size:10px;color:#0861ee;margin-left:auto}.table-column-fields{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:0 16px}.table-column-fields .column-description{grid-column:1/-1;margin-bottom:0}.table-column-preserved{display:block;font-size:11px;padding:12px 0;color:#7a8ba3}.table-columns-pagination{display:flex;gap:9px;align-items:center;justify-content:flex-end;flex-wrap:wrap;margin-top:16px}.table-columns-pagination>span{font-size:11px;color:#7d8fa7;margin-right:auto}.table-column-empty{padding:20px;color:#7b8da7;font-size:12px;background:#f5f8fc;border-radius:8px;line-height:1.8}.table-presentation-note{display:flex;align-items:flex-start;gap:9px;background:#f0f5fd;color:#586f90;padding:13px;border-radius:9px;font-size:12px;line-height:1.8}.table-presentation-note svg{flex-shrink:0;margin-top:3px}.table-presentation-footer{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-top:22px}.table-presentation-loading{display:flex;align-items:center;justify-content:center;gap:12px;min-height:140px;font-size:13px;color:#748198}.table-presentation-result{display:flex;align-items:flex-start;gap:13px;background:#fff7e9;padding:19px;border-radius:10px;color:#85692c}.table-presentation-result.success{background:#edf8f2;color:#2d7654}.table-presentation-result strong{display:block;font-size:14px}.table-presentation-result p{font-size:12px;line-height:1.8;margin:7px 0}.table-review-title{font-size:16px}.table-presentation-problems{padding:12px 12px 12px 30px;background:#fff7eb;color:#8b631c;font-size:12px;line-height:1.8;border-radius:8px}
@media(max-width:600px){.table-column-fields{grid-template-columns:minmax(0,1fr)}.table-presentation-footer{align-items:stretch;flex-direction:column}.table-presentation-intro h3{font-size:18px}.table-columns-heading{align-items:flex-start}.table-column-card{padding:12px}}
</style>
