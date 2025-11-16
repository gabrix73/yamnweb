<?php
/**
 * Tor Extension for YAMN Web Interface
 * Handles Tor integration for anonymous remailer network
 */

// Constants
define('YAMN_PATH', '/opt/yamn-master/yamn');
define('YAMN_CONFIG', '/opt/yamn-master/yamn.cfg');
define('TOR_PROXY', '127.0.0.1:9050');
define('TOR_CONFIG_FILE', '/var/www/yamnweb/tor_config.ini');
define('TORSOCKS_PATH', 'torsocks');

// SMTP Onion Relays - Primary and Fallback
define('SMTP_RELAYS', [
    [
        'host' => '4uwpi53u524xdphjw2dv5kywsxmyjxtk4facb76jgl3sc3nda3sz4fqd.onion',
        'port' => 25,
        'name' => 'Primary Onion SMTP'
    ],
    [
        'host' => 'xilb7y4kj6u6qfo45o3yk2kilfv54ffukzei3puonuqlncy7cn2afwyd.onion',
        'port' => 25,
        'name' => 'Fallback Onion SMTP'
    ]
]);

/**
 * Debug logger function
 *
 * @param string $message Message to log
 * @param bool $include_timestamp Whether to include timestamp
 * @return void
 */
function logDebug($message, $include_timestamp = true) {
    $log_file = '/var/www/yamnweb/debug.log';
    $log_entry = ($include_timestamp ? date('Y-m-d H:i:s') . " - " : "") . $message . "\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

/**
 * Check if Tor is available on the system
 *
 * @return bool True if Tor service is available
 */
function isTorAvailable() {
    logDebug("Checking if Tor is available...");

    // Check if torsocks is installed
    $torsocks_check = shell_exec('which torsocks 2>&1');
    if (empty($torsocks_check) || strpos($torsocks_check, 'no torsocks') !== false) {
        logDebug("WARNING: torsocks not found in PATH. Tor routing may not work correctly.");
    } else {
        logDebug("torsocks found: " . trim($torsocks_check));
    }

    // Check if Tor SOCKS proxy is listening
    $connection = @fsockopen('127.0.0.1', 9050, $errno, $errstr, 1);
    if (is_resource($connection)) {
        fclose($connection);
        logDebug("Tor is available: SOCKS proxy connection successful");
        return true;
    }

    logDebug("SOCKS proxy connection failed: $errno - $errstr");

    // Alternative: Check if Tor service is running
    $tor_running = shell_exec('pgrep -f "tor" | wc -l');
    if (intval($tor_running) > 0) {
        logDebug("Tor is available: Tor process is running");
        return true;
    }

    logDebug("Tor is NOT available: No Tor process found");
    return false;
}

/**
 * Check if an email address belongs to an .onion domain
 *
 * @param string $email The email address to check
 * @return bool True if the email is for an .onion domain
 */
function isOnionEmail($email) {
    // Extract domain from email address
    if (preg_match('/@([^>]+)/', $email, $matches)) {
        $domain = $matches[1];
        $is_onion = (strpos($domain, '.onion') !== false);
        logDebug("Email domain check: $domain - " . ($is_onion ? "is .onion" : "is NOT .onion"));
        return $is_onion;
    }
    logDebug("No domain found in email: $email");
    return false;
}

/**
 * Determine if Tor should be used for the current email
 *
 * @param string $to The recipient email address
 * @return bool True if Tor should be used
 */
function shouldUseTor($to) {
    logDebug("Checking if Tor should be used for recipient: $to");

    // Use Tor if the destination is an .onion address
    if (isOnionEmail($to)) {
        logDebug("Tor will be used: .onion address detected");
        return true;
    }

    // Use Tor if forced by configuration file
    if (file_exists(TOR_CONFIG_FILE)) {
        logDebug("Config file exists: " . TOR_CONFIG_FILE);
        $config = parse_ini_file(TOR_CONFIG_FILE, true);
        if (isset($config['tor']) && isset($config['tor']['force_tor'])) {
            $force_tor = ($config['tor']['force_tor'] === 'true' || $config['tor']['force_tor'] === '1');
            logDebug("Config file force_tor setting: " . ($force_tor ? "true" : "false"));
            return $force_tor;
        }
    }

    // Check environment variables
    $force_tor_env = getenv('YAMN_TOR_FORCE');
    if ($force_tor_env) {
        $result = ($force_tor_env === 'true' || $force_tor_env === '1');
        logDebug("Environment variable YAMN_TOR_FORCE: " . ($result ? "true" : "false"));
        return $result;
    }

    logDebug("Tor will NOT be used: No .onion address or force settings");
    return false;
}

/**
 * Configure Tor environment settings for YAMN
 *
 * @param bool $useTor Whether to force Tor usage
 * @return void
 */
function configureTorForYamn($useTor = false) {
    if ($useTor) {
        logDebug("Configuring Tor environment variables");

        // Set SOCKS proxy environment variables
        putenv("SOCKS_PROXY=" . TOR_PROXY);
        putenv("ALL_PROXY=socks5://" . TOR_PROXY);
        putenv("socks_proxy=" . TOR_PROXY);
        putenv("all_proxy=socks5://" . TOR_PROXY);
        putenv("YAMN_USE_TOR=1");
        putenv("YAMN_TOR_ENABLED=true");
        putenv("YAMN_TOR_FORCE=true");

        logDebug("Tor environment configured with proxy: " . TOR_PROXY);
    } else {
        logDebug("Clearing Tor environment variables");

        // Clear environment variables when not using Tor
        putenv("SOCKS_PROXY=");
        putenv("ALL_PROXY=");
        putenv("socks_proxy=");
        putenv("all_proxy=");
        putenv("YAMN_USE_TOR=0");
        putenv("YAMN_TOR_ENABLED=false");
        putenv("YAMN_TOR_FORCE=false");
    }
}

/**
 * Test SMTP relay connectivity through Tor
 *
 * @param string $host SMTP relay hostname
 * @param int $port SMTP port
 * @return bool True if relay is reachable
 */
function testSmtpRelay($host, $port) {
    logDebug("Testing SMTP relay: $host:$port");

    // Use torsocks to test connection through Tor
    $command = "timeout 10 torsocks nc -zv $host $port 2>&1";
    exec($command, $output, $return_var);

    $success = ($return_var == 0);
    logDebug("SMTP relay test result: " . ($success ? "SUCCESS" : "FAILED"));
    logDebug("Test output: " . implode(" ", $output));

    return $success;
}

/**
 * Update YAMN config with specific SMTP relay
 * VERSIONE DISABILITATA - Non modifica il file, usa configurazione statica
 *
 * @param string $host SMTP relay hostname
 * @param int $port SMTP port
 * @return bool True if update successful
 */
function updateYamnConfig($host, $port) {
    logDebug("Config update DISABLED - using static configuration");
    logDebug("Requested relay: $host:$port (will be ignored, using config file as-is)");

    // Verifica solo che il file esista
    if (!file_exists(YAMN_CONFIG)) {
        logDebug("ERROR: Config file not found: " . YAMN_CONFIG);
        return false;
    }

    logDebug("Config file exists, skipping dynamic update");
    return true;
}

/**
 * Send an email through YAMN with optional Tor routing and automatic fallback
 *
 * @param string $chain The remailer chain string (e.g., "entry,middle,exit")
 * @param int $copies Number of copies to send (1-3)
 * @param string $messageFile Path to the message file
 * @param bool $useTor Whether to force Tor usage
 * @return array Array containing success status, error message, and output
 */
function sendYamnEmail($chain, $copies, $messageFile, $useTor = false) {
    logDebug("=== Starting sendYamnEmail (Static Config Mode) ===");
    logDebug("Chain: $chain");
    logDebug("Copies: $copies");
    logDebug("Message file: $messageFile");
    logDebug("Use Tor: " . ($useTor ? "YES" : "NO"));

    // Configure environment for YAMN to use Tor if needed
    configureTorForYamn($useTor);

    // Check if message file exists
    if (!file_exists($messageFile)) {
        $error = "Message file does not exist: $messageFile";
        logDebug("ERROR: $error");
        return [
            'success' => false,
            'error' => $error,
            'relay_used' => null,
            'output' => []
        ];
    }

    // Verifica che il config file esista
    if (!file_exists(YAMN_CONFIG)) {
        $error = "Config file does not exist: " . YAMN_CONFIG;
        logDebug("ERROR: $error");
        return [
            'success' => false,
            'error' => $error,
            'relay_used' => null,
            'output' => []
        ];
    }

    // Build YAMN command to add message to pool
    $command_add_to_pool = YAMN_PATH . " " .
                          "--config=" . YAMN_CONFIG . " " .
                          "--mail " .
                          "--chain=\"$chain\" " .
                          "--copies=$copies < " . escapeshellarg($messageFile) . " 2>&1";

    // Command to send emails in pool
    $command_send = YAMN_PATH . " " .
                   "--config=" . YAMN_CONFIG . " -S 2>&1";

    // Log the commands
    logDebug("Add to pool command: $command_add_to_pool");

    // Execute add to pool command
    logDebug("Executing add to pool command...");
    exec($command_add_to_pool, $output_add_to_pool, $return_var_add_to_pool);

    // Log the output and return code
    logDebug("Add to pool output: " . implode("\n", $output_add_to_pool));
    logDebug("Add to pool return code: $return_var_add_to_pool");

    // Initialize send variables
    $output_send = [];
    $return_var_send = -1;

    // Only execute send command if add to pool was successful
    if ($return_var_add_to_pool == 0) {
        logDebug("Send command: $command_send");
        logDebug("Executing send command...");
        exec($command_send, $output_send, $return_var_send);
        logDebug("Send output: " . implode("\n", $output_send));
        logDebug("Send return code: $return_var_send");
    } else {
        logDebug("Skipping send command due to add to pool failure");
        $output_send = ["Skipped - add to pool command failed"];
        $return_var_send = -1;
    }

    // Check if succeeded
    $success = ($return_var_send == 0 && $return_var_add_to_pool == 0);

    if ($success) {
        // SUCCESS! Log and return
        $log_entry = date('Y-m-d H:i:s') . " | SUCCESS | Chain: $chain | Copies: $copies | Tor: " .
                     ($useTor ? "Yes" : "No") . "\n";
        file_put_contents('/var/www/yamnweb/yamn_send.log', $log_entry, FILE_APPEND);

        logDebug("✓ SUCCESS");
        logDebug("=== End sendYamnEmail ===");

        return [
            'success' => true,
            'error' => '',
            'relay_used' => 'Static Config',
            'tor_used' => $useTor,
            'outputs' => [
                'add_to_pool' => $output_add_to_pool,
                'send' => $output_send
            ]
        ];
    } else {
        // Failed
        $lastError = "Failed to send message";
        if ($return_var_add_to_pool != 0) {
            $lastError = "Failed to add message to pool: " . implode(" ", $output_add_to_pool);
        } elseif ($return_var_send != 0) {
            $lastError = "Failed to send from pool: " . implode(" ", $output_send);
        }

        $log_entry = date('Y-m-d H:i:s') . " | FAILED | Chain: $chain | Copies: $copies | Tor: " .
                     ($useTor ? "Yes" : "No") . " | Error: $lastError\n";
        file_put_contents('/var/www/yamnweb/yamn_send.log', $log_entry, FILE_APPEND);

        logDebug("✗ FAILED: $lastError");
        logDebug("=== End sendYamnEmail ===");

        return [
            'success' => false,
            'error' => $lastError,
            'relay_used' => null,
            'tor_used' => $useTor,
            'outputs' => [
                'add_to_pool' => $output_add_to_pool,
                'send' => $output_send
            ]
        ];
    }
}
