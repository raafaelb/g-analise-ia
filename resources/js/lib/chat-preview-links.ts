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

export function interactivePreviewTarget(href: string, location: string, basePath: string, project: Item | undefined): {path: string; dashboard: string | null} | null {
    if (!project?.id) return null;
    let url: URL;
    let current: URL;
    try { url = new URL(href, location); current = new URL(location); } catch { return null; }
    const loopbacks = new Set(['localhost', '127.0.0.1', '[::1]']);
    const localAlias = loopbacks.has(url.hostname) && loopbacks.has(current.hostname) && url.protocol === current.protocol && url.port === current.port;
    const path = basePath.replace(/\/$/, '') + '/app/project/' + project.id;
    const dashboard = url.searchParams.get('dashboard');
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password || (url.origin !== current.origin && !localAlias) || url.pathname !== path || url.searchParams.get('preview') !== 'interactive' || (dashboard !== null && !/^[1-9][0-9]*$/.test(dashboard))) return null;
    return {path: path + '?preview=interactive' + (dashboard ? '&dashboard=' + dashboard : ''), dashboard};
}
