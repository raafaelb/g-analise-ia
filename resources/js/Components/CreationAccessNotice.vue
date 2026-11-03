<script setup lang="ts">
import { ArrowRight, Info } from 'lucide-vue-next';
import type { CreationAccess, CreationAccessTarget } from '../lib/creation-access';
defineProps<{ access: CreationAccess; compact?: boolean }>();
const emit = defineEmits<{ action: [target: CreationAccessTarget] }>();
</script>

<template>
    <section v-if="!access.canSend" class="creation-access-notice" :class="{compact}" role="status" :aria-label="access.title">
        <Info :size="19" class="creation-access-icon" aria-hidden="true" />
        <div class="creation-access-copy"><strong>{{ access.title }}</strong><p>{{ access.description }}</p></div>
        <div class="creation-access-actions"><button v-for="action in access.actions" :key="action.target" type="button" class="secondary-button compact" @click="emit('action', action.target)">{{ action.label }}<ArrowRight :size="13" aria-hidden="true" /></button></div>
    </section>
</template>

<style scoped>
.creation-access-notice{display:flex;align-items:flex-start;gap:12px;text-align:left;padding:16px 18px;border:1px solid #dbe6f8;border-radius:14px;background:#f2f7ff;color:#52647c;margin:16px 0}.creation-access-icon{flex-shrink:0;margin-top:1px;color:#3978ce}.creation-access-copy{flex:1;min-width:0}.creation-access-copy strong{display:block;font-size:13px;font-weight:650;color:#365779;line-height:1.5}.creation-access-copy p{font-size:12px;line-height:1.6;margin:4px 0 0}.creation-access-actions{display:flex;flex-wrap:wrap;gap:7px;align-self:center;max-width:230px}.creation-access-actions button{font-size:11px;white-space:normal;text-align:left}.creation-access-notice.compact{padding:12px 14px;margin:0 0 12px;gap:9px}.compact .creation-access-copy strong{font-size:12px}.compact .creation-access-copy p{font-size:11px}.compact .creation-access-actions{max-width:180px}
@media(max-width:720px){.creation-access-notice{flex-wrap:wrap;padding:14px;gap:9px}.creation-access-copy{flex-basis:calc(100% - 30px)}.creation-access-actions,.compact .creation-access-actions{padding-left:28px;max-width:none}.creation-access-actions button{min-height:36px}}
</style>
