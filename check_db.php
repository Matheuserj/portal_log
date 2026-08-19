<?php
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

echo "==================================================\n";
echo "DOCKER PORTAL - DATABASE DIAGNOSTIC CHECK\n";
echo "==================================================\n\n";

echo "1. DATABASE CONFIGURATION:\n";
echo "   Host: " . DB_HOST . "\n";
echo "   Database: " . DB_NAME . "\n";
echo "   User: " . DB_USER . "\n";
echo "   Password: " . (empty(DB_PASS) ? 'EMPTY' : 'SET (Length: ' . strlen(DB_PASS) . ')') . "\n\n";

echo "2. ATTEMPTING CONNECTION:\n";
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5 // 5 seconds timeout
    ]);
    echo "   Success: SIM (Conexão estabelecida com sucesso!)\n\n";
    
    echo "3. CHECKING TABLES:\n";
    $stmt = $pdo->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "   Tables found: " . implode(', ', $tables) . "\n\n";
    
    if (in_array('whitelisted_users', $tables)) {
        echo "4. USER LIST IN DATABASE:\n";
        $stmt = $pdo->query("SELECT * FROM whitelisted_users");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as $u) {
            echo "   - Email: {$u['email']} | Permissão: {$u['role']}\n";
        }
    } else {
        echo "   Warning: Tabela 'whitelisted_users' não encontrada no banco.\n";
    }
} catch (PDOException $e) {
    echo "   Error: " . $e->getMessage() . "\n";
    echo "   Code: " . $e->getCode() . "\n\n";
    echo "Please make sure your 'portal_log_db' container is running and booted successfully.\n";
}
