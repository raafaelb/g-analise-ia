<script setup lang="ts">
import {computed,onUnmounted,ref,watch} from 'vue';
import {AlertCircle,LoaderCircle,RefreshCw} from 'lucide-vue-next';
type Item=Record<string,any>;
const props=defineProps<{approval:Item;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{confirmed:[result:Item]}>();
const busy=ref(false);const error=ref('');let version=0;let disposed=false;
const available=computed(()=>['failed','cancelled','expired'].includes(props.approval.status)&&props.approval.type==='manual'&&props.approval.payload?.action?.action==='create_app'&&!!props.approval.payload?.app_creation_attempt?.started_at);
async function verify(){
 if(busy.value||!available.value)return;const current=++version;const id=props.approval.id;const projectId=props.approval.project_id;busy.value=true;error.value='';
 try{const result=await props.request(`/bi-actions/${id}/app-creation-verification`,'POST');if(disposed||version!==current||props.approval.id!==id)return;const receipt=result.approval?.payload?.execution?.result;if(result.approval?.id!==id||result.approval?.project_id!==projectId||result.approval?.status!=='completed'||receipt?.app_creation?.verified!==true||!/^app:[1-9][0-9]*$/.test(receipt.resource??'')||result.project?.id!==projectId||result.project?.source_ref!==receipt.resource)throw new Error('A criação ainda não foi confirmada neste projeto. Confira esta operação antes de criar outra aplicação.');emit('confirmed',result);}
 catch(e){if(!disposed&&version===current)error.value=(e as Error).message;}finally{if(!disposed&&version===current)busy.value=false;}
}
watch(()=>props.approval.id,()=>{version++;busy.value=false;error.value='';});
onUnmounted(()=>{disposed=true;version++;});
</script>
<template><div v-if="available" class="app-creation-recovery"><p>Confira a aplicação desta tentativa. Esta consulta não cria outro recurso e não inicia uma tarefa de IA.</p><button type="button" class="secondary-button" :disabled="busy" @click="verify"><LoaderCircle v-if="busy" class="spin" :size="15"/><RefreshCw v-else :size="15"/>{{busy?'Conferindo criação…':'Conferir criação'}}</button><p v-if="error" class="form-error" role="alert"><AlertCircle :size="15"/>{{error}}</p></div></template>
<style scoped>.app-creation-recovery{margin-top:12px;padding:13px;border:1px solid #dce6f5;background:#f8fbff;border-radius:8px}.app-creation-recovery>p{font-size:12px;line-height:1.7;color:#68778f;margin-bottom:10px}.app-creation-recovery .form-error{margin:10px 0 0;color:#99552c}</style>
