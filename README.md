# 🔐 Guida Completa Installazione YAMN Web Interface Sicura

## 📋 Indice
1. [Prerequisiti](#prerequisiti)
2. [Installazione Tor](#installazione-tor)
3. [Installazione YAMN](#installazione-yamn)
4. [Configurazione File Sicuri](#configurazione-file-sicuri)
5. [Configurazione Nginx](#configurazione-nginx)
6. [Configurazione PHP](#configurazione-php)
7. [Configurazione Cronjob](#configurazione-cronjob)
8. [Test Funzionalità](#test-funzionalità)
9. [Troubleshooting](#troubleshooting)

---

## 1. Prerequisiti

```bash
# Sistema operativo supportato
- Debian 11/12 o Ubuntu 20.04/22.04/24.04

# Software richiesto
apt-get update
apt-get install -y \
    tor \
    torsocks \
    nginx \
    php8.2-fpm \
    php8.2-curl \
    php8.2-sqlite3 \
    php8.2-mbstring \
    sqlite3 \
    curl \
    git
```

---

## 2. Installazione Tor

### A. Installare Tor
```bash
apt-get install -y tor torsocks
```

### B. Configurare Tor
```bash
# Backup configurazione originale
cp /etc/tor/torrc /etc/tor/torrc.backup

# Creare nuova configurazione
cat > /etc/tor/torrc << 'EOF'
# SOCKS proxy con stream isolation
SocksPort 127.0.0.1:9050 IsolateDestAddr IsolateDestPort
SocksPort 127.0.0.1:9150 IsolateDestAddr IsolateDestPort

# Control port per gestione circuiti
ControlPort 127.0.0.1:9051
CookieAuthentication 1
CookieAuthFileGroupReadable 1

# Ottimizzazione circuiti
CircuitBuildTimeout 60
LearnCircuitBuildTimeout 0
MaxCircuitDirtiness 600
NewCircuitPeriod 30

# Stream isolation avanzata
IsolateClientAddr 1
IsolateSOCKSAuth 1
IsolateClientProtocol 1

# Security
SafeLogging 1
WarnUnsafeSocks 1

# Performance
NumEntryGuards 8
NumDirectoryGuards 3

# Opzionale: Escludere nodi exit in certi paesi
# ExcludeExitNodes {us},{gb},{au},{ca},{nz}
# StrictNodes 0
EOF
```

### C. Avviare Tor
```bash
systemctl enable tor
systemctl restart tor
systemctl status tor

# Verificare che sia in ascolto
netstat -tlnp | grep tor
# Dovresti vedere:
# 127.0.0.1:9050 (SOCKS)
# 127.0.0.1:9051 (Control)
```

### D. Testare Tor
```bash
# Test 1: Connessione SOCKS
curl --socks5-hostname 127.0.0.1:9050 https://check.torproject.org/api/ip

# Dovrebbe mostrare: "IsTor":true

# Test 2: Con torsocks
torsocks curl https://check.torproject.org/api/ip
```

---

## 3. Installazione YAMN

### A. Scaricare e compilare YAMN
```bash
# Creare directory
mkdir -p /opt/yamn-build
cd /opt/yamn-build

# Scaricare sorgenti
wget https://github.com/crooks/yamn/archive/refs/heads/master.zip
unzip master.zip
cd yamn-master

# Installare Go se necessario
apt-get install -y golang-go

# Compilare
go build

# Copiare binario
mkdir -p /opt/yamn-master
cp yamn /opt/yamn-master/
chmod +x /opt/yamn-master/yamn

# Verificare
/opt/yamn-master/yamn --version
```

### B. Configurare YAMN
```bash
# Creare configurazione
cat > /opt/yamn-master/yamn.yml << 'EOF'
# YAMN Configuration

remailer:
  name: "your-remailer"           # Cambia con il tuo nome
  address: "yamn@yourdomain.com"  # Cambia con la tua email

files:
  pubring: "pubring.mix"
  secring: "secring.mix"
  pooldir: "pool"
  maildir: "Maildir"
  chunkdb: "chunkdb"

urls:
  pubring: "https://echolot.virebent.art/pubring.mix"
  stats: "https://echolot.virebent.art/mlist2.txt"

mail:
  outfile: no
  sendmail: yes
  smtprelay: "localhost:25"
  # Se usi autenticazione SMTP:
  # smtpusername: "your-user"
  # smtppassword: "your-pass"

stats:
  numcopies: 2

# Client mode settings
chain:
  length: 3
  select: "*,*,*"
EOF

# Creare directory necessarie
mkdir -p /opt/yamn-master/{pool,Maildir/{cur,new,tmp},chunkdb}
chmod 700 /opt/yamn-master/{pool,Maildir,chunkdb}
```

---

## 4. Configurazione File Sicuri

### A. Creare directory sicure
```bash
# Directory per dati sensibili (fuori da web root!)
mkdir -p /opt/yamn-data/{pool,cache,backups}
chmod 700 /opt/yamn-data
chown www-data:www-data /opt/yamn-data -R

# Directory web
mkdir -p /var/www/yamnweb
cd /var/www/yamnweb
```

### B. Copiare file sicuri
```bash
# Copiare i file PHP sicuri che hai ricevuto:
# - downloadRemailers_secure.php
# - tor_extension_secure.php
# - send_secure.php
# - index.php (interfaccia)
# - styles.css

# Impostare permessi
chown www-data:www-data /var/www/yamnweb -R
chmod 755 /var/www/yamnweb
chmod 644 /var/www/yamnweb/*.php
chmod 644 /var/www/yamnweb/*.css

# RIMUOVERE file log vecchi se presenti
rm -f /var/www/yamnweb/*.log
rm -f /var/www/yamnweb/*.txt
```

### C. Permettere a www-data di usare Tor
```bash
usermod -a -G debian-tor www-data
```

---

## 5. Configurazione Nginx

### A. Creare configurazione sito
```bash
cat > /etc/nginx/sites-available/yamnweb << 'EOF'
server {
    listen 80;
    server_name your-domain.com;  # Cambia con il tuo dominio
    
    # Redirect to HTTPS
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;  # Cambia con il tuo dominio
    
    # TLS 1.3 only
    ssl_protocols TLSv1.3;
    ssl_ciphers 'TLS_CHACHA20_POLY1305_SHA256:TLS_AES_256_GCM_SHA384';
    ssl_prefer_server_ciphers on;
    
    # SSL certificates (usa Let's Encrypt o certificati esistenti)
    ssl_certificate /etc/ssl/certs/your-cert.pem;
    ssl_certificate_key /etc/ssl/private/your-key.pem;
    
    # HSTS
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
    
    # NO LOGS - CRITICO!
    access_log off;
    error_log /dev/null;
    
    # Security headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; object-src 'none'; style-src 'self' 'unsafe-inline';" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Permissions-Policy "geolocation=(), microphone=(), camera=()" always;
    
    # Rate limiting
    limit_req_zone $binary_remote_addr zone=yamnlimit:10m rate=10r/m;
    limit_req zone=yamnlimit burst=5 nodelay;
    
    root /var/www/yamnweb;
    index index.php index.html;
    
    # PHP processing
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
        
        # Hide PHP version
        fastcgi_hide_header X-Powered-By;
    }
    
    # Block access to sensitive files
    location ~ /\.(ht|git|env|log|txt|bak|tmp)$ {
        deny all;
    }
    
    # Block access to secure directories
    location ~ ^/(pool|cache|backups|opt)/ {
        deny all;
    }
    
    # Disable autoindex
    autoindex off;
}
EOF

# Abilitare sito
ln -s /etc/nginx/sites-available/yamnweb /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Test configurazione
nginx -t

# Riavviare Nginx
systemctl restart nginx
```

---

## 6. Configurazione PHP

### A. Configurare PHP-FPM
```bash
# Modificare /etc/php/8.2/fpm/php.ini

cat >> /etc/php/8.2/fpm/php.ini << 'EOF'

; Security settings
display_errors = Off
display_startup_errors = Off
log_errors = Off
error_log = /dev/null

; Session security
session.use_cookies = 0
session.use_trans_sid = 0
session.cookie_httponly = 1
session.cookie_secure = 1
session.cookie_samesite = Strict

; File uploads
file_uploads = Off
upload_max_filesize = 0M

; Execution limits
max_execution_time = 300
memory_limit = 256M

; Extensions
extension=curl
extension=sqlite3
extension=mbstring
EOF

# Riavviare PHP-FPM
systemctl restart php8.2-fpm
```

---

## 7. Configurazione Cronjob

### A. Installare script cron
```bash
# Copiare script cron
cp cron_update_remailers.sh /var/www/yamnweb/
chmod +x /var/www/yamnweb/cron_update_remailers.sh
chown www-data:www-data /var/www/yamnweb/cron_update_remailers.sh

# Aggiungere a crontab di www-data
crontab -u www-data -e

# Aggiungere questa riga:
*/6 * * * * /var/www/yamnweb/cron_update_remailers.sh
```

### B. Creare log file
```bash
touch /var/log/yamn_cron.log
touch /var/log/yamn_download.log
chown www-data:www-data /var/log/yamn_*.log
chmod 600 /var/log/yamn_*.log
```

---

## 8. Test Funzionalità

### A. Test manuale download
```bash
# Eseguire come www-data
sudo -u www-data php /var/www/yamnweb/test_remailer_download.php
```

Output atteso:
```
=== YAMN Secure Downloader Test ===

1. Checking current status...
   Tor status: Available

2. Testing download (this may take 1-3 minutes due to random delays)...
   ✅ Download successful!

3. Verifying downloaded files...
   ✅ Stats file OK (XXXX bytes)
   ✅ Keyring file OK (XXXX bytes)
   Detected approximately XX key entries

=== Test Complete ===
✅ All checks passed! YAMN is ready to use.
```

### B. Test invio email
```bash
# Creare messaggio di test
cat > /tmp/test_message.txt << 'EOF'
From: anonymous@anonymous.invalid
To: your-test-email@example.com
Subject: YAMN Test Message

This is a test message sent through YAMN.
EOF

# Inviare con yamn
cd /opt/yamn-master
torsocks ./yamn --mail --chain="*,*,*" --copies=1 < /tmp/test_message.txt

# Controllare pool
ls -la /opt/yamn-master/pool/
```

### C. Test interfaccia web
```bash
# Aprire browser e navigare a:
https://your-domain.com

# Compilare form e inviare email di test
# Verificare che:
# 1. Form viene validato correttamente
# 2. Email viene aggiunta al pool
# 3. Nessun errore nei log
```

---

## 9. Troubleshooting

### Problema: "Tor is not available"
```bash
# Verificare stato Tor
systemctl status tor

# Verificare porte in ascolto
netstat -tlnp | grep tor

# Test connessione
curl --socks5-hostname 127.0.0.1:9050 https://check.torproject.org/api/ip

# Se non funziona, riavviare Tor
systemctl restart tor
```

### Problema: "Permission denied" su file
```bash
# Verificare proprietà
ls -la /opt/yamn-data/
ls -la /opt/yamn-master/

# Correggere permessi
chown www-data:www-data /opt/yamn-data -R
chmod 700 /opt/yamn-data
chmod 700 /opt/yamn-master/pool
```

### Problema: "Failed to download from all sources"
```bash
# Test manuale connessione
torsocks curl https://echolot.virebent.art/mlist2.txt

# Verificare proxy Tor
ps aux | grep tor

# Verificare configurazione PHP cURL
php -i | grep -i curl
```

### Problema: YAMN non invia messaggi
```bash
# Verificare configurazione YAMN
cat /opt/yamn-master/yamn.yml

# Test manuale
cd /opt/yamn-master
echo "Test" | ./yamn --mail --to test@example.com --stdout

# Verificare pool
ls -la /opt/yamn-master/pool/

# Verificare pubring
ls -la /opt/yamn-master/pubring.mix
```

### Log utili
```bash
# Log nginx (se abilitati temporaneamente)
tail -f /var/log/nginx/error.log

# Log PHP
tail -f /var/log/php8.2-fpm.log

# Log Tor
journalctl -u tor -f

# Log cron
tail -f /var/log/yamn_cron.log
```

---

## 🎯 Checklist Finale

- [ ] Tor installato e funzionante
- [ ] YAMN compilato e configurato
- [ ] Directory sicure create (/opt/yamn-data/)
- [ ] Nginx configurato senza logging
- [ ] PHP configurato correttamente
- [ ] File PHP sicuri installati
- [ ] Cronjob configurato
- [ ] Test download completato con successo
- [ ] Test invio email funzionante
- [ ] Interfaccia web accessibile
- [ ] Nessun file .log in /var/www/yamnweb/
- [ ] Permessi corretti su tutti i file

---

## 📚 Riferimenti

- YAMN Documentation: https://mixmin.net/yamn.html
- Tor Project: https://www.torproject.org/
- Victor Yamn Pinger: https://echolot.virebent.art/
- YAMN GitHub: https://github.com/crooks/yamn

---

## 🔒 Note di Sicurezza

**IMPORTANTE:**
1. Disabilitare SEMPRE i log di Nginx
2. Non salvare MAI metadata sensibili
3. Tor SEMPRE obbligatorio per tutti i download e invii
4. Verificare periodicamente che non ci siano file .log in /var/www/
5. Mantenere aggiornati Tor e YAMN
6. Usare HTTPS con TLS 1.3
7. Rate limiting sempre attivo

**Privacy:**
- Nessun log IP
- Nessun timestamp persistente
- Replay protection attivo
- Adaptive padding attivo
- Randomized delays attivi

