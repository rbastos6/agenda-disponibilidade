# Agenda do Rafa Bastos — Agregador de Disponibilidade (Free/Busy)

> **Projeto de aula de IA** — desenvolvido com apoio de IA generativa (Claude Code / modelo Opus).
>
> - 🔗 **Repositório GitHub:** _(preencher com a URL após criar o repositório)_
> - 🌐 **Demo ao vivo:** https://rafabastos.com/agenda/

Uma página que **junta dois calendários** (Microsoft Office 365 + Google) e mostra, de forma pública e segura, **apenas os horários livres/ocupados** numa grade semanal — sem revelar nenhum detalhe dos compromissos. Qualquer pessoa com o link vê a disponibilidade e pode **pedir uma reserva pelo WhatsApp** escolhendo um horário.

---

## 1. O mini-projeto escolhido

**Problema:** eu mantenho compromissos em duas agendas diferentes (uma corporativa no Microsoft/Office 365 e uma pessoal no Google). Quando alguém quer marcar um horário comigo, não há uma visão única da minha disponibilidade — e eu não quero expor os títulos/detalhes dos meus compromissos.

**Solução:** uma página web que, **no servidor**, lê os feeds `.ics` das duas agendas, calcula os horários **livres** dentro de uma janela útil (Seg–Sex, 8h–19h) e os apresenta numa **grade semanal**. A página:

- mostra só **livre/ocupado** (os títulos dos eventos nunca aparecem — privacidade);
- deixa a pessoa **clicar num horário livre**, escolher **início (de 15 em 15 min)** e **duração**, e enviar um **pedido de reserva pré-formatado pelo WhatsApp**;
- tem botão **"carregar mais semanas"** (até 12 semanas à frente);
- **não é indexável** por buscadores e protege os dados em cache.

**Stack:** PHP (fetch + parsing dos `.ics` no servidor, sem depender do navegador), HTML/CSS/JavaScript puro (sem frameworks), hospedagem compartilhada (Hostinger). Sem banco de dados — o estado vem direto dos calendários.

---

## 2. Como a IA generativa apoiou o desenvolvimento

O projeto foi construído **de forma conversacional com o Claude Code**, em iterações curtas "pedido → código → deploy → verificação ao vivo". A IA apoiou em praticamente todas as camadas:

- **Levantamento de requisitos:** a IA fez perguntas estruturadas antes de codar (formato da visualização, janela de horário útil, horizonte de semanas, onde hospedar), evitando retrabalho.
- **Parsing de iCalendar (`.ics`):** gerou o leitor de `VEVENT` com os casos difíceis — fusos horários, eventos de dia inteiro, eventos marcados como "livre" (`TRANSP:TRANSPARENT`), cancelados, e **expansão de eventos recorrentes (`RRULE`)** dentro da janela.
- **Cálculo de disponibilidade:** a lógica de mesclar os intervalos ocupados dos dois calendários e derivar os vãos livres dentro do horário útil.
- **Interface:** a grade semanal responsiva, o modal de reserva com os slots de 15 min e o link profundo (`deep link`) do WhatsApp já preenchido.
- **Segurança/privacidade:** sugeriu e implementou as proteções (ver seções 3 e 4).
- **Documentação:** este próprio README e os comentários do código.

Na prática, a IA atuou como par de programação: eu definia o objetivo e as decisões de produto; ela propunha a implementação, que era revisada e testada antes de ir ao ar.

---

## 3. Desafios encontrados (organização, documentação e submissão)

- **CORS / arquitetura:** a primeira ideia era um HTML estático que buscasse os calendários no navegador. Descobrimos que Google e Microsoft **não liberam CORS** nos feeds, então o `fetch` do navegador é bloqueado. Foi preciso mudar a arquitetura para **buscar os feeds no servidor** (PHP) e entregar o HTML pronto.
- **Diferenças entre provedores:** a Microsoft publica os horários com **nomes de fuso do Windows** (ex.: `E. South America Standard Time`) que o PHP não reconhece — foi necessário um **mapa de conversão para IANA** (`America/Sao_Paulo`). O Google exigiu o **endereço secreto em formato iCal** (o feed público estava desabilitado).
- **Recorrências e bordas de semana:** expandir `RRULE` corretamente e **alinhar a grade ao fim da semana** (um bug mostrava "semana" com um único dia quando a janela cortava no meio).
- **Organização para submissão / segredos:** o projeto nasceu sem Git (deploy via FTP). Para publicar no GitHub foi preciso:
  - inicializar o repositório e escrever a documentação;
  - **proteger credenciais**: o `config.php` contém o **endereço secreto do Google** e não pode ir para um repositório público. A solução foi um **`.gitignore`** que exclui o `config.php` + um **`config.sample.php`** como modelo, para qualquer pessoa reproduzir o projeto com as próprias credenciais.
- **Documentar algo construído de forma incremental:** o sistema evoluiu ao longo de vários pedidos; consolidar tudo numa documentação clara exigiu revisão do histórico de decisões.

---

## 4. Cuidados para revisar, testar e validar as sugestões da IA

Nada gerado pela IA foi para produção sem verificação. Os principais cuidados:

- **Teste offline antes do deploy:** o parser de `.ics` foi rodado contra os **feeds reais baixados** e conferido — verificando que **todos os horários livres caíam dentro de 8h–19h** e que o **fuso estava correto** (se estivesse errado, os horários apareceriam deslocados em ~3h entre UTC e São Paulo).
- **Verificação ao vivo a cada mudança:** depois de cada deploy, a página era consultada por `curl` para confirmar o resultado esperado (ex.: as semanas vindo completas após o ajuste de alinhamento).
- **Validação de privacidade (crítico):** confirmado ao vivo que
  - a página devolve `X-Robots-Tag: noindex` + a `<meta robots>` (não indexável);
  - os `.ics` em cache (que contêm **detalhes dos eventos**) retornam **403** por `.htaccess` — senão vazariam por URL direta;
  - o `config.php` (com o segredo) devolve **0 bytes** quando acessado pela web (não vaza).
- **Lint/validação de sintaxe:** `php -l` no PHP e `node --check` no JavaScript a cada alteração.
- **Decisões humanas no circuito ("human-in-the-loop"):** escolhas de produto (formato, janela de horário, horizonte, domínio de publicação, número do WhatsApp) foram **decididas por mim**, não pela IA.
- **Não confiar cegamente:** cada sugestão foi lida e comparada com o comportamento real do site antes de ser considerada pronta — inclusive revertendo/ajustando quando o resultado ao vivo não batia (ex.: o bug da semana de um dia só).

---

## Arquitetura (apêndice)

```
index.php            # busca os .ics no servidor, parseia, calcula livre/ocupado e renderiza a grade
config.sample.php    # modelo de configuração (copiar para config.php e preencher)
config.php           # (NÃO versionado) credenciais reais: URLs .ics, fuso, janela, WhatsApp, etc.
cache/               # .ics baixados (TTL ~15 min); protegido por .htaccess (403)
```

**Fontes de calendário:**
- Microsoft (Office 365): a URL de calendário publicado trocando `calendar.html` por `calendar.ics`.
- Google: *Endereço secreto no formato iCal* do calendário.

## Como rodar

1. `cp config.sample.php config.php`
2. Preencha em `config.php`: as duas URLs `.ics` (`sources`), o fuso (`tz`), a janela (`work_start`/`work_end`/`work_days`), o horizonte (`horizon_days`) e o WhatsApp (`whatsapp`).
3. Publique os arquivos num servidor PHP. A pasta `cache/` precisa ser gravável.
4. (Opcional) Agende uma visita periódica para manter o cache quente.

> ⚠️ O `config.php` contém um **endereço secreto** do Google. Mantenha-o fora de repositórios públicos (já está no `.gitignore`).

---

*Desenvolvido com apoio de IA generativa (Claude Code). Revisão, testes e decisões de produto por Rafael Bastos.*
