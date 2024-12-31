<b>Yamn Web Interface</b><br>
<p>This repository contains the Yamn Web Interface, a user-friendly web application designed to facilitate the sending of anonymous emails through a chain of remailers using the Yamn remailer software. 
The interface is built using HTML5, CSS3, and PHP, and it ensures secure and anonymous email transmission.</p>

<p>Make this repository as the apache document's root /var/www/yamnweb.
Create a subdirectory pool/ in /var/www/yamnweb.</p>
<p>git clone https://github.com/crooks/yamn /opt/yamn-master </p>
<p>Build and ensure the GO Yamn executable path is located at /opt/yamn-master/yamn.
Update the configuration file /opt/yamn-master/yamn.yml with the appropriate settings for your environment.</p>

<b>it is recommended</b><br> 
<p>Configure your http daemon with TLSv1.3 and the following SSL cipher suite: TLS_CHACHA20_POLY1305_SHA256:TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384.</p>
<p>Make sure your http daemon does not collect login ip's via module or by commenting access logs.<br>
Deploy it as tor hidden http service.</p>
