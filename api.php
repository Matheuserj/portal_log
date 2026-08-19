<?php
/**
 * Backend API for Docker Log Portal
 */

require_once __DIR__ . '/config.php';

// Configure session options for security
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

header('Content-Type: application/json');

$action = $_GET['action'] ?? '';

// Helper function to send JSON response
function send_json($data, $status_code = 200) {
    http_response_code($status_code);
    echo json_encode($data);
    exit;
}

// PDO Connection Manager
function get_db_connection() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    
    $host = DB_HOST;
    $db = DB_NAME;
    $user = DB_USER;
    $pass = DB_PASS;
    $charset = 'utf8mb4';
    
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    
    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    } catch (\PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        send_json(['success' => false, 'error' => 'Falha na conexão com o banco de dados.'], 500);
    }
}

// Auto-Initialization & Auto-Migration from users.json
function init_database() {
    static $initialized = false;
    if ($initialized) return;
    
    $pdo = get_db_connection();
    
    // Create whitelisted_users table
    $sql = "CREATE TABLE IF NOT EXISTS whitelisted_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) UNIQUE NOT NULL,
        role VARCHAR(50) NOT NULL DEFAULT 'log_viewer',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    $pdo->exec($sql);
    
    // Check if table is empty
    $stmt = $pdo->query("SELECT COUNT(*) FROM whitelisted_users");
    if ($stmt->fetchColumn() == 0) {
        $migrated_users = [];
        
        // Read users.json for backwards-compatibility migration
        $db_file = USERS_DB_FILE;
        if (file_exists($db_file)) {
            $content = file_get_contents($db_file);
            $data = json_decode($content, true);
            if (is_array($data)) {
                foreach ($data as $item) {
                    if (is_string($item)) {
                        $migrated_users[] = [
                            'email' => strtolower(trim($item)),
                            'role' => 'admin'
                        ];
                    } else if (is_array($item) && isset($item['email'])) {
                        $migrated_users[] = [
                            'email' => strtolower(trim($item['email'])),
                            'role' => strtolower(trim($item['role'] ?? 'log_viewer'))
                        ];
                    }
                }
            }
        }
        
        // Fallback default admin users if users.json is empty or missing
        if (empty($migrated_users)) {
            $migrated_users = [
                ['email' => 'admin', 'role' => 'admin']
            ];
        }
        
        $insert = $pdo->prepare("INSERT IGNORE INTO whitelisted_users (email, role) VALUES (?, ?)");
        foreach ($migrated_users as $u) {
            $insert->execute([$u['email'], $u['role']]);
        }
        
        // Clean up users.json after successful migration to prevent re-triggering
        if (file_exists($db_file)) {
            @unlink($db_file);
        }
    }
    
    $initialized = true;
}

// Helper to load whitelisted users
function load_whitelisted_emails() {
    init_database();
    $pdo = get_db_connection();
    $stmt = $pdo->query("SELECT email, role FROM whitelisted_users ORDER BY email ASC");
    return $stmt->fetchAll();
}

// Helper to check if email is whitelisted
function is_email_allowed($email) {
    init_database();
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM whitelisted_users WHERE email = ?");
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetchColumn() > 0;
}

// Helper to get user role
function get_user_role($email) {
    init_database();
    $pdo = get_db_connection();
    $stmt = $pdo->prepare("SELECT role FROM whitelisted_users WHERE email = ?");
    $stmt->execute([strtolower(trim($email))]);
    return $stmt->fetchColumn() ?: 'guest';
}

// Helper to enforce role access
function require_role($allowed_roles) {
    if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        send_json(['success' => false, 'error' => 'Não autorizado. Por favor, faça login.'], 401);
    }
    
    $user_email = $_SESSION['user_email'] ?? '';
    if (strtolower($user_email) === 'admin') {
        return 'admin';
    }
    
    $role = get_user_role($user_email);
    if (!in_array($role, $allowed_roles)) {
        send_json(['success' => false, 'error' => 'Acesso negado. Você não tem as permissões necessárias.'], 403);
    }
    return $role;
}

// Helper to log user activities with automatic 30-day retention
function log_audit_action($action, $details = '') {
    $log_file = AUDIT_LOG_FILE;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $email = $_SESSION['user_email'] ?? 'guest';
    
    $timestamp = date('Y-m-d H:i:s');
    $log_line = sprintf("%s | %s | %s | %s | %s\n", $timestamp, $email, $ip, $action, $details);
    @file_put_contents($log_file, $log_line, FILE_APPEND);
    
    // 1-in-20 chance to run log pruning to keep performance high
    if (mt_rand(1, 20) === 1) {
        prune_audit_logs($log_file);
    }
}

// Pruning routine for 30 days retention
function prune_audit_logs($log_file) {
    if (!file_exists($log_file)) return;
    $lines = @file($log_file);
    if ($lines === false) return;
    
    $cutoff = time() - (30 * 24 * 60 * 60); // 30 days
    $new_lines = [];
    
    foreach ($lines as $line) {
        $parts = explode(' | ', $line, 2);
        if (count($parts) < 2) continue;
        $time = strtotime($parts[0]);
        if ($time && $time >= $cutoff) {
            $new_lines[] = $line;
        }
    }
    
    @file_put_contents($log_file, implode('', $new_lines));
}

// Helper to detect technology stack from image string or service name
function detect_tech_from_image_or_name($name, $image) {
    $search_string = strtolower($name . ' ' . $image);
    
    if (strpos($search_string, 'php') !== false) {
        return 'php';
    }
    if (
        strpos($search_string, 'node') !== false || 
        strpos($search_string, 'ecma') !== false || 
        strpos($search_string, 'npm') !== false || 
        strpos($search_string, 'pm2') !== false || 
        strpos($search_string, 'react') !== false || 
        strpos($search_string, 'vue') !== false || 
        strpos($search_string, 'nuxt') !== false || 
        strpos($search_string, 'next') !== false
    ) {
        return 'node';
    }
    if (strpos($search_string, 'python') !== false || strpos($search_string, 'django') !== false || strpos($search_string, 'flask') !== false || strpos($search_string, 'pip') !== false) {
        return 'python';
    }
    if (strpos($search_string, 'mysql') !== false || strpos($search_string, 'mariadb') !== false) {
        return 'mysql';
    }
    if (strpos($search_string, 'postgres') !== false || strpos($search_string, 'postgresql') !== false || strpos($search_string, 'psql') !== false) {
        return 'postgres';
    }
    if (strpos($search_string, 'redis') !== false) {
        return 'redis';
    }
    if (strpos($search_string, 'nginx') !== false) {
        return 'nginx';
    }
    if (strpos($search_string, 'apache') !== false || strpos($search_string, 'httpd') !== false) {
        return 'apache';
    }
    if (strpos($search_string, 'mongo') !== false) {
        return 'mongodb';
    }
    
    // Fallbacks based on common names
    if ($name === 'db' || $name === 'database' || $name === 'banco' || $name === 'mysql' || $name === 'postgres') {
        return 'db';
    }
    if ($name === 'web' || $name === 'http' || $name === 'app') {
        return 'web';
    }
    
    return 'unknown';
}

// Helper to parse service names and their metadata from docker-compose.yml
function parse_services_from_compose($file_path) {
    if (!file_exists($file_path)) {
        return [];
    }
    $content = file_get_contents($file_path);
    $lines = explode("\n", $content);
    $services = [];
    $current_service = null;
    $in_services = false;
    $services_indent = -1;
    $srv_indent = -1;

    foreach ($lines as $line) {
        // Skip empty lines or comments
        if (trim($line) === '' || strpos(trim($line), '#') === 0) {
            continue;
        }

        // Detect services root block
        if (preg_match('/^(\s*)services\s*:/i', $line, $matches)) {
            $in_services = true;
            $services_indent = strlen($matches[1]);
            continue;
        }

        if ($in_services) {
            // Check indentation level of the current line
            preg_match('/^(\s*)/', $line, $indent_matches);
            $current_indent = strlen($indent_matches[1]);

            // Exit services block if indentation drops
            if ($current_indent <= $services_indent && trim($line) !== '') {
                if (preg_match('/^[a-zA-Z0-9_-]+:/', trim($line))) {
                    $in_services = false;
                    $current_service = null;
                    continue;
                }
            }

            // Inside services block, look for service definitions
            if (preg_match('/^(\s+)([a-zA-Z0-9_-]+)\s*:/', $line, $service_matches)) {
                $indent = strlen($service_matches[1]);
                if ($indent > $services_indent) {
                    if ($srv_indent === -1) {
                        $srv_indent = $indent;
                    }
                    if ($indent === $srv_indent) {
                        $current_service = $service_matches[2];
                        $services[$current_service] = [
                            'name' => $current_service,
                            'image' => '',
                            'tech' => 'unknown'
                        ];
                    }
                }
            }

            // If we are currently inside a service block, look for the image attribute
            if ($current_service !== null && preg_match('/^\s+image\s*:\s*["\']?([^"\'\s]+)["\']?/i', $line, $image_matches)) {
                $image = $image_matches[1];
                $services[$current_service]['image'] = $image;
                $services[$current_service]['tech'] = detect_tech_from_image_or_name($current_service, $image);
            }
        }
    }

    // Convert associative array to list and normalize tech detection
    $result = [];
    foreach ($services as $srv) {
        if ($srv['tech'] === 'unknown') {
            $srv['tech'] = detect_tech_from_image_or_name($srv['name'], '');
        }
        $result[] = $srv;
    }
    return $result;
}

// Helper to parse custom project name from docker-compose.yml (name: attribute at root level)
function parse_project_name_from_compose($file_path, $default_name) {
    if (!file_exists($file_path)) {
        return $default_name;
    }
    $content = file_get_contents($file_path);
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        if (trim($line) === '' || strpos(trim($line), '#') === 0) {
            continue;
        }
        // Match root level "name: project_name" (no leading space)
        if (preg_match('/^name\s*:\s*["\']?([a-zA-Z0-9_-]+)["\']?/i', $line, $matches)) {
            return $matches[1];
        }
    }
    return $default_name;
}

// Helper to recursively list files in src/ directory, excluding heavy dependency directories
function get_file_tree($dir, $base_dir) {
    $result = [];
    if (!is_dir($dir)) {
        return $result;
    }
    
    $items = @scandir($dir);
    if ($items === false) {
        return $result;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        // Exclude directories to avoid heavy listing and security/build folders
        if (in_array(strtolower($item), ['vendor', 'node_modules', '.git', '.github', 'dist', 'build', '.cache'])) {
            continue;
        }

        $path = $dir . '/' . $item;
        // Normalize paths for comparison (replace backslashes with forward slashes)
        $normalized_base = str_replace('\\', '/', $base_dir);
        $normalized_path = str_replace('\\', '/', $path);
        $rel_path = ltrim(str_replace($normalized_base, '', $normalized_path), '/');

        $item_name = $item;
        // Ensure valid UTF-8 for JSON encoding (prevent failure on special characters)
        if (function_exists('mb_convert_encoding')) {
            if (!mb_check_encoding($item_name, 'UTF-8')) {
                $item_name = mb_convert_encoding($item_name, 'UTF-8', 'UTF-8, ISO-8859-1, ASCII');
            }
            if (!mb_check_encoding($rel_path, 'UTF-8')) {
                $rel_path = mb_convert_encoding($rel_path, 'UTF-8', 'UTF-8, ISO-8859-1, ASCII');
            }
        } else {
            $item_name = @iconv('UTF-8', 'UTF-8//IGNORE', $item_name);
            $rel_path = @iconv('UTF-8', 'UTF-8//IGNORE', $rel_path);
        }

        if (is_dir($path)) {
            $result[] = [
                'name' => $item_name,
                'path' => $rel_path,
                'type' => 'directory',
                'children' => get_file_tree($path, $base_dir)
            ];
        } else {
            $result[] = [
                'name' => $item_name,
                'path' => $rel_path,
                'type' => 'file',
                'size' => @filesize($path) ?: 0
            ];
        }
    }

    // Sort folders first, then files
    usort($result, function($a, $b) {
        if ($a['type'] === $b['type']) {
            return strcasecmp($a['name'], $b['name']);
        }
        return ($a['type'] === 'directory') ? -1 : 1;
    });

    return $result;
}

// Helper to validate and return canonical safe path inside app src/ directory
function validate_safe_src_path($app, $file_path) {
    if (empty($app)) {
        send_json(['success' => false, 'error' => 'Nome do aplicativo não fornecido.'], 400);
    }

    // Filter app folder name to prevent arbitrary directory reading
    $app = preg_replace('/[^a-zA-Z0-9_-]/', '', $app);
    $app_src_path = realpath(APP_DIR . '/' . $app . '/src');

    if (!$app_src_path || !is_dir($app_src_path)) {
        send_json(['success' => false, 'error' => 'Diretório de código fonte (src/) não encontrado para esta aplicação.'], 404);
    }

    if (empty($file_path)) {
        return $app_src_path; // Return base src path
    }

    // Resolve absolute path and enforce LFI/Path Traversal validation
    $target_path = realpath($app_src_path . '/' . $file_path);

    if (!$target_path || strpos(str_replace('\\', '/', $target_path), str_replace('\\', '/', $app_src_path)) !== 0) {
        send_json(['success' => false, 'error' => 'Acesso negado. Tentativa de Path Traversal detectada.'], 403);
    }

    return $target_path;
}

// ----------------------------------------------------
// Public Endpoints (No authentication required)
// ----------------------------------------------------

if ($action === 'send_otp') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');

    // Check admin bypass (if they input literally "admin", they go to the password screen)
    $is_admin_attempt = (defined('ADMIN_USER') && strtolower($email) === strtolower(ADMIN_USER));

    if (!$is_admin_attempt) {
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            send_json(['success' => false, 'error' => 'Por favor, insira um e-mail válido.'], 400);
        }

        if (!is_email_allowed($email)) {
            send_json(['success' => false, 'error' => 'Este e-mail não possui convite de acesso.'], 403);
        }
    } else {
        // Return success immediately to transition to password screen
        send_json([
            'success' => true,
            'message' => 'Modo Administrador: Digite a senha administrativa para prosseguir.',
            'dev_mode' => false
        ]);
    }

    // Generate 6-digit OTP code
    $otp = sprintf("%06d", mt_rand(0, 999999));

    // Save in session
    $_SESSION['otp'] = $otp;
    $_SESSION['otp_email'] = $email;
    $_SESSION['otp_expires'] = time() + OTP_EXPIRY_SECONDS;

    // Log to file for development/testing
    $log_entry = sprintf("[%s] E-mail: %s | OTP: %s\n", date('Y-m-d H:i:s'), $email, $otp);
    @file_put_contents(OTP_LOG_FILE, $log_entry, FILE_APPEND);
    
    // Also write to container logs so you can view it via 'docker logs <container_name>'
    error_log("DOCKER LOG PORTAL: " . trim($log_entry));

    log_audit_action('login_otp_requested', "Email: $email");

    // Send via email using SMTP mailer if enabled
    $mail_sent = false;
    if (SMTP_ENABLED) {
        require_once __DIR__ . '/mailer.php';
        $subject = "Seu codigo de acesso ao Docker Log Portal: $otp";
        $message = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
            <h2 style='color: #0ea5e9; text-align: center;'>Código de Autenticação</h2>
            <p>Olá,</p>
            <p>Seu código de uso único (OTP) para acessar o <strong>Docker Log Portal</strong> é:</p>
            <div style='background-color: #f8fafc; border: 1px dashed #cbd5e1; padding: 15px; text-align: center; font-size: 28px; font-weight: bold; letter-spacing: 5px; color: #0f172a; margin: 20px 0;'>
                $otp
            </div>
            <p style='color: #64748b; font-size: 14px;'>Este código expira em 10 minutos por motivos de segurança.</p>
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
            <p style='color: #94a3b8; font-size: 12px; text-align: center;'>Docker Log Portal &bull; Acesso Seguro</p>
        </div>
        ";
        
        $mail_sent = send_smtp_email($email, $subject, $message);
    }

    send_json([
        'success' => true,
        'message' => $mail_sent 
            ? 'Código de autenticação enviado para o seu e-mail!' 
            : 'Código gerado com sucesso! (Verifique o log do container ou otp.log, pois o envio de e-mail falhou ou está desativado).',
        'dev_mode' => !$mail_sent
    ]);
}

if ($action === 'verify_otp') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['success' => false, 'error' => 'Method not allowed'], 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $email = trim($input['email'] ?? '');
    $otp = trim($input['otp'] ?? '');

    if (empty($email) || empty($otp)) {
        send_json(['success' => false, 'error' => 'E-mail/usuário e código/senha são obrigatórios.'], 400);
    }

    // Check admin password bypass
    if (defined('ADMIN_USER') && defined('ADMIN_PASSWORD') && $email === ADMIN_USER && $otp === ADMIN_PASSWORD) {
        $_SESSION['authenticated'] = true;
        $_SESSION['user_email'] = ADMIN_USER;
        log_audit_action('login_success', 'Bypassed with Admin password');
        send_json(['success' => true]);
    }

    if (
        isset($_SESSION['otp']) &&
        isset($_SESSION['otp_email']) &&
        isset($_SESSION['otp_expires']) &&
        $_SESSION['otp'] === $otp &&
        $_SESSION['otp_email'] === $email &&
        time() < $_SESSION['otp_expires']
    ) {
        // Authenticate user
        $_SESSION['authenticated'] = true;
        $_SESSION['user_email'] = $email;

        // Clear OTP session variables
        unset($_SESSION['otp']);
        unset($_SESSION['otp_email']);
        unset($_SESSION['otp_expires']);

        log_audit_action('login_success');
        send_json(['success' => true]);
    } else {
        log_audit_action('login_failed', "Email attempted: $email");
        send_json(['success' => false, 'error' => 'Código ou senha incorretos.'], 401);
    }
}

if ($action === 'status') {
    $authenticated = isset($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    $email = $_SESSION['user_email'] ?? null;
    $role = $authenticated ? (($email === 'admin') ? 'admin' : get_user_role($email)) : null;
    send_json([
        'authenticated' => $authenticated,
        'email' => $email,
        'role' => $role
    ]);
}

// ----------------------------------------------------
// Authenticated Endpoints (Require valid session)
// ----------------------------------------------------

if (!isset($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
    send_json(['success' => false, 'error' => 'Não autorizado. Por favor, faça login.'], 401);
}

// Release session lock immediately to allow other requests (like new logs streams or dashboard updates) to run concurrently
session_write_close();

if ($action === 'logout') {
    log_audit_action('logout');
    session_start(); // Re-open to clear session values
    session_destroy();
    send_json(['success' => true]);
}

// User Management Endpoints (Only accessible by specific administrators)
if ($action === 'list_users') {
    require_role(['admin']);
    $whitelist = load_whitelisted_emails();
    send_json(['success' => true, 'users' => $whitelist]);
}

if ($action === 'invite_user') {
    require_role(['admin']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['success' => false, 'error' => 'Method not allowed'], 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $invited_email = trim($input['email'] ?? '');
    $role = trim($input['role'] ?? 'log_viewer');
    
    if (empty($invited_email) || !filter_var($invited_email, FILTER_VALIDATE_EMAIL)) {
        send_json(['success' => false, 'error' => 'Por favor, insira um e-mail válido para convidar.'], 400);
    }
    
    if (!in_array($role, ['admin', 'code_viewer', 'log_viewer'])) {
        $role = 'log_viewer';
    }
    
    $pdo = get_db_connection();
    
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM whitelisted_users WHERE email = ?");
    $stmt->execute([strtolower($invited_email)]);
    if ($stmt->fetchColumn() > 0) {
        send_json(['success' => false, 'error' => 'Este e-mail já está cadastrado no sistema.'], 400);
    }
    
    $stmt = $pdo->prepare("INSERT INTO whitelisted_users (email, role) VALUES (?, ?)");
    if ($stmt->execute([strtolower($invited_email), $role])) {
        log_audit_action('user_invited', "Email: $invited_email, Role: $role");
        
        // Send email invitation via SMTP if enabled
        $mail_sent = false;
        if (SMTP_ENABLED) {
            require_once __DIR__ . '/mailer.php';
            $subject = "Convite de acesso: Docker Log Portal";
            $portal_url = "http://" . ($_SERVER['HTTP_HOST'] ?? 'localhost:8000');
            $message = "
            <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2e8f0; border-radius: 8px;'>
                <h2 style='color: #0ea5e9; text-align: center;'>Acesso Autorizado</h2>
                <p>Olá,</p>
                <p>Seu e-mail foi cadastrado no <strong>Docker Log Portal</strong>. Agora você tem permissão para acessar o painel de logs.</p>
                <p>Para entrar no portal, acesse o link abaixo e informe seu e-mail para receber seu token de acesso:</p>
                <div style='text-align: center; margin: 30px 0;'>
                    <a href='$portal_url' style='background-color: #0ea5e9; color: #ffffff; padding: 12px 24px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block;'>Acessar o Portal de Logs</a>
                </div>
                <p style='color: #64748b; font-size: 14px;'>E-mail cadastrado: <strong>$invited_email</strong></p>
                <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 20px 0;' />
                <p style='color: #94a3b8; font-size: 12px; text-align: center;'>Docker Log Portal &bull; Acesso Seguro</p>
            </div>
            ";
            $mail_sent = send_smtp_email($invited_email, $subject, $message);
        }
        
        send_json([
            'success' => true,
            'message' => $mail_sent 
                ? 'Usuário convidado e e-mail enviado com sucesso!' 
                : 'E-mail adicionado à lista de acesso! (Envio do e-mail de convite falhou ou está desativado).'
        ]);
    } else {
        send_json(['success' => false, 'error' => 'Falha ao salvar o e-mail no banco de dados.'], 500);
    }
}

if ($action === 'delete_user') {
    require_role(['admin']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        send_json(['success' => false, 'error' => 'Method not allowed'], 405);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $delete_email = trim($input['email'] ?? '');
    
    if (empty($delete_email)) {
        send_json(['success' => false, 'error' => 'E-mail a ser removido é obrigatório.'], 400);
    }
    
    $administrators = ['admin'];
    if (in_array(strtolower($delete_email), array_map('strtolower', $administrators))) {
        send_json(['success' => false, 'error' => 'Não é possível remover contas administrativas.'], 400);
    }
    
    $pdo = get_db_connection();
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM whitelisted_users WHERE email = ?");
    $stmt->execute([strtolower($delete_email)]);
    if ($stmt->fetchColumn() == 0) {
        send_json(['success' => false, 'error' => 'Usuário não encontrado na lista de acesso.'], 404);
    }
    
    $stmt = $pdo->prepare("DELETE FROM whitelisted_users WHERE email = ?");
    if ($stmt->execute([strtolower($delete_email)])) {
        log_audit_action('user_deleted', "Email: $delete_email");
        send_json(['success' => true, 'message' => 'Usuário removido da lista de acesso com sucesso!']);
    } else {
        send_json(['success' => false, 'error' => 'Falha ao atualizar o banco de dados.'], 500);
    }
}

if ($action === 'list_audit_logs') {
    require_role(['admin']);
    $log_file = AUDIT_LOG_FILE;
    $history = [];
    if (file_exists($log_file)) {
        $lines = @file($log_file);
        if ($lines !== false) {
            $lines = array_reverse($lines); // Latest first
            $count = 0;
            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;
                $parts = explode(' | ', $line, 5);
                if (count($parts) >= 4) {
                    $history[] = [
                        'timestamp' => $parts[0],
                        'email' => $parts[1],
                        'ip' => $parts[2],
                        'action' => $parts[3],
                        'details' => $parts[4] ?? ''
                    ];
                    $count++;
                    if ($count >= 100) break;
                }
            }
        }
    }
    send_json(['success' => true, 'logs' => $history]);
}

if ($action === 'list_files') {
    require_role(['admin', 'code_viewer']);
    $app = $_GET['app'] ?? '';
    
    // Resolve base src/ folder
    $src_path = validate_safe_src_path($app, '');
    
    // Scan and build file tree
    $files = get_file_tree($src_path, $src_path);
    
    log_audit_action('list_files', "App: $app");
    send_json(['success' => true, 'files' => $files]);
}

if ($action === 'view_file') {
    require_role(['admin', 'code_viewer']);
    $app = $_GET['app'] ?? '';
    $file_path = $_GET['file_path'] ?? '';
    
    // Safe validation (prevents directory traversal)
    $target_file = validate_safe_src_path($app, $file_path);
    
    if (!file_exists($target_file) || is_dir($target_file)) {
        send_json(['success' => false, 'error' => 'Arquivo não encontrado.'], 404);
    }
    
    // Get contents (limit reading size to 5MB for safety)
    if (filesize($target_file) > 5 * 1024 * 1024) {
        send_json(['success' => false, 'error' => 'Arquivo é muito grande para visualização.'], 400);
    }
    
    $content = file_get_contents($target_file);
    
    // Ensure content is valid UTF-8 for JSON encoding (handles files with Latin-1/Portuguese accents)
    if (function_exists('mb_convert_encoding')) {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'UTF-8, ISO-8859-1, ASCII');
        }
    } else {
        $content = @iconv('UTF-8', 'UTF-8//IGNORE', $content);
    }
    
    log_audit_action('view_file', "App: $app, File: $file_path");
    send_json([
        'success' => true, 
        'content' => $content,
        'filename' => basename($target_file),
        'size' => filesize($target_file)
    ]);
}

if ($action === 'download_file') {
    require_role(['admin', 'code_viewer']);
    $app = $_GET['app'] ?? '';
    $file_path = $_GET['file_path'] ?? '';
    
    // Safe validation (prevents directory traversal)
    $target_file = validate_safe_src_path($app, $file_path);
    
    if (!file_exists($target_file) || is_dir($target_file)) {
        http_response_code(404);
        echo "Arquivo não encontrado.";
        exit;
    }
    
    log_audit_action('download_file', "App: $app, File: $file_path");
    
    // Force download headers
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($target_file) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($target_file));
    
    // Read and output file contents directly
    @readfile($target_file);
    exit;
}

if ($action === 'list_apps') {
    require_role(['admin', 'code_viewer', 'log_viewer']);
    $apps = [];
    $app_dir = APP_DIR;

    if (!is_dir($app_dir)) {
        send_json(['success' => false, 'error' => "Diretório de aplicativos não encontrado: $app_dir"], 500);
    }

    // Optimization: Query ALL containers on the host with ONE command to group states.
    // This avoids running 30+ sequential command executions (which takes ~15 seconds).
    // This single execution takes ~0.05 seconds.
    $ps_output = [];
    $ps_cmd = 'docker ps -a --format "{\"ID\":\"{{.ID}}\",\"Project\":\"{{.Label \`com.docker.compose.project\`}}\",\"State\":\"{{.State}}\"}" 2>/dev/null';
    @exec($ps_cmd, $ps_output);

    $project_states = [];
    foreach ($ps_output as $line) {
        $container = json_decode(trim($line), true);
        if (is_array($container) && !empty($container['Project'])) {
            // Project labels are normalized to lowercase by docker compose
            $project = strtolower($container['Project']);
            if (!isset($project_states[$project])) {
                $project_states[$project] = [
                    'running' => 0,
                    'total' => 0
                ];
            }
            $project_states[$project]['total']++;
            $state = strtolower($container['State'] ?? '');
            if ($state === 'running' || strpos($state, 'up') !== false) {
                $project_states[$project]['running']++;
            }
        }
    }

    $items = scandir($app_dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        // Skip excluded directories
        if (defined('EXCLUDE_DIRS') && in_array($item, EXCLUDE_DIRS)) {
            continue;
        }

        $path = $app_dir . '/' . $item;
        if (is_dir($path)) {
            $compose_file = '';
            if (file_exists($path . '/docker-compose.yml')) {
                $compose_file = $path . '/docker-compose.yml';
            } elseif (file_exists($path . '/docker-compose.yaml')) {
                $compose_file = $path . '/docker-compose.yaml';
            }

            if ($compose_file) {
                // Parse services
                $services = parse_services_from_compose($compose_file);
                $total_count = count($services);
                $running_count = 0;
                $status = 'stopped';

                // Parse custom project name if defined in compose, default to folder name
                $compose_project = parse_project_name_from_compose($compose_file, $item);

                // Find matching project in our pre-scanned state list
                // Group all possible normalized variants to guarantee matches
                $proj_keys = [
                    strtolower($compose_project),
                    str_replace('_', '-', strtolower($compose_project)),
                    str_replace('-', '_', strtolower($compose_project)),
                    preg_replace('/[^a-z0-9]/', '', strtolower($compose_project)),
                    strtolower($item),
                    str_replace('_', '-', strtolower($item)),
                    str_replace('-', '_', strtolower($item)),
                    preg_replace('/[^a-z0-9]/', '', strtolower($item))
                ];
                
                $proj_keys = array_unique($proj_keys);

                $matched_project = null;
                foreach ($proj_keys as $key) {
                    if (isset($project_states[$key])) {
                        $matched_project = $project_states[$key];
                        break;
                    }
                }

                if ($matched_project !== null) {
                    $running_count = $matched_project['running'];
                    
                    // If the project containers exist, we determine the status accordingly.
                    if ($running_count === 0) {
                        $status = 'stopped';
                    } elseif ($running_count >= $total_count) {
                        $status = 'running';
                    } else {
                        $status = 'partial';
                    }
                } else {
                    // No containers found for this project
                    $status = 'stopped';
                }

                $apps[] = [
                    'name' => $item,
                    'services' => $services,
                    'status' => $status,
                    'running_services' => $running_count,
                    'total_services' => $total_count
                ];
            }
        }
    }

    send_json(['success' => true, 'apps' => $apps]);
}

if ($action === 'stream_logs') {
    require_role(['admin', 'code_viewer', 'log_viewer']);
    
    // Disable output buffering
    while (ob_get_level()) {
        ob_end_clean();
    }

    $app = $_GET['app'] ?? '';
    $service = $_GET['service'] ?? '';

    // Prevent directory traversal attacks
    $app = basename($app);
    $service = preg_replace('/[^a-zA-Z0-9_-]/', '', $service);

    $app_path = APP_DIR . '/' . $app;
    $compose_file = '';
    if (file_exists($app_path . '/docker-compose.yml')) {
        $compose_file = $app_path . '/docker-compose.yml';
    } elseif (file_exists($app_path . '/docker-compose.yaml')) {
        $compose_file = $app_path . '/docker-compose.yaml';
    }

    if (empty($app) || empty($service) || !$compose_file || !is_dir($app_path)) {
        header("HTTP/1.1 400 Bad Request");
        echo json_encode(['error' => 'Parâmetros inválidos ou compose não encontrado']);
        exit;
    }

    log_audit_action('stream_logs_started', "App: $app, Service: $service");

    // Server-Sent Events headers
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Disable buffering for Nginx proxy

    // Check if we should use mock logs (either on Windows or if it's the test directory)
    $use_mock = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') || (strpos($compose_file, 'test_app_dir') !== false);

    if ($use_mock) {
        $log_types = ['INFO', 'WARN', 'ERROR', 'DEBUG'];
        $db_messages = [
            "Database connection established successfully on port 3306.",
            "Query executed: SELECT * FROM users WHERE active = 1 (took 12ms)",
            "Slow query detected: SELECT COUNT(*) FROM transaction_history WHERE status = 'pending' (took 1.2s)",
            "Error connecting to database: Connection timed out. Retrying in 5s...",
            "Re-established connection to replica host db-replica-01.",
            "Database backup completed. File size: 142MB.",
            "Table lock held on table 'invoices' by thread 14892.",
            "Prepared statement cached: get_user_by_id.",
            "Database optimization running: ANALYZE TABLE users, accounts."
        ];
        $app_messages = [
            "Starting application server on port 8080...",
            "Configured router successfully with 24 routes.",
            "User authentication success: user=admin@company.com ip=192.168.1.42",
            "Failed login attempt for user=root from ip=203.0.113.5",
            "Incoming request: GET /api/v1/status - Response: 200 OK (took 4ms)",
            "Incoming request: POST /api/v1/transaction - Response: 500 Internal Server Error (took 150ms)",
            "Background cron job 'cleanup_old_sessions' finished in 0.5s.",
            "Memory usage warning: Heap memory exceeded 80% of configured threshold.",
            "Caching service response for key 'dashboard_summary_q3'.",
            "Job processed successfully: send_welcome_email (1420ms).",
            "Webhook payload received from stripe: charge.succeeded.",
            "Service main-app ready for connections."
        ];
        
        $messages = (strpos(strtolower($service), 'db') !== false || strpos(strtolower($service), 'mysql') !== false || strpos(strtolower($service), 'postgres') !== false) ? $db_messages : $app_messages;
        
        // Output initial history lines
        for ($i = 0; $i < 40; $i++) {
            $time = date('Y-m-d H:i:s.v', time() - (40 - $i) * 3);
            $type = $log_types[array_rand($log_types)];
            $msg = $messages[array_rand($messages)];
            $line = "[$time] [$type] $msg";
            echo "data: " . json_encode($line) . "\n\n";
        }
        flush();
        
        // Stream real-time logs
        while (true) {
            if (connection_aborted()) {
                break;
            }
            
            $time = date('Y-m-d H:i:s.v');
            $type = $log_types[array_rand($log_types)];
            $msg = $messages[array_rand($messages)];
            $line = "[$time] [$type] $msg";
            
            echo "data: " . json_encode($line) . "\n\n";
            flush();
            
            // Sleep between 0.3 and 1.5 seconds
            usleep(mt_rand(300000, 1500000));
        }
        exit;
    }

    // Command to stream docker compose logs
    // --follow keeps streaming, --tail=50 limits initial history to the last 50 lines, --no-color disables terminal escape colors
    $cmd = "docker compose -f " . escapeshellarg($compose_file) . " logs " . escapeshellarg($service) . " --follow --tail=50 --no-color 2>&1";

    $descriptorspec = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"]  // stderr
    ];

    // Disable compression and buffering in Apache / PHP
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', 0);
    @ini_set('implicit_flush', 1);

    // Server-Sent Events headers
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Disable buffering for Nginx proxy
    header('Content-Encoding: none');

    // Send initial ping to establish connection immediately
    echo ": ping\n\n";
    flush();

    $process = null;
    $pipes = null;
    $last_ping = time();
    $last_spawn_time = 0;
    $spawn_cooldown = 4; // wait at least 4 seconds before trying to restart logs command if it exits
    $notified_stopped = false;

    // Persistent streaming loop
    while (true) {
        // Check if connection is aborted by the client
        if (connection_aborted()) {
            if ($process && is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
            }
            break;
        }

        // Spawn/respawn the process if not running, with cooldown
        $is_running = ($process && is_resource($process) && proc_get_status($process)['running']);
        
        if (!$is_running && (time() - $last_spawn_time > $spawn_cooldown)) {
            // Clean up previous process resources if any
            if ($process && is_resource($process)) {
                @fclose($pipes[0]);
                @fclose($pipes[1]);
                @fclose($pipes[2]);
                @proc_close($process);
            }
            
            $process = proc_open($cmd, $descriptorspec, $pipes);
            $last_spawn_time = time();

            if (is_resource($process)) {
                stream_set_blocking($pipes[1], 0);
                stream_set_blocking($pipes[2], 0);
                $is_running = true;
            }
        }

        // Notify client if container is stopped/not producing logs
        if (!$is_running) {
            if (!$notified_stopped) {
                echo "data: " . json_encode("[SISTEMA] Container não está rodando no momento. Tentando conectar...") . "\n\n";
                flush();
                $notified_stopped = true;
            }
        } else {
            $notified_stopped = false;
        }

        $data_sent = false;
        
        // Read stdout if process is active
        if ($is_running && $process && is_resource($process)) {
            $line = fgets($pipes[1]);
            if ($line !== false && trim($line) !== '') {
                // Send raw log lines to SSE stream
                echo "data: " . json_encode(rtrim($line)) . "\n\n";
                $data_sent = true;
            }
        }

        if ($data_sent) {
            flush();
        }

        // Send a ping every 2 seconds to keep SSE connection alive
        if (time() - $last_ping > 2) {
            echo ": ping\n\n";
            flush();
            $last_ping = time();
        }

        // sleep 100ms
        usleep(100000);
    }

    // Final clean up
    if ($process && is_resource($process)) {
        @fclose($pipes[0]);
        @fclose($pipes[1]);
        @fclose($pipes[2]);
        @proc_close($process);
    }
    exit;
}

send_json(['success' => false, 'error' => 'Endpoint não encontrado'], 404);
