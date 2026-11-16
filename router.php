<?php
/* Local test router: php -S 127.0.0.1:8090 -t public_html router.php */
$u = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($u, '/api')) { require __DIR__ . '/public_html/api/index.php'; return true; }
if (str_starts_with($u, '/stream/')) { $_SERVER['REQUEST_URI'] = '/api' . $u; require __DIR__ . '/public_html/api/index.php'; return true; }
if ($u === '/admin' || $u === '/admin/') { readfile(__DIR__ . '/public_html/admin/index.html'); return true; }
if (preg_match('~^/watch/([^/]+)/?$~', $u)) { readfile(__DIR__ . '/public_html/watch.html'); return true; }
return false;
