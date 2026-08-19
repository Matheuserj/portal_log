<?php
/**
 * Zero-dependency Socket-based SMTP Mailer for PHP
 * Handles authentication and TLS encryption over ports 587/465
 */

require_once __DIR__ . '/config.php';

function send_smtp_email($to, $subject, $message_body) {
    if (!SMTP_ENABLED) {
        error_log("SMTP Mailer Error: SMTP is disabled in config.");
        return false;
    }

    $host = SMTP_HOST;
    $port = SMTP_PORT;
    $user = SMTP_USER;
    $pass = SMTP_PASS;
    $from = SMTP_FROM;
    $from_name = SMTP_FROM_NAME;

    // Helper closure to read socket response and verify expected status code
    $read_response = function($socket, $expected_code) {
        $response = "";
        while ($line = @fgets($socket, 515)) {
            $response .= $line;
            // Response line ending with a space after code means it is the last line of response
            if (substr($line, 3, 1) === " ") {
                break;
            }
        }
        $code = substr($response, 0, 3);
        if ($code !== $expected_code) {
            error_log("SMTP Error: Expected code $expected_code, but received: " . trim($response));
            return false;
        }
        return true;
    };

    // Auto-detect SSL/TLS prefix
    $connection_host = $host;
    if ($port == 465 && strpos($host, 'ssl://') !== 0) {
        $connection_host = 'ssl://' . $host;
    }

    // Disable SSL verification for maximum compatibility inside Docker containers
    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);

    // Connect to host
    $socket = @stream_socket_client($connection_host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log("SMTP Connection failed: $errstr ($errno) on host $connection_host:$port");
        return false;
    }

    // 1. Initial 220 greeting
    if (!$read_response($socket, "220")) { @fclose($socket); return false; }

    // 2. Say EHLO
    @fwrite($socket, "EHLO localhost\r\n");
    if (!$read_response($socket, "250")) { @fclose($socket); return false; }

    // 3. Negotiate TLS (if port 587 and secure is TLS/STARTTLS)
    if (strtolower(SMTP_SECURE) === 'tls' || $port == 587) {
        @fwrite($socket, "STARTTLS\r\n");
        if (!$read_response($socket, "220")) { @fclose($socket); return false; }

        // Enable TLS encryption on the stream (suppress warnings with @)
        if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            error_log("SMTP TLS Error: Failed to secure socket stream using TLS.");
            @fclose($socket);
            return false;
        }

        // Say EHLO again under encrypted tunnel
        @fwrite($socket, "EHLO localhost\r\n");
        if (!$read_response($socket, "250")) { @fclose($socket); return false; }
    }

    // 4. Authenticate
    @fwrite($socket, "AUTH LOGIN\r\n");
    if (!$read_response($socket, "334")) { @fclose($socket); return false; }

    @fwrite($socket, base64_encode($user) . "\r\n");
    if (!$read_response($socket, "334")) { @fclose($socket); return false; }

    @fwrite($socket, base64_encode($pass) . "\r\n");
    if (!$read_response($socket, "235")) { @fclose($socket); return false; }

    // 5. Setup Mail envelopes
    @fwrite($socket, "MAIL FROM:<" . $from . ">\r\n");
    if (!$read_response($socket, "250")) { @fclose($socket); return false; }

    @fwrite($socket, "RCPT TO:<" . $to . ">\r\n");
    if (!$read_response($socket, "250")) { @fclose($socket); return false; }

    // 6. Send Mail Data payload
    @fwrite($socket, "DATA\r\n");
    if (!$read_response($socket, "354")) { @fclose($socket); return false; }

    // Formulate HTML mail
    $headers = "MIME-Version: 1.0\r\n" .
               "Content-type: text/html; charset=utf-8\r\n" .
               "To: <" . $to . ">\r\n" .
               "From: " . $from_name . " <" . $from . ">\r\n" .
               "Subject: " . $subject . "\r\n" .
               "Date: " . date('r') . "\r\n" .
               "X-Mailer: PHP-Socket-SMTP\r\n\r\n";

    @fwrite($socket, $headers . $message_body . "\r\n.\r\n");
    if (!$read_response($socket, "250")) { @fclose($socket); return false; }

    // 7. QUIT
    @fwrite($socket, "QUIT\r\n");
    @fclose($socket);

    return true;
}
