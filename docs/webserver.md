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
