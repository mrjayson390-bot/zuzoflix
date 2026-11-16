# Zuzoflix: run it, deploy it

PHP 8.2 + MariaDB, static pages, no build step. Videos and posters live in Cloudflare R2, never on the server.

## Run locally (test data only)
```
php private\install.php --demo --admin-email=admin@zuzo.test --admin-password=CHOOSE_A_LOCAL_TEST_PASSWORD
php -S 127.0.0.1:8090 -t public_html router.php
```
Open http://127.0.0.1:8090 and http://127.0.0.1:8090/admin/ . Use a throwaway test password; these values are for local testing only (sqlite, sample titles, no storage). Never use them on a server. `private/config.php` here is the local one: do not upload it.

## Layout
```
public_html/   web root: index, browse, watch .html, admin/, api/index.php, assets/
private/       outside the web root: lib/ (core, r2, routes), schema.sql, install.php, config.sample.php
```
On the server put `private/` beside `public_html/` (the API looks for `../private` or `../zuzo-private`).

## 1. Cloudflare R2 (you do this; secrets never in chat)
1. Create a bucket `zuzoflix-media` (private, no public access).
2. Bucket > Settings > CORS policy (the browser uploads straight to R2 and must read the ETag):
```
[{ "AllowedOrigins": ["https://YOUR-DOMAIN"], "AllowedMethods": ["GET", "PUT", "HEAD"], "AllowedHeaders": ["*"], "ExposeHeaders": ["ETag"], "MaxAgeSeconds": 3600 }]
```
3. R2 > Manage API tokens > create a token with Object Read and Write on this bucket only. Copy the account id, access key id and secret.
4. Lifecycle rule: abort incomplete multipart uploads after 1 day (stops abandoned uploads from costing storage).

## 2. Server (Contabo, shared with Eduscape and Zino, kept apart)
- Linux user `zuzoflix`, folder `/home/zuzoflix/zuzoflix.site/public_html` plus `/home/zuzoflix/zuzoflix.site/private`.
- Own PHP-FPM pool (socket `/run/php/zuzoflix.sock`, user zuzoflix, `pm.max_children` small, `open_basedir` to its own folder).
- Own MariaDB database and user (`zuzoflix`), never the Eduscape or Zino ones.
- nginx site (own server_name, own certificate):
```
server {
    listen 443 ssl http2; server_name YOUR-DOMAIN;
    root /home/zuzoflix/zuzoflix.site/public_html; index index.html;
    client_max_body_size 5m;
    location ^~ /api/ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root/api/index.php; fastcgi_param SCRIPT_NAME /api/index.php; fastcgi_pass unix:/run/php/zuzoflix.sock; }
    location ^~ /stream/ { rewrite ^/stream/(.*)$ /api/stream/$1 last; }   # playback tokens: checked here, then sent to a private R2 link
    location = /admin { try_files /admin/index.html =404; }
    location ~ ^/watch/ { try_files /watch.html =404; }
    location ~ /\.(?!well-known) { deny all; }
    location / { try_files $uri $uri.html $uri/ =404; }
}
```
- Copy `private/config.sample.php` to `private/config.php` ON THE SERVER and fill it in there (database password, R2 keys). Set `app.url`, `whatsapp`, `takedown_email`.
- `php private/install.php --admin-email=YOU --admin-password=A_LONG_PASSWORD` creates the tables and your admin sign-in.
- Sign in at `/admin/`, press **Storage check**. It must say "Storage works" before you upload films.

## Not built yet (on purpose)
Viewer accounts and favourites, prices and subscriptions (Marz Pay), adaptive streaming/transcoding (upload MP4 H.264 + AAC), comments, a mobile app. `access = premium` is stored but only enforced once `app.payments_enabled` is true and payments exist.
The R2 link signing matches Amazon's published SigV4 example; it has NOT been run against a live bucket yet.
