<script setup lang="ts">
import {computed} from 'vue';
import {describeExecutionMessage} from '../lib/execution-message';
const props=defineProps<{message:unknown;state?:string}>();
const notice=computed(()=>describeExecutionMessage(props.message,props.state));
</script>
<template><div v-if="notice" class="bi-execution-message" :class="{technical:notice.details}"><p>{{notice.summary}}</p><details v-if="notice.details"><summary>Ver detalhes técnicos</summary><pre>{{notice.details}}</pre></details></div></template>
<style scoped>
.bi-execution-message{width:100%;min-width:0;box-sizing:border-box;margin:10px 0;color:#6b7f99}.bi-execution-message p{font-size:12px;line-height:1.8;margin:0;overflow-wrap:anywhere}.bi-execution-message.technical{padding:12px;background:#f5f8fd;border:1px solid #e0e8f3;border-radius:8px}.bi-execution-message details{margin-top:8px;font-size:11px}.bi-execution-message summary{cursor:pointer;color:#4e719e;line-height:1.7}.bi-execution-message pre{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:11px;line-height:1.7;white-space:pre-wrap;overflow-wrap:anywhere;max-height:240px;overflow:auto;margin:10px 0 0;padding:10px;border:1px solid #e1e8f3;border-radius:6px;background:#fff;color:#6d7c91;box-sizing:border-box}
</style>
