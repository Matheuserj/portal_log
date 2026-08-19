<?php
/**
 * Main Web Interface for Docker Log Portal
 */

require_once __DIR__ . '/config.php';

// Configure session options for security
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

$authenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
$user_email = $_SESSION['user_email'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Docker Compose Log Portal</title>
    <!-- Google Fonts: Inter & JetBrains Mono for Console -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
    <!-- FontAwesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom Style -->
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <!-- BG Gradient Blobs -->
    <div class="blob-bg">
        <div class="blob blob-1"></div>
        <div class="blob blob-2"></div>
    </div>

    <div id="app">
        <!-- 1. LOGIN SCREEN -->
        <div id="login-screen" class="screen <?php echo $authenticated ? 'hidden' : 'active'; ?>">
            <div class="login-card glass">
                <div class="login-header">
                    <div class="logo-icon">
                        <i class="fab fa-docker"></i>
                    </div>
                    <h2>Docker Log Portal</h2>
                    <p>Entre com seu e-mail corporativo para acessar os logs.</p>
                </div>

                <!-- Form: Step 1 (Send Email) -->
                <form id="email-form" class="auth-form active">
                    <div class="form-group">
                        <label for="email"><i class="fa-regular fa-envelope"></i> E-mail</label>
                        <input type="text" id="email" placeholder="nome@empresa.com ou admin" required autocomplete="email">
                    </div>
                    <button type="submit" class="btn-primary" id="btn-send-otp">
                        <span>Receber Código de Acesso</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    <div class="form-status" id="email-status"></div>
                </form>

                <!-- Form: Step 2 (Verify OTP) -->
                <form id="otp-form" class="auth-form">
                    <div class="form-group">
                        <label for="otp"><i class="fa-solid fa-key"></i> Código de Acesso (OTP)</label>
                        <input type="text" id="otp" placeholder="Código (OTP) ou Senha" required autocomplete="current-password">
                    </div>
                    <div class="otp-hint-text" id="otp-hint">
                        Digite o código de 6 dígitos ou a senha administrativa para prosseguir.
                    </div>
                    <button type="submit" class="btn-primary" id="btn-verify-otp">
                        <span>Verificar e Entrar</span>
                        <i class="fa-solid fa-check"></i>
                    </button>
                    <button type="button" class="btn-secondary" id="btn-back-to-email">
                        <i class="fa-solid fa-arrow-left"></i> Voltar
                    </button>
                    <div class="form-status" id="otp-status"></div>
                </form>
            </div>
        </div>

        <!-- 2. DASHBOARD SCREEN -->
        <div id="dashboard-screen" class="screen <?php echo $authenticated ? 'active' : 'hidden'; ?>">
            <div class="dashboard-layout">
                
                <!-- Sidebar -->
                <aside class="sidebar glass">
                    <div class="sidebar-header">
                        <div class="brand">
                            <i class="fab fa-docker"></i>
                            <span>Logs Portal</span>
                        </div>
                        <div class="user-profile">
                            <span class="user-email" id="user-email-display"><?php echo htmlspecialchars($user_email); ?></span>
                            <button id="btn-logout" title="Sair do Portal">
                                <i class="fa-solid fa-right-from-bracket"></i>
                            </button>
                        </div>
                    </div>

                    <div class="search-bar">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="app-search" placeholder="Filtrar aplicações...">
                    </div>

                    <!-- Administration Section (Visible to admin only) -->
                    <div class="app-list-container" id="admin-panel-menu" style="display: none; margin-bottom: 20px;">
                        <div class="section-title">Administração</div>
                        <ul class="app-list">
                            <li class="app-item" id="menu-btn-users" style="flex-direction: row; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-users" style="color: var(--color-primary); font-size: 14px; width: 16px; text-align: center;"></i>
                                <span style="font-weight: 500; font-size: 13px;">Gerenciar Acessos</span>
                            </li>
                            <li class="app-item" id="menu-btn-audit" style="flex-direction: row; align-items: center; gap: 8px; margin-top: 4px;">
                                <i class="fa-solid fa-clipboard-list" style="color: var(--color-warning); font-size: 14px; width: 16px; text-align: center;"></i>
                                <span style="font-weight: 500; font-size: 13px;">Logs de Auditoria</span>
                            </li>
                        </ul>
                    </div>

                    <div class="app-list-container">
                        <div class="section-title">Aplicações (/app)</div>
                        <ul id="app-list" class="app-list">
                            <!-- Populated by JS -->
                            <div class="sidebar-loader">
                                <i class="fa-solid fa-circle-notch fa-spin"></i> Carregando apps...
                            </div>
                        </ul>
                    </div>
                </aside>

                <!-- Main Content Log Area -->
                <main class="main-content">
                    
                    <!-- Empty State -->
                    <div id="empty-state" class="empty-state glass">
                        <i class="fa-solid fa-terminal"></i>
                        <h3>Nenhuma aplicação selecionada</h3>
                        <p>Selecione uma aplicação no menu lateral para visualizar os logs dos containers correspondentes em tempo real.</p>
                    </div>

                    <!-- Log Viewer (Initially hidden) -->
                    <div id="log-viewer" class="log-viewer-container glass hidden">
                        <!-- Top Info Banner -->
                        <div class="viewer-header" style="align-items: center;">
                            <div class="app-info">
                                <h2 id="current-app-name">app_teste_uat</h2>
                                <span class="app-path-badge"><i class="fa-regular fa-folder-open"></i> <span id="current-app-path">/app/app_teste_uat</span></span>
                            </div>
                            <div style="display: flex; gap: 8px; margin-left: 20px; background: rgba(0, 0, 0, 0.2); padding: 4px; border-radius: 6px; border: 1px solid rgba(255, 255, 255, 0.05);">
                                <button class="btn-tool active" id="btn-mode-logs" style="border: none; margin: 0; padding: 6px 12px; font-size: 12px; display: flex; align-items: center; gap: 6px; cursor: pointer; border-radius: 4px; font-weight: bold;"><i class="fa-solid fa-terminal"></i> Logs</button>
                                <button class="btn-tool" id="btn-mode-code" style="border: none; margin: 0; padding: 6px 12px; font-size: 12px; display: flex; align-items: center; gap: 6px; cursor: pointer; border-radius: 4px; font-weight: bold;"><i class="fa-solid fa-code"></i> Código Fonte</button>
                            </div>
                            <div class="app-status" style="margin-left: auto;">
                                <span id="app-status-badge" class="status-badge status-running">
                                    <span class="pulse-dot"></span>
                                    <span id="app-status-text">Executando</span>
                                </span>
                            </div>
                        </div>

                        <!-- Logs View Sub-Panel (toggled by Logs mode) -->
                        <div id="logs-view-panel">
                            <!-- Service Selector Tabs (app vs database) -->
                            <div class="tabs-container">
                                <div class="tabs-scroll" id="service-tabs">
                                    <!-- Populated dynamically by JS (e.g. app, db) -->
                                </div>
                            </div>

                            <!-- Terminal Panel -->
                            <div class="terminal-panel">
                                <!-- Terminal Toolbar -->
                                <div class="terminal-toolbar">
                                    <div class="toolbar-left">
                                        <span class="stream-status" id="stream-status">
                                            <i class="fa-solid fa-circle-dot streaming"></i> CONECTADO
                                        </span>
                                        <span class="terminal-stats" id="terminal-stats">200 linhas</span>
                                    </div>
                                    <div class="toolbar-right">
                                        <div class="terminal-search">
                                            <i class="fa-solid fa-filter"></i>
                                            <input type="text" id="log-filter" placeholder="Filtrar conteúdo...">
                                        </div>
                                        <button class="btn-tool" id="btn-toggle-scroll" title="Ativar/Desativar rolagem automática">
                                            <i class="fa-solid fa-angle-double-down"></i> Auto-Scroll
                                        </button>
                                        <button class="btn-tool" id="btn-clear-logs" title="Limpar terminal local">
                                            <i class="fa-solid fa-eraser"></i> Limpar
                                        </button>
                                        <button class="btn-tool btn-highlight" id="btn-download-logs" title="Baixar logs atuais">
                                            <i class="fa-solid fa-download"></i> Baixar
                                        </button>
                                    </div>
                                </div>

                                <!-- Real Log Monospace Console -->
                                <div class="terminal-body" id="terminal-body">
                                    <pre id="log-output" class="log-output"><code>Selecione um serviço acima para ver os logs.</code></pre>
                                </div>
                            </div>
                        </div>

                        <!-- Code Explorer (Initially hidden, toggled by Code mode) -->
                        <div id="code-explorer" class="hidden" style="display: flex; gap: 16px; height: calc(100% - 95px); min-height: 480px; margin-top: 15px; box-sizing: border-box;">
                            <!-- Sidebar File Tree -->
                            <div class="file-tree-container" style="width: 260px; background: rgba(0,0,0,0.25); padding: 12px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); overflow-y: auto; display: flex; flex-direction: column; gap: 8px; box-sizing: border-box; flex-shrink: 0; max-height: 100%;">
                                <div style="font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; display: flex; align-items: center; gap: 6px;"><i class="fa-regular fa-folder-open"></i> <span>Arquivos (src/)</span></div>
                                <div id="file-tree" style="display: flex; flex-direction: column; gap: 4px; font-size: 13px;">
                                    <!-- Populated by JS -->
                                </div>
                            </div>
                            
                            <!-- Code Viewer Panel -->
                            <div class="code-viewer-panel" style="flex: 1; display: flex; flex-direction: column; background: rgba(0,0,0,0.3); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); overflow: hidden; height: 100%; box-sizing: border-box;">
                                <!-- Viewer Header / File Info -->
                                <div style="padding: 10px 16px; background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
                                    <span id="selected-file-path" style="font-size: 13px; font-family: monospace; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 70%;">Selecione um arquivo para visualizar</span>
                                    <a id="btn-download-file" class="btn-tool btn-highlight hidden" href="#" download style="padding: 6px 12px; border-radius: 4px; font-size: 12px; cursor: pointer; border: none; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-weight: bold; width: auto; font-family: inherit;">
                                        <i class="fa-solid fa-download"></i> <span>Baixar Arquivo</span>
                                    </a>
                                </div>
                                
                                <!-- Monospace Code View Area -->
                                <div style="flex: 1; overflow: auto; padding: 16px; font-family: var(--font-mono); font-size: 13px; line-height: 1.5; color: #cbd5e1; white-space: pre; background: rgba(0,0,0,0.15); box-sizing: border-box;">
                                    <pre id="code-output-pre" style="margin: 0; outline: none; white-space: pre; font-family: inherit; color: inherit;">Selecione um arquivo de código da pasta src/ no menu lateral.</pre>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Users Management Panel (Initially hidden) -->
                    <div id="users-panel" class="log-viewer-container glass hidden" style="padding: 24px; display: flex; flex-direction: column; gap: 20px; overflow-y: auto; height: 100%;">
                        <div class="viewer-header" style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 15px;">
                            <div class="app-info">
                                <h2><i class="fa-solid fa-users-gear" style="color: var(--color-primary);"></i> Gerenciar Lista de Acesso</h2>
                                <span class="app-path-badge">E-mails autorizados que podem receber código de login</span>
                            </div>
                        </div>

                        <!-- Invite/Add User Form -->
                        <form id="invite-form" style="display: flex; gap: 12px; background: rgba(255,255,255,0.02); padding: 16px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); align-items: flex-end; width: 100%; box-sizing: border-box;">
                            <div style="flex: 1; display: flex; flex-direction: column; gap: 6px;">
                                <label for="invite-email" style="font-size: 12px; color: var(--text-secondary); display: flex; align-items: center; gap: 6px;"><i class="fa-regular fa-envelope"></i> Convidar Novo E-mail</label>
                                <input type="email" id="invite-email" placeholder="exemplo@empresa.com" required style="width: 100%; padding: 10px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; height: 42px; box-sizing: border-box;">
                            </div>
                            <div style="width: 220px; display: flex; flex-direction: column; gap: 6px;">
                                <label for="invite-role" style="font-size: 12px; color: var(--text-secondary); display: flex; align-items: center; gap: 6px;"><i class="fa-solid fa-user-shield"></i> Permissão (Role)</label>
                                <select id="invite-role" style="width: 100%; padding: 10px; background: rgba(0,0,0,0.2); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; color: #fff; height: 42px; box-sizing: border-box; outline: none; cursor: pointer;">
                                    <option value="log_viewer" style="background: #1e293b;">Visualizador de Logs</option>
                                    <option value="code_viewer" style="background: #1e293b;">Visualizador de Logs + Código</option>
                                    <option value="admin" style="background: #1e293b;">Administrador</option>
                                </select>
                            </div>
                            <button type="submit" class="btn-primary" style="padding: 0 24px; height: 42px; border-radius: 6px; display: flex; align-items: center; gap: 8px; border: none; cursor: pointer; white-space: nowrap; flex-shrink: 0; width: auto;">
                                <i class="fa-solid fa-user-plus"></i> <span>Convidar</span>
                            </button>
                        </form>
                        <div id="invite-status" style="font-size: 13px;"></div>

                        <!-- Whitelist Table -->
                        <div style="background: rgba(255,255,255,0.02); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); overflow: hidden;">
                            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
                                <thead>
                                    <tr style="background: rgba(255,255,255,0.04); border-bottom: 1px solid rgba(255,255,255,0.08);">
                                        <th style="padding: 12px 16px; color: var(--text-secondary);">E-mail Autorizado</th>
                                        <th style="padding: 12px 16px; color: var(--text-secondary); width: 180px;">Permissão</th>
                                        <th style="padding: 12px 16px; color: var(--text-secondary); text-align: right; width: 120px;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody id="users-table-body">
                                    <!-- Populated dynamically by JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Audit Logs Panel (Initially hidden) -->
                    <div id="audit-panel" class="log-viewer-container glass hidden" style="padding: 24px; display: flex; flex-direction: column; gap: 20px; overflow-y: auto; height: 100%;">
                        <div class="viewer-header" style="border-bottom: 1px solid rgba(255,255,255,0.08); padding-bottom: 15px;">
                            <div class="app-info">
                                <h2><i class="fa-solid fa-clipboard-list" style="color: var(--color-warning);"></i> Logs de Auditoria do Portal</h2>
                                <span class="app-path-badge">Histórico recente de atividades e acessos no portal (Últimos 30 dias)</span>
                            </div>
                        </div>

                        <!-- Audit Logs Table -->
                        <div style="background: rgba(255,255,255,0.02); border-radius: 8px; border: 1px solid rgba(255,255,255,0.05); overflow: hidden;">
                            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
                                <thead>
                                    <tr style="background: rgba(255,255,255,0.04); border-bottom: 1px solid rgba(255,255,255,0.08);">
                                        <th style="padding: 12px 16px; color: var(--text-secondary); width: 160px;">Data/Hora</th>
                                        <th style="padding: 12px 16px; color: var(--text-secondary); width: 200px;">Usuário</th>
                                        <th style="padding: 12px 16px; color: var(--text-secondary); width: 130px;">IP</th>
                                        <th style="padding: 12px 16px; color: var(--text-secondary); width: 180px;">Ação</th>
                                        <th style="padding: 12px 16px; color: var(--text-secondary);">Detalhes</th>
                                    </tr>
                                </thead>
                                <tbody id="audit-table-body">
                                    <!-- Populated dynamically by JS -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </main>
            </div>
        </div>
    </div>

    <!-- Frontend Script -->
    <script src="js/app.js?v=<?php echo time(); ?>"></script>
</body>
</html>
