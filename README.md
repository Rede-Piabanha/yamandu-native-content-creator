# Yamandu Native AI Content Creator

## Visão geral

`Yamandu Native AI Content Creator` é um plugin WordPress para geração de textos, imagens e metadados de mídia com inteligência artificial dentro dos fluxos nativos do painel administrativo.

A ferramenta adiciona recursos de IA ao editor de posts, à Biblioteca de Mídia e às telas de edição de anexos, permitindo gerar textos editoriais por prompt, criar imagens diretamente no WordPress e produzir títulos e textos alternativos para imagens com foco em organização, SEO e acessibilidade.

O objetivo é apoiar equipes editoriais, sites de conteúdo, agências e administradores WordPress que precisam acelerar a produção sem abandonar os campos, telas e controles nativos do WordPress.

## Funcionalidades

- Gera textos para posts a partir de prompts no editor do WordPress.
- Suporta fluxos de uso no Gutenberg e no Classic Editor.
- Insere o texto gerado no editor ou substitui o trecho selecionado.
- Copia o texto gerado para uso manual.
- Cria imagens por prompt e salva o arquivo diretamente na Biblioteca de Mídia.
- Gera título de anexo para imagens.
- Gera texto alternativo para imagens.
- Permite geração individual na tela de edição do anexo.
- Adiciona ações rápidas na listagem da Biblioteca de Mídia.
- Adiciona ações em massa para processar imagens selecionadas.
- Permite controlar quais campos serão gerados: título, texto alternativo ou ambos.
- Permite definir se a geração comum pode sobrescrever campos existentes.
- Mantém a regeneração como ação intencional de substituição.
- Valida a chave de API no painel de configurações.
- Lista modelos Gemini disponíveis conforme a chave configurada.
- Permite escolher modelo de texto e modelo de geração de imagem.
- Mantém requisições externas bloqueadas até o consentimento explícito do administrador.
- Permite preservar ou remover dados do plugin na desinstalação.

## Quando usar

Use este plugin quando precisar incorporar IA generativa ao fluxo editorial de um site WordPress sem criar uma plataforma paralela de produção de conteúdo.

Ele é útil para blogs, portais, sites institucionais, operações de SEO, equipes de marketing e administradores que precisam produzir rascunhos, imagens e metadados de mídia com mais velocidade, mantendo revisão humana e controle sobre o que será publicado.

O Yamandu não substitui planejamento editorial, revisão profissional, auditoria técnica de SEO ou validação humana de acessibilidade. Ele atua como ferramenta operacional para acelerar tarefas repetitivas e apoiar a criação dentro do próprio WordPress.

## Estrutura do projeto

- `yamandu-native-ai-content-creator.php`: arquivo principal do plugin, responsável por carregar constantes, inicialização, integração com Freemius, ativação, desativação e rotina de desinstalação condicionada.
- `includes/class-core.php`: núcleo do plugin, carregamento das classes, opções padrão, campos suportados, recursos disponíveis e conteúdo de privacidade.
- `includes/class-utils.php`: funções utilitárias para sanitização, normalização de texto, validação de anexos, caminhos, URLs e conversões internas.
- `includes/class-api-client.php`: cliente de integração com APIs externas, chamadas ao Gemini, geração de imagem, validação da chave, listagem de modelos e chamadas ao Cloud Vision.
- `includes/class-generator.php`: lógica de geração de metadados, textos e imagens, incluindo preparo de contexto, tratamento de respostas, gravação de anexos e geração de arquivos na Biblioteca de Mídia.
- `includes/class-ajax.php`: endpoints AJAX e ações administrativas para geração, validação de chave, remoção de chave, geração de texto, geração de imagem e processamento individual.
- `admin/class-admin.php`: integração com telas administrativas, menus, notices, assets, metaboxes, ações rápidas, ações em massa e caixas de geração.
- `admin/class-settings.php`: registro, sanitização e renderização da página de configurações.
- `assets/js/admin.js`: comportamento da interface no painel, botões de IA, chamadas AJAX e inserção de resultados.
- `assets/css/admin.css`: estilos administrativos do plugin.
- `languages/yamandu-native-ai-content-creator.pot`: arquivo base de tradução.
- `readme.txt`: readme no formato do repositório oficial de plugins do WordPress.
- `uninstall.php`: tratamento de segurança para desinstalação direta.

## Pré-requisitos

- WordPress 5.8 ou superior.
- PHP 7.4 ou superior.
- Permissão administrativa no WordPress para configurar o plugin.
- Permissão de edição de posts para usar o gerador de texto.
- Permissão de upload e edição de mídia para gerar imagens e metadados.
- Projeto no Google Cloud com APIs necessárias habilitadas.
- Chave de API do Google Cloud com acesso aos serviços usados pelo plugin.

## APIs utilizadas

A versão 1.0.0 utiliza serviços do Google para análise e geração:

- Cloud Vision API, usada na análise de imagens existentes.
- Gemini API / Generative Language API, usada na geração de textos, metadados e imagens.
- Modelos Gemini disponíveis para geração de texto conforme validação da chave.
- Modelos de imagem configuráveis no painel do plugin, conforme opções disponíveis na versão instalada.

O uso das APIs pode consumir cotas ou gerar custos no projeto Google Cloud. Revise faturamento, limites, permissões e restrições de chave antes de usar o plugin em produção ou em processamento em massa.

## Instalação

1. Envie a pasta `yamandu-native-ai-content-creator` para `/wp-content/plugins/`.
2. Também é possível instalar o plugin por ZIP em `Plugins > Adicionar novo > Enviar plugin`.
3. Ative o plugin no painel do WordPress.
4. Acesse `Configurações > Yamandu`.
5. Configure a chave de API.
6. Habilite o consentimento para requisições externas.
7. Valide a chave.
8. Escolha os modelos desejados.
9. Salve as configurações.

## Configuração no Google Cloud

No Google Cloud Console, selecione ou crie um projeto.

Ative o faturamento do projeto, quando necessário, e habilite as APIs usadas pelo plugin:

- Cloud Vision API.
- Generative Language API.

Depois, crie uma chave em `APIs e serviços > Credenciais`.

Quando possível, restrinja a chave apenas às APIs necessárias. Não publique a chave em repositórios, páginas públicas, temas, snippets ou arquivos expostos.

## Configuração do plugin

Acesse:

```text
Configurações > Yamandu
```

Na seção de API, informe a chave do Google Cloud e valide o acesso.

A validação depende do consentimento para requisições externas. Se as requisições de terceiros estiverem desativadas, o plugin mantém a geração bloqueada.

Depois da validação, selecione:

- Modelo Gemini para geração de texto.
- Modelo de geração de imagem.
- Campos de mídia que podem ser gerados.
- Comportamento de sobrescrita.
- Política de remoção de dados na desinstalação.

## Geração de texto em posts

Abra um post no editor do WordPress.

Na caixa `Yamandu Text Generator`, informe o prompt com a orientação do texto desejado e clique em `Generate text with AI`.

O resultado pode ser:

- inserido no editor;
- usado para substituir o trecho selecionado;
- copiado para revisão manual.

O gerador considera o idioma do site, o título do post e, quando houver, o texto selecionado como contexto. O conteúdo gerado deve ser revisado antes da publicação.

## Geração de imagens

Acesse a Biblioteca de Mídia.

Na caixa `Yamandu Image Generator`, descreva a imagem desejada no campo de prompt e gere o arquivo.

Quando a geração é concluída, o plugin salva a imagem como um novo item da Biblioteca de Mídia e disponibiliza o link de edição do anexo.

Também é possível gerar imagem a partir da tela de edição de um anexo, mantendo referência interna ao anexo de origem quando aplicável.

## Geração de metadados de imagem

O plugin trabalha com imagens da Biblioteca de Mídia e pode gerar:

- título do anexo;
- texto alternativo.

Na tela de edição de uma imagem, use os botões de geração ou regeneração de metadados.

Na listagem da Biblioteca de Mídia, use as ações rápidas para processar uma imagem individualmente.

Para processar várias imagens, selecione os anexos desejados e use as ações em massa do Yamandu.

## Geração e regeneração

A geração comum pode respeitar campos já preenchidos, conforme configuração administrativa.

A regeneração é uma ação intencional de substituição e pode sobrescrever campos elegíveis.

Esse comportamento evita substituição acidental de metadados existentes e mantém controle editorial sobre o uso da IA.

## Dados enviados a serviços externos

Dependendo da ação executada, o plugin pode enviar aos serviços configurados:

- conteúdo de imagem selecionada;
- URL ou arquivo de imagem;
- título atual do anexo;
- texto alternativo existente;
- termos derivados do nome do arquivo;
- idioma do site;
- dados de análise de imagem;
- textos detectados na imagem;
- entidades, rótulos e logotipos detectados;
- prompts de geração;
- trecho selecionado no editor de posts;
- configurações de modelo necessárias para a requisição.

As requisições externas só são executadas após o administrador habilitar explicitamente essa permissão nas configurações.

## Dados armazenados no WordPress

O plugin armazena suas configurações na tabela de opções do WordPress.

Entre os dados armazenados estão:

- chave de API configurada;
- hash interno da chave;
- status de validação;
- modelo selecionado;
- modelo de imagem selecionado;
- consentimento para requisições externas;
- campos habilitados para geração;
- comportamento de sobrescrita;
- preferência de remoção de dados na desinstalação.

Também podem ser usados transients para cache de modelos Gemini disponíveis.

Os metadados gerados são gravados nos campos nativos do WordPress, como título do anexo e `_wp_attachment_image_alt`.

## Desinstalação

Por padrão, a desinstalação preserva as configurações do plugin.

Para remover dados do plugin ao desinstalar, habilite a opção de remoção de dados na tela de configurações antes de excluir o plugin.

Quando essa opção está ativa, o plugin remove suas opções e caches internos relacionados.

## Limites e comportamento esperado

- A geração depende da disponibilidade das APIs externas.
- Firewalls, bloqueios de servidor, cotas, faturamento ou restrições de chave podem impedir as requisições.
- Apenas anexos de imagem são processados nos fluxos de metadados.
- O gerador de texto atua em posts editáveis pelo usuário.
- Campos existentes só devem ser substituídos conforme ação e configuração escolhidas.
- Respostas de IA podem exigir revisão, ajuste editorial e validação factual.
- Processamentos em massa devem ser usados com cautela em bibliotecas grandes.
- Imagens e textos enviados a serviços externos podem conter dados pessoais ou sensíveis.

## Observações

- O Yamandu foi desenvolvido para operar dentro de fluxos nativos do WordPress, sem criar uma camada editorial proprietária.
- O plugin não publica conteúdo automaticamente.
- O administrador mantém controle sobre chave de API, modelos, consentimento, campos elegíveis e sobrescrita.
- O resultado deve ser tratado como apoio editorial, não como conteúdo final sem revisão.
- Em ambientes profissionais, revise política de privacidade, base legal, consentimentos e regras internas antes de usar IA com dados de usuários, imagens de pessoas ou informações sensíveis.
