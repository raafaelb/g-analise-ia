<script setup lang="ts">
import {computed,ref} from 'vue';
import {AlertCircle,LoaderCircle,RefreshCw} from 'lucide-vue-next';
type Item=Record<string,any>;
const props=defineProps<{approval:Item;request:(path:string,method?:string,data?:unknown)=>Promise<any>}>();
const emit=defineEmits<{confirmed:[result:Item]}>();
const busy=ref(false);const error=ref('');
const available=computed(()=>props.approval.status==='failed'&&['publish_app','publish_flow'].includes(props.approval.payload?.action?.action)&&!!props.approval.payload?.publication_attempt?.started_at);
async function verify(){
 if(busy.value||!available.value)return;
 busy.value=true;error.value='';
 try{const result=await props.request(`/approvals/${props.approval.id}/publication/verify`,'POST');emit('confirmed',result);}
 catch(e){error.value=(e as Error).message;}finally{busy.value=false;}
}
</script>
<template><div v-if="available" class="publication-recovery"><p>Consulte o BI novamente para conferir o resultado desta revisão. A conferência não envia outra publicação nem inicia uma tarefa de IA.</p><button class="secondary-button" :disabled="busy" @click="verify"><LoaderCircle v-if="busy" :size="15" class="spin"/><RefreshCw v-else :size="15"/>{{busy?'Conferindo publicação…':'Conferir publicação'}}</button><p v-if="error" class="form-error" role="alert"><AlertCircle :size="15"/>{{error}}</p></div></template>
<style scoped>.publication-recovery{margin-top:12px;padding:13px;border:1px solid #dce6f5;background:#f8fbff;border-radius:8px}.publication-recovery>p{font-size:12px;line-height:1.7;color:#68778f;margin-bottom:10px}.publication-recovery .form-error{margin-top:10px;margin-bottom:0;color:#99552c}</style>
