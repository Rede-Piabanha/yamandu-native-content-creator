# Browser Notification Triggered Periodic Random Button Clicker

## Visão geral

`Browser Notification Triggered Periodic Random Button Clicker` é uma extensão Chrome em Manifest V3 para preparar rodadas periódicas de seleção de botões em uma página específica do Enjoei, disparadas por alarmes internos e notificações do navegador.

A extensão mantém uma aba dedicada da loja, percorre a página, identifica botões com indícios de ação de megafone, seleciona uma quantidade aleatória de itens por rodada, destaca visualmente os botões encontrados e emite uma notificação para o usuário abrir a aba preparada.

O objetivo do projeto é testar um fluxo de automação controlada por notificação, com seleção randômica de botões, persistência do estado em `chrome.storage.local` e registro técnico da última rodada executada.

## Funcionalidades

- Executa como extensão Chrome Manifest V3.
- Usa `service_worker.js` como processo de fundo.
- Injeta `content.js` em páginas do Enjoei.
- Mantém uma aba dedicada para a loja configurada.
- Agenda rodadas periódicas por `chrome.alarms`.
- Define intervalos randômicos entre rodadas.
- Seleciona uma quantidade randômica de botões por rodada.
- Procura botões com termos relacionados a megafone, promoção ou impulsionamento.
- Ignora botões invisíveis, desabilitados, já selecionados ou indisponíveis.
- Tenta diversificar itens por marca e tipo de produto.
- Destaca visualmente botões e cards selecionados.
- Exibe notificação persistente quando uma rodada está pronta.
- Ao clicar na notificação, abre e foca a aba preparada.
- Armazena dados operacionais em `chrome.storage.local`.
- Registra a última rodada e o último log de revelação.
- Permite ativar e pausar a extensão pelo ícone da barra do navegador.

## Quando usar

Use esta extensão para testar fluxos de automação de interface em páginas do Enjoei quando for necessário selecionar botões em intervalos randômicos, preparar uma aba em segundo plano e acionar o fluxo a partir de uma notificação do navegador.

A ferramenta foi criada para um cenário específico de página, seletor semântico e comportamento de botões do Enjoei. Ela não é uma automação genérica para qualquer site, não possui painel visual próprio e depende da estrutura atual do DOM da página analisada.

## Estrutura do projeto

- `manifest.json`: define a extensão Chrome, permissões, host permitido, service worker, content script, ação da extensão e ícones.
- `service_worker.js`: controla ativação, pausa, agendamento de rodadas, criação de notificações, abertura da aba dedicada, injeção do content script e persistência do estado.
- `content.js`: executa dentro da página do Enjoei, coleta botões candidatos, identifica cards de produto, aplica seleção randômica, destaca elementos e responde às mensagens do service worker.
- `icons/`: diretório com os ícones da extensão em diferentes tamanhos.

## Pré-requisitos

- Google Chrome ou navegador compatível com extensões Manifest V3.
- Acesso a `chrome://extensions`.
- Modo do desenvolvedor ativado no navegador.
- Página do Enjoei acessível pelo navegador.
- Permissão para carregar extensão não empacotada.

## Instalação local

1. Baixe ou clone este repositório.
2. Abra o Chrome.
3. Acesse:

```text
chrome://extensions
```

4. Ative o `Modo do desenvolvedor`.
5. Clique em `Carregar sem compactação`.
6. Selecione a pasta do repositório.
7. Confirme se a extensão aparece na lista de extensões instaladas.

## Configuração principal

A URL da loja é definida diretamente em `service_worker.js`:

```js
const SHOP_URL = 'https://www.enjoei.com.br/@rafaela-e753ff?sid=d442a1b2-fd20-4e08-a3cc-19aa36f4a429-1780377161587';
```

Os parâmetros operacionais também ficam no início de `service_worker.js`:

```js
const MIN_ITEMS = 5;
const MAX_ITEMS = 9;
const MIN_INTERVAL_MINUTES = 11;
const MAX_INTERVAL_MINUTES = 22;
const DEFAULT_MAX_PAGE = 1;
const LOAD_TIMEOUT_MS = 30000;
const RETRY_ATTEMPTS = 4;
```

### Parâmetros

- `SHOP_URL`: página da loja usada como origem das rodadas.
- `MIN_ITEMS`: quantidade mínima de botões selecionados por rodada.
- `MAX_ITEMS`: quantidade máxima de botões selecionados por rodada.
- `MIN_INTERVAL_MINUTES`: intervalo mínimo entre rodadas.
- `MAX_INTERVAL_MINUTES`: intervalo máximo entre rodadas.
- `DEFAULT_MAX_PAGE`: página máxima usada quando a extensão não consegue detectar paginação.
- `LOAD_TIMEOUT_MS`: tempo máximo de espera pelo carregamento da aba.
- `RETRY_ATTEMPTS`: quantidade de tentativas para encontrar botões válidos.

## Como usar

1. Clique no ícone da extensão para ativar.
2. A extensão cria ou reutiliza uma aba dedicada da loja.
3. A primeira rodada é preparada imediatamente.
4. Quando a rodada fica pronta, uma notificação é exibida.
5. Clique na notificação para abrir a aba preparada.
6. A aba será focada e os botões selecionados serão destacados.
7. Clique novamente no ícone da extensão para pausar novas rodadas.

Quando pausada, a extensão limpa o alarme ativo e deixa de preparar novas rodadas até ser reativada.

## Funcionamento técnico

O fluxo principal é dividido em duas partes.

No `service_worker.js`, a extensão:

1. alterna entre estado ativo e pausado;
2. cria ou recupera uma aba dedicada;
3. descobre a quantidade máxima de páginas, quando possível;
4. escolhe uma página aleatória;
5. envia a mensagem `RP_PREPARE` ao content script;
6. salva a rodada em `chrome.storage.local`;
7. cria uma notificação persistente;
8. agenda a próxima rodada com intervalo randômico.

No `content.js`, a extensão:

1. rola a página para carregar itens;
2. coleta elementos clicáveis;
3. filtra botões visíveis e potencialmente megafonáveis;
4. identifica o card de produto associado ao botão;
5. remove candidatos desabilitados ou já selecionados;
6. tenta diversificar a seleção por marca e tipo;
7. aplica destaque visual nos botões e cards;
8. retorna ao service worker os dados da rodada.

## Mensagens internas

O `content.js` responde às seguintes mensagens:

- `RP_PREPARE`: coleta, filtra, seleciona e destaca botões da rodada.
- `RP_DISCOVER`: tenta identificar a maior página disponível na paginação.
- `RP_REVEAL`: revela a seleção existente ao clicar na notificação ou tenta recuperar a seleção salva.

## Dados armazenados

A extensão usa `chrome.storage.local` para armazenar estado operacional.

Entre os dados gravados estão:

- `active`: indica se a extensão está ativa.
- `tabId`: identifica a aba dedicada usada pela extensão.
- `maxPage`: maior página detectada.
- `maxPageTrusted`: indica se a paginação detectada é confiável.
- `nextAt`: horário previsto da próxima rodada.
- `nextDelayMinutes`: intervalo sorteado para a próxima rodada.
- `lastRound`: última rodada preparada.
- `lastStatus`: último status operacional.
- `lastRevealLog`: último log retornado ao clicar na notificação.

## Permissões da extensão

A extensão solicita as seguintes permissões:

- `alarms`: agenda rodadas periódicas.
- `storage`: salva estado local, última rodada e logs.
- `tabs`: cria, atualiza, consulta e foca a aba dedicada.
- `scripting`: injeta o content script quando necessário.
- `notifications`: cria notificações de ativação, pausa, erro e rodada pronta.

A permissão de host é restrita a:

```text
https://www.enjoei.com.br/*
```

## Comportamento atual da sequência final

Na versão atual, a função responsável pela sequência final percorre os botões visíveis e executa `button.click()` com atraso progressivo.

Isso significa que o comportamento atual não é apenas visual ou de log. A sequência pode acionar os botões selecionados na página.

Se a intenção for manter a extensão em modo de auditoria, sem clique real, a função `sequenceHelloWorldLogs()` deve ser ajustada para exibir o texto `hello world` sobre cada botão e registrar a sequência no console ou em `chrome.storage.local`, sem chamar `button.click()`.

## Limites e comportamento esperado

- A extensão depende da estrutura atual do DOM do Enjoei.
- Mudanças em classes, textos, botões ou paginação podem reduzir a precisão da seleção.
- A detecção de botões é heurística e baseada em texto, atributos, classes e contexto do card.
- A seleção randômica pode retornar menos itens quando não houver candidatos suficientes.
- A aba dedicada pode ser perdida se for fechada manualmente.
- O service worker do Manifest V3 pode ser encerrado pelo navegador entre eventos.
- O log salvo em `chrome.storage.local` representa apenas a última revelação recebida.
- A função de overlay visual está parcialmente preparada, mas a sequência atual executa clique real.
- O projeto não possui painel de configuração próprio.
- Os parâmetros precisam ser alterados diretamente no código.

## Observações

- O projeto é específico para testes controlados em página do Enjoei.
- A extensão não deve ser usada em páginas, contas ou fluxos sem autorização.
- Antes de usar em produção, revise cuidadosamente a função de clique sequencial.
- Para operação segura em modo de auditoria, substitua o clique automático por log visual e persistente.
- Após qualquer alteração em `manifest.json`, `service_worker.js` ou `content.js`, recarregue a extensão em `chrome://extensions`.
