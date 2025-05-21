<?php
// Enable error display during development - comment out in production
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

include 'download_remailers.php';
include 'tor_extension.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Retrieve form data
    $entryRemailer = isset($_POST['entry_remailer']) ? $_POST['entry_remailer'] : '';
    $middleRemailer = isset($_POST['middle_remailer']) ? $_POST['middle_remailer'] : '';
    $exitRemailer = isset($_POST['exit_remailer']) ? $_POST['exit_remailer'] : '';
    $from = isset($_POST['from']) ? $_POST['from'] : '';
    $replyTo = isset($_POST['reply_to']) ? $_POST['reply_to'] : '';
    $to = isset($_POST['to']) ? $_POST['to'] : '';
    $subject = isset($_POST['subject']) ? $_POST['subject'] : '';
    $newsgroups = isset($_POST['newsgroups']) ? $_POST['newsgroups'] : '';
    $references = isset($_POST['references']) ? $_POST['references'] : '';
    $data = isset($_POST['data']) ? $_POST['data'] : '';
    $copies = isset($_POST['copies']) ? intval($_POST['copies']) : 1;
    
    // Force Tor usage regardless of form input
    $useTor = true;
    
    // Validation
    $errors = [];
    
    // Required fields validation
    if (empty($to)) {
        $errors[] = "Recipient (To) field is required";
    }
    
    if (empty($from)) {
        $errors[] = "Sender (From) field is required";
    }
    
    if (empty($subject)) {
        $errors[] = "Subject field is required";
    }
    
    // Validate number of copies
    if ($copies < 1 || $copies > 3) {
        $errors[] = "Number of copies must be between 1 and 3";
    }
    
    // Check if Tor is available
    if (!isTorAvailable()) {
        $errors[] = "Tor is required but not available on the system. Please install and start Tor service.";
    }
    
    // If no errors, proceed with sending
    if (empty($errors)) {
        // Build the remailer chain
        $chain = "$entryRemailer,$middleRemailer,$exitRemailer";
        
        // Build headers
        $headers = "Content-Type: text/plain; charset=utf-8\n";
        $headers .= "Content-Transfer-Encoding: 8bit\n";
        $headers .= "MIME-Version: 1.0\n";
        
        if (!empty($references)) {
            $headers .= "References: $references\n";
        }
        
        // Build message content
        $messageContent = $headers . "From: $from\n";
        
        if (!empty($replyTo)) {
            $messageContent .= "Reply-To: $replyTo\n";
        }
        
        $messageContent .= "To: $to\nSubject: $subject\n";
        
        if (!empty($newsgroups)) {
            $messageContent .= "Newsgroups: $newsgroups\n";
        }
        
        $messageContent .= "\n$data";
        
        // Create a unique filename for the message
        $messageFile = '/var/www/yamnweb/message_' . time() . '_' . rand(1000, 9999) . '.txt';
        
        $write_success = file_put_contents($messageFile, $messageContent);
        if ($write_success === false) {
            echo "Error: Unable to write to message file. Check permissions.";
            exit;
        }
        
        // Log the operation
        $log_entry = date('Y-m-d H:i:s') . " - Sending message: from $from to $to using Tor\n";
        file_put_contents('/var/www/yamnweb/send_log.txt', $log_entry, FILE_APPEND);
        
        // Send the email with Tor (always enabled)
        try {
            $result = sendYamnEmail($chain, $copies, $messageFile, $useTor);
        } catch (Exception $e) {
            echo "Error sending email: " . $e->getMessage();
            exit;
        }
        
        // Clean up message file after sending
        if (file_exists($messageFile)) {
            unlink($messageFile);
        }
        
        // Display result to user
        if ($result['success']) {
            echo "<!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Email Sent - YAMN Web Interface</title>
                <link rel='stylesheet' href='styles.css'>
            </head>
            <body>
                <div class='success-message'>
                    <h2>Email sent successfully!</h2>
                    <p>Your email has been added to the YAMN sending queue.</p>
                    <p>As per system policy, Tor was used for enhanced anonymity.</p>
                    <a href='index.php' class='button'>Return to Home</a>
                </div>
            </body>
            </html>";
        } else {
            echo "<!DOCTYPE html>
            <html lang='en'>
            <head>
                <meta charset='UTF-8'>
                <meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Email Error - YAMN Web Interface</title>
                <link rel='stylesheet' href='styles.css'>
                <style>
                    pre {
                        background-color: #f8f8f8;
                        padding: 10px;
                        border-radius: 4px;
                        overflow-x: auto;
                        font-size: 12px;
                        text-align: left;
                    }
                </style>
            </head>
            <body>
                <div class='error-message'>
                    <h2>Error sending email</h2>
                    <p>An error occurred while processing your request.</p>
                    <p>Error details:</p>
                    <pre>";
            echo "===== ADD TO POOL COMMAND =====\n";
            echo htmlspecialchars($result['add_to_pool']['command']) . "\n\n";
            echo "===== ADD TO POOL OUTPUT =====\n";
            echo htmlspecialchars(implode("\n", $result['add_to_pool']['output'])) . "\n\n";
            echo "===== ADD TO POOL RETURN CODE =====\n";
            echo $result['add_to_pool']['return_var'] . "\n\n";
            
            if (isset($result['send']) && $result['add_to_pool']['return_var'] == 0) {
                echo "===== SEND COMMAND =====\n";
                echo htmlspecialchars($result['send']['command']) . "\n\n";
                echo "===== SEND OUTPUT =====\n";
                echo htmlspecialchars(implode("\n", $result['send']['output'])) . "\n\n";
                echo "===== SEND RETURN CODE =====\n";
                echo $result['send']['return_var'] . "\n";
            }
            echo "</pre>
                    <p>Please check system logs for more details.</p>
                    <a href='index.php' class='button'>Return to Home</a>
                </div>
            </body>
            </html>";
            
            // Detailed error logging
            $logEntry = "==== ERROR LOG ====\n";
            $logEntry .= "Date: " . date('Y-m-d H:i:s') . "\n";
            $logEntry .= "From: $from\n";
            $logEntry .= "To: $to\n";
            $logEntry .= "Subject: $subject\n";
            if (!empty($newsgroups)) {
                $logEntry .= "Newsgroups: $newsgroups\n";
            }
            $logEntry .= "Chain: $chain\n";
            $logEntry .= "Copies: $copies\n";
            $logEntry .= "Using Tor: Yes\n";
            $logEntry .= "\n=== ADD TO POOL ===\n";
            $logEntry .= "Command: " . $result['add_to_pool']['command'] . "\n";
            $logEntry .= "Output: " . implode("\n", $result['add_to_pool']['output']) . "\n";
            $logEntry .= "Return Code: " . $result['add_to_pool']['return_var'] . "\n";
            
            if (isset($result['send'])) {
                $logEntry .= "\n=== SEND ===\n";
                $logEntry .= "Command: " . $result['send']['command'] . "\n";
                $logEntry .= "Output: " . implode("\n", $result['send']['output']) . "\n";
                $logEntry .= "Return Code: " . $result['send']['return_var'] . "\n";
            }
            
            $logEntry .= "----------------------------------------\n";
            error_log($logEntry, 3, "/var/www/yamnweb/email_errors.log");
        }
    } else {
        // Display validation errors
        echo "<!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Form Error - YAMN Web Interface</title>
            <link rel='stylesheet' href='styles.css'>
        </head>
        <body>
            <div class='error-message'>
                <h2>Form Errors</h2>
                <ul>";
        foreach ($errors as $error) {
            echo "<li>" . htmlspecialchars($error) . "</li>";
        }
        echo "</ul>
                <a href='javascript:history.back()' class='button'>Back to Form</a>
            </div>
        </body>
        </html>";
    }
} else {
    // If not a POST request, redirect to home
    header("Location: index.php");
    exit;
}
?>
