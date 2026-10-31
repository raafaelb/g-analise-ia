export const flowWizardSteps = [
    {action:'create_source_flow',label:'Configuração',button:'Revisar configuração'},
    {action:'test_source_flow',label:'Teste de extração',button:'Revisar teste de extração'},
    {action:'create_source_table',label:'Tabela privada',button:'Revisar criação da tabela'},
    {action:'load_source_table',label:'Carga e conferência',button:'Revisar carga da tabela'},
] as const;
type Review = Record<string, any>;
export function wizardReviewState(review:Review, now=Date.now()):string {
    if(review.status==='pending'&&(!review.expires_at||!Number.isFinite(Date.parse(review.expires_at))||Date.parse(review.expires_at)<=now))return 'expired';
    return review.status;
}
export function wizardProgress(reviews:Review[],now=Date.now()) {
    const active=[...reviews].reverse().find(review=>['pending','approved','executing'].includes(wizardReviewState(review,now)));
    const completed=new Set(reviews.filter(review=>review.status==='completed').map(review=>review.payload?.action?.action));
    const next=flowWizardSteps.find(step=>!completed.has(step.action));
    const previous=next?[...reviews].reverse().find(review=>review.payload?.action?.action===next.action):null;
    const uncertain=!!previous&&!!previous.payload?.flow_wizard_attempt?.started_at&&previous.status!=='completed'&&previous.payload?.etl_attempt?.not_dispatched!==true;
    const activeAction=active?.payload?.action?.action;
    return {active,completed,next:active||uncertain?undefined:next,uncertain,blocked:uncertain?previous:null,currentAction:activeAction==='configure_source_flow'?'create_source_flow':activeAction??next?.action};
}
