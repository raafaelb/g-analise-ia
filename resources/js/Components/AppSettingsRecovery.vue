<script setup lang="ts">
import {computed,onUnmounted,ref,watch} from 'vue';
import {AlertCircle,LoaderCircle,RefreshCw} from 'lucide-vue-next';
type Item=Record<string,any>;
const props=defineProps<{approval:Item;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{confirmed:[result:Item]}>();
const busy=ref(false);const error=ref('');let version=0;let disposed=false;
const available=computed(()=>['failed','cancelled','expired'].includes(props.approval.status)&&props.approval.type==='manual'&&['update_app_details','update_app_dashboards'].includes(props.approval.payload?.action?.action)&&/^app:[1-9][0-9]*$/.test(props.approval.payload?.action?.resource??'')&&props.approval.payload?.revision?.resource===props.approval.payload?.action?.resource&&props.approval.payload?.app_settings_attempt?.resource===props.approval.payload?.action?.resource&&!!props.approval.payload?.app_settings_attempt?.started_at);
async function verify(){
 if(busy.value||!available.value)return;const current=++version;const id=props.approval.id;const resource=props.approval.payload.action.resource;busy.value=true;error.value='';
 try{const result=await props.request(`/bi-actions/${id}/app-settings-verification`,'POST');if(disposed||version!==current||props.approval.id!==id)return;if(result.approval?.id!==id||result.approval?.status!=='completed'||result.approval?.payload?.execution?.result?.resource!==resource||result.approval?.payload?.execution?.result?.configuration_verified!==true||(props.approval.payload.action.action==='update_app_dashboards'&&result.approval?.payload?.execution?.result?.dashboards_verified!==true))throw new Error('O resultado desta alteração ainda não foi confirmado. Confira a aplicação atual.');emit('confirmed',result);}
 catch(e){if(!disposed&&version===current)error.value=(e as Error).message;}finally{if(!disposed&&version===current)busy.value=false;}
}
watch(()=>props.approval.id,()=>{version++;busy.value=false;error.value='';});
onUnmounted(()=>{disposed=true;version++;});
</script>
<template><div v-if="available" class="app-settings-recovery"><p>{{approval.payload?.action?.action==='update_app_dashboards'?'Confira se os nomes e a ordem dos dashboards foram salvos na aplicação.':'Confira se o nome e a descrição revisados foram salvos na aplicação.'}} Esta consulta não reenvia a edição nem inicia uma tarefa de IA.</p><button type="button" class="secondary-button" :disabled="busy" @click="verify"><LoaderCircle v-if="busy" class="spin" :size="15"/><RefreshCw v-else :size="15"/>{{busy?'Conferindo resultado…':'Conferir resultado'}}</button><p v-if="error" class="form-error" role="alert"><AlertCircle :size="15"/>{{error}}</p></div></template>
<style scoped>.app-settings-recovery{margin-top:12px;padding:13px;border:1px solid #dce6f5;background:#f8fbff;border-radius:8px}.app-settings-recovery>p{font-size:12px;line-height:1.7;color:#68778f;margin-bottom:10px}.app-settings-recovery .form-error{margin-top:10px;margin-bottom:0;color:#99552c}</style>
