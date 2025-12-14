# Nginx & PHP-FPM Configuration
## wpradio PHP Stream Proxy

This document describes the **required** Nginx and PHP-FPM configuration
for running the wpradio PHP Stream Proxy correctly.

The proxy is triggered **only** when the request contains the query string:

    ?wpradio_php_stream=1

Normal WordPress traffic must continue to use the default PHP-FPM pool.

---

## 1. Nginx Configuration (REQUIRED)

### 1.1 Key Requirements

- Dedicated `location` matched by query string
- Dedicated PHP-FPM socket for streams
- Disabled FastCGI buffering
- Very long timeouts
- No gzip
- No caching

---

### 1.2 Nginx Server Block Example

```nginx
server {
    listen 443 ssl;
    server_name example.com;

    root /var/www/example.com/public_html;
    index index.php;

    gzip off;

    # ===============================
    # STREAM PROXY (QUERY-BASED)
    # ===============================
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;

        # Default PHP pool
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    # Stream proxy entrypoint
    location ~ ^/index\.php$ {
        if ($arg_wpradio_php_stream = 1) {
            include fastcgi_params;

            fastcgi_param SCRIPT_FILENAME $document_root/index.php;
            fastcgi_param SCRIPT_NAME     /index.php;

            # IMPORTANT: dedicated stream FPM pool
            fastcgi_pass unix:/run/php/php8.2-fpm-wpradio-stream.sock;

            fastcgi_read_timeout 86400;
            fastcgi_send_timeout 86400;
            fastcgi_connect_timeout 10;

            fastcgi_buffering off;
            fastcgi_request_buffering off;

            break;
        }

        # Normal WordPress requests
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location / {
        try_files $uri $uri/ /index.php?$args;
    }
}
```

---

## 2. PHP-FPM Configuration (REQUIRED)

### 2.1 Dedicated Stream Pool

Create a **separate PHP-FPM pool** for streaming.

File example:

```ini
/etc/php/8.2/fpm/pool.d/wpradio-stream.conf
```

```ini
[wpradio-stream]

user = www-data
group = www-data

listen = /run/php/php8.2-fpm-wpradio-stream.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0666

pm = static
pm.max_children = 200

request_terminate_timeout = 0
pm.max_requests = 0

php_admin_value[max_execution_time] = 0
php_admin_value[output_buffering] = Off
php_admin_flag[zlib.output_compression] = Off
php_admin_value[memory_limit] = 256M
```

Restart PHP-FPM after creating the pool:

```bash
systemctl restart php8.2-fpm
```

---

## 3. Why This Is Required

The wpradio PHP Stream Proxy:

- Opens **long-lived connections**
- Handles **HTTP/0.9 Shoutcast endpoints**
- Strips **ICY metadata**
- Streams audio continuously

If it runs on the normal WordPress PHP-FPM pool, requests **will be killed**
or buffering will break audio playback.

That is why:
- Query-based routing is mandatory
- A dedicated PHP-FPM pool is mandatory

---

## 4. cURL Requirements

The plugin internally uses:

```php
CURLOPT_HTTP09_ALLOWED => true
CURLOPT_TCP_KEEPALIVE => 1
CURLOPT_TCP_KEEPIDLE  => 30
CURLOPT_TCP_KEEPINTVL => 15
```

Ensure PHP cURL supports these options.

---

## 5. Summary

- Uses `?wpradio_php_stream=1` query flag
- Routed by Nginx to a dedicated PHP-FPM pool
- Normal WordPress traffic unaffected
- No dependency on WP Radio proxy addons
