export function createProjectDrafts() {
    const drafts = new Map<string, string>();
    const key = (project: string | null) => project ?? 'home';

    return {
        switchProject(current: string | null, next: string | null, text: string) {
            drafts.set(key(current), text);
            return drafts.get(key(next)) ?? '';
        },
        seed(project: string, text: string) { drafts.set(key(project), text); },
        clearSent(project: string, text: string) {
            if (drafts.get(key(project)) === text) drafts.delete(key(project));
        },
    };
}
