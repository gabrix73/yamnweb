<?php
/**
 * Tor Extension for YAMN Web Interface
 * 
 * This file provides functions to enable Tor connectivity for YAMN
 * using torsocks to force all connections through Tor.
 */

// Constants
define('YAMN_PATH', '/opt/yamn-master/yamn');
define('YAMN_CONFIG', '/opt/yamn-master/yamn.yml');
define('TOR_PROXY', '127.0.0.1:9050');  // Default Tor SOCKS proxy
define('TOR_CONFIG_FILE', '/var/www/yamnweb/tor_config.ini');
define('TORSOCKS_PATH', 'torsocks');    // Path to torsocks command (assuming it's in PATH)

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
 * Always returns true since Tor is now mandatory
 * 
 * @param string $to The recipient email address
 * @return bool Always returns true
 */
function shouldUseTor($to) {
    logDebug("Checking if Tor should be used for recipient: $to");
    logDebug("Tor usage is mandatory for all communications");
    return true;
}

/**
 * Configure environment variables for Tor
 * 
 * @return void
 */
function configureTorForYamn() {
    logDebug("Configuring environment for YAMN with Tor (mandatory)");
    
    // Set environment variables that torsocks and YAMN might check for Tor configuration
    putenv("SOCKS_PROXY=" . TOR_PROXY);
    putenv("ALL_PROXY=socks5://" . TOR_PROXY);
    putenv("socks_proxy=" . TOR_PROXY);
    putenv("all_proxy=socks5://" . TOR_PROXY);
    putenv("TORSOCKS_ALLOW_INBOUND=1");  // Allow inbound connections through torsocks
    
    logDebug("Environment variables set for Tor usage");
    
    // Display current environment settings
    logDebug("Current environment settings:");
    logDebug("SOCKS_PROXY: " . getenv("SOCKS_PROXY"), false);
    logDebug("ALL_PROXY: " . getenv("ALL_PROXY"), false);
    logDebug("TORSOCKS_ALLOW_INBOUND: " . getenv("TORSOCKS_ALLOW_INBOUND"), false);
}

/**
 * Send an email through YAMN with Tor routing using torsocks
 * 
 * @param string $chain The remailer chain string
 * @param int $copies Number of copies to send
 * @param string $messageFile Path to the message file
 * @param bool $useTor Parameter kept for compatibility, but ignored (Tor is always used)
 * @return array Array containing success status and output
 */
function sendYamnEmail($chain, $copies, $messageFile, $useTor = true) {
    logDebug("=== STARTING EMAIL SEND PROCESS ===");
    logDebug("Chain: $chain");
    logDebug("Copies: $copies");
    logDebug("Message file: $messageFile");
    logDebug("Using Tor: Yes (mandatory with torsocks)");
    
    // Check if message file exists
    if (!file_exists($messageFile)) {
        logDebug("ERROR: Message file does not exist: $messageFile");
        return [
            'success' => false,
            'tor_used' => true,
            'add_to_pool' => [
                'command' => '',
                'output' => ['Message file not found'],
                'return_var' => 1
            ],
            'send' => [
                'command' => '',
                'output' => ['Skipped due to previous error'],
                'return_var' => 1
            ]
        ];
    }
    
    // Check if YAMN exists and is executable
    if (!file_exists(YAMN_PATH)) {
        logDebug("ERROR: YAMN executable not found at: " . YAMN_PATH);
        return [
            'success' => false,
            'tor_used' => true,
            'add_to_pool' => [
                'command' => '',
                'output' => ['YAMN executable not found'],
                'return_var' => 1
            ],
            'send' => [
                'command' => '',
                'output' => ['Skipped due to previous error'],
                'return_var' => 1
            ]
        ];
    }
    
    if (!is_executable(YAMN_PATH)) {
        logDebug("ERROR: YAMN is not executable: " . YAMN_PATH);
        return [
            'success' => false,
            'tor_used' => true,
            'add_to_pool' => [
                'command' => '',
                'output' => ['YAMN is not executable'],
                'return_var' => 1
            ],
            'send' => [
                'command' => '',
                'output' => ['Skipped due to previous error'],
                'return_var' => 1
            ]
        ];
    }
    
    // Check if YAMN config exists
    if (!file_exists(YAMN_CONFIG)) {
        logDebug("ERROR: YAMN config not found at: " . YAMN_CONFIG);
        return [
            'success' => false,
            'tor_used' => true,
            'add_to_pool' => [
                'command' => '',
                'output' => ['YAMN config not found'],
                'return_var' => 1
            ],
            'send' => [
                'command' => '',
                'output' => ['Skipped due to previous error'],
                'return_var' => 1
            ]
        ];
    }
    
    // Check if torsocks is available
    $torsocks_check = shell_exec('which torsocks 2>/dev/null');
    if (empty($torsocks_check)) {
        logDebug("ERROR: torsocks not found. Cannot route traffic through Tor.");
        return [
            'success' => false,
            'tor_used' => true,
            'add_to_pool' => [
                'command' => '',
                'output' => ['torsocks not installed'],
                'return_var' => 1
            ],
            'send' => [
                'command' => '',
                'output' => ['Skipped due to previous error'],
                'return_var' => 1
            ]
        ];
    }
    
    // Configure environment for torsocks
    configureTorForYamn();
    
    // Build YAMN command to add message to pool with torsocks
    $command_add_to_pool = TORSOCKS_PATH . " " . YAMN_PATH . " " .
                          "--config=" . YAMN_CONFIG . " " .
                          "--mail " .
                          "--chain=\"$chain\" " .
                          "--copies=$copies < $messageFile 2>&1";
    
    // Command to send emails in pool with torsocks
    $command_send = TORSOCKS_PATH . " " . YAMN_PATH . " " .
                   "--config=" . YAMN_CONFIG . " -S 2>&1";
    
    // Log the commands
    logDebug("Add to pool command: $command_add_to_pool");
    
    // Execute add to pool command
    logDebug("Executing add to pool command...");
    exec($command_add_to_pool, $output_add_to_pool, $return_var_add_to_pool);
    
    // Log the output and return code
    logDebug("Add to pool output: " . implode("\n", $output_add_to_pool));
    logDebug("Add to pool return code: $return_var_add_to_pool");
    
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
    
    // Standard log entry
    $log_entry = date('Y-m-d H:i:s') . " | Chain: $chain | Copies: $copies | Tor: Yes | Status: " . 
                 (($return_var_send == 0 && $return_var_add_to_pool == 0) ? "Success" : "Failed") . "\n";
    file_put_contents('/var/www/yamnweb/yamn_send.log', $log_entry, FILE_APPEND);
    
    // Success status
    $success = ($return_var_send == 0 && $return_var_add_to_pool == 0);
    logDebug("Operation " . ($success ? "SUCCEEDED" : "FAILED"));
    logDebug("=== EMAIL SEND PROCESS COMPLETE ===");
    
    return [
        'success' => $success,
        'tor_used' => true,
        'add_to_pool' => [
            'command' => $command_add_to_pool,
            'output' => $output_add_to_pool,
            'return_var' => $return_var_add_to_pool
        ],
        'send' => [
            'command' => $command_send,
            'output' => $output_send,
            'return_var' => $return_var_send
        ]
    ];
}

/**
 * Alternative version that uses proxychains4 instead of torsocks
 * Uncomment and modify the above function if you prefer proxychains4
 */
/*
function sendYamnEmail($chain, $copies, $messageFile, $useTor = true) {
    // Configuration similar to above, but using proxychains4
    // ...
    
    // Build YAMN command with proxychains4
    $command_add_to_pool = "proxychains4 -q " . YAMN_PATH . " " .
                          "--config=" . YAMN_CONFIG . " " .
                          "--mail " .
                          "--chain=\"$chain\" " .
                          "--copies=$copies < $messageFile 2>&1";
    
    $command_send = "proxychains4 -q " . YAMN_PATH . " " .
                   "--config=" . YAMN_CONFIG . " -S 2>&1";
    
    // Rest of the function is the same
    // ...
}
*/
?>
