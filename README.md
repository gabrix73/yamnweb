Yamn Web Interface
This repository contains the Yamn Web Interface, a user-friendly web application designed to facilitate the sending of anonymous emails through a chain of remailers using the Yamn remailer software. 
The interface is built using HTML5, CSS3, and PHP, and it ensures secure and anonymous email transmission.

Make this repository as the apache document's root /var/www/yamnweb.
Create a subdirectory pool/ in /var/www/yamnweb.
git clone https://github.com/crooks/yamn 
Build and ensure the GO Yamn executable path is located at /opt/yamn-master/yamn.
Update the configuration file /opt/yamn-master/yamn.yml with the appropriate settings for your environment.

it is recommended 
Configure your http daemon with TLSv1.3 and the following SSL cipher suite: TLS_CHACHA20_POLY1305_SHA256:TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384.
Make sure your http daemon does not collect login ip's via module or by commenting access logs.
As tor hidden http service.
