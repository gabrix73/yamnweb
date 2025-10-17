<?php
/**
 * YAMN Web Interface - Email Gateway
 * All traffic routed through Tor
 */

// Disable all error display
ini_set('display_errors', 0);
error_reporting(0);

// Load remailer downloader to populate select options
require_once 'download_remailers.php';

$downloader = new SecureRemailerDownloader();
$remailerList = $downloader->getRemailerList();

// Parse remailer list to extract remailer names
$remailers = [];
if ($remailerList) {
    $lines = explode("\n", $remailerList);
    foreach ($lines as $line) {
        // Extract remailer names (format: "name ******** time uptime")
        if (preg_match('/^([a-zA-Z0-9\-]+)\s+/', $line, $matches)) {
            $name = trim($matches[1]);
            if (!empty($name) && $name !== 'mixmaster' && strlen($name) > 2) {
                $remailers[] = $name;
            }
        }
    }
    // Remove duplicates and sort
    $remailers = array_unique($remailers);
    sort($remailers);
}

// If no remailers found, add wildcards
if (empty($remailers)) {
    $remailers = ['*'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex, nofollow">
    <title>YAMN Gateway</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #000;
            color: #0f0;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            padding: 20px;
            max-width: 900px;
            margin: 0 auto;
        }

        .container {
            border: 2px solid #0f0;
            padding: 20px;
            background: #000;
        }

        .header {
            text-align: center;
            border-bottom: 2px solid #0f0;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        h1 {
            font-size: 24px;
            letter-spacing: 3px;
            font-weight: bold;
            color: #0f0;
            text-shadow: 0 0 10px #0f0;
        }

        .subtitle {
            font-size: 11px;
            color: #0a0;
            margin-top: 5px;
            letter-spacing: 1px;
        }

        .mission-brief {
            background: #001a00;
            border: 1px solid #0f0;
            padding: 15px;
            margin-bottom: 25px;
            font-size: 12px;
            line-height: 1.8;
        }

        .mission-brief p {
            margin-bottom: 10px;
        }

        .mission-brief strong {
            color: #0f0;
            text-shadow: 0 0 5px #0f0;
        }

        .form-section {
            margin-bottom: 20px;
            border: 1px solid #0a0;
            padding: 15px;
            background: #001100;
        }

        .section-title {
            color: #0f0;
            font-size: 13px;
            font-weight: bold;
            text-transform: uppercase;
            margin-bottom: 10px;
            letter-spacing: 2px;
            border-bottom: 1px solid #0a0;
            padding-bottom: 5px;
        }

        label {
            display: block;
            color: #0a0;
            margin-bottom: 5px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        input[type="text"],
        input[type="email"],
        input[type="number"],
        select,
        textarea {
            width: 100%;
            background: #000;
            border: 1px solid #0f0;
            color: #0f0;
            padding: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }

        input[type="text"]:focus,
        input[type="email"]:focus,
        input[type="number"]:focus,
        select:focus,
        textarea:focus {
            outline: none;
            border-color: #0f0;
            box-shadow: 0 0 10px #0f0;
        }

        textarea {
            min-height: 200px;
            resize: vertical;
        }

        select {
            cursor: pointer;
        }

        select option {
            background: #000;
            color: #0f0;
        }

        .inline-group {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }

        .field-note {
            color: #0a0;
            font-size: 11px;
            margin-top: -10px;
            margin-bottom: 15px;
            font-style: italic;
        }

        .required {
            color: #ff0;
            text-shadow: 0 0 5px #ff0;
        }

        .submit-container {
            text-align: center;
            margin-top: 25px;
            padding-top: 20px;
            border-top: 2px solid #0f0;
        }

        button[type="submit"] {
            background: #000;
            color: #0f0;
            border: 2px solid #0f0;
            padding: 12px 40px;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 3px;
            cursor: pointer;
            transition: all 0.3s;
        }

        button[type="submit"]:hover {
            background: #0f0;
            color: #000;
            box-shadow: 0 0 20px #0f0;
        }

        button[type="submit"]:active {
            transform: scale(0.98);
        }

        .tor-status {
            position: fixed;
            top: 10px;
            right: 10px;
            background: #001a00;
            border: 1px solid #0f0;
            padding: 8px 12px;
            font-size: 11px;
            color: #0f0;
            box-shadow: 0 0 10px #0f0;
        }

        .tor-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #0f0;
            border-radius: 50%;
            margin-right: 5px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }

        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #0a0;
            text-align: center;
            font-size: 11px;
            color: #0a0;
        }

        @media (max-width: 768px) {
            .inline-group {
                grid-template-columns: 1fr;
            }
            
            body {
                padding: 10px;
            }
            
            .container {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="tor-status">
        <span class="tor-indicator"></span>TOR ACTIVE
    </div>

    <div class="container">
        <div class="header">
            <h1>[ YAMN GATEWAY ]</h1>
            <div class="subtitle">YET ANOTHER MIX NETWORK - SECURE ANONYMOUS EMAIL SYSTEM</div>
        </div>

        <div class="mission-brief">
            <p><strong>MISSION:</strong> This interface provides access to the YAMN anonymous remailer network for secure, untraceable email transmission. Messages are encrypted and routed through a chain of randomly selected mix nodes, making traffic analysis and sender identification computationally infeasible.</p>
            
            <p><strong>OPERATION:</strong> All traffic is mandatorily routed through Tor before reaching the YAMN network. The YAMN entry node receives connections only from Tor exit nodes, never learning the true origin IP address. Messages are padded to standard sizes, delayed with random intervals, and protected against replay attacks through cryptographic message-ID verification.</p>
            
            <p><strong>SECURITY:</strong> Double-layer anonymization: Tor conceals your identity from the YAMN network, while YAMN's multi-hop mixing (minimum 3 remailers) prevents the final recipient from tracing back to the entry point. No persistent metadata retained.</p>
            
            <p><strong>STATUS:</strong> System operational. Tor connection verified. No logs retained.</p>
        </div>

        <form action="send_email_with_tor.php" method="POST">
            <!-- REMAILER CHAIN CONFIGURATION -->
            <div class="form-section">
                <div class="section-title">[ Chain Configuration ]</div>
                
                <div class="inline-group">
                    <div>
                        <label for="entry_remailer">Entry Node <span class="required">*</span></label>
                        <select name="entry_remailer" id="entry_remailer" required>
                            <option value="*" selected>* (Random)</option>
                            <?php foreach ($remailers as $remailer): ?>
                                <option value="<?php echo htmlspecialchars($remailer); ?>">
                                    <?php echo htmlspecialchars($remailer); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="middle_remailer">Middle Node <span class="required">*</span></label>
                        <select name="middle_remailer" id="middle_remailer" required>
                            <option value="*" selected>* (Random)</option>
                            <?php foreach ($remailers as $remailer): ?>
                                <option value="<?php echo htmlspecialchars($remailer); ?>">
                                    <?php echo htmlspecialchars($remailer); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label for="exit_remailer">Exit Node <span class="required">*</span></label>
                        <select name="exit_remailer" id="exit_remailer" required>
                            <option value="*" selected>* (Random)</option>
                            <?php foreach ($remailers as $remailer): ?>
                                <option value="<?php echo htmlspecialchars($remailer); ?>">
                                    <?php echo htmlspecialchars($remailer); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field-note">* = Random selection (recommended for maximum security)</div>
            </div>

            <!-- MESSAGE HEADERS -->
            <div class="form-section">
                <div class="section-title">[ Message Headers ]</div>
                
                <label for="from">From <span class="required">*</span></label>
                <input type="email" name="from" id="from" placeholder="anonymous@anonymous.invalid" required>
                
                <label for="reply_to">Reply-To (Optional)</label>
                <input type="email" name="reply_to" id="reply_to" placeholder="reply@example.com">
                
                <label for="to">To <span class="required">*</span></label>
                <input type="email" name="to" id="to" placeholder="recipient@example.com" required>
                
                <label for="subject">Subject <span class="required">*</span></label>
                <input type="text" name="subject" id="subject" placeholder="Message subject" required>
                
                <label for="newsgroups">Newsgroups (Optional)</label>
                <input type="text" name="newsgroups" id="newsgroups" placeholder="alt.anonymous.messages">
                <div class="field-note">For Usenet posting (leave blank for email only)</div>
                
                <label for="references">References (Optional)</label>
                <input type="text" name="references" id="references" placeholder="Message-ID for threading">
                <div class="field-note">For reply threading</div>
            </div>

            <!-- MESSAGE BODY -->
            <div class="form-section">
                <div class="section-title">[ Message Body ]</div>
                
                <label for="data">Content <span class="required">*</span></label>
                <textarea name="data" id="data" placeholder="Enter your message here..." required></textarea>
            </div>

            <!-- TRANSMISSION OPTIONS -->
            <div class="form-section">
                <div class="section-title">[ Transmission Options ]</div>
                
                <label for="copies">Number of Copies <span class="required">*</span></label>
                <select name="copies" id="copies" required>
                    <option value="1">1 Copy (Faster)</option>
                    <option value="2" selected>2 Copies (Balanced - Recommended)</option>
                    <option value="3">3 Copies (Maximum Reliability)</option>
                </select>
                <div class="field-note">Multiple copies share same exit node to prevent duplicates</div>
            </div>

            <!-- SUBMIT -->
            <div class="submit-container">
                <button type="submit">[ TRANSMIT MESSAGE ]</button>
            </div>
        </form>

        <div class="footer">
            YAMN GATEWAY v2.0 | ALL TRAFFIC ROUTED VIA TOR | NO LOGS RETAINED<br>
            OPERATIONAL SECURITY: ENABLED | METADATA PROTECTION: ACTIVE
        </div>
    </div>
</body>
</html>
