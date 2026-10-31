<script setup lang="ts">
import {nextTick,onUnmounted,ref,watch} from 'vue';
import {AlertCircle,CheckCircle2,Database,LoaderCircle,Pencil,PlugZap,ShieldCheck,Workflow,ChevronUp} from 'lucide-vue-next';
import BiCredentialForm from './BiCredentialForm.vue';
import BiSourceRelationships from './BiSourceRelationships.vue';
import BiSourceSchema from './BiSourceSchema.vue';
import {createRequestGuard} from '../lib/async-response';
import {previousInspectionIsUsable} from '../lib/inspection-result';
type Item=Record<string,any>;
const props=defineProps<{tenant:string;tenantName:string;resource:Item;management?:Item|null;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{updated:[];saved:[resource:string];notice:[message:string]}>();
const section=ref<HTMLElement|null>(null);const inspectionError=ref<HTMLParagraphElement|null>(null);const lastAction=ref('');
const editing=ref(false);const busy=ref('');const report=ref<Item|null>(null);const error=ref('');const schema=ref('');const guard=createRequestGuard();
async function inspect(action:'test'|'schema'|'relationships',filters?:{schema:string|null;search:string|null;offset:number}){
 if(busy.value)return;const keepReport=!!filters&&lastAction.value===action;const current=guard.next();busy.value=action;lastAction.value=action;if(!keepReport)report.value=null;error.value='';
 try{const result=await props.request(`/catalog/${props.tenant}/credentials/${props.resource.id}/inspect/${action}`,'POST',filters??(action!=='test'&&schema.value.trim()?{schema:schema.value.trim()}:{}));if(current())report.value=result;}
 catch(e){if(current()){const retain=keepReport&&previousInspectionIsUsable(e);if(!retain)report.value=null;error.value=(e as Error).message+(retain?' O resultado anterior permanece abaixo, com seus filtros e horário.':'');if(!retain){await nextTick();if(current())inspectionError.value?.focus();}}}finally{if(current())busy.value='';}
}
async function closeReport(){if(busy.value)return;guard.invalidate();report.value=null;error.value='';await nextTick();section.value?.querySelector<HTMLButtonElement>(`[data-inspection-action="${lastAction.value}"]`)?.focus({preventScroll:true});}
watch(()=>[props.tenant,props.resource.ref],()=>{guard.invalidate();report.value=null;error.value='';busy.value='';editing.value=false;schema.value='';});
onUnmounted(()=>guard.invalidate());
</script>
<template>
 <section ref="section" class="credential-actions" aria-label="Gerenciar conexão de dados">
  <BiCredentialForm v-if="editing" :tenant="tenant" :tenant-name="tenantName" :resource="resource" :request="request" @close="editing=false" @updated="emit('updated')" @notice="emit('notice',$event)" @saved="emit('saved',$event)"/>
  <template v-else><div class="credential-actions-heading"><ShieldCheck :size="18"/><div><h3>Gerenciar conexão</h3><p>Confira o acesso e a estrutura da fonte pelo agente do G-Analise.</p></div></div><div class="credential-action-buttons"><button v-if="management?.can_edit" class="secondary-button" :disabled="!!busy" @click="editing=true"><Pencil :size="15"/>Editar conexão</button><button class="primary-button" :disabled="!!busy" data-inspection-action="test" @click="inspect('test')"><LoaderCircle v-if="busy==='test'" :size="15" class="spin"/><PlugZap v-else :size="15"/>Testar conexão</button><button class="secondary-button" :disabled="!!busy" data-inspection-action="schema" @click="inspect('schema')"><LoaderCircle v-if="busy==='schema'" :size="15" class="spin"/><Database v-else :size="15"/>Ver tabelas e colunas</button><button class="secondary-button" :disabled="!!busy" data-inspection-action="relationships" @click="inspect('relationships')"><LoaderCircle v-if="busy==='relationships'" :size="15" class="spin"/><Workflow v-else :size="15"/>Ver relacionamentos</button></div>
   <p v-if="management?.message" class="credential-action-note">{{management.message}}</p><details class="credential-schema-filter"><summary>Filtrar por schema (opcional)</summary><label>Nome do schema<input v-model="schema" placeholder="Ex.: public ou dbo" :disabled="!!busy" maxlength="128"></label><p>Deixe em branco para consultar os ambientes disponíveis desta fonte.</p></details>
   <p v-if="busy" class="credential-action-note" role="status"><LoaderCircle :size="15" class="spin"/>Consultando o agente. Nenhuma carga de dados será iniciada.</p><p v-if="error&&!(report?.verified&&(report.tables||report.relationships))" ref="inspectionError" class="form-error" role="alert" tabindex="-1"><AlertCircle :size="16"/>{{error}}</p>
   <div v-if="report" class="credential-report-tools"><button type="button" class="text-button" :disabled="!!busy" @click="closeReport"><ChevronUp :size="14"/>Ocultar consulta</button></div>
   <div v-if="report" class="credential-report" :class="{confirmed:report.verified}" role="status"><CheckCircle2 v-if="report.verified" :size="20"/><AlertCircle v-else :size="20"/><div><strong>{{report.verified?(report.relationships?'Relacionamentos consultados':report.tables?'Estrutura consultada':'Conexão confirmada'):'Sem confirmação'}}</strong><p>{{report.message}}</p><small>Consultado em {{new Date(report.checked_at).toLocaleString('pt-BR',{timeZone:'America/Sao_Paulo'})}} · Brasília</small></div></div>
   <BiSourceRelationships v-if="report?.verified&&report.relationships" :report="report" :schema="schema" :busy="!!busy" :error="error" @query="inspect('relationships',$event)"/>
   <BiSourceSchema v-if="report?.verified&&report.tables" :report="report" :schema="schema" :busy="!!busy" :error="error" @query="inspect('schema',$event)"/>
  </template>
 </section>
</template>
<style scoped>
.credential-actions{margin-top:20px;border:1px solid #dce6f4;border-radius:10px;padding:18px;background:#f8fbff}.credential-actions-heading{display:flex;align-items:flex-start;gap:10px;color:#3e608d}.credential-actions-heading h3{font-size:13px;margin:0 0 6px}.credential-actions-heading p,.credential-action-note{font-size:12px;color:#6b7f9c;line-height:1.7}.credential-action-buttons{display:flex;gap:10px;flex-wrap:wrap;margin:16px 0}.credential-schema-filter{font-size:11px;color:#7185a0;margin:16px 0}.credential-schema-filter summary{cursor:pointer}.credential-schema-filter label{display:block;max-width:280px;margin:12px 0}.credential-schema-filter input{display:block;width:100%;margin-top:6px;background:white}.credential-schema-filter p{line-height:1.7}.credential-report-tools{display:flex;justify-content:flex-end;margin-top:14px}.credential-report-tools button{display:flex;align-items:center;gap:6px;font-size:11px;color:#426c9f}.credential-report{display:flex;gap:12px;padding:14px;border:1px solid #eddfc8;background:#fffaf2;color:#86602e;border-radius:8px;margin-top:15px}.credential-report>svg{flex-shrink:0}.credential-report.confirmed{color:#287253;border-color:#d4e8dd;background:#f1faf5}.credential-report strong{font-size:13px}.credential-report p{font-size:12px;line-height:1.7;margin:7px 0}.credential-report small{font-size:10px;color:#71869c}.credential-action-note svg{display:inline-block;vertical-align:middle;margin-right:6px}@media(max-width:600px){.credential-actions{padding:13px}.credential-action-buttons button{flex-grow:1}}
</style>
