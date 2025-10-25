# Tor before Yamn Anonymous Remailers Network Gateway

![Status](https://img.shields.io/badge/status-operational-green)
![Security](https://img.shields.io/badge/security-hardened-red)
![Tor](https://img.shields.io/badge/tor-mandatory-purple)
![License](https://img.shields.io/badge/license-MIT-blue)

A hardened web interface for [YAMN (Yet Another Mix Network)](https://github.com/crooks/yamn) implementing military-grade operational security with mandatory Tor routing and zero metadata retention.

## 🎯 Mission Statement

This interface provides access to the YAMN remailer network. **All traffic is mandatorily routed through Tor before reaching the YAMN network.** The YAMN entry node receives connections exclusively from Tor exit nodes, ensuring the true origin IP address is never exposed.

### Double-Layer Unlinkability

```
User → Tor Network (3+ hops) → Tor Exit Node → YAMN Entry Remailer
                                                 ↓
                                          YAMN sees only Tor IP
                                          User IP: UNKNOWN
```

- **Layer 1 (Tor):** Conceals identity from YAMN network
- **Layer 2 (YAMN):** Multi-hop mixing (minimum 3 remailers) prevents recipient from tracing back to entry point

**Result:** Complete unlinkability between sender and recipient.

---

## 🛡️ Security Features

### Implemented Threat Mitigations

| Threat | Mitigation | Implementation |
|--------|------------|----------------|
| **Traffic Analysis** | Padding + Cover traffic | Adaptive padding (512B-32KB) |
| **Timing Attacks** | Randomized delays | 10-120s random intervals |
| **Replay Attacks** | Message-ID cache | SQLite cache with 7-day expiration |
| **Node Compromise** | Forward secrecy | Ephemeral keys per message |
| **Size Correlation** | Adaptive padding | Standardized message sizes |
| **Partial Network Observation** | Mixnet architecture | 3-hop minimum chain |
| **Global Adversary** | Multi-hop routing | Tor + YAMN = 6+ total hops |
| **Metadata Analysis** | No retention | Zero persistent logs |

### Core Protection Principles

- ✅ **Mandatory Tor Routing** - No exceptions, all traffic via Tor SOCKS5
- ✅ **Zero Logging** - No access logs, no error logs, no metadata retention
- ✅ **Military-Grade File Handling** - DoD 5220.22-M compliant deletion (3-pass overwrite)
- ✅ **Replay Protection** - SHA256 message-ID cache with 7-day TTL
- ✅ **Timing Obfuscation** - Random delays (10-120s) between operations
- ✅ **Adaptive Padding** - Messages padded to standard sizes (512B, 1KB, 2KB, 4KB, 8KB, 16KB, 32KB)
- ✅ **Input Validation** - All user input sanitized and validated
- ✅ **Fortified Temporary Files** - Created in `/opt/yamn-data/pool/` with 0600 permissions
- ✅ **Automatic Keyring Updates** - Downloads both stats and pubring.mix via Tor
- ✅ **Multi-Source Redundancy** - 4 verified pinger sources with automatic fallback

---

## 🏗️ Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                        User Browser                         │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTPS (TLS 1.3)
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    Nginx Web Server                         │
│                  (No logging enabled)                       │
└──────────────────────────┬──────────────────────────────────┘
                           │
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                      PHP Frontend                           │
│  • index.php (Form interface)                               │
│  • send_email_with_tor.php (Message processing)             │
│  • download_remailers.php (Auto-update)                     │
└──────────────────────────┬──────────────────────────────────┘
                           │
                ┌──────────┴──────────┐
                │                     │
                ▼                     ▼
    ┌──────────────────┐  ┌──────────────────┐
    │  Tor SOCKS5      │  │  torsocks        │
    │  127.0.0.1:9050  │  │  wrapper         │
    └────────┬─────────┘  └────────┬─────────┘
             │                     │
             ▼                     ▼
    ┌──────────────────────────────────────┐
    │       Tor Network (3+ hops)          │
    └──────────────┬───────────────────────┘
                   │
                   ▼
    ┌──────────────────────────────────────┐
    │       Tor Exit Node                  │
    │    (Only IP visible to YAMN)         │
    └──────────────┬───────────────────────┘
                   │
                   ▼
    ┌──────────────────────────────────────┐
    │    YAMN Entry Remailer               │
    │    (Receives from Tor IP only)       │
    └──────────────┬───────────────────────┘
                   │
                   ▼
           YAMN Network (3+ hops)
                   │
                   ▼
              Recipient
```

---

## 📋 Requirements

### System Requirements

- **OS:** Debian 11/12 or Ubuntu 20.04/22.04/24.04
- **RAM:** 512MB minimum, 1GB recommended
- **Disk:** 1GB free space
- **Network:** Internet connectivity required

### Software Dependencies

```bash
# Core components
- Tor 0.4.7+ (anonymity network)
- torsocks (Tor wrapper for applications)
- YAMN (remailer client)
- Nginx 1.18+ (web server)
- PHP 8.1+ with extensions:
  - php-fpm
  - php-curl
  - php-sqlite3
  - php-mbstring

# Build tools (for YAMN compilation)
- Go 1.19+ (for building YAMN from source)
- git
```

---

## 🚀 Installation

### Quick Start

```bash
# 1. Clone repository
git clone https://github.com/gabrix73/yamnweb.git
cd yamnweb

# 2. Run installation script
sudo ./install.sh

# 3. Configure your domain in nginx
sudo nano /etc/nginx/sites-available/yamnweb

# 4. Test installation
sudo -u www-data php test_download.php
```

### Manual Installation

#### Step 1: Install Tor

```bash
# Install Tor and torsocks
apt-get update
apt-get install -y tor torsocks

# Configure Tor
cat > /etc/tor/torrc << 'EOF'
# SOCKS proxy with stream isolation
SocksPort 127.0.0.1:9050 IsolateDestAddr IsolateDestPort
SocksPort 127.0.0.1:9150 IsolateDestAddr IsolateDestPort

# Control port for circuit management
ControlPort 127.0.0.1:9051
CookieAuthentication 1
CookieAuthFileGroupReadable 1

# Circuit optimization
CircuitBuildTimeout 60
LearnCircuitBuildTimeout 0
MaxCircuitDirtiness 600
NewCircuitPeriod 30

# Advanced stream isolation
IsolateClientAddr 1
IsolateSOCKSAuth 1
IsolateClientProtocol 1

# Security
SafeLogging 1
WarnUnsafeSocks 1
EOF

# Start Tor
systemctl enable tor
systemctl restart tor
systemctl status tor

# Verify Tor is running
netstat -tlnp | grep tor
# Should show: 127.0.0.1:9050 (SOCKS) and 127.0.0.1:9051 (Control)
```

#### Step 2: Install YAMN

```bash
# Install Go compiler
apt-get install -y golang-go

# Download and build YAMN
mkdir -p /opt/yamn-build
cd /opt/yamn-build
wget https://github.com/crooks/yamn/archive/refs/heads/master.zip
unzip master.zip
cd yamn-master
go build

# Install YAMN
mkdir -p /opt/yamn-master
cp yamn /opt/yamn-master/
chmod +x /opt/yamn-master/yamn

# Create configuration
cat > /opt/yamn-master/yamn.yml << 'EOF'
remailer:
  name: "your-remailer"
  address: "yamn@yourdomain.com"

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

stats:
  numcopies: 2

chain:
  length: 3
  select: "*,*,*"
EOF

# Create required directories
mkdir -p /opt/yamn-master/{pool,Maildir/{cur,new,tmp},chunkdb}
chmod 700 /opt/yamn-master/{pool,Maildir,chunkdb}
```

#### Step 3: Install Web Interface

```bash
# Create protected directories
mkdir -p /opt/yamn-data/{pool,cache,backups}
chmod 700 /opt/yamn-data
chown www-data:www-data /opt/yamn-data -R

# Install web files
mkdir -p /var/www/yamnweb
cd /var/www/yamnweb

# Copy application files
# - index.php
# - send_email_with_tor.php
# - download_remailers.php
# - tor_extension.php
# - test_download.php
# - cron_update.sh

# Set permissions
chown www-data:www-data /var/www/yamnweb -R
chmod 755 /var/www/yamnweb
chmod 644 /var/www/yamnweb/*.php
chmod 755 /var/www/yamnweb/*.sh

# Allow www-data to use Tor
usermod -a -G debian-tor www-data
```

#### Step 4: Configure Nginx

```bash
cat > /etc/nginx/sites-available/yamnweb << 'EOF'
server {
    listen 80;
    server_name your-domain.com;
    return 301 https://$server_name$request_uri;
}

server {
    listen 443 ssl http2;
    server_name your-domain.com;
    
    # TLS 1.3 only
    ssl_protocols TLSv1.3;
    ssl_ciphers 'TLS_CHACHA20_POLY1305_SHA256:TLS_AES_256_GCM_SHA384';
    ssl_prefer_server_ciphers on;
    
    # SSL certificates
    ssl_certificate /etc/ssl/certs/your-cert.pem;
    ssl_certificate_key /etc/ssl/private/your-key.pem;
    
    # HSTS
    add_header Strict-Transport-Security "max-age=31536000" always;
    
    # CRITICAL: NO LOGS
    access_log off;
    error_log /dev/null;
    
    # Security headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; object-src 'none';" always;
    add_header Referrer-Policy "no-referrer" always;
    
    # Rate limiting
    limit_req_zone $binary_remote_addr zone=yamnlimit:10m rate=10r/m;
    limit_req zone=yamnlimit burst=5 nodelay;
    
    root /var/www/yamnweb;
    index index.php;
    
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_hide_header X-Powered-By;
    }
    
    location ~ /\.(ht|git|env|log|txt|bak|tmp)$ {
        deny all;
    }
}
EOF

# Enable site
ln -s /etc/nginx/sites-available/yamnweb /etc/nginx/sites-enabled/
rm -f /etc/nginx/sites-enabled/default

# Test and restart
nginx -t
systemctl restart nginx
```

#### Step 5: Configure Automatic Updates

```bash
# Make cron script executable
chmod +x /var/www/yamnweb/cron_update.sh

# Add to crontab
crontab -u www-data -e

# Add this line:
*/6 * * * * /var/www/yamnweb/cron_update.sh
```

---

## 🧪 Testing

### Test 1: Verify Tor Connection

```bash
# Test Tor SOCKS proxy
curl --socks5-hostname 127.0.0.1:9050 https://check.torproject.org/api/ip

# Expected output: {"IsTor":true, ...}

# Test with torsocks
torsocks curl https://check.torproject.org/api/ip

# Expected output: {"IsTor":true, ...}
```

### Test 2: Test Remailer Download

```bash
# Run as www-data user
sudo -u www-data php /var/www/yamnweb/test_download.php
```

**Expected output:**
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

### Test 3: Test YAMN Sending

```bash
# Create test message
cat > /tmp/test_message.txt << 'EOF'
From: anonymous@anonymous.invalid
To: your-test-email@example.com
Subject: YAMN Test Message

This is a test message sent through YAMN.
EOF

# Send via YAMN with Tor
cd /opt/yamn-master
torsocks ./yamn --mail --chain="*,*,*" --copies=1 < /tmp/test_message.txt

# Check pool directory
ls -la /opt/yamn-master/pool/
```

### Test 4: Test Web Interface

1. Open browser: `https://your-domain.com`
2. Fill out the form with test data
3. Submit message
4. Verify success message appears
5. Check no errors in system logs

---

## 🔧 Configuration

### Tor Configuration

Edit `/etc/tor/torrc`:

```ini
# Optional: Exclude certain countries from exit nodes
# ExcludeExitNodes {us},{gb},{au},{ca},{nz}
# StrictNodes 0

# Performance tuning
NumEntryGuards 8
NumDirectoryGuards 3
```

### YAMN Configuration

Edit `/opt/yamn-master/yamn.yml`:

```yaml
# Customize remailer settings
remailer:
  name: "your-remailer-name"
  address: "yamn@your-domain.com"

# SMTP settings (if using authenticated SMTP)
mail:
  sendmail: yes
  smtprelay: "smtp.example.com:587"
  smtpusername: "your-username"
  smtppassword: "your-password"
```

### PHP Configuration

Edit `/etc/php/8.2/fpm/php.ini`:

```ini
# Security settings
display_errors = Off
log_errors = Off
error_log = /dev/null

# Resource limits
max_execution_time = 300
memory_limit = 256M

# Disable file uploads (security)
file_uploads = Off
```

---

## 🔍 How It Works

### Message Flow

1. **User submits message** via web form (HTTPS)
2. **PHP validates input** and checks for replays (SHA256 message-ID)
3. **Adaptive padding applied** to standardize message size
4. **Random delay** (10-120 seconds) prevents timing correlation
5. **Protected temporary file** created in `/opt/yamn-data/pool/` with 0600 permissions
6. **torsocks wrapper** called: `torsocks yamn --mail --chain="*,*,*" ...`
7. **All YAMN connections** automatically routed through Tor SOCKS5 (127.0.0.1:9050)
8. **YAMN receives** connection from Tor exit node IP (user IP unknown)
9. **Message encrypted** and sent through 3+ YAMN remailers
10. **Temporary file** wiped (DoD 5220.22-M: 3-pass overwrite)
11. **Message-ID stored** in replay cache (SQLite, 7-day TTL)

### Tor Routing Implementation

#### For Remailer List Downloads

**File:** `download_remailers.php`

```php
// cURL configured with Tor SOCKS5 proxy
curl_setopt_array($ch, [
    CURLOPT_PROXY => '127.0.0.1:9050',          // Tor SOCKS proxy
    CURLOPT_PROXYTYPE => CURLPROXY_SOCKS5_HOSTNAME, // DNS via Tor
    // ...
]);
```

#### For YAMN Message Sending

**File:** `tor_extension.php`

```php
// torsocks wrapper forces all connections through Tor
$command = sprintf(
    'torsocks %s --mail --chain=%s --copies=%d < %s',
    '/opt/yamn-master/yamn',
    $chain,
    $copies,
    $messageFile
);
```

**How torsocks works:**
- Uses `LD_PRELOAD` to intercept syscalls
- Redirects `connect()` to Tor SOCKS5
- Resolves DNS through Tor (prevents leaks)
- Transparent to application (YAMN thinks it's connecting normally)

---

## 📊 File Structure

```
/var/www/yamnweb/
├── index.php                      # Main web interface
├── send_email_with_tor.php        # Message processing
├── download_remailers.php         # Auto-update remailer lists
├── tor_extension.php              # Tor routing & YAMN interface
├── test_download.php              # Testing utility
└── cron_update.sh                 # Cronjob script

/opt/yamn-master/
├── yamn                           # YAMN binary
├── yamn.yml                       # YAMN configuration
├── pubring.mix                    # Public keyring (auto-updated)
├── pool/                          # Outgoing message queue
├── Maildir/                       # Incoming mail (if running as server)
└── chunkdb/                       # Partial message reassembly

/opt/yamn-data/
├── pool/                          # Secure temp files
├── cache/
│   ├── remailers.txt             # Downloaded stats
│   └── replay_cache.db           # SQLite replay protection
└── backups/                       # Automatic backups
    ├── remailers_*.bak
    └── pubring_*.bak
```

---

## 🐛 Troubleshooting

### Problem: "Tor is not available"

**Diagnosis:**
```bash
systemctl status tor
netstat -tlnp | grep tor
curl --socks5-hostname 127.0.0.1:9050 https://check.torproject.org/api/ip
```

**Solution:**
```bash
systemctl restart tor
journalctl -u tor -f
```

### Problem: "Failed to download from all sources"

**Diagnosis:**
```bash
# Test manual download via Tor
torsocks curl https://echolot.virebent.art/mlist2.txt

# Check Tor logs
journalctl -u tor --since "10 minutes ago"
```

**Solution:**
```bash
# Restart Tor
systemctl restart tor

# Request new circuit
sudo -u debian-tor tor-control --signal NEWNYM
```

### Problem: "Permission denied" on files

**Solution:**
```bash
# Fix ownership
chown www-data:www-data /opt/yamn-data -R
chmod 700 /opt/yamn-data

# Fix YAMN permissions
chmod 700 /opt/yamn-master/pool
chmod 700 /opt/yamn-master/Maildir
```

### Problem: Messages not sending

**Diagnosis:**
```bash
# Test YAMN manually
cd /opt/yamn-master
echo "Test" | torsocks ./yamn --mail --to test@example.com --stdout

# Check pool
ls -la /opt/yamn-master/pool/

# Verify pubring.mix exists
ls -la /opt/yamn-master/pubring.mix
```

**Solution:**
```bash
# Force pubring download
sudo -u www-data php -r "
require 'download_remailers.php';
\$d = new SecureRemailerDownloader();
\$d->forceUpdate();
"
```

### Useful Logs

```bash
# Tor logs
journalctl -u tor -f

# Nginx logs (if temporarily enabled)
tail -f /var/log/nginx/error.log

# PHP logs
tail -f /var/log/php8.2-fpm.log

# Cron logs
tail -f /var/log/yamn_cron.log
```

---

## 🔒 Security Checklist

- [ ] Tor installed and running
- [ ] torsocks installed and functional
- [ ] YAMN compiled and configured
- [ ] Secure directories created (`/opt/yamn-data/`)
- [ ] Nginx configured **without logging** (`access_log off; error_log /dev/null;`)
- [ ] PHP configured to not display errors
- [ ] All web files owned by `www-data`
- [ ] Cronjob configured for automatic updates
- [ ] Test download completed successfully
- [ ] Test message sent successfully
- [ ] **No `.log` files in `/var/www/yamnweb/`**
- [ ] Permissions correct (0700 for sensitive dirs, 0600 for sensitive files)
- [ ] TLS 1.3 enabled with strong ciphers
- [ ] Rate limiting active
- [ ] Security headers configured
- [ ] Replay protection tested
- [ ] Timing delays verified

---

## 📚 References

- **YAMN Project:** https://github.com/crooks/yamn
- **YAMN Documentation:** https://mixmin.net/yamn.html
- **Tor Project:** https://www.torproject.org/
- **Mixmaster Protocol:** http://mixmaster.sourceforge.net/
- **Victor's YAMN Pinger:** https://echolot.virebent.art/
- **Remailer Best Practices:** https://www.freehaven.net/anonbib/

---

## ⚠️ Disclaimer

This software is provided for legitimate privacy protection purposes. Users are responsible for complying with all applicable laws and regulations in their jurisdiction. The authors assume no liability for misuse.

**Important:**
- Use responsibly and ethically
- Respect local laws and regulations
- Do not use for illegal activities
- Understand the implications of anonymous communication

---

## 📜 License

MIT License - See LICENSE file for details

---

## 🤝 Contributing

Contributions welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

**Security Issues:** Please report security vulnerabilities privately to the project maintainer

---

## 📞 Support

- **Issues:** https://github.com/gabrix73/yamnweb/issues
- **Repository:** https://github.com/gabrix73/yamnweb
- **Community:** https://groups.google.com/g/alt.privacy.anon-server

---

## 🎖️ Credits

- **YAMN:** Created by [Zax/crooks](https://github.com/crooks)
- **Tor Project:** https://www.torproject.org
- **Mixmaster:** Original anonymous remailer protocol
- **Remailer Community:** For maintaining the anonymous remailer network

---

**Status:** ✅ Operational | **Last Updated:** October 2025 | **Version:** 2.0

