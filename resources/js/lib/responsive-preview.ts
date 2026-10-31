export const previewDevices = [
    {id: 'auto', label: 'Ajustar à área disponível', width: null, height: null},
    {id: 'desktop', label: 'Computador', width: 1280, height: 800},
    {id: 'tablet', label: 'Tablet', width: 768, height: 1024},
    {id: 'phone', label: 'Celular', width: 390, height: 844},
] as const;

export type PreviewDevice = typeof previewDevices[number]['id'];
export type PreviewGeometry = {ready: boolean; viewportWidth: number; viewportHeight: number; displayWidth: number; displayHeight: number; scale: number};

export function fitPreview(device: PreviewDevice, availableWidth: number, availableHeight: number): PreviewGeometry {
    const width = Number.isFinite(availableWidth) ? Math.max(0, availableWidth) : 0;
    const height = Number.isFinite(availableHeight) ? Math.max(0, availableHeight) : 0;
    const preset = previewDevices.find(item => item.id === device) ?? previewDevices[0];
    const inset = preset.id === 'auto' ? 0 : 16;
    const contentWidth = Math.max(0, width - inset * 2);
    const contentHeight = Math.max(0, height - inset * 2);
    const viewportWidth = preset.width ?? contentWidth;
    const viewportHeight = preset.height ?? contentHeight;
    if (contentWidth === 0 || contentHeight === 0) return {ready: false, viewportWidth, viewportHeight, displayWidth: 0, displayHeight: 0, scale: 0};
    const scale = preset.id === 'auto' ? 1 : Math.min(1, contentWidth / viewportWidth, contentHeight / viewportHeight);
    return {ready: true, viewportWidth, viewportHeight, displayWidth: viewportWidth * scale, displayHeight: viewportHeight * scale, scale};
}

export function canStartPreviewSession(context: {supported: boolean; active: boolean; responsive: boolean; measured: boolean}): boolean {
    return context.supported && context.active && (!context.responsive || context.measured);
}
