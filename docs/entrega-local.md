# Entrega local e pendências de homologação

Instalação de desenvolvimento: http://127.0.0.1:8088. Estado conferido em 29/10/2026. O código está preparado para execução Docker; esta entrega não declara a implantação central concluída.

| Entrega | Implementado e verificado | Etapa restante |
|---|---|---|
| Catálogo por tenant | Oito tenants da conta real; 13 tipos Lumo, dashboards, Minha Mesa, mesas, etiquetas, fontes, agendas, metadados e variáveis sem valores | Homologar módulos sem dados/permissão e conta real com acesso restrito |
| ChatGPT | Login oficial OSS local, plano individual, modelos autorizados, inferência remota, Codex e ferramentas reais | Enquadramento/configuração oficial para hospedagem central; repetir no domínio público |
| Criação completa | Clone, manutenção, consultas, criação nova, prévia PNG, revisão assíncrona, carga e exclusão da cópia de teste | Decisão específica de publicação e verificação do recurso publicado |
| Anexos | Extração limitada e privada de texto/CSV/JSON/YAML/MD/PDF/XLSX/imagens; interpretação real de CSV, PDF, XLSX e imagens; consentimento individual de PNG BI | OCR não implementado; validar amostras de cada cliente no piloto |
| Agendamento | Fontes compartilhadas, frequência preservada, duplicação recusada, agenda ativa e execução nova concluída confirmadas | Validar cenários adicionais de fontes privadas com autorização do titular |
| Operação | HTTPS/subpasta/WebSocket em ensaio isolado, backup criptografado/restauração independente, retenção e saúde; testes de propriedade e concorrência | Domínio/certificado públicos, backup externo agendado, alertas e piloto de capacidade com contas independentes |

## Evidências e uso

138 testes PHP e 453 verificações aprovados; Pint em 96 arquivos; build Vue/TypeScript, smoke do protocolo Codex e regressão Python aprovados. As evidências reais e seus limites estão em [homologação](homologacao.md). Os testes automatizados não usam as contas reais.

As duas conexões foram conferidas no portal. Renovação BI preserva a sessão criptografada. Desconectar cancela revisões pendentes e tarefas pausadas; ações já iniciadas remotamente não são desfeitas. Consultas sem permissão são diferenciadas de listas vazias.

A publicação está preparada para decisão humana. A aplicação criada no teste permanece em rascunho, disponível na página de seu projeto; o relatório privado da instalação registra o recurso/destino exatos. Uma revisão expirada exige revalidação do estado atual antes da execução.

## Disponibilização

Repositório público oficial: [raafaelb/g-analise-ia](https://github.com/raafaelb/g-analise-ia), licença MIT. O workflow de CI valida cada push e pull request. Antes do piloto, confira a execução correspondente ao commit implantado. Ambientes reais, tokens, arquivos do BI, bancos, backups e relatórios privados não integram o código publicado.

Para a OpenAI, apresentar [a solicitação preparada](solicitacao-openai.md). A gratuidade/código aberto não dispensa a confirmação do caso hospedado remotamente. Não há garantia documental de prazo, custo ou aprovação. O formulário não foi enviado automaticamente.

Operação e reprodução dos ensaios: [operação](operacao.md). A configuração do domínio público está preparada para https://suite.inovadora.com.br/ia-ganalise. O modo OSS local permanece limitado à instalação local elegível.
