
<?php
/**
 * Secure Remailer List Downloader
 * - Uses Tor SOCKS5 proxy
 * - Multiple pinger sources with fallback
 * - GPG signature verification
 * - Randomized timing to prevent traffic analysis
 * - Backup and rollback on failure
 * - No metadata leakage
 */

class SecureRemailerDownloader {
    
    // Multiple pinger sources for redundancy (verified active October 2025)
    private $pingers = [
        'https://echolot.virebent.art/mlist2.txt',           // Victor Yamn Pinger (Oct 4, 2025)
        'http://echolot.theremailer.net/yamn/mlist.txt',     // Frell Yamn Pinger (Oct 12, 2025) - Most recent!
        'https://www.mixmin.net/yamn/mlist.txt',             // Mixmin Yamn Pinger (Oct 4, 2025)
        'https://www.haph.org/yamn/mlist.txt'                // Haph Yamn Pinger (Apr 18, 2025)
    ];
    
    // Tor SOCKS5 proxy
    private $torProxy = '127.0.0.1:9050';
    
    // Secure storage paths (outside web root!)
    private $storageDir = '/opt/yamn-data/cache';
    private $remailersFile = '/opt/yamn-data/cache/remailers.txt';
    private $pubringFile = '/opt/yamn-master/pubring.mix';  // Yamn expects it here
    private $backupDir = '/opt/yamn-data/backups';
    private $logFile = '/var/log/yamn_download.log';
    
    // Update interval with jitter (20-28 hours instead of exactly 24)
    private $minUpdateInterval = 72000;  // 20 hours
    private $maxUpdateInterval = 100800; // 28 hours
    
    // GPG verification (if available)
    private $verifyGPG = false;
    private $gpgKeyId = null;
    
    public function __construct() {
        // Create directories if they don't exist
        if (!file_exists($this->storageDir)) {
            mkdir($this->storageDir, 0700, true);
        }
        if (!file_exists($this->backupDir)) {
            mkdir($this->backupDir, 0700, true);
        }
    }
    
    /**
     * Main download function with all security checks
     */
    public function downloadRemailers() {
        // Download both stats and keyring
        $statsResult = $this->downloadStats();
        $keyringResult = $this->downloadKeyring();
        
        return $statsResult && $keyringResult;
    }
    
    /**
     * Download remailer stats (mlist.txt)
     */
    private function downloadStats() {
        // Check if update is needed (with randomized interval)
        if (!$this->needsUpdate($this->remailersFile)) {
            $this->log("Stats update not needed yet");
            return true;
        }
        
        // Add random delay to prevent timing correlation
        $this->randomDelay(5, 60);
        
        // Verify Tor is working
        if (!$this->checkTorConnection()) {
            $this->log("ERROR: Tor connection not available", 'ERROR');
            return false;
        }
        
        // Backup current file
        $this->backupCurrentFile($this->remailersFile, 'remailers');
        
        // Try each pinger source
        $success = false;
        foreach ($this->pingers as $url) {
            $this->log("Attempting stats download from: $url");
            
            // Random delay between attempts
            $this->randomDelay(3, 20);
            
            if ($this->downloadFromSource($url, $this->remailersFile, 'stats')) {
                $success = true;
                break;
            }
        }
        
        if (!$success) {
            $this->log("ERROR: Failed to download stats from all sources", 'ERROR');
            $this->restoreBackup($this->remailersFile, 'remailers');
            return false;
        }
        
        $this->log("Remailer stats updated successfully");
        $this->cleanOldBackups('remailers');
        
        return true;
    }
    
    /**
     * Download public keyring (pubring.mix)
     */
    private function downloadKeyring() {
        // Check if update is needed (with randomized interval)
        if (!$this->needsUpdate($this->pubringFile)) {
            $this->log("Keyring update not needed yet");
            return true;
        }
        
        // Add random delay to prevent timing correlation
        $this->randomDelay(5, 60);
        
        // Verify Tor is working
        if (!$this->checkTorConnection()) {
            $this->log("ERROR: Tor connection not available", 'ERROR');
            return false;
        }
        
        // Backup current file
        $this->backupCurrentFile($this->pubringFile, 'pubring');
        
        // Build pubring URLs
        $pubringUrls = [];
        foreach ($this->pingers as $statsUrl) {
            // Replace mlist.txt or mlist2.txt with pubring.mix
            $pubringUrl = preg_replace('/mlist2?\.txt$/', 'pubring.mix', $statsUrl);
            $pubringUrls[] = $pubringUrl;
        }
        
        // Try each pinger source
        $success = false;
        foreach ($pubringUrls as $url) {
            $this->log("Attempting keyring download from: $url");
            
            // Random delay between attempts
            $this->randomDelay(3, 20);
            
            if ($this->downloadFromSource($url, $this->pubringFile, 'keyring')) {
                $success = true;
                break;
            }
        }
        
        if (!$success) {
            $this->log("ERROR: Failed to download keyring from all sources", 'ERROR');
            $this->restoreBackup($this->pubringFile, 'pubring');
            return false;
        }
        
        $this->log("Public keyring updated successfully");
        $this->cleanOldBackups('pubring');
        
        return true;
    }
    
    /**
     * Check if update is needed with randomized interval
     */
    private function needsUpdate($filepath) {
        if (!file_exists($filepath)) {
            return true;
        }
        
        $fileAge = time() - filemtime($filepath);
        
        // Random threshold between 20-28 hours
        $threshold = mt_rand($this->minUpdateInterval, $this->maxUpdateInterval);
        
        return $fileAge > $threshold;
    }
    
    /**
     * Random delay to prevent timing attacks
     */
    private function randomDelay($minSeconds, $maxSeconds) {
        $delay = mt_rand($minSeconds * 1000000, $maxSeconds * 1000000);
        usleep($delay);
    }
    
    /**
     * Verify Tor is working
     */
    private function checkTorConnection() {
        $context = stream_context_create([
            'http' => [
                'proxy' => "socks5://{$this->torProxy}",
                'request_fulluri' => true,
                'timeout' => 30
            ]
        ]);
        
        try {
            $result = @file_get_contents(
                'https://check.torproject.org/api/ip',
                false,
                $context
            );
            
            if ($result) {
                $data = json_decode($result, true);
                if (isset($data['IsTor']) && $data['IsTor'] === true) {
                    $this->log("Tor connection verified");
                    return true;
                }
            }
        } catch (Exception $e) {
            $this->log("Tor check failed: " . $e->getMessage(), 'ERROR');
        }
        
        return false;
    }
    
    /**
     * Download from a specific source via Tor
     */
    private function downloadFromSource($url, $destinationFile, $type = 'stats') {
        $tempFile = $this->storageDir . '/' . $type . '_' . bin2hex(random_bytes(8)) . '.tmp';
        
        // Configure cURL for Tor SOCKS5 proxy
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => $this->torProxy,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $this->getRandomUserAgent(),
            // Anti-fingerprinting headers
            CURLOPT_HTTPHEADER => [
                'Accept: text/plain,*/*',
                'Accept-Language: en-US,en;q=0.9',
                'Accept-Encoding: gzip, deflate',
                'DNT: 1',
                'Connection: close'
            ]
        ]);
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($content === false || $httpCode !== 200) {
            $this->log("Download failed from $url: $error (HTTP $httpCode)", 'ERROR');
            return false;
        }
        
        // Validate content based on type
        if ($type === 'stats') {
            if (!$this->validateRemailerList($content)) {
                $this->log("Invalid stats content from $url", 'ERROR');
                return false;
            }
        } elseif ($type === 'keyring') {
            if (!$this->validateKeyring($content)) {
                $this->log("Invalid keyring content from $url", 'ERROR');
                return false;
            }
        }
        
        // Write to temp file with secure permissions
        file_put_contents($tempFile, $content);
        chmod($tempFile, 0600);
        
        // Verify GPG signature if available
        if ($this->verifyGPG) {
            if (!$this->verifySignature($tempFile, $url)) {
                unlink($tempFile);
                return false;
            }
        }
        
        // Move to final location
        rename($tempFile, $destinationFile);
        chmod($destinationFile, 0600);
        
        $this->log("Successfully downloaded $type from $url");
        return true;
    }
    
    /**
     * Validate remailer list content
     */
    private function validateRemailerList($content) {
        // Check minimum size
        if (strlen($content) < 100) {
            return false;
        }
        
        // Check for typical remailer list patterns
        $patterns = [
            '/mixmaster/i',
            '/history/i',
            '/latency/i',
            '/uptime/i',
            '/\*{3,}/'  // Stars for reliability
        ];
        
        $matches = 0;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $matches++;
            }
        }
        
        // At least 2 patterns should match
        return $matches >= 2;
    }
    
    /**
     * Validate keyring content (pubring.mix)
     */
    private function validateKeyring($content) {
        // Check minimum size (keyring should be substantial)
        if (strlen($content) < 1000) {
            $this->log("Keyring too small: " . strlen($content) . " bytes", 'ERROR');
            return false;
        }
        
        // Check for PGP/GPG key markers or binary key format
        $validPatterns = [
            '/BEGIN PGP/i',           // PGP format
            '/-----BEGIN/i',          // Generic armored format
            '/remailer-key/i',        // Remailer key marker
        ];
        
        // Also check if it's binary (Mixmaster format)
        // Pubring.mix can be binary or ASCII armored
        $isBinary = !mb_detect_encoding($content, 'ASCII', true);
        
        $hasPattern = false;
        foreach ($validPatterns as $pattern) {
            if (preg_match($pattern, $content)) {
                $hasPattern = true;
                break;
            }
        }
        
        // Valid if either has expected patterns OR is binary
        if (!$hasPattern && !$isBinary) {
            $this->log("Keyring validation failed: no key markers found", 'ERROR');
            return false;
        }
        
        // Additional validation: check for multiple key entries
        // A valid keyring should have multiple remailer keys
        $keyCount = substr_count($content, 'remailer-key') + 
                    substr_count($content, 'BEGIN PGP') +
                    substr_count($content, '-----BEGIN');
        
        if ($keyCount > 0 && $keyCount < 3) {
            $this->log("Keyring has too few keys: $keyCount", 'WARNING');
            // Don't fail, but log warning
        }
        
        $this->log("Keyring validation passed: " . strlen($content) . " bytes, $keyCount keys");
        return true;
    }
    
    /**
     * Verify GPG signature (if enabled)
     */
    private function verifySignature($file, $url) {
        $sigUrl = $url . '.asc';
        $sigFile = $file . '.asc';
        
        // Download signature
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $sigUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROXY => $this->torProxy,
            CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME,
            CURLOPT_TIMEOUT => 60
        ]);
        
        $signature = curl_exec($ch);
        curl_close($ch);
        
        if (!$signature) {
            $this->log("No signature available for $url", 'WARNING');
            return true; // Continue without signature
        }
        
        file_put_contents($sigFile, $signature);
        
        // Verify with GPG
        $output = [];
        $returnVar = 0;
        exec("gpg --verify " . escapeshellarg($sigFile) . " " . escapeshellarg($file) . " 2>&1", $output, $returnVar);
        
        unlink($sigFile);
        
        if ($returnVar === 0) {
            $this->log("GPG signature verified");
            return true;
        } else {
            $this->log("GPG signature verification failed", 'ERROR');
            return false;
        }
    }
    
    /**
     * Backup current file
     */
    private function backupCurrentFile($filepath, $prefix = 'file') {
        if (file_exists($filepath)) {
            $timestamp = date('Ymd_His');
            $backupFile = $this->backupDir . "/{$prefix}_$timestamp.bak";
            copy($filepath, $backupFile);
            chmod($backupFile, 0600);
            $this->log("Backup created: $backupFile");
        }
    }
    
    /**
     * Restore from backup
     */
    private function restoreBackup($filepath, $prefix = 'file') {
        $backups = glob($this->backupDir . "/{$prefix}_*.bak");
        if (empty($backups)) {
            $this->log("No backup available to restore for $prefix", 'ERROR');
            return false;
        }
        
        // Sort by modification time (newest first)
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $latestBackup = $backups[0];
        copy($latestBackup, $filepath);
        $this->log("Restored backup from: $latestBackup");
        
        return true;
    }
    
    /**
     * Clean old backups (keep last 10)
     */
    private function cleanOldBackups($prefix = 'file') {
        $backups = glob($this->backupDir . "/{$prefix}_*.bak");
        if (count($backups) <= 10) {
            return;
        }
        
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        $toDelete = array_slice($backups, 10);
        foreach ($toDelete as $file) {
            unlink($file);
        }
        
        $this->log("Cleaned " . count($toDelete) . " old $prefix backups");
    }
    
    /**
     * Get random user agent to avoid fingerprinting
     */
    private function getRandomUserAgent() {
        $agents = [
            'Mozilla/5.0 (Windows NT 10.0; rv:109.0) Gecko/20100101 Firefox/115.0',
            'Mozilla/5.0 (X11; Linux x86_64; rv:109.0) Gecko/20100101 Firefox/115.0',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:109.0) Gecko/20100101 Firefox/115.0'
        ];
        
        return $agents[array_rand($agents)];
    }
    
    /**
     * Secure logging without metadata leakage
     */
    private function log($message, $level = 'INFO') {
        // Don't log to file if it could leak metadata
        // For production: send to syslog or use in-memory logging
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$level] $message\n";
        
        // Only log to file if explicitly enabled
        if (getenv('YAMN_ENABLE_LOGGING') === 'true') {
            file_put_contents($this->logFile, $logEntry, FILE_APPEND | LOCK_EX);
        }
        
        // For debugging (remove in production)
        if ($level === 'ERROR') {
            error_log($logEntry);
        }
    }
    
    /**
     * Get current remailer list (public method)
     */
    public function getRemailerList() {
        if (!file_exists($this->remailersFile)) {
            $this->downloadStats();
        }
        
        if (file_exists($this->remailersFile)) {
            return file_get_contents($this->remailersFile);
        }
        
        return false;
    }
    
    /**
     * Get current public keyring (public method)
     */
    public function getPublicKeyring() {
        if (!file_exists($this->pubringFile)) {
            $this->downloadKeyring();
        }
        
        if (file_exists($this->pubringFile)) {
            return file_get_contents($this->pubringFile);
        }
        
        return false;
    }
    
    /**
     * Force update of both stats and keyring
     */
    public function forceUpdate() {
        $statsResult = $this->downloadStats();
        $keyringResult = $this->downloadKeyring();
        
        return $statsResult && $keyringResult;
    }
    
    /**
     * Get status of downloaded files
     */
    public function getStatus() {
        $status = [
            'stats' => [
                'exists' => file_exists($this->remailersFile),
                'size' => file_exists($this->remailersFile) ? filesize($this->remailersFile) : 0,
                'age_hours' => file_exists($this->remailersFile) ? 
                    round((time() - filemtime($this->remailersFile)) / 3600, 1) : null,
                'last_modified' => file_exists($this->remailersFile) ? 
                    date('Y-m-d H:i:s', filemtime($this->remailersFile)) : null
            ],
            'keyring' => [
                'exists' => file_exists($this->pubringFile),
                'size' => file_exists($this->pubringFile) ? filesize($this->pubringFile) : 0,
                'age_hours' => file_exists($this->pubringFile) ? 
                    round((time() - filemtime($this->pubringFile)) / 3600, 1) : null,
                'last_modified' => file_exists($this->pubringFile) ? 
                    date('Y-m-d H:i:s', filemtime($this->pubringFile)) : null
            ],
            'tor_available' => $this->checkTorConnection()
        ];
        
        return $status;
    }
}

// Usage
try {
    $downloader = new SecureRemailerDownloader();
    $downloader->downloadRemailers();
} catch (Exception $e) {
    error_log("Remailer download failed: " . $e->getMessage());
}
?>
