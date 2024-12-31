<?php
include 'download_remailers.php';

function getRemailers($type) {
    $remailers = [];
    $file = '/var/www/yamnweb/remailers.txt';
    if (file_exists($file)) {
        $lines = file($file);
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', $line);
            if (($type == 'E' && !in_array('D', $parts)) || ($type == 'M' && in_array('D', $parts))) {
                $remailers[] = $parts[0];
            }
        }
    }
    return $remailers;
}

$entryRemailers = getRemailers('E');
$middleRemailers = getRemailers('M');
$exitRemailers = getRemailers('E');

// Filtra i remailers per il primo e terzo campo
$allowedEntryExitRemailers = ['paranoyamn', 'yamn', 'yamn2', 'yamn3', 'frell'];
$entryRemailers = array_intersect($entryRemailers, $allowedEntryExitRemailers);
$exitRemailers = array_intersect($exitRemailers, $allowedEntryExitRemailers);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Victor's Yamn Web Interface</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <div class="header">
        <div class="logo-frame">
            <img src="logo.png" alt="VHCC Logo">
        </div>
        <div class="title-frame">
            <h1>Victor's Yamn Web Interface</h1>
        </div>
    </div>
    <form action="send_email.php" method="post">
        <label for="entry_remailer">Entry Remailer:</label>
        <select name="entry_remailer" id="entry_remailer">
            <option value="*">Random</option>
            <?php foreach ($entryRemailers as $remailer): ?>
                <option value="<?php echo $remailer; ?>"><?php echo $remailer; ?></option>
            <?php endforeach; ?>
        </select>
        <br>
        <label for="middle_remailer">Middle Remailer:</label>
        <select name="middle_remailer" id="middle_remailer">
            <option value="*">Random</option>
            <?php foreach ($middleRemailers as $remailer): ?>
                <option value="<?php echo $remailer; ?>"><?php echo $remailer; ?></option>
            <?php endforeach; ?>
        </select>
        <br>
        <label for="exit_remailer">Exit Remailer:</label>
        <select name="exit_remailer" id="exit_remailer">
            <option value="*">Random</option>
            <?php foreach ($exitRemailers as $remailer): ?>
                <option value="<?php echo $remailer; ?>"><?php echo $remailer; ?></option>
            <?php endforeach; ?>
        </select>
        <br>
        <label for="from">From:</label>
        <input type="text" name="from" id="from" placeholder="Please use this format: Jane Doe &lt;jane@nowhere.com&gt;">
        <br>
        <label for="to">To:</label>
        <input type="text" name="to" id="to">
        <br>
        <label for="subject">Subject:</label>
        <input type="text" name="subject" id="subject">
        <br>
        <label for="newsgroups">Newsgroups:</label>
        <input type="text" name="newsgroups" id="newsgroups">
        <br>
        <label for="references">References:</label>
        <input type="text" name="references" id="references">
        <br>
        <label for="data">Data:</label>
        <textarea name="data" id="data" rows="30" cols="80"></textarea>
        <br>
        <label for="copies">Number of Copies:</label>
        <input type="number" name="copies" id="copies" min="1" max="3" value="1">
        <br>
        <input type="submit" value="Send Email">
    </form>
    <div class="instructions-frame">
        <h2>About the Interface</h2>
        <p>This web interface is built using the following technologies: HTML5 CSS3 PHP</p>
        <p>Contact for abuses: <a href="">abuse (at) domain</a></p>
    </div>
    <footer>
        <p>&copy; 2024 VICTOR - Hostile Communication Center & &#169; FUCK Design All Rights Reserved.</p>
    </footer>
</body>
</html>
