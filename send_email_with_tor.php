<?php
/**
 * Secure YAMN Email Sender
 * Implements all security requirements:
 * - No metadata retention
 * - Replay protection
 * - Adaptive padding
 * - Randomized delays
 * - Secure file handling
 * - Tor enforcement
 */

// Disable error display in production
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(0);

require_once 'download_remailers_secure.php';
require_once 'tor_extension_secure.php';

class SecureYamnSender {
    
    private $secureDir = '/opt/yamn-data/pool';
    private $replayCache = '/opt/yamn-data/cache/replay_cache.db';
    private $replayCacheTTL = 604800; // 7 days
    
    // Padding sizes (in bytes)
    private $paddingSizes = [512, 1024, 2048, 4096, 8192, 16384, 32768];
    
    // Delay ranges (seconds)
    private $minDelay = 10;
    private $maxDelay = 120;
    
    public function __construct() {
        // Ensure secure directories exist
        if (!file_exists($this->secureDir)) {
            mkdir($this->secureDir, 0700, true);
        }
        
        // Initialize replay cache DB
        $this->initReplayCache();
    }
    
    /**
     * Main send function
     */
    public function sendEmail($postData) {
        // Extract and validate input (no storage of raw data)
        $validated = $this->validateInput($postData);
        if ($validated['errors']) {
            return ['success' => false, 'errors' => $validated['errors']];
        }
        
        $data = $validated['data'];
        
        // Generate message-ID for replay protection
        $messageId = $this->generateMessageId($data);
        
        // Check for replay attack
        if ($this->isReplay($messageId)) {
            return [
                'success' => false,
                'errors' => ['This message has already been sent recently']
            ];
        }
        
        // Build message content
        $messageContent = $this->buildMessage($data);
        
        // Apply adaptive padding
        $paddedMessage = $this->applyAdaptivePadding($messageContent);
        
        // Create secure temporary file
        $messageFile = $this->createSecureMessageFile($paddedMessage);
        
        if (!$messageFile) {
            return [
                'success' => false,
                'errors' => ['Failed to create secure message file']
            ];
        }
        
        // Random delay to prevent timing correlation
        $this->randomDelay();
        
        // Build chain
        $chain = implode(',', [
            $data['entry_remailer'],
            $data['middle_remailer'],
            $data['exit_remailer']
        ]);
        
        // Send via YAMN with Tor (always enforced)
        try {
            $result = sendYamnEmailSecure(
                $chain,
                $data['copies'],
                $messageFile,
                true // Force Tor
            );
            
            // Store message-ID in replay cache only if successful
            if ($result['success']) {
                $this->storeMessageId($messageId);
            }
            
            return $result;
            
        } catch (Exception $e) {
            // No detailed error logging
            return [
                'success' => false,
                'errors' => ['Failed to send message']
            ];
        } finally {
            // ALWAYS secure delete the file
            $this->secureDeleteFile($messageFile);
        }
    }
    
    /**
     * Validate input without storing sensitive data
     */
    private function validateInput($post) {
        $errors = [];
        $data = [];
        
        // Required fields
        $required = ['to', 'from', 'subject', 'entry_remailer', 'middle_remailer', 'exit_remailer'];
        foreach ($required as $field) {
            if (empty($post[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . " is required";
            } else {
                $data[$field] = $post[$field];
            }
        }
        
        // Optional fields
        $optional = ['reply_to', 'newsgroups', 'references', 'data'];
        foreach ($optional as $field) {
            $data[$field] = isset($post[$field]) ? $post[$field] : '';
        }
        
        // Validate copies
        $copies = isset($post['copies']) ? intval($post['copies']) : 1;
        if ($copies < 1 || $copies > 3) {
            $errors[] = "Number of copies must be between 1 and 3";
        }
        $data['copies'] = $copies;
        
        // Check Tor availability
        if (!isTorAvailable()) {
            $errors[] = "Tor is required but not available";
        }
        
        // Email validation
        if (!empty($data['to']) && !filter_var($data['to'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid recipient email address";
        }
        
        if (!empty($data['from']) && !filter_var($data['from'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Invalid sender email address";
        }
        
        return [
            'errors' => $errors,
            'data' => $data
        ];
    }
    
    /**
     * Generate deterministic message-ID for replay detection
     */
    private function generateMessageId($data) {
        // Use hash of critical fields (not entire message to allow minor variations)
        $critical = $data['to'] . '|' . 
                   $data['from'] . '|' . 
                   $data['subject'] . '|' . 
                   substr($data['data'], 0, 100); // First 100 chars of body
        
        return hash('sha256', $critical);
    }
    
    /**
     * Initialize SQLite DB for replay protection
     */
    private function initReplayCache() {
        try {
            $db = new SQLite3($this->replayCache);
            $db->exec('CREATE TABLE IF NOT EXISTS message_cache (
                message_id TEXT PRIMARY KEY,
                timestamp INTEGER NOT NULL
            )');
            
            // Create index for faster lookups
            $db->exec('CREATE INDEX IF NOT EXISTS idx_timestamp ON message_cache(timestamp)');
            
            $db->close();
        } catch (Exception $e) {
            // Fail silently - replay protection optional
        }
    }
    
    /**
     * Check if message is a replay
     */
    private function isReplay($messageId) {
        try {
            $db = new SQLite3($this->replayCache);
            
            // Clean old entries
            $expiration = time() - $this->replayCacheTTL;
            $db->exec("DELETE FROM message_cache WHERE timestamp < $expiration");
            
            // Check if message-ID exists
            $stmt = $db->prepare('SELECT message_id FROM message_cache WHERE message_id = :id');
            $stmt->bindValue(':id', $messageId, SQLITE3_TEXT);
            $result = $stmt->execute();
            
            $exists = $result->fetchArray() !== false;
            
            $db->close();
            return $exists;
            
        } catch (Exception $e) {
            // If cache fails, allow message (better than blocking legitimate use)
            return false;
        }
    }
    
    /**
     * Store message-ID in cache
     */
    private function storeMessageId($messageId) {
        try {
            $db = new SQLite3($this->replayCache);
            
            $stmt = $db->prepare('INSERT OR IGNORE INTO message_cache (message_id, timestamp) VALUES (:id, :ts)');
            $stmt->bindValue(':id', $messageId, SQLITE3_TEXT);
            $stmt->bindValue(':ts', time(), SQLITE3_INTEGER);
            $stmt->execute();
            
            $db->close();
        } catch (Exception $e) {
            // Fail silently
        }
    }
    
    /**
     * Build message with proper headers
     */
    private function buildMessage($data) {
        $headers = "Content-Type: text/plain; charset=utf-8\n";
        $headers .= "Content-Transfer-Encoding: 8bit\n";
        $headers .= "MIME-Version: 1.0\n";
        
        if (!empty($data['references'])) {
            $headers .= "References: {$data['references']}\n";
        }
        
        $message = $headers . "From: {$data['from']}\n";
        
        if (!empty($data['reply_to'])) {
            $message .= "Reply-To: {$data['reply_to']}\n";
        }
        
        $message .= "To: {$data['to']}\n";
        $message .= "Subject: {$data['subject']}\n";
        
        if (!empty($data['newsgroups'])) {
            $message .= "Newsgroups: {$data['newsgroups']}\n";
        }
        
        $message .= "\n{$data['data']}";
        
        return $message;
    }
    
    /**
     * Apply adaptive padding to prevent size correlation
     */
    private function applyAdaptivePadding($message) {
        $currentSize = strlen($message);
        
        // Find next padding size
        $targetSize = $currentSize;
        foreach ($this->paddingSizes as $size) {
            if ($size >= $currentSize) {
                $targetSize = $size;
                break;
            }
        }
        
        // If message is larger than max padding size, round up to next 32KB
        if ($currentSize >= max($this->paddingSizes)) {
            $targetSize = ceil($currentSize / 32768) * 32768;
        }
        
        // Generate random padding
        $paddingSize = $targetSize - $currentSize;
        if ($paddingSize > 0) {
            // Use printable random characters for padding
            $padding = "\n\n--- Padding ---\n";
            $padding .= base64_encode(random_bytes($paddingSize - strlen($padding)));
            $message .= $padding;
        }
        
        return $message;
    }
    
    /**
     * Create secure temporary file
     */
    private function createSecureMessageFile($content) {
        // Generate cryptographically secure random filename
        $filename = bin2hex(random_bytes(32)) . '.tmp';
        $filepath = $this->secureDir . '/' . $filename;
        
        // Write with exclusive lock and restrictive permissions
        $fp = fopen($filepath, 'w');
        if (!$fp) {
            return false;
        }
        
        flock($fp, LOCK_EX);
        fwrite($fp, $content);
        flock($fp, LOCK_UN);
        fclose($fp);
        
        // Set restrictive permissions
        chmod($filepath, 0600);
        
        return $filepath;
    }
    
    /**
     * Random delay to prevent timing attacks
     */
    private function randomDelay() {
        $delay = random_int($this->minDelay, $this->maxDelay);
        sleep($delay);
    }
    
    /**
     * Secure file deletion (DoD 5220.22-M standard)
     */
    private function secureDeleteFile($filepath) {
        if (!file_exists($filepath)) {
            return;
        }
        
        $filesize = filesize($filepath);
        
        // Overwrite with random data 3 times
        for ($i = 0; $i < 3; $i++) {
            $fp = fopen($filepath, 'w');
            if ($fp) {
                fwrite($fp, random_bytes($filesize));
                fclose($fp);
            }
        }
        
        // Overwrite with zeros
        $fp = fopen($filepath, 'w');
        if ($fp) {
            fwrite($fp, str_repeat("\0", $filesize));
            fclose($fp);
        }
        
        // Finally delete
        unlink($filepath);
    }
    
    /**
     * Render success response (minimal info)
     */
    public function renderSuccess() {
        return "<!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Message Queued</title>
            <link rel='stylesheet' href='styles.css'>
        </head>
        <body>
            <div class='success-message'>
                <h2>Message queued successfully</h2>
                <p>Your message has been added to the anonymous sending queue.</p>
                <a href='index.php' class='button'>Return</a>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Render error response (minimal info)
     */
    public function renderError($errors) {
        $errorList = '';
        foreach ($errors as $error) {
            $errorList .= "<li>" . htmlspecialchars($error) . "</li>";
        }
        
        return "<!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Error</title>
            <link rel='stylesheet' href='styles.css'>
        </head>
        <body>
            <div class='error-message'>
                <h2>Unable to process request</h2>
                <ul>$errorList</ul>
                <a href='javascript:history.back()' class='button'>Back</a>
            </div>
        </body>
        </html>";
    }
}

// Main execution
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: index.php");
    exit;
}

try {
    $sender = new SecureYamnSender();
    $result = $sender->sendEmail($_POST);
    
    if ($result['success']) {
        echo $sender->renderSuccess();
    } else {
        echo $sender->renderError($result['errors']);
    }
    
} catch (Exception $e) {
    // Generic error - no details leaked
    echo (new SecureYamnSender())->renderError(['An error occurred. Please try again.']);
}
?>
