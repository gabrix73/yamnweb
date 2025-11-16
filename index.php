<?php
// yamnweb - YAMN Mixmaster Network Web Interface
// Secure anonymous email interface with Tor integration

// Enable error logging (disable display for security)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// ini_set('error_log', '/var/log/php/yamnweb_errors.log'); // Uncomment if specific log needed

// PHP 8.1+ compatibility: FILTER_SANITIZE_STRING is deprecated
if (!defined('FILTER_SANITIZE_STRING')) {
    define('FILTER_SANITIZE_STRING', 513);
}

// Configure session BEFORE starting it (critical for cookie-based sessions)
ini_set('session.cookie_httponly', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_secure', '0');  // Set to '1' if using HTTPS
ini_set('session.cookie_samesite', 'Lax'); // Changed from 'Strict' to allow form submissions
ini_set('session.gc_maxlifetime', 7200); // 2 hours session lifetime

session_start();

// Ensure session is actually working
if (!isset($_SESSION)) {
    die("Error: Session could not be initialized. Check PHP session configuration.");
}

// Test if session is writable
if (!isset($_SESSION['session_test'])) {
    $_SESSION['session_test'] = 'working';
}

// Verify session persists
if ($_SESSION['session_test'] !== 'working') {
    die("Error: Session is not persisting. Check session.save_path permissions.");
}

// Load optional dependencies (don't fail if missing)
if (file_exists('download_remailers.php')) {
    require_once 'download_remailers.php';
} else {
    error_log("Warning: download_remailers.php not found");
}

if (file_exists('tor_extension.php')) {
    require_once 'tor_extension.php';
} else {
    error_log("Warning: tor_extension.php not found - Tor integration disabled");
}

// Theme management via cookie
$theme = 'dark'; // Default
if (isset($_COOKIE['yamn_theme'])) {
    $theme = $_COOKIE['yamn_theme'] === 'light' ? 'light' : 'dark';
}

// Handle theme switch
if (isset($_GET['theme']) && in_array($_GET['theme'], ['light', 'dark'])) {
    $theme = $_GET['theme'];
    setcookie('yamn_theme', $theme, time() + (365 * 24 * 60 * 60), '/', '', true, true);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Security headers
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline';");

// NO CACHE headers - prevent browser from caching form with old CSRF token
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT"); // Date in the past

// CSRF token generation - IMPROVED with refresh after use
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Store current token for validation BEFORE any regeneration
$currentCsrfToken = $_SESSION['csrf_token'];

/**
 * Parse remailers from file and return array by type
 * Entry and Exit can use ANY remailer
 * Middle should use remailers with specific flags
 *
 * @param string $type 'entry', 'middle', or 'exit'
 * @return array Array of remailer names
 */
function getRemailers($type) {
    $remailers = ['*']; // Always include Random option

    // Try multiple file locations
    $files = [
        '/opt/yamn-data/cache/remailers.txt',
        '/var/www/yamnweb/remailers.txt'
    ];

    foreach ($files as $file) {
        if (!file_exists($file)) continue;

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $inDataSection = false;

        foreach ($lines as $line) {
            $line = trim($line);

            // Start parsing after separator line (--------)
            if (preg_match('/^-{10,}/', $line)) {
                $inDataSection = true;
                continue;
            }

            // Stop at Remailer-Capabilities section
            if (stripos($line, 'Remailer-Capabilities') !== false) {
                break;
            }

            // Only parse lines after the separator
            if (!$inDataSection) continue;

            // Skip empty lines
            if (empty($line)) continue;

            // Split line: name latency uptime [flags]
            // Example: "middleman    211112111211    :45   ++++++++++++  100.0%  D"
            $parts = preg_split('/\s+/', $line);

            // Must have at least name + latency
            if (count($parts) < 2) continue;

            // Extract ONLY the remailer name (first field)
            $remailerName = $parts[0];

            // Validate name: lowercase letters, numbers, hyphens only
            if (!preg_match('/^[a-z0-9-]+$/', $remailerName)) continue;

            // Check if last field is 'D' (middle capability flag)
            $lastField = end($parts);
            $isMiddleCapable = ($lastField === 'D');

            // LOGICA CORRETTA: Mutuamente esclusivi
            if ($isMiddleCapable) {
                // Remailers CON 'D' = SOLO middle
                if ($type === 'middle') {
                    $remailers[] = $remailerName;
                }
            } else {
                // Remailers SENZA 'D' = SOLO entry/exit
                if ($type === 'entry' || $type === 'exit') {
                    $remailers[] = $remailerName;
                }
            }
        }
        break; // Use first file found
    }

    return array_unique($remailers);
}

/**
 * Replace asterisk (*) with random remailer from available list
 * @param string $remailer Selected remailer (may be "*")
 * @param array $availableRemailers List of available remailers
 * @return string Actual remailer name
 */
function resolveRemailer($remailer, $availableRemailers) {
    if ($remailer === '*') {
        // Filter out the asterisk itself from candidates
        $candidates = array_filter($availableRemailers, function($r) {
            return $r !== '*';
        });

        if (empty($candidates)) {
            throw new Exception("No remailers available for random selection");
        }

        // Pick random remailer
        $randomIndex = array_rand($candidates);
        return $candidates[$randomIndex];
    }
    return $remailer;
}

// Load available remailers
$entryRemailers = getRemailers('entry');
$middleRemailers = getRemailers('middle');
$exitRemailers = getRemailers('exit');

// If no remailers loaded, try to download them
if (count($entryRemailers) <= 1 && class_exists('SecureRemailerDownloader')) {
    try {
        $downloader = new SecureRemailerDownloader();
        $downloader->downloadRemailers();
        // Reload after download
        $entryRemailers = getRemailers('entry');
        $middleRemailers = getRemailers('middle');
        $exitRemailers = getRemailers('exit');
    } catch (Exception $e) {
        error_log("Failed to download remailers: " . $e->getMessage());
    }
}

$message = '';
$messageType = '';

// Check for flash messages from previous redirect (PRG pattern)
if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    $messageType = isset($_SESSION['flash_type']) ? $_SESSION['flash_type'] : 'info';

    // Clear flash messages after displaying
    unset($_SESSION['flash_message']);
    unset($_SESSION['flash_type']);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // CSRF validation - IMPROVED with better error handling
    if (!isset($_POST['csrf_token'])) {
        // Save error in session and redirect
        $_SESSION['flash_message'] = "Security token missing. Please reload the page and try again.";
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } elseif ($_POST['csrf_token'] !== $currentCsrfToken) {
        // DEBUG: Log the mismatch for troubleshooting
        error_log("CSRF Mismatch - POST: " . substr($_POST['csrf_token'], 0, 10) . "... SESSION: " . substr($currentCsrfToken, 0, 10) . "...");

        // Save error in session and redirect
        $_SESSION['flash_message'] = "Security token mismatch. Please try submitting again.";
        $_SESSION['flash_type'] = 'error';
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } else {
        try {
            // Get and sanitize form data with additional validation
            $entryRemailer = isset($_POST['entry_remailer']) ? filter_var($_POST['entry_remailer'], FILTER_SANITIZE_STRING) : '';
            $middleRemailer = isset($_POST['middle_remailer']) ? filter_var($_POST['middle_remailer'], FILTER_SANITIZE_STRING) : '';
            $exitRemailer = isset($_POST['exit_remailer']) ? filter_var($_POST['exit_remailer'], FILTER_SANITIZE_STRING) : '';
            $from = isset($_POST['from']) ? filter_var($_POST['from'], FILTER_SANITIZE_STRING) : '';
            $replyTo = isset($_POST['reply_to']) ? filter_var($_POST['reply_to'], FILTER_SANITIZE_STRING) : '';
            $to = isset($_POST['to']) ? filter_var($_POST['to'], FILTER_SANITIZE_EMAIL) : '';
            $subject = isset($_POST['subject']) ? filter_var($_POST['subject'], FILTER_SANITIZE_STRING) : '';
            $newsgroups = isset($_POST['newsgroups']) ? filter_var($_POST['newsgroups'], FILTER_SANITIZE_STRING) : '';
            $references = isset($_POST['references']) ? filter_var($_POST['references'], FILTER_SANITIZE_STRING) : '';
            $data = isset($_POST['data']) ? $_POST['data'] : ''; // Keep original formatting
            $copies = isset($_POST['copies']) ? intval($_POST['copies']) : 1;

            // Validate copies
            if ($copies < 1 || $copies > 3) {
                throw new Exception("Number of copies must be between 1 and 3.");
            }

            // Validate required fields
            if (empty($to)) {
                throw new Exception("Recipient (To) is required.");
            }

            if (empty($from)) {
                throw new Exception("Sender (From) is required.");
            }

            // Validate remailer chain
            if (empty($entryRemailer) || empty($middleRemailer) || empty($exitRemailer)) {
                throw new Exception("All three remailers must be specified.");
            }

            // Resolve asterisks (*) to actual random remailers
            $resolvedEntry = resolveRemailer($entryRemailer, $entryRemailers);
            $resolvedMiddle = resolveRemailer($middleRemailer, $middleRemailers);
            $resolvedExit = resolveRemailer($exitRemailer, $exitRemailers);

            // Build remailer chain with resolved names
            $chain = "$resolvedEntry,$resolvedMiddle,$resolvedExit";

            // Log the resolved chain for debugging
            error_log("Resolved remailer chain: $chain (from: $entryRemailer,$middleRemailer,$exitRemailer)");

            // Build message headers
            $headers = "Content-Type: text/plain; charset=utf-8\n";
            $headers .= "Content-Transfer-Encoding: 8bit\n";
            $headers .= "MIME-Version: 1.0\n";

            if (!empty($references)) {
                $headers .= "References: $references\n";
            }

            // Build complete message
            $messageContent = $headers . "From: $from\n";

            if (!empty($replyTo)) {
                $messageContent .= "Reply-To: $replyTo\n";
            }

            $messageContent .= "To: $to\nSubject: $subject\n";

            if (!empty($newsgroups)) {
                $messageContent .= "Newsgroups: $newsgroups\n";
            }

            $messageContent .= "\n$data";

            // Verify YAMN executable exists
            $yamnPath = '/opt/yamn-master/yamn';
            if (!file_exists($yamnPath)) {
                throw new Exception("YAMN executable not found at: $yamnPath");
            }

            if (!is_executable($yamnPath)) {
                throw new Exception("YAMN executable is not executable. Check permissions.");
            }

            // Verify YAMN config file exists
            $yamnConfig = '/opt/yamn-master/yamn.yml';
            if (!file_exists($yamnConfig)) {
                throw new Exception("YAMN config file not found at: $yamnConfig");
            }

            // Ensure temp directory exists and is writable
            $tempDir = '/var/www/yamnweb';
            if (!is_dir($tempDir)) {
                throw new Exception("Temp directory does not exist: $tempDir");
            }

            if (!is_writable($tempDir)) {
                throw new Exception("Temp directory is not writable: $tempDir");
            }

            // Ensure Maildir exists (required by YAMN)
            $maildirBase = '/var/www/yamnweb/Maildir';
            $maildirDirs = [
                $maildirBase,
                $maildirBase . '/tmp',
                $maildirBase . '/new',
                $maildirBase . '/cur'
            ];

            foreach ($maildirDirs as $dir) {
                if (!is_dir($dir)) {
                    if (!@mkdir($dir, 0755, true)) {
                        throw new Exception("Failed to create Maildir directory: $dir");
                    }
                    error_log("Created Maildir directory: $dir");
                }
            }

            // Write message to temp file (use single file as in original)
            $tempFile = $tempDir . '/message.txt';
            $writeResult = @file_put_contents($tempFile, $messageContent);

            if ($writeResult === false) {
                throw new Exception("Failed to write message to temporary file: $tempFile");
            }

            // Verify file was written
            if (!file_exists($tempFile)) {
                throw new Exception("Temp file was not created: $tempFile");
            }

            // Log to debug.log
            $debugLog = '/var/www/yamnweb/debug.log';
            file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Starting email send\n", FILE_APPEND);
            file_put_contents($debugLog, "To: $to | Chain: $chain | Copies: $copies\n", FILE_APPEND);

            // Use sendYamnEmail() from tor_extension.php
            if (function_exists('sendYamnEmail')) {
                $result = sendYamnEmail($chain, $copies, $tempFile, true);

                // Log result
                file_put_contents($debugLog, date('Y-m-d H:i:s') . " - Result: " . ($result['success'] ? 'SUCCESS' : 'FAILED') . "\n", FILE_APPEND);

                // Clean up
                @unlink($tempFile);

                if ($result['success']) {
                    // Success - save message in session and redirect (PRG pattern)
                    $_SESSION['flash_message'] = "✓ Message sent successfully via YAMN network ($copies " . ($copies > 1 ? "copies" : "copy") . ")";
                    $_SESSION['flash_type'] = 'success';

                    // Regenerate CSRF token after successful submission
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    // Redirect to prevent form resubmission on page reload
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit;
                } else {
                    throw new Exception("YAMN send failed. Check debug.log for details.");
                }
            } else {
                throw new Exception("sendYamnEmail() function not found. Check tor_extension.php");
            }
        } catch (Exception $e) {
            // Save error in session and redirect (PRG pattern for errors too)
            $_SESSION['flash_message'] = "✗ Error: " . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
            error_log("YAMN submission error: " . $e->getMessage() . "\nStack: " . $e->getTraceAsString());

            // Redirect to prevent form resubmission
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (Error $e) {
            // Catch PHP 7+ Error class (for fatal errors)
            $_SESSION['flash_message'] = "✗ Fatal Error: " . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
            error_log("YAMN fatal error: " . $e->getMessage() . "\nStack: " . $e->getTraceAsString());

            // Redirect to prevent form resubmission
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        } catch (Throwable $e) {
            // Catch everything else
            $_SESSION['flash_message'] = "✗ Unexpected error: " . $e->getMessage();
            $_SESSION['flash_type'] = 'error';
            error_log("YAMN unexpected error: " . $e->getMessage());

            // Redirect to prevent form resubmission
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>YAMN Web Interface</title>
    <style>
        :root {
            --transition-speed: 0.3s;
        }

        /* Dark theme (default) */
        body.theme-dark {
            --bg-primary: #0a0a0a;
            --bg-secondary: #1a1a1a;
            --text-primary: #e0e0e0;
            --text-secondary: #a0a0a0;
            --accent: #00ff00;
            --accent-hover: #00cc00;
            --border: #333;
            --error: #ff4444;
            --warning: #ffaa00;
            --success: #00ff00;
            --tor-bg: rgba(138, 43, 226, 0.1);
            --tor-border: #8a2be2;
            --tor-text: #bb86fc;
        }

        /* Light theme */
        body.theme-light {
            --bg-primary: #f5f5f5;
            --bg-secondary: #ffffff;
            --text-primary: #2a2a2a;
            --text-secondary: #666666;
            --accent: #007700;
            --accent-hover: #005500;
            --border: #dddddd;
            --error: #cc0000;
            --warning: #ff8800;
            --success: #008800;
            --tor-bg: rgba(138, 43, 226, 0.05);
            --tor-border: #6a1ba2;
            --tor-text: #6a1ba2;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Courier New', monospace;
            background: var(--bg-primary);
            color: var(--text-primary);
            padding: 20px;
            line-height: 1.6;
            transition: background var(--transition-speed), color var(--transition-speed);
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
            background: var(--bg-secondary);
            padding: 30px;
            border: 2px solid var(--border);
            border-radius: 5px;
            box-shadow: 0 0 20px rgba(0, 255, 0, 0.1);
            transition: all var(--transition-speed);
        }

        h1 {
            color: var(--accent);
            text-align: center;
            margin-bottom: 10px;
            font-size: 2em;
            text-transform: uppercase;
            letter-spacing: 3px;
            transition: color var(--transition-speed);
        }

        .subtitle {
            text-align: center;
            color: var(--text-secondary);
            margin-bottom: 30px;
            font-size: 0.9em;
            transition: color var(--transition-speed);
        }

        .info-box {
            background: var(--tor-bg);
            border: 1px solid var(--tor-border);
            padding: 15px;
            margin-bottom: 25px;
            border-radius: 5px;
            font-size: 0.9em;
            transition: all var(--transition-speed);
        }

        .info-box strong {
            color: var(--tor-text);
            display: block;
            margin-bottom: 10px;
        }

        .info-box ul {
            margin-left: 20px;
            color: var(--text-secondary);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 5px;
            color: var(--accent);
            font-weight: bold;
            transition: color var(--transition-speed);
        }

        .required {
            color: var(--error);
        }

        input[type="text"],
        input[type="email"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            padding: 10px;
            background: var(--bg-primary);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 3px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            transition: all var(--transition-speed);
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 5px var(--accent);
        }

        textarea {
            min-height: 150px;
            resize: vertical;
        }

        .remailer-chain {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 10px;
        }

        .remailer-chain label {
            font-size: 0.9em;
        }

        button {
            width: 100%;
            padding: 15px;
            background: var(--accent);
            color: var(--bg-primary);
            border: none;
            border-radius: 5px;
            font-size: 1.1em;
            font-weight: bold;
            cursor: pointer;
            text-transform: uppercase;
            letter-spacing: 2px;
            font-family: 'Courier New', monospace;
            transition: all var(--transition-speed);
        }

        button:hover {
            background: var(--accent-hover);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 255, 0, 0.3);
        }

        .message {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 5px;
            border-left: 4px solid;
            transition: all var(--transition-speed);
        }

        .message.success {
            background: rgba(0, 255, 0, 0.1);
            border-color: var(--success);
            color: var(--success);
        }

        .message.error {
            background: rgba(255, 68, 68, 0.1);
            border-color: var(--error);
            color: var(--error);
        }

        .message.warning {
            background: rgba(255, 170, 0, 0.1);
            border-color: var(--warning);
            color: var(--warning);
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input[type="checkbox"] {
            width: auto;
        }

        .checkbox-group label {
            margin: 0;
            font-weight: normal;
        }

        /* Theme toggle button */
        .theme-toggle {
            position: fixed;
            top: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            background: var(--bg-secondary);
            border: 2px solid var(--border);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 24px;
            transition: all var(--transition-speed);
            z-index: 1000;
            text-decoration: none;
        }

        .theme-toggle:hover {
            transform: scale(1.1) rotate(15deg);
            border-color: var(--accent);
            box-shadow: 0 0 20px var(--accent);
        }

        .theme-toggle-slider {
            transition: transform var(--transition-speed);
        }

        .theme-toggle:hover .theme-toggle-slider {
            transform: rotate(180deg);
        }

        /* Better mobile support */
        input[type="number"] {
            -moz-appearance: textfield;
        }

        input[type="number"]::-webkit-inner-spin-button,
        input[type="number"]::-webkit-outer-spin-button {
            -webkit-appearance: none;
            margin: 0;
        }

        /* Improved select dropdown */
        select {
            cursor: pointer;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2300ff00' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 10px center;
            padding-right: 30px;
            appearance: none;
            -webkit-appearance: none;
            -moz-appearance: none;
        }

        body.theme-light select {
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23007700' d='M6 9L1 4h10z'/%3E%3C/svg%3E");
        }

        select:hover {
            border-color: var(--accent);
        }

        select option:checked {
            background: var(--accent);
            color: var(--text-primary);
        }

        .tor-indicator {
            display: inline-block;
            padding: 5px 10px;
            background: var(--tor-bg);
            border: 1px solid var(--tor-border);
            color: var(--tor-text);
            border-radius: 3px;
            font-size: 12px;
            margin-left: 10px;
            transition: all var(--transition-speed);
        }

        small {
            color: var(--text-secondary);
            transition: color var(--transition-speed);
        }

        select option {
            background: var(--bg-secondary);
            color: var(--text-primary);
        }

        @media (max-width: 768px) {
            .remailer-chain {
                grid-template-columns: 1fr;
            }

            .theme-toggle {
                top: 10px;
                right: 10px;
            }

            h1 {
                font-size: 1.5em;
                padding-right: 70px;
            }
        }

        /* Smooth theme transition */
        body, .container, input, select, textarea, button, .message, .info-box, .tor-indicator {
            transition: all var(--transition-speed) ease-in-out;
        }
    </style>
</head>
<body class="theme-<?php echo htmlspecialchars($theme); ?>">
    <div class="container">
        <!-- Theme Toggle -->
        <a href="?theme=<?php echo $theme === 'dark' ? 'light' : 'dark'; ?>" class="theme-toggle" title="Switch to <?php echo $theme === 'dark' ? 'Light' : 'Dark'; ?> Mode">
            <div class="theme-toggle-slider">
                <?php echo $theme === 'dark' ? '🌙' : '☀️'; ?>
            </div>
        </a>

        <h1>⚡ YAMN WEB INTERFACE ⚡</h1>
        <div class="subtitle">Tor before Yamn Remailer Network</div>

        <!-- DEBUG: Session check (can be removed after troubleshooting) -->
        <?php if (isset($_GET['debug'])): ?>
            <div class="info-box" style="font-size: 11px; font-family: monospace;">
                <strong>DEBUG INFO:</strong>
                Session ID: <?php echo substr(session_id(), 0, 16); ?>...<br>
                CSRF Token: <?php echo substr($_SESSION['csrf_token'], 0, 16); ?>...<br>
                PHP Version: <?php echo PHP_VERSION; ?><br>
                Session Save Path: <?php echo session_save_path(); ?><br>
                Cookie Params: <?php echo json_encode(session_get_cookie_params()); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($message)): ?>
            <div class="message <?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div class="info-box">
            <strong>Security Features Active:</strong>
            <ul>
               <li>Tor/Onion network before Yamn Mix Network</li>
                <li>Onion Smtp Relay with traffic padding and timing randomization</li>
                <li>Forward secrecy and metadata protection</li>
                <li>No persistent message retention - No logs website</li>
            </ul>
        </div>

        <form method="POST" action="" id="yamnForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="form-group">
                <label>Remailer Chain <span class="required">*</span></label>
                <div class="remailer-chain">
                    <div>
                        <label for="entry_remailer">Entry Node</label>
                        <select name="entry_remailer" id="entry_remailer" required>
                            <option value="">-- Select Entry --</option>
                            <?php foreach ($entryRemailers as $remailer): ?>
                                <option value="<?php echo htmlspecialchars($remailer); ?>">
                                    <?php echo htmlspecialchars($remailer); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="middle_remailer">Middle Node</label>
                        <select name="middle_remailer" id="middle_remailer" required>
                            <option value="">-- Select Middle --</option>
                            <?php foreach ($middleRemailers as $remailer): ?>
                                <option value="<?php echo htmlspecialchars($remailer); ?>">
                                    <?php echo htmlspecialchars($remailer); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label for="exit_remailer">Exit Node</label>
                        <select name="exit_remailer" id="exit_remailer" required>
                            <option value="">-- Select Exit --</option>
                            <?php foreach ($exitRemailers as $remailer): ?>
                                <option value="<?php echo htmlspecialchars($remailer); ?>">
                                    <?php echo htmlspecialchars($remailer); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="from">From Address <span class="required">*</span></label>
                <input type="text" name="from" id="from" placeholder="Anonymous <anonymous@anonymous.com>" required>
            </div>

            <div class="form-group">
                <label for="reply_to">Reply-To Address</label>
                <input type="text" name="reply_to" id="reply_to" placeholder="No Reply <noreply@anonymous.com>">
            </div>

            <div class="form-group">
                <label for="to">To Address <span class="required">*</span></label>
                <input type="email" name="to" id="to" placeholder="recipient@example.com or user@example.onion" required>
            </div>

            <div class="form-group">
                <label for="subject">Subject</label>
                <input type="text" name="subject" id="subject" placeholder="Message subject">
            </div>

            <div class="form-group">
                <label for="newsgroups">Newsgroups (optional)</label>
                <input type="text" name="newsgroups" id="newsgroups" placeholder="alt.anonymous.messages">
            </div>

            <div class="form-group">
                <label for="references">References (optional)</label>
                <input type="text" name="references" id="references" placeholder="Message-ID for threading">
            </div>

            <div class="form-group">
                <label for="data">Message Body <span class="required">*</span></label>
                <textarea name="data" id="data" placeholder="Your anonymous message..." required></textarea>
            </div>

            <div class="form-group">
                <label for="copies">Number of Copies (1-3)</label>
                <input type="number" name="copies" id="copies" value="1" min="1" max="3" required>
                <small>Multiple copies increase reliability through redundancy</small>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
            </div>

            <button type="submit">🚀 SEND </button>
        </form>
    </div>

    <script>
        // Auto-enable Tor checkbox for .onion addresses
        document.getElementById('to').addEventListener('input', function() {
            const torCheckbox = document.getElementById('use_tor');
            if (this.value.includes('.onion')) {
                torCheckbox.checked = true;
            }
        });

        // Prevent selecting same remailer multiple times
        document.querySelectorAll('.remailer-chain select').forEach(select => {
            select.addEventListener('change', function() {
                const selects = document.querySelectorAll('.remailer-chain select');
                const values = Array.from(selects).map(s => s.value).filter(v => v);

                selects.forEach(s => {
                    Array.from(s.options).forEach(option => {
                        if (option.value && values.includes(option.value) && option.value !== '*' && s !== select) {
                            option.style.color = '#666';
                        } else {
                            option.style.color = 'var(--text-primary)';
                        }
                    });
                });
            });
        });

        // Smooth scroll to top after theme change
        if (window.location.search.includes('theme=')) {
            window.scrollTo({top: 0, behavior: 'smooth'});
            // Clean URL without reloading
            const url = new URL(window.location);
            url.searchParams.delete('theme');
            window.history.replaceState({}, '', url);
        }
    </script>
</body>
</html>
