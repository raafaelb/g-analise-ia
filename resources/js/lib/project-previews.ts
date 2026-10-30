export type PreviewArtifact = Record<string, any> & {
    id: string;
    project_id: string;
    kind: 'dashboard_export';
    mime: 'image/png';
    created_at: string;
};

export function projectPreviews(artifacts: readonly unknown[], projectId: string, resource?: string): PreviewArtifact[] {
    return artifacts.filter((value): value is PreviewArtifact => {
        if (value === null || typeof value !== 'object' || Array.isArray(value)) return false;
        const artifact = value as Record<string, any>;
        return typeof artifact.id === 'string' && artifact.project_id === projectId
            && (!resource || !/^app:[1-9][0-9]*$/.test(resource) || artifact.metadata?.resource === resource)
            && artifact.kind === 'dashboard_export' && artifact.mime === 'image/png'
            && typeof artifact.created_at === 'string' && artifact.metadata?.example !== true
            && typeof artifact.metadata?.resource === 'string' && /^app:[1-9][0-9]*$/.test(artifact.metadata.resource);
    }).sort((left, right) => {
        const time = (Date.parse(right.created_at) || 0) - (Date.parse(left.created_at) || 0);
        return time || right.id.localeCompare(left.id);
    });
}

export function choosePreview(artifacts: readonly PreviewArtifact[], selectedId: string | null, followLatest: boolean): PreviewArtifact | null {
    if (!artifacts.length) return null;
    return followLatest ? artifacts[0] : artifacts.find(artifact => artifact.id === selectedId) ?? artifacts[0];
}

export function dashboardPreviews(artifacts: readonly PreviewArtifact[], dashboard: string | null, firstDashboard: string | null): PreviewArtifact[] {
    return artifacts.filter(artifact => !dashboard || String(artifact.metadata?.dashboard_id ?? '') === dashboard
        || (artifact.metadata?.dashboard_id == null && dashboard === firstDashboard));
}

export type LivePreviewContext = {projectId: string; resource: string | null; revision: string | null};

export function shouldFollowLivePreview(before: LivePreviewContext, after: LivePreviewContext, follow: boolean, mode: string, visible = true): boolean {
    return visible && follow && mode === 'interactive' && after.projectId === before.projectId
        && after.resource === before.resource && /^app:[1-9][0-9]*$/.test(after.resource ?? '')
        && Boolean(after.revision) && after.revision !== before.revision;
}
