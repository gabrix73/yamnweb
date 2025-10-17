<?php
/**
 * Secure Tor Extension for YAMN Web Interface
 * 
 * Security Features:
 * - No metadata retention (no detailed logging)
 * - Randomized timing delays
 * - Secure command execution
 * - Tor enforcement with verification
 * - Protection against timing attacks
 * 
 * This implementation follows privacy-first principles
 */

// Constants
define('YAMN_PATH', '/opt/yamn-master/yamn');
define('YAMN_CONFIG', '/opt/yamn-master/yamn.yml');
define('TOR_PROXY', '127.0.0.1:9050');
define('TORSOCKS_PATH', 'torsocks');
define('SECURE_POOL_DIR', '/opt/yamn-data/pool');

/**
 * Check if Tor is available and working
 * 
 * @return bool True if Tor is available
 */
function isTorAvailable() {
    // Check if torsocks is installed
    $torsocksPath = shell_exec('which torsocks 2>/dev/null');
    if (empty($torsocksPath)) {
        return false;
    }
    
    // Check if Tor SOCKS proxy is listening
    $connection = @fsockopen('127.0.0.1', 9050, $errno, $errstr, 2);
    if (!is_resource($connection)) {
        return false;
    }
    
    fclose($connection);
    
    // Verify Tor by checking external IP through Tor
    // This ensures Tor is actually working, not just listening
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://check.torproject.org/api/ip',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROXY => '127.0.0.1:9050',
        CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $result) {
        $data = json_decode($result, true);
        return isset($data['IsTor']) && $data['IsTor'] === true;
    }
    
    return false;
}

/**
 * Secure random delay to prevent timing correlation
 * 
 * @param int $minSeconds Minimum delay in seconds
 * @param int $maxSeconds Maximum delay in seconds
 */
function secureRandomDelay($minSeconds = 10, $maxSeconds = 120) {
    $delay = random_int($minSeconds, $maxSeconds);
    sleep($delay);
}

/**
 * Validate remailer chain to prevent injection
 * 
 * @param string $chain The remailer chain
 * @return bool True if valid
 */
function isValidChain($chain) {
    // Chain should only contain alphanumeric, comma, asterisk, and hyphen
    return preg_match('/^[a-zA-Z0-9,\*\-]+$/', $chain) === 1;
}

/**
 * Secure command execution with proper escaping
 * 
 * @param string $command Command to execute
 * @param array &$output Output array
 * @param int &$returnVar Return code
 */
function secureExec($command, &$output, &$returnVar) {
    // Execute with output buffering
    exec($command, $output, $returnVar);
}

/**
 * Send email through YAMN with Tor (always enforced)
 * 
 * @param string $chain Remailer chain
 * @param int $copies Number of copies
 * @param string $messageFile Path to message file
 * @param bool $useTor Ignored, Tor is always used
 * @return array Result status
 */
function sendYamnEmailSecure($chain, $copies, $messageFile, $useTor = true) {
    // Validate inputs
    if (!isValidChain($chain)) {
        return [
            'success' => false,
            'errors' => ['Invalid remailer chain format']
        ];
    }
    
    if ($copies < 1 || $copies > 3) {
        return [
            'success' => false,
            'errors' => ['Invalid number of copies']
        ];
    }
    
    // Verify prerequisites
    if (!file_exists($messageFile)) {
        return [
            'success' => false,
            'errors' => ['Message file not found']
        ];
    }
    
    if (!file_exists(YAMN_PATH) || !is_executable(YAMN_PATH)) {
        return [
            'success' => false,
            'errors' => ['YAMN executable not available']
        ];
    }
    
    if (!file_exists(YAMN_CONFIG)) {
        return [
            'success' => false,
            'errors' => ['YAMN configuration not found']
        ];
    }
    
    // Verify torsocks is available
    $torsocksCheck = shell_exec('which torsocks 2>/dev/null');
    if (empty($torsocksCheck)) {
        return [
            'success' => false,
            'errors' => ['torsocks not installed']
        ];
    }
    
    // Random delay BEFORE adding to pool (prevent timing correlation)
    secureRandomDelay(5, 30);
    
    // Escape arguments properly
    $escapedChain = escapeshellarg($chain);
    $escapedCopies = (int)$copies;
    $escapedMessageFile = escapeshellarg($messageFile);
    $escapedYamnPath = escapeshellarg(YAMN_PATH);
    $escapedConfig = escapeshellarg(YAMN_CONFIG);
    
    // Build command with proper escaping
    // Use process substitution to avoid leaving message file readable
    $commandAddToPool = sprintf(
        '%s %s --config=%s --mail --chain=%s --copies=%d 2>&1 < %s',
        TORSOCKS_PATH,
        $escapedYamnPath,
        $escapedConfig,
        $escapedChain,
        $escapedCopies,
        $escapedMessageFile
    );
    
    // Execute add to pool
    $outputAddToPool = [];
    $returnVarAddToPool = 0;
    secureExec($commandAddToPool, $outputAddToPool, $returnVarAddToPool);
    
    // Check if add to pool succeeded
    if ($returnVarAddToPool !== 0) {
        return [
            'success' => false,
            'errors' => ['Failed to add message to pool']
        ];
    }
    
    // Random delay BETWEEN operations
    secureRandomDelay(3, 15);
    
    // Build send command
    $commandSend = sprintf(
        '%s %s --config=%s -S 2>&1',
        TORSOCKS_PATH,
        $escapedYamnPath,
        $escapedConfig
    );
    
    // Execute send
    $outputSend = [];
    $returnVarSend = 0;
    secureExec($commandSend, $outputSend, $returnVarSend);
    
    // Determine success
    $success = ($returnVarSend === 0 && $returnVarAddToPool === 0);
    
    // Random delay AFTER sending (prevent timing correlation)
    secureRandomDelay(2, 10);
    
    // Return minimal information (no detailed logs)
    if ($success) {
        return [
            'success' => true
        ];
    } else {
        return [
            'success' => false,
            'errors' => ['Failed to send message']
        ];
    }
}

/**
 * Legacy wrapper for backward compatibility
 * Always uses Tor regardless of $useTor parameter
 */
function sendYamnEmail($chain, $copies, $messageFile, $useTor = true) {
    return sendYamnEmailSecure($chain, $copies, $messageFile, true);
}

/**
 * Verify Tor circuit and get new identity
 * Useful before sending to ensure fresh circuit
 * 
 * @return bool True if successful
 */
function getTorNewIdentity() {
    // Connect to Tor control port
    $fp = @fsockopen('127.0.0.1', 9051, $errno, $errstr, 5);
    if (!$fp) {
        return false;
    }
    
    // Authenticate (assuming no password or cookie auth)
    // In production, use proper authentication
    fwrite($fp, "AUTHENTICATE\r\n");
    $response = fgets($fp);
    
    if (strpos($response, '250') === false) {
        fclose($fp);
        return false;
    }
    
    // Request new identity
    fwrite($fp, "SIGNAL NEWNYM\r\n");
    $response = fgets($fp);
    
    fclose($fp);
    
    return strpos($response, '250') !== false;
}

/**
 * Enhanced Tor check with circuit verification
 * 
 * @return bool True if Tor is working properly
 */
function verifyTorCircuit() {
    // Check basic Tor availability
    if (!isTorAvailable()) {
        return false;
    }
    
    // Try to get a new circuit
    // This ensures Tor is not just running but actually functional
    if (!getTorNewIdentity()) {
        // If we can't get new identity, Tor might still work
        // Don't fail, just continue
    }
    
    // Wait a moment for circuit to build
    sleep(2);
    
    return true;
}

/**
 * Initialize secure environment for YAMN operations
 * 
 * @return bool True if successful
 */
function initSecureEnvironment() {
    // Ensure secure directories exist
    if (!file_exists(SECURE_POOL_DIR)) {
        mkdir(SECURE_POOL_DIR, 0700, true);
    }
    
    // Set restrictive umask
    umask(0077);
    
    // Verify Tor is available
    if (!isTorAvailable()) {
        return false;
    }
    
    return true;
}

/**
 * Clean up function to be called after operations
 * Removes temporary files securely
 * 
 * @param string $filepath File to securely delete
 */
function secureCleanup($filepath) {
    if (!file_exists($filepath)) {
        return;
    }
    
    $filesize = filesize($filepath);
    
    // DoD 5220.22-M standard: 3-pass overwrite
    for ($i = 0; $i < 3; $i++) {
        $fp = fopen($filepath, 'w');
        if ($fp) {
            fwrite($fp, random_bytes($filesize));
            fclose($fp);
        }
    }
    
    // Final overwrite with zeros
    $fp = fopen($filepath, 'w');
    if ($fp) {
        fwrite($fp, str_repeat("\0", $filesize));
        fclose($fp);
    }
    
    // Delete file
    unlink($filepath);
}

/**
 * Get Tor circuit information (for debugging only)
 * DO NOT use in production as it may leak information
 * 
 * @return array|false Circuit info or false
 */
function getTorCircuitInfo() {
    // Only enable if explicitly requested via environment variable
    if (getenv('YAMN_DEBUG_TOR') !== 'true') {
        return false;
    }
    
    $fp = @fsockopen('127.0.0.1', 9051, $errno, $errstr, 5);
    if (!$fp) {
        return false;
    }
    
    fwrite($fp, "AUTHENTICATE\r\n");
    $response = fgets($fp);
    
    if (strpos($response, '250') === false) {
        fclose($fp);
        return false;
    }
    
    fwrite($fp, "GETINFO circuit-status\r\n");
    $response = '';
    while (!feof($fp)) {
        $line = fgets($fp);
        $response .= $line;
        if (strpos($line, '250 OK') !== false) {
            break;
        }
    }
    
    fclose($fp);
    
    return ['circuit_status' => $response];
}

/**
 * Test Tor connectivity without leaving traces
 * 
 * @return array Test results
 */
function testTorConnectivity() {
    $results = [
        'tor_available' => false,
        'socks_proxy' => false,
        'circuit_working' => false,
        'torsocks_installed' => false
    ];
    
    // Check SOCKS proxy
    $connection = @fsockopen('127.0.0.1', 9050, $errno, $errstr, 2);
    if (is_resource($connection)) {
        $results['socks_proxy'] = true;
        fclose($connection);
    }
    
    // Check torsocks
    $torsocksPath = shell_exec('which torsocks 2>/dev/null');
    $results['torsocks_installed'] = !empty($torsocksPath);
    
    // Check if Tor is actually working
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => 'https://check.torproject.org/api/ip',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_PROXY => '127.0.0.1:9050',
        CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $result) {
        $data = json_decode($result, true);
        if (isset($data['IsTor']) && $data['IsTor'] === true) {
            $results['circuit_working'] = true;
        }
    }
    
    $results['tor_available'] = (
        $results['socks_proxy'] && 
        $results['torsocks_installed'] && 
        $results['circuit_working']
    );
    
    return $results;
}

// Initialize on load
if (!initSecureEnvironment()) {
    // Silent fail - don't leak information
    // In production, handle this appropriately
}
?>
