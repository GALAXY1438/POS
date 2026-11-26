<?php
/**
 * Simple SMTP Mailer
 * Sends emails via SMTP without requiring PHPMailer
 */
class SimpleMailer {
    private $host;
    private $port;
    private $username;
    private $password;
    private $from;
    private $fromName;
    private $encryption;
    private $socket;
    private $log = [];

    public function __construct($config) {
        $this->host = $config['host'] ?? '';
        $this->port = $config['port'] ?? 587;
        $this->username = $config['username'] ?? '';
        $this->password = $config['password'] ?? '';
        $this->from = $config['from'] ?? '';
        $this->fromName = $config['from_name'] ?? 'POS System';
        $this->encryption = $config['encryption'] ?? 'tls';
    }

    public function send($to, $subject, $body) {
        try {
            // Connect to SMTP server
            if (!$this->connect()) {
                return ['success' => false, 'message' => 'Connection failed: ' . implode(', ', $this->log)];
            }

            // Send EHLO
            $this->command("EHLO " . $_SERVER['SERVER_NAME'] ?? 'localhost', 250);

            // Start TLS if needed
            if ($this->encryption === 'tls' && $this->port == 587) {
                $this->command("STARTTLS", 220);
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new Exception('TLS encryption failed');
                }
                // Send EHLO again after STARTTLS
                $this->command("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'), 250);
            }

            // Authenticate
            $this->command("AUTH LOGIN", 334);
            $this->command(base64_encode($this->username), 334);
            $this->command(base64_encode($this->password), 235);

            // Send email
            $this->command("MAIL FROM: <{$this->from}>", 250);
            $this->command("RCPT TO: <{$to}>", 250);
            $this->command("DATA", 354);

            // Email headers and body
            $headers = "From: {$this->fromName} <{$this->from}>\r\n";
            $headers .= "To: <{$to}>\r\n";
            $headers .= "Subject: {$subject}\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $headers .= "\r\n";

            $this->command($headers . $body . "\r\n.", 250);
            $this->command("QUIT", 221);

            fclose($this->socket);
            return ['success' => true, 'message' => 'Email sent successfully'];

        } catch (Exception $e) {
            if ($this->socket) {
                fclose($this->socket);
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function connect() {
        $errno = 0;
        $errstr = '';
        $timeout = 30;

        // SSL connection (port 465)
        if ($this->encryption === 'ssl' && $this->port == 465) {
            $this->socket = @stream_socket_client(
                "ssl://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                $timeout,
                STREAM_CLIENT_CONNECT,
                stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false]])
            );
        } else {
            // Regular or TLS connection (port 587 or 25)
            $this->socket = @stream_socket_client(
                "{$this->host}:{$this->port}",
                $errno,
                $errstr,
                $timeout
            );
        }

        if (!$this->socket) {
            $this->log[] = "Socket error: $errstr ($errno)";
            return false;
        }

        // Read welcome message
        $response = $this->getResponse();
        if (substr($response, 0, 3) != '220') {
            $this->log[] = "Unexpected welcome: $response";
            return false;
        }

        return true;
    }

    private function command($command, $expectedCode) {
        fwrite($this->socket, $command . "\r\n");
        $response = $this->getResponse();
        $code = substr($response, 0, 3);

        $this->log[] = ">>> $command";
        $this->log[] = "<<< $response";

        if ($code != $expectedCode) {
            throw new Exception("Command failed: $command. Response: $response");
        }

        return $response;
    }

    private function getResponse() {
        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) == ' ') {
                break;
            }
        }
        return trim($response);
    }

    public function getLog() {
        return $this->log;
    }
}
