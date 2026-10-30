type Widget = Record<string, any>;
type Notice = {kind:'browser'|'unavailable'|'error';title:string;message:string};

const browserLimitation = /svelte\s+widget|app\.fetchMatrix\s*\(|\blumo\s+app\s+export\b|runs custom code in the browser/i;

export function widgetPreviewPresentation(widget:Widget):{typeLabel:string;notice:Notice|null;notes:string[]} {
    const type = typeof widget.type === 'string' ? widget.type.trim() : '';
    const customPanel = type.toLowerCase() === 'svelte';
    const semantic = widget.semantic && typeof widget.semantic === 'object' ? widget.semantic : {};
    const notes:string[] = Array.isArray(semantic.notes) ? semantic.notes.filter((note:unknown):note is string => typeof note === 'string' && !browserLimitation.test(note)) : [];
    const messages = [widget.reason,widget.error,...(Array.isArray(semantic.notes)?semantic.notes:[])];
    const knownLimitation = messages.some(message => typeof message === 'string' && browserLimitation.test(message));
    const failed = Boolean(widget.error) || ['error','failed'].includes(widget.status);
    const rows = widget.rows ?? widget.data?.rows ?? (Array.isArray(widget.data)?widget.data:[]);
    const hasResults = (Array.isArray(rows) && rows.length > 0) || semantic.value != null || semantic.total != null || typeof semantic.rowCount === 'number' || typeof widget.row_count === 'number';
    const typeLabel = customPanel ? 'Painel personalizado' : type;

    if (failed) {
        return {typeLabel,notes:[],notice:{kind:'error',title:'Não foi possível conferir este indicador',message:'Tente conferir os dados novamente ou abra a visualização do dashboard para verificar os indicadores.'}};
    }
    if ((knownLimitation || customPanel) && (widget.status === 'skipped' || !hasResults)) {
        return {typeLabel,notes:[],notice:{kind:'browser',title:'Confira este painel na visualização do dashboard',message:'Este painel consulta e apresenta os dados ao ser aberto no navegador. A conferência em tabela não está disponível para este formato.'}};
    }
    if (widget.reason || widget.status === 'skipped') {
        return {typeLabel,notes:[],notice:{kind:'unavailable',title:'Confira este indicador na visualização do dashboard',message:'A conferência em tabela não está disponível para o formato deste indicador.'}};
    }

    return {typeLabel,notes,notice:null};
}
