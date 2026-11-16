<?php
/* One entry point for every /api/* call. */
$priv = is_dir(dirname(__DIR__, 2) . '/private') ? dirname(__DIR__, 2) . '/private' : dirname(__DIR__, 2) . '/zuzo-private';
require $priv . '/lib/routes.php';
set_exception_handler(function (Throwable $e) { error_log('[zuzoflix] ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine()); fail(500, 'Something went wrong.', 'server'); });
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$path = preg_replace('~^/api/?~', '', (string) $path);
api_dispatch($path, $_SERVER['REQUEST_METHOD'] ?? 'GET');
