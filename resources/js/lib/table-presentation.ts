export type TableColumn = {
    key: string; name: string; type: string; label: string; description: string | null;
    mask: string | null; mask_editable: boolean; has_custom_mask: boolean;
};
export type TablePresentation = {
    resource: string; name: string; description: string | null; version: string;
    columns: TableColumn[]; mask_options: {value: string | null; label: string}[];
};
export type TablePresentationDraft = {name: string; description: string; columns: TableColumn[]};
export type TableColumnPatch = {key: string; label?: string; description?: string | null; mask?: string | null};
export type TablePresentationPatch = {version: string; name?: string; description?: string | null; columns?: TableColumnPatch[]};
const text = (value: string | null | undefined) => value?.replace(/\r\n?/g, '\n').trim() || null;

export function tablePresentationDraft(source: TablePresentation): TablePresentationDraft {
    return {name: source.name, description: source.description ?? '', columns: source.columns.map(column => ({...column}))};
}

export function tablePresentationPatch(source: TablePresentation, draft: TablePresentationDraft): TablePresentationPatch {
    const patch: TablePresentationPatch = {version: source.version};
    if (source.name.trim() !== draft.name.trim()) patch.name = draft.name.trim();
    if (text(source.description) !== text(draft.description)) patch.description = text(draft.description);
    const current = new Map(draft.columns.map(column => [column.key, column]));
    const columns: TableColumnPatch[] = [];
    for (const original of source.columns) {
        const column = current.get(original.key);
        if (!column) continue;
        const changes: TableColumnPatch = {key: original.key};
        if (original.label.trim() !== column.label.trim()) changes.label = column.label.trim();
        if (text(original.description) !== text(column.description)) changes.description = text(column.description);
        if (original.mask_editable && original.mask !== column.mask) changes.mask = column.mask;
        if (Object.keys(changes).length > 1) columns.push(changes);
    }
    if (columns.length) patch.columns = columns;
    return patch;
}

export function tablePresentationChangeCount(patch: TablePresentationPatch): number {
    return Number('name' in patch) + Number('description' in patch)
        + (patch.columns ?? []).reduce((count, column) => count + Object.keys(column).length - 1, 0);
}

export function tablePresentationProblems(draft: TablePresentationDraft, patch: TablePresentationPatch): string[] {
    const problems: string[] = [];
    if (!draft.name.trim() || draft.name.trim().length > 200) problems.push('Informe um nome para a tabela, com até 200 caracteres.');
    if (draft.description.length > 4000) problems.push('A descrição da tabela pode ter até 4.000 caracteres.');
    if ((patch.columns?.length ?? 0) > 500) problems.push('Divida a edição em revisões de até 500 colunas.');
    for (const column of patch.columns ?? []) {
        if ('label' in column && (!column.label || column.label.length > 200)) problems.push('Cada nome de coluna deve ter entre 1 e 200 caracteres.');
        if ((column.description?.length ?? 0) > 4000) problems.push('Cada descrição de coluna pode ter até 4.000 caracteres.');
    }
    return [...new Set(problems)];
}
