<script setup lang="ts">
import {computed,onUnmounted,ref,watch} from 'vue';
import {AlertCircle,LoaderCircle,RefreshCw} from 'lucide-vue-next';
type Item=Record<string,any>;
const props=defineProps<{approval:Item;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{confirmed:[result:Item]}>();
const busy=ref(false);const error=ref('');let version=0;let disposed=false;
const available=computed(()=>['failed','cancelled','expired'].includes(props.approval.status)&&props.approval.type==='manual'&&['create_variable','update_variable'].includes(props.approval.payload?.action?.action)&&props.approval.payload?.action?.resource==='variables'&&!!props.approval.payload?.variable_attempt?.started_at&&props.approval.payload.variable_attempt.key===props.approval.payload.action.key&&props.approval.payload.revision?.variable?.key===props.approval.payload.action.key);
async function verify(){
 if(busy.value||!available.value)return;const current=++version;const id=props.approval.id;const key=props.approval.payload.action.key;busy.value=true;error.value='';
 try{const response=await props.request(`/bi-actions/${id}/variable-verification`,'POST');if(disposed||version!==current||props.approval.id!==id)return;const result=response.approval?.payload?.execution?.result;if(response.approval?.id!==id||response.approval?.status!=='completed'||result?.resource!=='variables'||result?.variable_key!==key||result?.configuration_verified!==true)throw new Error('O resultado desta variável ainda não foi confirmado. Confira a configuração atual.');emit('confirmed',response);}
 catch(e){if(!disposed&&version===current)error.value=(e as Error).message;}finally{if(!disposed&&version===current)busy.value=false;}
}
watch(()=>props.approval.id,()=>{version++;busy.value=false;error.value='';});
onUnmounted(()=>{disposed=true;version++;});
</script>
<template><div v-if="available" class="variable-recovery"><p>Confira a configuração salva sem reenviar valores. Se o recebimento de um segredo não foi confirmado, a leitura mascarada não comprova sua gravação.</p><button class="secondary-button" type="button" :disabled="busy" @click="verify"><LoaderCircle v-if="busy" class="spin" :size="15"/><RefreshCw v-else :size="15"/>{{busy?'Conferindo resultado…':'Conferir resultado'}}</button><p v-if="error" class="form-error" role="alert"><AlertCircle :size="15"/>{{error}}</p></div></template>
<style scoped>.variable-recovery{margin-top:12px;padding:13px;border:1px solid #dce6f5;border-radius:8px;background:#f8fbff}.variable-recovery>p{font-size:12px;line-height:1.7;color:#68778f;margin-bottom:10px}.variable-recovery .form-error{margin-top:10px;color:#99552c}</style>
