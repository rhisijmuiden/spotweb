# Webserver configuration

Spotweb no longer needs rewrite rules for its Newznab API. Clients such as
Prowlarr, Sonarr and Radarr can use `https://<host>/<spotweb>/` as the
indexer URL. They call `<spotweb>/api`, which works in either of these ways:

- `api/index.php` serves `<spotweb>/api/`. A webserver redirects a request
  for the directory `<spotweb>/api` to `<spotweb>/api/` by itself.
- `index.php` also recognises paths ending in `/api`. This covers servers that
  send unknown paths to `index.php`, and requests like `index.php/api`.

The `.htaccess` rule still works on Apache, but it is optional now.

## nginx

```nginx
location /spotweb/ {
    index index.php;
    try_files $uri $uri/ /spotweb/index.php?$args;
}

location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php-fpm.sock;
}

# keep internal folders private (Apache uses the .htaccess files for this)
location ~ ^/spotweb/(lib|bin|utils|locales|vendor|cache)/ { deny all; }
```

## Caddy

```caddy
example.com {
    root * /var/www
    php_fastcgi unix//run/php/php-fpm.sock
    file_server
    @private path /spotweb/lib/* /spotweb/bin/* /spotweb/utils/* /spotweb/locales/* /spotweb/vendor/* /spotweb/cache/*
    respond @private 403
}
```

## Behind Cloudflare

Spotweb reads the visitor's address from `CF-Connecting-IP` when the request
comes from a Cloudflare address, and detects HTTPS from `CF-Visitor` /
`X-Forwarded-Proto`. You do not have to configure anything in Spotweb.

For nginx itself (access logs, `limit_req`, `limit_conn`) it is still wise to
restore the visitor address, otherwise all visitors share a few Cloudflare
addresses and rate limits hit everyone at once:

```nginx
# list from https://www.cloudflare.com/ips/
set_real_ip_from 173.245.48.0/20;
set_real_ip_from 103.21.244.0/22;
# ... the other Cloudflare ranges ...
real_ip_header CF-Connecting-IP;
```

Let Prowlarr, Sonarr and Radarr on your own network talk to Spotweb directly
(LAN address or `localhost`) instead of through Cloudflare. That avoids
Cloudflare's timeouts and bot checks for API traffic.

## Page caches (nginx `fastcgi_cache`, Cloudflare)

Spotweb pages are personal. Spotweb sends `Cache-Control: private, no-store`
for them, which nginx and Cloudflare respect. If your cache configuration
ignores those headers, add a bypass for the Spotweb session cookie:

```nginx
fastcgi_cache_bypass $cookie_spotsession;
fastcgi_no_cache     $cookie_spotsession;
```
