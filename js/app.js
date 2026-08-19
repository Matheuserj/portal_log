/**
 * Frontend logic for Docker Log Portal
 * Handles authentication, UI navigation, and SSE log streaming
 */

document.addEventListener('DOMContentLoaded', () => {
    // State Variables
    let currentUserEmail = '';
    let currentUserRole = '';
    let appsList = [];
    let selectedApp = null;
    let selectedService = null;
    let sseConnection = null;
    let autoScroll = true;
    let logHistory = [];
    let logFilterText = '';

    // DOM Elements - Screens
    const loginScreen = document.getElementById('login-screen');
    const dashboardScreen = document.getElementById('dashboard-screen');

    // DOM Elements - Auth
    const emailForm = document.getElementById('email-form');
    const otpForm = document.getElementById('otp-form');
    const emailInput = document.getElementById('email');
    const otpInput = document.getElementById('otp');
    const emailStatus = document.getElementById('email-status');
    const otpStatus = document.getElementById('otp-status');
    const otpHint = document.getElementById('otp-hint');
    const btnBackToEmail = document.getElementById('btn-back-to-email');
    const userEmailDisplay = document.getElementById('user-email-display');
    const btnLogout = document.getElementById('btn-logout');

    // DOM Elements - Dashboard Sidebar
    const appSearch = document.getElementById('app-search');
    const appList = document.getElementById('app-list');

    // DOM Elements - Viewer
    const emptyState = document.getElementById('empty-state');
    const logViewer = document.getElementById('log-viewer');
    const currentAppName = document.getElementById('current-app-name');
    const currentAppPath = document.getElementById('current-app-path');
    const appStatusBadge = document.getElementById('app-status-badge');
    const appStatusText = document.getElementById('app-status-text');
    const serviceTabs = document.getElementById('service-tabs');

    // DOM Elements - Terminal
    const terminalBody = document.getElementById('terminal-body');
    const logOutput = document.getElementById('log-output').querySelector('code');
    const streamStatus = document.getElementById('stream-status');
    const terminalStats = document.getElementById('terminal-stats');
    const logFilterInput = document.getElementById('log-filter');
    const btnToggleScroll = document.getElementById('btn-toggle-scroll');
    const btnClearLogs = document.getElementById('btn-clear-logs');
    const btnDownloadLogs = document.getElementById('btn-download-logs');

    // DOM Elements - View Mode & Code Explorer
    const btnModeLogs = document.getElementById('btn-mode-logs');
    const btnModeCode = document.getElementById('btn-mode-code');
    const logsViewPanel = document.getElementById('logs-view-panel');
    const codeExplorer = document.getElementById('code-explorer');
    const fileTree = document.getElementById('file-tree');
    const selectedFilePath = document.getElementById('selected-file-path');
    const btnDownloadFile = document.getElementById('btn-download-file');
    const codeOutputPre = document.getElementById('code-output-pre');

    // ----------------------------------------------------
    // 1. Initialization & Auth Status Check
    // ----------------------------------------------------
    checkAuthStatus();

    async function checkAuthStatus() {
        try {
            const res = await fetch('api.php?action=status');
            const data = await res.json();
            
            if (data.authenticated) {
                currentUserEmail = data.email;
                currentUserRole = data.role;
                showDashboard();
            } else {
                showLogin();
            }
        } catch (err) {
            console.error('Erro ao verificar status de autenticação:', err);
            showLogin();
        }
    }

    function showLogin() {
        loginScreen.classList.remove('hidden');
        loginScreen.classList.add('active');
        dashboardScreen.classList.remove('active');
        dashboardScreen.classList.add('hidden');
        resetAuthForms();
    }

    function showDashboard() {
        loginScreen.classList.remove('active');
        loginScreen.classList.add('hidden');
        dashboardScreen.classList.remove('hidden');
        dashboardScreen.classList.add('active');
        userEmailDisplay.textContent = currentUserEmail;
        
        // Show/hide Administration menu based on user role
        const adminMenu = document.getElementById('admin-panel-menu');
        if (adminMenu) {
            if (currentUserRole === 'admin') {
                adminMenu.style.display = 'block';
            } else {
                adminMenu.style.display = 'none';
            }
        }

        // Hide Code Source explorer mode if the user is only a log viewer
        if (btnModeCode) {
            if (currentUserRole === 'log_viewer') {
                btnModeCode.style.display = 'none';
            } else {
                btnModeCode.style.display = 'flex';
            }
        }
        
        // Load data
        loadApplications();
    }

    function resetAuthForms() {
        emailForm.classList.add('active');
        otpForm.classList.remove('active');
        emailInput.value = '';
        otpInput.value = '';
        emailStatus.className = 'form-status';
        emailStatus.textContent = '';
        otpStatus.className = 'form-status';
        otpStatus.textContent = '';
    }

    // ----------------------------------------------------
    // 2. Auth Event Handlers
    // ----------------------------------------------------

    // Form 1: Send OTP
    emailForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = emailInput.value.trim();
        if (!email) return;

        emailStatus.className = 'form-status';
        emailStatus.textContent = 'Enviando código...';
        
        try {
            const res = await fetch('api.php?action=send_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            });
            const data = await res.json();

            if (data.success) {
                emailStatus.className = 'form-status success';
                emailStatus.textContent = data.message;
                otpHint.textContent = data.message;
                
                // Show hint if in development mode (no mail config)
                if (data.dev_mode) {
                    console.log('%c[Docker Log Portal] Modo Dev: OTP gravado no arquivo "otp.log" no servidor.', 'color: #00f2fe; font-weight: bold;');
                }

                // Transition form
                setTimeout(() => {
                    emailForm.classList.remove('active');
                    otpForm.classList.add('active');
                    otpInput.value = '';
                    otpInput.focus();
                }, 1000);
            } else {
                emailStatus.className = 'form-status error';
                emailStatus.textContent = data.error || 'Erro ao enviar código de acesso.';
            }
        } catch (err) {
            emailStatus.className = 'form-status error';
            emailStatus.textContent = 'Erro de rede. Tente novamente mais tarde.';
        }
    });

    // Form 2: Verify OTP
    otpForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const email = emailInput.value.trim();
        const otp = otpInput.value.trim();
        if (!email || !otp) return;

        otpStatus.className = 'form-status';
        otpStatus.textContent = 'Verificando...';

        try {
            const res = await fetch('api.php?action=verify_otp', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email, otp })
            });
            const data = await res.json();

            if (data.success) {
                otpStatus.className = 'form-status success';
                otpStatus.textContent = 'Autenticado! Carregando dashboard...';
                currentUserEmail = email;
                
                setTimeout(() => {
                    showDashboard();
                }, 1000);
            } else {
                otpStatus.className = 'form-status error';
                otpStatus.textContent = data.error || 'Código incorreto ou expirado.';
            }
        } catch (err) {
            otpStatus.className = 'form-status error';
            otpStatus.textContent = 'Erro de rede. Tente novamente.';
        }
    });

    btnBackToEmail.addEventListener('click', () => {
        otpForm.classList.remove('active');
        emailForm.classList.add('active');
        emailStatus.className = 'form-status';
        emailStatus.textContent = '';
    });

    btnLogout.addEventListener('click', async () => {
        if (confirm('Deseja realmente sair do portal?')) {
            disconnectActiveStream();
            try {
                await fetch('api.php?action=logout');
            } catch (err) {
                console.error('Logout error:', err);
            }
            showLogin();
        }
    });

    // ----------------------------------------------------
    // 3. Application List & Search
    // ----------------------------------------------------
    async function loadApplications() {
        appList.innerHTML = `
            <div class="sidebar-loader">
                <i class="fa-solid fa-circle-notch fa-spin"></i> Buscando diretórios...
            </div>
        `;

        try {
            const res = await fetch('api.php?action=list_apps');
            const data = await res.json();

            if (data.success) {
                appsList = data.apps;
                renderAppList();
            } else {
                appList.innerHTML = `
                    <div class="sidebar-loader" style="color: var(--color-danger)">
                        <i class="fa-solid fa-triangle-exclamation"></i> ${data.error}
                    </div>
                `;
            }
        } catch (err) {
            appList.innerHTML = `
                <div class="sidebar-loader" style="color: var(--color-danger)">
                    <i class="fa-solid fa-triangle-exclamation"></i> Falha na conexão com o servidor.
                </div>
            `;
        }
    }

    function renderAppList() {
        const query = appSearch.value.trim().toLowerCase();
        const filteredApps = appsList.filter(app => app.name.toLowerCase().includes(query));

        if (filteredApps.length === 0) {
            appList.innerHTML = `
                <div class="sidebar-loader" style="color: var(--text-muted)">
                    Nenhum container encontrado.
                </div>
            `;
            return;
        }

        appList.innerHTML = '';
        filteredApps.forEach(app => {
            const li = document.createElement('li');
            li.className = `app-item ${selectedApp && selectedApp.name === app.name ? 'active' : ''}`;
            
            let statusClass = 'status-unknown';
            let statusText = 'Desconhecido';
            if (app.status === 'running') {
                statusClass = 'status-running';
                statusText = 'Executando';
            } else if (app.status === 'partial') {
                statusClass = 'status-partial';
                statusText = 'Parcial';
            } else if (app.status === 'stopped') {
                statusClass = 'status-stopped';
                statusText = 'Parado';
            }

            li.innerHTML = `
                <div class="app-item-main">
                    <span class="app-name" title="${app.name}">${app.name}</span>
                    <span class="status-indicator ${statusClass}" title="${statusText}"></span>
                </div>
                <div class="app-details">
                    <span class="service-count"><i class="fa-solid fa-cube"></i> ${app.running_services}/${app.total_services} containers</span>
                    <span>/app/${app.name}</span>
                </div>
            `;

            li.addEventListener('click', () => selectApp(app));
            appList.appendChild(li);
        });
    }

    appSearch.addEventListener('input', renderAppList);

    // ----------------------------------------------------
    // 4. App & Service Selection
    // ----------------------------------------------------
    function selectApp(app) {
        selectedApp = app;
        
        // Update active class in sidebar
        const items = appList.querySelectorAll('.app-item');
        items.forEach(item => {
            const nameEl = item.querySelector('.app-name');
            if (nameEl && nameEl.textContent === app.name) {
                item.classList.add('active');
            } else {
                item.classList.remove('active');
            }
        });

        // Deselect Admin menus if they were selected
        const menuBtnUsers = document.getElementById('menu-btn-users');
        if (menuBtnUsers) {
            menuBtnUsers.classList.remove('active');
        }
        const menuBtnAudit = document.getElementById('menu-btn-audit');
        if (menuBtnAudit) {
            menuBtnAudit.classList.remove('active');
        }
        
        // Hide Empty State, Users Panel and Audit Panel, show log viewer
        const usersPanel = document.getElementById('users-panel');
        if (usersPanel) {
            usersPanel.classList.add('hidden');
        }
        const auditPanel = document.getElementById('audit-panel');
        if (auditPanel) {
            auditPanel.classList.add('hidden');
        }
        emptyState.classList.add('hidden');
        logViewer.classList.remove('hidden');

        // Reset view mode tab back to Logs (default)
        if (btnModeLogs) btnModeLogs.classList.add('active');
        if (btnModeCode) btnModeCode.classList.remove('active');
        if (logsViewPanel) logsViewPanel.classList.remove('hidden');
        if (codeExplorer) codeExplorer.classList.add('hidden');

        // Clear code explorer output values
        if (selectedFilePath) selectedFilePath.textContent = 'Selecione um arquivo para visualizar';
        if (codeOutputPre) codeOutputPre.textContent = 'Selecione um arquivo de código da pasta src/ no menu lateral.';
        if (btnDownloadFile) btnDownloadFile.classList.add('hidden');

        // Update header details
        currentAppName.textContent = app.name;
        currentAppPath.textContent = `/app/${app.name}`;
        
        // Update App Status Badge
        appStatusBadge.className = 'status-badge';
        if (app.status === 'running') {
            appStatusBadge.classList.add('status-running');
            appStatusText.textContent = 'Executando';
        } else if (app.status === 'partial') {
            appStatusBadge.classList.add('status-partial');
            appStatusText.textContent = 'Parcial';
        } else {
            appStatusBadge.classList.add('status-stopped');
            appStatusText.textContent = 'Parado';
        }

        // Render Service tabs
        renderServiceTabs(app);
    }

    function renderServiceTabs(app) {
        serviceTabs.innerHTML = '';

        if (!app.services || app.services.length === 0) {
            serviceTabs.innerHTML = `
                <div style="font-size: 13px; color: var(--text-secondary); padding: 8px 0;">
                    Nenhum serviço mapeado no docker-compose.yml
                </div>
            `;
            return;
        }

        app.services.forEach((serviceObj, index) => {
            const serviceName = typeof serviceObj === 'string' ? serviceObj : serviceObj.name;
            const tech = typeof serviceObj === 'string' ? 'unknown' : serviceObj.tech;

            const button = document.createElement('button');
            button.className = 'tab';
            
            // Icon mapping helper with direct brand/tech icons
            let iconClass = 'fa-server';
            let iconFamily = 'fa-solid';
            let labelSuffix = '';

            const nameLower = serviceName.toLowerCase();
            const techLower = tech.toLowerCase();

            if (techLower === 'php') {
                iconFamily = 'fa-brands';
                iconClass = 'fa-php';
                labelSuffix = ' (PHP)';
            } else if (techLower === 'node') {
                iconFamily = 'fa-brands';
                iconClass = 'fa-node-js';
                labelSuffix = ' (Node)';
            } else if (techLower === 'python') {
                iconFamily = 'fa-brands';
                iconClass = 'fa-python';
                labelSuffix = ' (Python)';
            } else if (techLower === 'mysql' || techLower === 'mariadb' || techLower === 'postgres' || techLower === 'db') {
                iconFamily = 'fa-solid';
                iconClass = 'fa-database';
                if (techLower === 'mysql') labelSuffix = ' (MySQL)';
                else if (techLower === 'postgres') labelSuffix = ' (Postgres)';
                else labelSuffix = ' (Banco)';
            } else if (techLower === 'redis') {
                iconFamily = 'fa-solid';
                iconClass = 'fa-database';
                labelSuffix = ' (Redis)';
            } else if (techLower === 'nginx') {
                iconFamily = 'fa-solid';
                iconClass = 'fa-network-wired';
                labelSuffix = ' (Nginx)';
            } else if (techLower === 'apache') {
                iconFamily = 'fa-solid';
                iconClass = 'fa-network-wired';
                labelSuffix = ' (Apache)';
            } else {
                // Fallback to name match if tech is unknown
                if (nameLower.includes('db') || nameLower.includes('mysql') || nameLower.includes('postgres') || nameLower.includes('redis') || nameLower.includes('mongo') || nameLower.includes('sql')) {
                    iconFamily = 'fa-solid';
                    iconClass = 'fa-database';
                }
            }

            button.innerHTML = `<i class="${iconFamily} ${iconClass}"></i> <span>${serviceName}${labelSuffix}</span>`;
            
            button.addEventListener('click', () => {
                // Remove active classes
                serviceTabs.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                button.classList.add('active');
                
                selectedService = serviceName;
                startLogStreaming(app.name, serviceName);
            });

            serviceTabs.appendChild(button);

            // Automatically select first tab initially
            if (index === 0) {
                button.classList.add('active');
                selectedService = serviceName;
                startLogStreaming(app.name, serviceName);
            }
        });
    }

    // ----------------------------------------------------
    // 5. Server-Sent Events Log Streaming
    // ----------------------------------------------------
    function disconnectActiveStream() {
        if (sseConnection) {
            sseConnection.close();
            sseConnection = null;
            console.log('Stream de logs desconectado.');
        }
    }

    function startLogStreaming(appName, serviceName) {
        disconnectActiveStream();
        
        // Clear viewport
        logHistory = [];
        logOutput.innerHTML = '';
        updateStats();

        // Update UI Toolbar to Connecting
        streamStatus.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> CONECTANDO';
        streamStatus.style.color = 'var(--text-secondary)';

        const escApp = encodeURIComponent(appName);
        const escSrv = encodeURIComponent(serviceName);
        const url = `api.php?action=stream_logs&app=${escApp}&service=${escSrv}`;

        // Initialize EventSource
        sseConnection = new EventSource(url);

        sseConnection.onopen = () => {
            streamStatus.innerHTML = '<i class="fa-solid fa-circle-dot streaming"></i> TRANSMITINDO';
            streamStatus.style.color = 'var(--color-success)';
            appendSystemLog(`[SISTEMA] Conexão iniciada com sucesso. Transmitindo logs em tempo real para o container "${serviceName}"...`);
        };

        sseConnection.onmessage = (event) => {
            let logLine = '';
            try {
                logLine = JSON.parse(event.data);
            } catch (err) {
                logLine = event.data;
            }

            // Append to memory log cache (limit buffer to 200 lines)
            logHistory.push(logLine);
            if (logHistory.length > 200) {
                logHistory.shift();
            }

            updateStats();

            // Render line if it passes the text filter
            if (passesFilter(logLine)) {
                appendLogLine(logLine);
            }
        };

        sseConnection.onerror = (err) => {
            console.error('SSE Error:', err);
            streamStatus.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ERRO DE CONEXÃO';
            streamStatus.style.color = 'var(--color-danger)';
            appendSystemLog('[SISTEMA - ERRO] Falha no fluxo de logs. Tentando reconectar automaticamente...');
        };
    }

    function passesFilter(line) {
        if (!logFilterText) return true;
        return line.toLowerCase().includes(logFilterText.toLowerCase());
    }

    // Process and color log lines
    function formatLogLine(line) {
        // Escape HTML tags to prevent XSS
        let escaped = line
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");

        let className = 'log-line';
        const lowerLine = escaped.toLowerCase();

        // Color coding with expanded keywords for PHP, Node/JS, SQL and general errors
        if (
            lowerLine.includes('error') || 
            lowerLine.includes('fail') || 
            lowerLine.includes('fatal') || 
            lowerLine.includes('exception') || 
            lowerLine.includes('crit') ||
            lowerLine.includes('typeerror') ||
            lowerLine.includes('referenceerror') ||
            lowerLine.includes('syntaxerror') ||
            lowerLine.includes('rangeerror') ||
            lowerLine.includes('unhandledrejection') ||
            lowerLine.includes('sqlstate') ||
            lowerLine.includes('mysql error') ||
            lowerLine.includes('table \'') && lowerLine.includes('doesn\'t exist')
        ) {
            className += ' log-err';
        } else if (
            lowerLine.includes('warn') || 
            lowerLine.includes('warning') || 
            lowerLine.includes('attention') || 
            lowerLine.includes('notice') || 
            lowerLine.includes('deprecated')
        ) {
            className += ' log-warn';
        } else if (lowerLine.includes('info') || lowerLine.includes('success')) {
            className += ' log-info';
        }

        // Detect stack trace lines (Node.js, PHP, Python) to style them dynamically
        if (
            /^\s*at\s+[\w\.\/<>\$]+/i.test(escaped) ||           // Node/JS trace line: "   at Module..."
            /^\s*#\d+\s+/i.test(escaped) ||                      // PHP trace line: "   #0 /app/..."
            /^\s*stack\s+trace/i.test(escaped) ||                // Stack Trace header
            /^\s*File\s+["'].*?["'],\s+line\s+\d+/i.test(escaped) // Python trace line: "   File ..., line ..."
        ) {
            className += ' log-trace';
        }

        // Highlight search keyword if filtering is active
        if (logFilterText) {
            const regex = new RegExp(`(${escapeRegExp(logFilterText)})`, 'gi');
            escaped = escaped.replace(regex, '<span class="log-highlight">$1</span>');
        }

        return `<span class="${className}">${escaped}</span>`;
    }

    function appendLogLine(line) {
        const isAtBottom = isTerminalAtBottom();
        
        const formatted = formatLogLine(line);
        logOutput.insertAdjacentHTML('beforeend', formatted + '\n');

        // Keep DOM limited to 200 elements to match logHistory buffer
        while (logOutput.children.length > 200) {
            logOutput.removeChild(logOutput.firstChild);
        }

        // Scroll management
        if (autoScroll && isAtBottom) {
            scrollToBottom();
        }
    }

    function appendSystemLog(message) {
        logOutput.insertAdjacentHTML('beforeend', `<span class="log-line log-system">${message}</span>\n`);
        if (autoScroll) {
            scrollToBottom();
        }
    }

    function reRenderLogs() {
        logOutput.innerHTML = '';
        
        // Filter history
        const filtered = logHistory.filter(line => passesFilter(line));
        
        if (filtered.length === 0) {
            logOutput.innerHTML = '<span class="log-line log-system">[Filtro ativo: Nenhum log correspondente encontrado]</span>\n';
            return;
        }

        // Output lines batch-wise
        const html = filtered.map(line => formatLogLine(line)).join('\n');
        logOutput.innerHTML = html + '\n';
        
        if (autoScroll) {
            scrollToBottom();
        }
    }

    // Scroll helper utilities
    function isTerminalAtBottom() {
        const threshold = 50; // pixels
        return (terminalBody.scrollHeight - terminalBody.scrollTop - terminalBody.clientHeight) < threshold;
    }

    function scrollToBottom() {
        terminalBody.scrollTop = terminalBody.scrollHeight;
    }

    function updateStats() {
        terminalStats.textContent = `${logHistory.length} linhas em cache`;
    }

    function escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // ----------------------------------------------------
    // 6. Toolbar & Control Event Listeners
    // ----------------------------------------------------

    // Filter logs input
    logFilterInput.addEventListener('input', () => {
        logFilterText = logFilterInput.value.trim();
        reRenderLogs();
    });

    // Auto Scroll Lock toggle
    btnToggleScroll.addEventListener('click', () => {
        autoScroll = !autoScroll;
        
        if (autoScroll) {
            btnToggleScroll.classList.add('active');
            scrollToBottom();
        } else {
            btnToggleScroll.classList.remove('active');
        }
    });
    // Set auto-scroll initially active
    btnToggleScroll.classList.add('active');

    // Clear local terminal output
    btnClearLogs.addEventListener('click', () => {
        logOutput.innerHTML = '';
        logHistory = [];
        updateStats();
        appendSystemLog('[SISTEMA] Visualização limpa pelo usuário.');
    });

    // Download current logs to TXT
    btnDownloadLogs.addEventListener('click', () => {
        if (logHistory.length === 0) {
            alert('Não há logs carregados para download.');
            return;
        }

        const textContent = logHistory.join('\n');
        const blob = new Blob([textContent], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        
        const link = document.createElement('a');
        link.href = url;
        link.download = `${selectedApp.name}_${selectedService}_logs_${new Date().toISOString().slice(0,10)}.txt`;
        document.body.appendChild(link);
        link.click();
        
        // cleanup
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    });

    // ----------------------------------------------------
    // 7. Whitelist Management (Admin Only)
    // ----------------------------------------------------
    const inviteForm = document.getElementById('invite-form');
    const inviteEmail = document.getElementById('invite-email');
    const inviteStatus = document.getElementById('invite-status');
    const usersTableBody = document.getElementById('users-table-body');
    const usersPanel = document.getElementById('users-panel');

    const menuBtnUsers = document.getElementById('menu-btn-users');
    if (menuBtnUsers) {
        menuBtnUsers.addEventListener('click', () => {
            // Deselect active apps in sidebar
            const activeItems = appList.querySelectorAll('.app-item.active');
            activeItems.forEach(item => item.classList.remove('active'));
            
            // Deselect audit menu
            if (menuBtnAudit) {
                menuBtnAudit.classList.remove('active');
            }
            
            // Highlight this menu button
            menuBtnUsers.classList.add('active');
            
            // Disconnect active log stream
            disconnectActiveStream();
            
            // Switch main view
            emptyState.classList.add('hidden');
            logViewer.classList.add('hidden');
            if (auditPanel) auditPanel.classList.add('hidden');
            usersPanel.classList.remove('hidden');
            
            // Load Whitelist list
            loadWhitelist();
        });
    }

    async function loadWhitelist() {
        if (!usersTableBody) return;
        
        usersTableBody.innerHTML = '<tr><td colspan="3" style="padding: 16px; text-align: center; color: var(--text-muted);"><i class="fa-solid fa-circle-notch fa-spin"></i> Carregando lista...</td></tr>';
        
        try {
            const res = await fetch('api.php?action=list_users');
            const data = await res.json();
            
            if (data.success && Array.isArray(data.users)) {
                usersTableBody.innerHTML = '';
                
                if (data.users.length === 0) {
                    usersTableBody.innerHTML = '<tr><td colspan="3" style="padding: 16px; text-align: center; color: var(--text-muted);">Nenhum usuário cadastrado.</td></tr>';
                    return;
                }
                
                const administrators = ['admin'];

                data.users.forEach(user => {
                    const email = user.email;
                    const role = user.role;
                    
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid rgba(255,255,255,0.04)';
                    
                    const isProtected = administrators.includes(email.toLowerCase());
                    const deleteBtnHtml = isProtected 
                        ? `<span style="color: var(--text-muted); font-size: 12px; font-style: italic; padding-right: 8px;">Administrador</span>` 
                        : `<button class="btn-tool" style="color: var(--color-danger); border: 1px solid rgba(239, 68, 68, 0.2); background: rgba(239, 68, 68, 0.05); padding: 4px 8px; border-radius: 4px; cursor: pointer;" onclick="window._deleteWhitelistUser('${email}')"><i class="fa-solid fa-trash-can"></i> Remover</button>`;

                    let roleDisplay = 'Apenas Logs';
                    let roleColor = 'var(--text-muted)';
                    if (role === 'admin') {
                        roleDisplay = 'Administrador';
                        roleColor = 'var(--color-primary)';
                    } else if (role === 'code_viewer') {
                        roleDisplay = 'Logs e Código';
                        roleColor = '#38bdf8'; // light blue
                    }

                    tr.innerHTML = `
                        <td style="padding: 12px 16px; font-weight: 500;">${email}</td>
                        <td style="padding: 12px 16px; color: ${roleColor}; font-weight: 500;">${roleDisplay}</td>
                        <td style="padding: 12px 16px; text-align: right;">${deleteBtnHtml}</td>
                    `;
                    usersTableBody.appendChild(tr);
                });
            } else {
                usersTableBody.innerHTML = `<tr><td colspan="3" style="padding: 16px; text-align: center; color: var(--color-danger);">${data.error || 'Erro ao carregar lista.'}</td></tr>`;
            }
        } catch (err) {
            usersTableBody.innerHTML = '<tr><td colspan="3" style="padding: 16px; text-align: center; color: var(--color-danger);">Erro de rede ao carregar a lista de acessos.</td></tr>';
        }
    }

    if (inviteForm) {
        inviteForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const email = inviteEmail.value.trim();
            const inviteRoleEl = document.getElementById('invite-role');
            const role = inviteRoleEl ? inviteRoleEl.value : 'log_viewer';
            if (!email) return;

            inviteStatus.style.color = 'var(--text-secondary)';
            inviteStatus.textContent = 'Enviando convite...';

            const submitBtn = inviteForm.querySelector('button[type="submit"]');
            if (submitBtn) submitBtn.disabled = true;

            try {
                const res = await fetch('api.php?action=invite_user', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, role })
                });
                const data = await res.json();

                if (data.success) {
                    inviteStatus.style.color = 'var(--color-success)';
                    inviteStatus.textContent = data.message;
                    inviteEmail.value = '';
                    loadWhitelist();
                } else {
                    inviteStatus.style.color = 'var(--color-danger)';
                    inviteStatus.textContent = data.error || 'Erro ao convidar usuário.';
                }
            } catch (err) {
                inviteStatus.style.color = 'var(--color-danger)';
                inviteStatus.textContent = 'Erro de rede. Tente novamente mais tarde.';
            } finally {
                if (submitBtn) submitBtn.disabled = false;
            }
        });
    }

    // Expose delete user function globally so it can be called by inline onclick
    window._deleteWhitelistUser = async (email) => {
        if (!confirm(`Tem certeza que deseja remover o e-mail "${email}" da lista de acesso?`)) {
            return;
        }

        try {
            const res = await fetch('api.php?action=delete_user', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ email })
            });
            const data = await res.json();

            if (data.success) {
                loadWhitelist();
            } else {
                alert(data.error || 'Erro ao remover usuário.');
            }
        } catch (err) {
            alert('Erro de rede ao tentar remover usuário.');
        }
    };

    // ----------------------------------------------------
    // 7.5. Audit Logs View (Admin Only)
    // ----------------------------------------------------
    const menuBtnAudit = document.getElementById('menu-btn-audit');
    const auditPanel = document.getElementById('audit-panel');
    const auditTableBody = document.getElementById('audit-table-body');

    if (menuBtnAudit) {
        menuBtnAudit.addEventListener('click', () => {
            // Deselect active apps in sidebar
            const activeItems = appList.querySelectorAll('.app-item.active');
            activeItems.forEach(item => item.classList.remove('active'));
            
            // Deselect users menu
            if (menuBtnUsers) {
                menuBtnUsers.classList.remove('active');
            }
            
            // Highlight this menu button
            menuBtnAudit.classList.add('active');
            
            // Disconnect active log stream
            disconnectActiveStream();
            
            // Switch main view
            emptyState.classList.add('hidden');
            logViewer.classList.add('hidden');
            if (usersPanel) usersPanel.classList.add('hidden');
            if (auditPanel) auditPanel.classList.remove('hidden');
            
            // Load audit logs
            loadAuditLogs();
        });
    }

    async function loadAuditLogs() {
        if (!auditTableBody) return;
        
        auditTableBody.innerHTML = '<tr><td colspan="5" style="padding: 16px; text-align: center; color: var(--text-muted);"><i class="fa-solid fa-circle-notch fa-spin"></i> Carregando logs de auditoria...</td></tr>';
        
        try {
            const res = await fetch('api.php?action=list_audit_logs');
            const data = await res.json();
            
            if (data.success && Array.isArray(data.logs)) {
                auditTableBody.innerHTML = '';
                
                if (data.logs.length === 0) {
                    auditTableBody.innerHTML = '<tr><td colspan="5" style="padding: 16px; text-align: center; color: var(--text-muted);">Nenhuma atividade registrada ainda.</td></tr>';
                    return;
                }
                
                data.logs.forEach(log => {
                    const tr = document.createElement('tr');
                    tr.style.borderBottom = '1px solid rgba(255,255,255,0.04)';
                    
                    let actionDisplay = log.action;
                    let actionBadgeColor = 'rgba(255,255,255,0.1)';
                    let actionTextColor = 'var(--text-secondary)';
                    
                    if (log.action === 'login_success') {
                        actionDisplay = 'Login Sucesso';
                        actionBadgeColor = 'rgba(16, 185, 129, 0.15)';
                        actionTextColor = '#10b981';
                    } else if (log.action === 'login_failed') {
                        actionDisplay = 'Login Falhou';
                        actionBadgeColor = 'rgba(239, 68, 68, 0.15)';
                        actionTextColor = '#ef4444';
                    } else if (log.action === 'login_otp_requested') {
                        actionDisplay = 'OTP Solicitado';
                        actionBadgeColor = 'rgba(245, 158, 11, 0.15)';
                        actionTextColor = '#f59e0b';
                    } else if (log.action === 'stream_logs_started') {
                        actionDisplay = 'Ver Logs';
                        actionBadgeColor = 'rgba(56, 189, 248, 0.15)';
                        actionTextColor = '#38bdf8';
                    } else if (log.action === 'view_file') {
                        actionDisplay = 'Ler Código';
                        actionBadgeColor = 'rgba(139, 92, 246, 0.15)';
                        actionTextColor = '#8b5cf6';
                    } else if (log.action === 'download_file') {
                        actionDisplay = 'Baixar Código';
                        actionBadgeColor = 'rgba(236, 72, 153, 0.15)';
                        actionTextColor = '#ec4899';
                    } else if (log.action === 'user_invited') {
                        actionDisplay = 'Convidou Usuário';
                        actionBadgeColor = 'rgba(16, 185, 129, 0.15)';
                        actionTextColor = '#10b981';
                    } else if (log.action === 'user_deleted') {
                        actionDisplay = 'Excluiu Usuário';
                        actionBadgeColor = 'rgba(239, 68, 68, 0.15)';
                        actionTextColor = '#ef4444';
                    }
                    
                    tr.innerHTML = `
                        <td style="padding: 12px 16px; font-family: var(--font-mono); font-size: 12px; color: var(--text-muted);">${log.timestamp}</td>
                        <td style="padding: 12px 16px; font-weight: 500;">${log.email}</td>
                        <td style="padding: 12px 16px; font-family: var(--font-mono); font-size: 12px; color: var(--text-muted);">${log.ip}</td>
                        <td style="padding: 12px 16px;">
                            <span style="display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 700; background: ${actionBadgeColor}; color: ${actionTextColor}; text-transform: uppercase;">
                                ${actionDisplay}
                            </span>
                        </td>
                        <td style="padding: 12px 16px; color: var(--text-secondary); font-size: 13px;">${log.details}</td>
                    `;
                    auditTableBody.appendChild(tr);
                });
            } else {
                auditTableBody.innerHTML = `<tr><td colspan="5" style="padding: 16px; text-align: center; color: var(--color-danger);">${data.error || 'Erro ao carregar logs de auditoria.'}</td></tr>`;
            }
        } catch (err) {
            console.error("Error loading audit logs:", err);
            auditTableBody.innerHTML = '<tr><td colspan="5" style="padding: 16px; text-align: center; color: var(--color-danger);">Erro de rede ao carregar os logs de auditoria.</td></tr>';
        }
    }

    // ----------------------------------------------------
    // 8. View Mode Switches (Logs vs Source Code)
    // ----------------------------------------------------
    if (btnModeLogs && btnModeCode) {
        btnModeLogs.addEventListener('click', () => {
            btnModeLogs.classList.add('active');
            btnModeCode.classList.remove('active');
            logsViewPanel.classList.remove('hidden');
            codeExplorer.classList.add('hidden');
            
            // Resume logs streaming if app and service were selected
            if (selectedApp && selectedService) {
                startLogStreaming(selectedApp.name, selectedService);
            }
        });

        btnModeCode.addEventListener('click', () => {
            btnModeCode.classList.add('active');
            btnModeLogs.classList.remove('active');
            logsViewPanel.classList.add('hidden');
            codeExplorer.classList.remove('hidden');
            
            // Disconnect logs stream to save network bandwidth while reading code
            disconnectActiveStream();
            
            // Load source files directory tree
            if (selectedApp) {
                loadFileTree(selectedApp.name);
            }
        });
    }

    async function loadFileTree(appName) {
        if (!fileTree) return;
        fileTree.innerHTML = '<div style="font-size: 13px; color: var(--text-muted); padding: 8px 0;"><i class="fa-solid fa-circle-notch fa-spin"></i> Lendo pasta src/...</div>';
        
        try {
            const res = await fetch(`api.php?action=list_files&app=${encodeURIComponent(appName)}`);
            const data = await res.json();
            
            if (data.success && Array.isArray(data.files)) {
                fileTree.innerHTML = '';
                if (data.files.length === 0) {
                    fileTree.innerHTML = '<div style="font-size: 13px; color: var(--text-muted); padding: 8px 0;">Nenhum arquivo na pasta src/</div>';
                    return;
                }
                renderTreeNodes(data.files, fileTree, appName);
            } else {
                fileTree.innerHTML = `<div style="font-size: 13px; color: var(--color-danger); padding: 8px 0;">${data.error || 'src/ não encontrada.'}</div>`;
            }
        } catch (err) {
            console.error("Error loading file tree:", err);
            fileTree.innerHTML = '<div style="font-size: 13px; color: var(--color-danger); padding: 8px 0;">Erro ao ler arquivos.</div>';
        }
    }

    function renderTreeNodes(nodes, container, appName, depth = 0) {
        nodes.forEach(node => {
            const div = document.createElement('div');
            div.style.paddingLeft = `${depth * 12}px`;
            div.style.display = 'flex';
            div.style.alignItems = 'center';
            div.style.gap = '6px';
            div.style.paddingTop = '4px';
            div.style.paddingBottom = '4px';
            div.style.borderRadius = '4px';
            div.style.cursor = 'pointer';
            div.style.transition = 'background 0.2s';
            
            let icon = 'fa-file-code';
            let iconColor = 'var(--text-muted)';
            
            if (node.type === 'directory') {
                icon = 'fa-folder';
                iconColor = '#38bdf8'; // light blue for folders
            } else {
                const ext = node.name.split('.').pop().toLowerCase();
                if (ext === 'php') {
                    icon = 'fa-php';
                    iconColor = '#a78bfa'; // violet for php
                } else if (ext === 'js' || ext === 'mjs' || ext === 'json') {
                    icon = 'fa-node-js';
                    iconColor = '#fde047'; // yellow for node
                } else if (ext === 'py') {
                    icon = 'fa-python';
                    iconColor = '#60a5fa'; // python blue
                }
            }

            const iconFamily = (icon === 'fa-php' || icon === 'fa-node-js' || icon === 'fa-python') ? 'fa-brands' : 'fa-solid';

            div.innerHTML = `<i class="${iconFamily} ${icon}" style="color: ${iconColor}; font-size: 14px; width: 14px; text-align: center;"></i> <span style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">${node.name}</span>`;
            
            div.addEventListener('mouseenter', () => div.style.background = 'rgba(255,255,255,0.04)');
            div.addEventListener('mouseleave', () => div.style.background = 'transparent');

            container.appendChild(div);

            if (node.type === 'directory') {
                const childrenContainer = document.createElement('div');
                childrenContainer.style.display = 'none';
                container.appendChild(childrenContainer);

                let childrenRendered = false;

                div.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isCollapsed = childrenContainer.style.display === 'none';
                    
                    if (isCollapsed && !childrenRendered) {
                        // Render children dynamically on expand click (lazy loading)
                        renderTreeNodes(node.children, childrenContainer, appName, depth + 1);
                        childrenRendered = true;
                    }

                    childrenContainer.style.display = isCollapsed ? 'block' : 'none';
                    const iconEl = div.querySelector('i');
                    if (iconEl) {
                        iconEl.className = isCollapsed ? 'fa-solid fa-folder-open' : 'fa-solid fa-folder';
                    }
                });
            } else {
                div.addEventListener('click', (e) => {
                    e.stopPropagation();
                    
                    // Highlight selected file item in color
                    fileTree.querySelectorAll('div').forEach(d => {
                        if (d.style) d.style.color = '';
                    });
                    div.style.color = 'var(--color-primary)';
                    
                    loadFileContent(appName, node.path);
                });
            }
        });
    }

    async function loadFileContent(appName, filePath) {
        if (!selectedFilePath || !codeOutputPre || !btnDownloadFile) return;

        selectedFilePath.textContent = `src/${filePath}`;
        codeOutputPre.textContent = 'Carregando conteúdo...';
        btnDownloadFile.classList.add('hidden');

        try {
            const res = await fetch(`api.php?action=view_file&app=${encodeURIComponent(appName)}&file_path=${encodeURIComponent(filePath)}`);
            const data = await res.json();

            if (data.success) {
                codeOutputPre.textContent = data.content;
                
                // Show download link
                btnDownloadFile.href = `api.php?action=download_file&app=${encodeURIComponent(appName)}&file_path=${encodeURIComponent(filePath)}`;
                btnDownloadFile.classList.remove('hidden');
            } else {
                codeOutputPre.textContent = data.error || 'Erro ao carregar arquivo.';
            }
        } catch (err) {
            console.error("Error loading file content:", err);
            codeOutputPre.textContent = 'Erro de rede ao ler o arquivo.';
        }
    }
});
