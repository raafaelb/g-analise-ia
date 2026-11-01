export const MAX_TASK_ATTACHMENTS = 12;
type Attachment = Record<string, any>;

export function orderedTaskAttachments(files: Attachment[]): Attachment[] {
    return files.filter(file => file.kind === 'attachment').slice().sort((left, right) =>
        String(left.created_at ?? '').localeCompare(String(right.created_at ?? '')) || String(left.id).localeCompare(String(right.id)),
    );
}

export function taskAttachmentIds(files: Attachment[], selected: readonly string[] | null | undefined): string[] {
    return selected == null ? orderedTaskAttachments(files).slice(0, MAX_TASK_ATTACHMENTS).map(file => String(file.id)) : [...selected];
}

export function moveTaskAttachment(ids: readonly string[], id: string, direction: -1 | 1): string[] {
    const next = [...ids];
    const index = next.indexOf(id);
    const target = index + direction;
    if (index >= 0 && target >= 0 && target < next.length) [next[index], next[target]] = [next[target], next[index]];
    return next;
}

export function taskAttachmentLabel(file: Attachment): string {
    const state = file.metadata?.extraction;
    if (state?.status === 'ready' && state.visual_pdf) {
        const count = state.visual_pages?.length ?? 0;
        return 'Leitura visual · ' + count + ' ' + (count === 1 ? 'página' : 'páginas');
    }
    return ({queued: 'Preparando conteúdo…', processing: 'Preparando leitura…', ready: state?.truncated ? 'Conteúdo disponível com limites' : 'Pronto para usar', empty: 'Sem texto extraível', failed: 'Falha na preparação'} as Record<string, string>)[state?.status] || 'Arquivo anexado';
}
