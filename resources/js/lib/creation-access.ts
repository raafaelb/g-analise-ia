export type CreationAccessTarget = 'connections' | 'catalog' | 'configure';
export interface CreationAccess {
    canSend: boolean;
    state: 'ready' | 'read_only' | 'configuration' | 'chatgpt' | 'plan' | 'bi' | 'tenants' | 'tenant' | 'tenant_access';
    title: string;
    description: string;
    actions: { label: string; target: CreationAccessTarget }[];
}
export interface CreationAccessInput {
    role: string;
    openaiConfigured: boolean;
    openai?: { status?: string; requires_reauthorization?: boolean; metadata?: { plan_usage?: unknown } };
    biConnected: boolean;
    hasTenants: boolean;
    projectSelected: boolean;
    tenantSelected: boolean;
    tenantAuthorized: boolean;
}

export function creationAccess(input: CreationAccessInput): CreationAccess {
    const blocked = (state: CreationAccess['state'], title: string, description: string, target: CreationAccessTarget, label: string): CreationAccess => ({ canSend: false, state, title, description, actions: [{ label, target }] });
    if (input.role === 'viewer') {
        return blocked('read_only', 'Seu acesso é de consulta', 'Explore os dashboards e projetos disponíveis para sua conta. Para criar ou alterar recursos, peça um perfil de autoria ao responsável.', 'catalog', 'Explorar catálogo');
    }
    const connected = input.openai?.status === 'connected' && input.openai.requires_reauthorization !== true;
    if (!connected) {
        if (!input.openaiConfigured) {
            const result = blocked('configuration', 'A IA ainda não foi habilitada nesta instalação', 'Seus projetos e as ações disponíveis no Catálogo BI continuam acessíveis. Confira a situação da integração em Conexões.', 'catalog', 'Explorar catálogo');
            result.actions.push({ label: 'Ver conexões', target: 'connections' });
            return result;
        }
        return blocked('chatgpt', input.openai ? 'Reconecte sua conta ChatGPT' : 'Conecte sua conta ChatGPT', 'Autorize sua própria conta em Conexões para enviar pedidos de criação e manutenção. Escolher um projeto não exige refazer o login do BI.', 'connections', 'Abrir conexões');
    }
    if (input.openai?.metadata?.plan_usage !== true) {
        return blocked('plan', 'O uso do plano ainda precisa de autorização', 'A conta foi reconhecida, mas a autorização atual não habilita tarefas de IA. Confira Conexões e a configuração desta instalação.', 'connections', 'Conferir autorização');
    }
    if (!input.biConnected) {
        return blocked('bi', 'Conecte sua conta G-Analise BI', 'A criação usa os ambientes e as permissões da sua conta. Você pode abrir seus projetos enquanto prepara essa conexão.', 'connections', 'Conectar G-Analise BI');
    }
    if (!input.hasTenants) {
        return blocked('tenants', 'Nenhum ambiente está disponível para esta conta', 'Atualize os acessos em Conexões. Se a lista continuar vazia, peça ao responsável pelo BI para conferir suas permissões.', 'connections', 'Conferir acessos');
    }
    if (input.projectSelected && !input.tenantSelected) {
        return blocked('tenant', 'Escolha o ambiente deste projeto', 'Em Configurar, selecione o tenant onde a IA deverá trabalhar. Isso mantém os dados e as alterações no lugar correto.', 'configure', 'Configurar projeto');
    }
    if (input.projectSelected && !input.tenantAuthorized) {
        return blocked('tenant_access', 'Confira o acesso ao ambiente do projeto', 'Este tenant não está na lista atual da sua conexão. Atualize os acessos ou escolha outro projeto antes de enviar um pedido.', 'connections', 'Conferir acessos');
    }
    return { canSend: true, state: 'ready', title: '', description: '', actions: [] };
}
