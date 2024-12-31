<?php
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $entryRemailer = $_POST['entry_remailer'];
    $middleRemailer = $_POST['middle_remailer'];
    $exitRemailer = $_POST['exit_remailer'];
    $from = $_POST['from'];
    $to = $_POST['to'];
    $subject = $_POST['subject'];
    $newsgroups = $_POST['newsgroups'];
    $references = $_POST['references'];
    $data = $_POST['data'];
    $copies = $_POST['copies'];

    // Validazione del numero di copie
    if ($copies < 1 || $copies > 3) {
        error_log("Error: Number of copies must be between 1 and 3.", 3, "/var/www/yamnweb/email_log.txt");
        echo "Error: Number of copies must be between 1 and 3.";
        exit;
    }

    $chain = "$entryRemailer,$middleRemailer,$exitRemailer";
    $headers = "X-User-Agent: Victor's Yamn Web Interface\n";
    $headers .= "X-Mailer: Victor Hostile Communications Center\n";
    $headers .= "Content-Type: text/plain; charset=utf-8\n";
    $headers .= "Content-Transfer-Encoding: 8bit\n";
    $headers .= "MIME-Version: 1.0\n";
    if (!empty($references)) {
        $headers .= "References: $references\n";
    }
    $messageContent = $headers . "From: $from\nTo: $to\nSubject: $subject\n";
    if (!empty($newsgroups)) {
        $messageContent .= "Newsgroups: $newsgroups\n";
    }
    $messageContent .= "\n$data";
    file_put_contents('/var/www/yamnweb/message.txt', $messageContent);

    // Aggiungi la mail al pool
    $command_add_to_pool = "/opt/yamn-master/yamn --config=/opt/yamn-master/yamn.yml --mail --chain=\"$chain\" --copies=$copies < /var/www/yamnweb/message.txt";
    exec($command_add_to_pool, $output_add_to_pool, $return_var_add_to_pool);

    // Invia le mail presenti nel pool
    $command_send = "/opt/yamn-master/yamn --config=/opt/yamn-master/yamn.yml -S";
    exec($command_send, $output_send, $return_var_send);

    // Log dei dettagli dell'invio
    if ($return_var_send != 0) {
        $logEntry = "Date: " . date('Y-m-d H:i:s') . "\n";
        $logEntry .= "From: $from\n";
        $logEntry .= "To: $to\n";
        $logEntry .= "Subject: $subject\n";
        if (!empty($newsgroups)) {
            $logEntry .= "Newsgroups: $newsgroups\n";
        }
        if (!empty($references)) {
            $logEntry .= "References: $references\n";
        }
        $logEntry .= "Chain: $chain\n";
        $logEntry .= "Copies: $copies\n";
        $logEntry .= "Command Add to Pool: $command_add_to_pool\n";
        $logEntry .= "Output Add to Pool: " . implode("\n", $output_add_to_pool) . "\n";
        $logEntry .= "Return Code Add to Pool: $return_var_add_to_pool\n";
        $logEntry .= "Command Send: $command_send\n";
        $logEntry .= "Output Send: " . implode("\n", $output_send) . "\n";
        $logEntry .= "Return Code Send: $return_var_send\n";
        $logEntry .= "----------------------------------------\n";

        error_log($logEntry, 3, "/var/www/yamnweb/email_log.txt");
    }

    if ($return_var_send == 0) {
        echo "Email sent successfully!<br>";
        echo "<a href='https://home'>Return to Home</a>";
    } else {
        echo "Error sending email.";
    }
}
?>
