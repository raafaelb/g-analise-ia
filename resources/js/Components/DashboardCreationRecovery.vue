<script setup lang="ts">
import {computed,onUnmounted,ref,watch} from 'vue';
import {AlertCircle,LoaderCircle,RefreshCw} from 'lucide-vue-next';
type Item=Record<string,any>;
const props=defineProps<{approval:Item;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{confirmed:[result:Item]}>();const busy=ref(false);const error=ref('');let version=0;let disposed=false;
const available=computed(()=>props.approval.payload?.dashboard_creation_resolution?.state!=='rejected'&&['failed','expired','cancelled'].includes(props.approval.status)&&props.approval.type==='manual'&&props.approval.payload?.action?.action==='create_dashboard'&&/^app:[1-9][0-9]*$/.test(props.approval.payload.action.resource??'')&&props.approval.payload?.revision?.resource===props.approval.payload.action.resource&&props.approval.payload?.dashboard_creation_attempt?.resource===props.approval.payload.action.resource&&!!props.approval.payload.dashboard_creation_attempt.started_at);
async function verify(){
 if(!available.value||busy.value)return;const current=++version;const id=props.approval.id;const resource=props.approval.payload.action.resource;busy.value=true;error.value='';
 try{const response=await props.request(`/bi-actions/${id}/dashboard-creation-verification`,'POST');if(disposed||version!==current||props.approval.id!==id)return;const result=response.approval?.payload?.execution?.result;const receipt=result?.dashboard_creation;const resolution=response.approval?.payload?.dashboard_creation_resolution;if(response.approval?.id===id&&response.approval.status==='failed'&&response.approval.payload?.action?.resource===resource&&resolution?.state==='rejected'&&resolution.native_rejection_verified===true){emit('confirmed',response);return;}if(response.approval?.id!==id||response.approval?.status!=='completed'||result?.resource!==resource||result.configuration_verified!==true||receipt?.verified!==true||!Number.isInteger(receipt.dashboard_id)||receipt.dashboard_id<1||receipt.name!==props.approval.payload.action.name)throw new Error('A criação deste painel ainda não foi confirmada. Confira a aplicação atual.');emit('confirmed',response);}
 catch(e){if(!disposed&&version===current)error.value=(e as Error).message;}
 finally{if(!disposed&&version===current)busy.value=false;}
}
watch(()=>props.approval.id,()=>{version++;busy.value=false;error.value='';});onUnmounted(()=>{disposed=true;version++;});
</script>
<template><div v-if="available" class="dashboard-creation-recovery"><p>Confira se o novo dashboard foi adicionado à aplicação. Esta consulta não repete a criação nem inicia uma tarefa de IA.</p><button class="secondary-button" :disabled="busy" @click="verify"><LoaderCircle v-if="busy" class="spin" :size="15"/><RefreshCw v-else :size="15"/>{{busy?'Conferindo criação…':'Conferir criação'}}</button><p v-if="error" class="form-error" role="alert"><AlertCircle :size="15"/>{{error}}</p></div></template>
<style scoped>.dashboard-creation-recovery{margin-top:12px;border:1px solid #dce6f5;background:#f8fbff;border-radius:8px;padding:13px}.dashboard-creation-recovery>p{font-size:12px;line-height:1.8;color:#71859f;margin:0 0 10px}.dashboard-creation-recovery .form-error{margin:10px 0 0;color:#99552c}</style>
