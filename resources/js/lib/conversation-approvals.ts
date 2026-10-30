type Item = Record<string, any>;

export function conversationReviewState(approval:Item, now=Date.now()):string {
    if (approval.status !== 'pending') return ['approved','executing','completed','rejected','expired','cancelled','failed'].includes(approval.status)?approval.status:'unavailable';
    const expires=Date.parse(approval.expires_at??'');
    if (!Number.isFinite(expires)||!approval.id||!/^[a-f0-9]{64}$/.test(approval.fingerprint??'')) return 'unavailable';
    return expires<=now?'expired':'pending';
}

export function conversationReviewAction(approval:Item):{label:string;destructive:boolean} {
    const action=approval.payload?.action?.action;
    const labels:Record<string,string>={push:'Autorizar alteração',publish_app:'Autorizar publicação',publish_flow:'Autorizar publicação',run_schedule:'Autorizar carga',resume_schedule:'Autorizar ativação',delete_draft:'Autorizar exclusão',clone_app:'Autorizar cópia'};
    return {label:labels[action]??'Autorizar esta execução',destructive:action==='delete_draft'};
}

export function conversationApprovalTimeline(messages:Item[], approvals:Item[], conversationId:string|null, projectId:string|null):{all:Item[];after:Record<string,Item[]>;unanchored:Item[]} {
    const all:Item[]=[];
    const seen=new Set<string>();
    for(const approval of approvals) {
        if(!conversationId||!projectId||approval.conversation_id!==conversationId||approval.project_id!==projectId||!approval.agent_run_id||!approval.id||seen.has(approval.id))continue;
        seen.add(approval.id);all.push(approval);
    }
    all.sort((a,b)=>(Date.parse(a.created_at)||0)-(Date.parse(b.created_at)||0)||String(a.id).localeCompare(String(b.id)));
    const after=Object.create(null) as Record<string,Item[]>;const unanchored:Item[]=[];
    for(const approval of all) {
        let anchor:Item|undefined;
        for(const message of messages)if(message.role==='assistant'&&message.metadata?.run_id===approval.agent_run_id)anchor=message;
        if(!anchor) {
            const start=messages.findIndex(message=>message.id===approval.input_message_id);
            if(start>=0) {
                anchor=messages[start];
                for(let i=start+1;i<messages.length;i++) {
                    const message=messages[i];
                    if(message.role==='user'||(message.metadata?.run_id&&message.metadata.run_id!==approval.agent_run_id))break;
                    if(Date.parse(message.created_at)>Date.parse(approval.created_at))break;
                    if(message.role==='assistant')anchor=message;
                }
            }
        }
        if(anchor)(after[anchor.id]??=[]).push(approval);else unanchored.push(approval);
    }
    return {all,after,unanchored};
}
