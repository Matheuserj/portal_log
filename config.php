<?php
/**
 * Configuration for Docker Log Portal
 */

// Root directory to scan for docker-compose applications
// If /app does not exist (like on Windows local development), it falls back to a test folder in the workspace.
define('APP_DIR', is_dir('/app') ? '/app' : __DIR__ . '/test_app_dir');

// Email authentication rules
// You can define specific emails, wildcard domains like "*@company.com", or "*" to allow any email.
define('ALLOWED_EMAILS', [
    '*' // Allow all emails by default for easy testing. Change to e.g. '*@yourcompany.com' or 'admin@example.com' to restrict.
]);

// Admin bypass configurations (allows direct access without OTP)
define('ADMIN_USER', 'admin');
define('ADMIN_PASSWORD', 'sua_senha_mestra_aqui');

// One-Time Password (OTP) configurations
define('OTP_EXPIRY_SECONDS', 600); // 10 minutes

// Log file where OTPs will be saved for testing/development.
// Look at this file to find the OTP code if SMTP is not configured.
define('OTP_LOG_FILE', __DIR__ . '/otp.log');

// SMTP Settings (Using Gmail SMTP relay config)
define('SMTP_ENABLED', true);
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'seu-email@empresa.com');
define('SMTP_PASS', 'sua-senha-de-aplicativo-do-gmail');
define('SMTP_SECURE', 'tls');
define('SMTP_FROM', 'seu-email@empresa.com');
define('SMTP_FROM_NAME', 'Docker Log Portal');

// Whitelisted users database file path
define('USERS_DB_FILE', __DIR__ . '/users.json');

// MariaDB Database Configurations
define('DB_HOST', 'db');
define('DB_NAME', 'portal_log');
define('DB_USER', 'portal_log');
define('DB_PASS', 'sua_senha_segura_do_banco');

// Audit log file path
define('AUDIT_LOG_FILE', __DIR__ . '/audit.log');

// Directories to exclude from application scanning
define('EXCLUDE_DIRS', [
    'portal_log_uat'
]);
