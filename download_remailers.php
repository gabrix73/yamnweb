<?php
function downloadRemailers() {
    $url = 'https://www.mixmin.net/yamn/mlist2.txt';
    $file = '/var/www/yamnweb/remailers.txt';
    if (!file_exists($file) || (time() - filemtime($file) > 86400)) { // Scarica solo se il file non esiste o è più vecchio di 24 ore
        file_put_contents($file, file_get_contents($url));
    }
}

downloadRemailers();
?>
