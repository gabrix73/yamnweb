<b>Yamn Web Interface</b><br>
<p>This repository contains a web interface for <em>crooks/yamn</em>, a user-friendly web application designed to facilitate the sending of anonymous emails through a chain of remailers using the yamn network.<br> 
The interface is built using HTML5, CSS3, and PHP.</p>

<b>Instructions</b><br>
<p>Make this repository as your http daemon document's root <pre>/var/www/yamnweb</pre>.<br>
Create a subdirectory pool/ in /var/www/yamnweb.</p>
<pre>git clone https://github.com/crooks/yamn /opt/yamn-master </pre>
<p>Build and ensure the GO Yamn executable path is located at /opt/yamn-master/yamn.<br>
Update the configuration file /opt/yamn-master/yamn.yml with the appropriate settings for your environment.</p>

<b>It's recommended</b><br> 
<p>Configure your http daemon with TLSv1.3 and the following SSL cipher suite: TLS_CHACHA20_POLY1305_SHA256:TLS_AES_128_GCM_SHA256:TLS_AES_256_GCM_SHA384.</p>
<p>Even better as tor hidden http service.</p>
<p>Make sure your http daemon does not collect ip accesses via module or by commenting out the access logs entirely.<br>

