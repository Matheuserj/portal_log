# Docker Log & Source Code Explorer Portal

Um painel de administração seguro, de alta performance e somente leitura, projetado para inspecionar logs de containers em tempo real, navegar por repositórios de código-fonte diretamente a partir de volumes mapeados e gerenciar permissões de acesso ao portal através de um banco de dados MariaDB.

---

## 🚀 Principais Funcionalidades

### 1. Transmissão de Logs em Tempo Real (Streaming)
- Transmite logs de containers instantaneamente usando **Server-Sent Events (SSE)**.
- Interface customizada em estilo console com rolagem automática, opções para limpar a tela e download de histórico de logs em formato texto.
- Filtro de busca de texto em tempo real do lado do cliente.

### 2. Explorador de Código de Alta Performance
- Explorador de árvore de arquivos somente leitura que mapeia a pasta `/app/<nome_do_app>/src`.
- **Lazy Rendering (Carregamento Sob Demanda)**: Só carrega subdiretórios de pastas quando expandidos, permitindo renderização instantânea para projetos massivos (mais de 3.500 arquivos) sem travamentos na interface.
- Ícones de sintaxe automática para arquivos PHP, Node.js, Python e JSON.
- Integração de clique para download dos arquivos de código fonte.

### 3. Controle de Acesso e Segurança
- **Garantia de Acesso Apenas de Leitura (Read-Only)**: Sem exposição de rotas de escrita, upload, alteração ou exclusão nos repositórios.
- **Proteção contra Directory Traversal (LFI)**: Validação rígida de caminhos usando `realpath()` do PHP. Qualquer tentativa de leitura fora da pasta `/src` retorna erro `403 Forbidden`.
- **Controle de Acesso Baseado em Perfis (RBAC)**:
  - **Administrador (`admin`)**: Acesso total (logs, explorador de código, gerenciamento de permissões de whitelist e logs de auditoria).
  - **Visualizador de Código (`code_viewer`)**: Acesso aos logs e ao explorador de código fonte.
  - **Visualizador de Logs (`log_viewer`)**: Acesso apenas aos logs de containers (as abas de código e administração ficam totalmente ocultas).

### 4. Autenticação e Envio de E-mails (SMTP)
- **Login sem Senha (Passwordless)**: Verificação de e-mails através de token de uso único de 6 dígitos (OTP).
- **Cliente Socket SMTP**: Mailer nativo construído puramente em sockets PHP que suporta STARTTLS e AUTH LOGIN (otimizado para SMTP do Gmail).
- **Bypass de Senha Administrativa**: Login administrador master configurável usando o usuário `admin` e senha estática.

### 5. Banco de Dados MariaDB Persistente
- Armazenamento da lista de e-mails autorizados em banco relacional estável MariaDB.
- **Migração Automática**: No primeiro boot do container, o portal detecta o arquivo legado `users.json`, migra todas as permissões para o MariaDB e remove o arquivo JSON para manter a organização.

### 6. Logs de Auditoria e Retenção
- Registra todas as atividades importantes de usuários (logins, requisições de OTP, visualizações e downloads de código, alterações de permissão).
- **Auto-Retenção de 30 Dias**: Um script de limpeza em segundo plano varre o arquivo `audit.log` a cada 20 operações e deleta de forma permanente entradas com mais de 30 dias de idade.
- **Linha do Tempo para Administradores**: Histórico visual dos logs de auditoria disponível na interface administrativa.

---

## 📁 Estrutura de Diretórios

```
├── Dockerfile          # Instala o PHP, Apache, pdo_mysql e binários do Docker
├── docker-compose.yml   # Mapeia volumes (apps do host, socket do docker, logs) e inicia o MariaDB
├── config.php          # Configurações de SMTP, whitelist e credenciais do banco
├── api.php             # Backend PHP (SSE streams, comandos PDO SQL e listagem de código)
├── index.php           # Interface visual do painel, abas de navegação e modais
├── mailer.php          # Cliente leve de sockets SMTP em PHP
├── entrypoint.sh       # Script de inicialização e configurações internas do container
├── check_db.php        # Script utilitário de diagnóstico da conexão com o banco
└── js/
    └── app.js          # Controla SSE log streams, lazy tree rendering e ações de interface
```

---

## 🛠️ Instalação & Configuração

### Pré-requisitos
- Docker e Docker Compose instalados no servidor.
- Diretório `/app` contendo os subdiretórios dos seus projetos com `docker-compose.yml`.

### Configuração
Ajuste os parâmetros em **`config.php`** com suas credenciais de e-mail e dados do administrador:
```php
// config.php
define('ADMIN_USER', 'admin');
define('ADMIN_PASSWORD', 'sua_senha_mestra_aqui'); // Senha mestra do bypass administrador

define('SMTP_ENABLED', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'seu-email@empresa.com');
define('SMTP_PASS', 'sua-senha-de-aplicativo-do-gmail');
```

Configure as senhas do banco de dados no **`docker-compose.yml`** e sincronize no **`config.php`**:
```yaml
# docker-compose.yml
MYSQL_DATABASE: portal_log
MYSQL_USER: portal_log
MYSQL_PASSWORD: sua_senha_segura_do_banco
MYSQL_ROOT_PASSWORD: sua_senha_root_do_banco
```

### Inicializando o Portal
1. Clone os arquivos na pasta `/app/portal_log_uat/` no seu servidor.
2. Crie e aplique permissão de escrita para a pasta de dados do banco de dados:
   ```bash
   mkdir -p /app/portal_log_uat/mariadb_data
   chmod 777 -R /app/portal_log_uat/mariadb_data
   ```
3. Construa e suba a stack multi-container:
   ```bash
   docker compose up --build -d
   ```
4. Acesse o portal em seu navegador na porta configurada: `http://<IP_DO_SERVIDOR>:7005/`.

---

## 🔍 Diagnóstico da Conexão com o Banco
Se encontrar qualquer problema de comunicação com o MariaDB, acesse:
`http://<IP_DO_SERVIDOR>:7005/check_db.php`

O utilitário executará um teste completo de conexão PDO e listagem de tabelas, imprimindo o relatório e erros específicos (como falha de privilégios ou conexão recusada) na tela para facilitar o debug.
