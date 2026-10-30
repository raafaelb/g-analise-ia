# Arquitetura

```mermaid
flowchart LR
 U[Navegador] --> W[Nginx e Laravel/PHP]
 W --> P[(PostgreSQL)]
 W --> R[(Redis)]
 R --> Q[Worker PHP]
 Q --> C[Codex app-server]
 C --> O[OpenAI: inferência remota]
 C --> B[Ferramentas limitadas]
 B --> L[Lumo: sessão do usuário]
 L --> H[G-Analise BI]
 W --> E[Reverb: canal privado]
 E --> U
```

PHP é o backend principal. Node compila Vue/TypeScript e instala o binário oficial do Codex durante o build. O worker PHP fala JSON-RPC por stdio com Codex; o modelo permanece na OpenAI. Não há necessidade de GPU no servidor.

## Fronteiras

- Projetos, arquivos, conversas, canais e revisões pertencem ao usuário do portal. Todas as rotas verificam propriedade; viewer não cria nem executa tarefas.
- A conexão BI individual determina tenants e permissões. Catálogos/cache são separados por conta, sessão e tenant. Reconectar cria uma nova geração de catálogo para não reaproveitar metadados do login anterior.
- Sessão nativa Lumo é temporária por comando, com mutex por conexão. A renovação é gravada criptografada de volta no banco antes de limpar os arquivos temporários.
- Codex recebe somente as ferramentas do broker. Shell, plugins, navegador, goals e multiagente nativos são desabilitados pela configuração. Requisições nativas de aprovação de comandos/escrita são recusadas; escritas autorizadas passam pelo broker. `workspace_file` valida pastas, extensões, tamanho, symlinks e conflitos de conteúdo. Credenciais/estado interno do CLI não são expostos.
- Um mutex por projeto impede duas conversas de alterar seu workspace ao mesmo tempo. A mesma conversa admite uma tarefa ativa. Filas `actions/agents` e `documents` separam alterações/IA da extração de arquivos.
- Tokens não são armazenados em texto aberto no banco nem enviados para o navegador. A APP_KEY integra o backup criptografado. Credenciais de um login OSS local são recusadas em ambiente de produção.

Esse isolamento é aplicado pelo portal e pelo broker. Não equivale a uma VM por cliente. Antes do piloto central, avalie capacidade e endurecimento de processos no host escolhido.

## Ciclo de alteração

1. Escolher tenant e aplicação ou criar um projeto.
2. Inspecionar metadados, dados e instruções; criar/editar somente rascunho.
3. Validar YAML, expressões e consultas; preparar revisão com tenant, recurso, destino e diff.
4. Pausar a tarefa e liberar o worker. A decisão humana expira e fica vinculada ao fingerprint da revisão.
5. Revalidar estado atual, papel e propriedade antes de executar uma ação autorizada. Nunca repetir automaticamente uma ação cujo resultado ficou incerto.
6. Persistir resultado confirmado e retomar IA para validação e prévia.
7. Conferir todas as fontes, agendas e teste concluído antes da publicação de uma aplicação gerenciada.

Atualizações do conjunto de ferramentas iniciam uma nova thread nativa com histórico limitado do portal, preservando a conversa e evitando retomar um schema antigo. Histórico anterior é contexto, nunca autorização de escrita.

## Atualização de dados

`RefreshCoverage` resolve aplicação → tabelas → fluxos, deduplica fontes e confere publicação, frequência, agente/contexto e atividade. Agendas diárias ou mais frequentes existentes são preservadas. Novas cargas preferem madrugada em America/Sao_Paulo.

`run_schedule` registra horário, fontes esperadas e hash da configuração. Quando o CLI não retorna run_id, a conferência aceita apenas uma execução nova correspondente no histórico. Sucesso exige término, timestamps válidos e sucesso de todas as fontes. O comprovante vale para a mesma configuração; uma mudança exige nova conferência.

## Pendências externas

- Enquadramento/configuração oficial OpenAI para hospedagem remota.
- Primeira publicação do código e execução de CI em https://github.com/raafaelb/g-analise-ia.
- Instalação no servidor, certificado público, backup fora do host e piloto com usuários reais.
- Homologação adicional com uma conta BI restrita/2FA e revogação real de acesso, sem alterar permissões de clientes ativos.

## Catálogo e parâmetros

O catálogo cobre os 13 tipos aceitos por `lumo list` na versão fixada. Variáveis usam uma rota própria e expõem apenas nomes/referências; nenhum valor é enviado ao navegador ou à IA. `tenant_variables` oferece o mesmo contrato ao agente. Apresentações, cadastros e investigações usam listagem autorizada e pull do recurso, porque o comando info do Lumo não aceita esses tipos. Sem metadados de autoria, a interface informa que o resumo é parcial.

`ScheduleChanges` confere vínculos novos antes de ativar ou sincronizar uma agenda ativa. Uma fonte já coberta por outra agenda ativa deve reutilizá-la; KeySweep é tratado separadamente da carga. Um mutex por tenant serializa essas alterações entre usuários. A conferência não altera agendas preexistentes.

A preservação das agendas também impede reduzir intervalos, remover horários, desativar a agenda ou retirar fontes já cobertas diariamente. Intervalos equivalentes ou menores são aceitos quando preservam as demais condições e o fuso. Alterações de restrições que não possam ser comprovadas como equivalentes são recusadas.

A geração de login e o estado da conexão são revalidados após cada comando Lumo. Renovações e falhas de uma sessão antiga não sobrescrevem uma reconexão, não restauram credenciais após desconexão e não substituem o catálogo de tenants da conta nova. As gravações usam transação com bloqueio da linha da conexão.
