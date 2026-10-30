type Item = Record<string, any>;
type Target = {artifactId: string; mode: 'interactive' | 'image'; dashboard: string | null};

export function chatPreviewTarget(href: string, location: string, basePath: string, artifacts: Item[], project: Item | undefined): Target | null {
    if (!project?.id) return null;
    let url: URL;
    try { url = new URL(href, location); } catch { return null; }
    if (url.origin !== new URL(location).origin || url.username || url.password) return null;
    const prefix = basePath.replace(/\/$/, '');
    const expected = prefix + '/api/artifacts/';
    if (!url.pathname.startsWith(expected) || url.searchParams.get('download') === '1') return null;
    const match = url.pathname.slice(expected.length).match(/^([a-zA-Z0-9-]+)\/download$/);
    if (!match) return null;
    const artifact = artifacts.find(item => item.id === match[1] && item.project_id === project.id && item.kind === 'dashboard_export' && item.mime === 'image/png' && !item.metadata?.example && /^app:[1-9][0-9]*$/.test(item.metadata?.resource ?? ''));
    if (!artifact) return null;
    return {artifactId: artifact.id, mode: artifact.metadata.resource === project.source_ref ? 'interactive' : 'image', dashboard: artifact.metadata.dashboard_id == null ? null : String(artifact.metadata.dashboard_id)};
}
