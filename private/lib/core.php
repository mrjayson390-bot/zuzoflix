<?php
/* Zuzoflix core: config, database, JSON helpers, admin session. */
define('PRIV', dirname(__DIR__));

function cfg(string $path, $def = null) {
  static $c = null;
  if ($c === null) $c = require PRIV . '/config.php';
  $v = $c;
  foreach (explode('.', $path) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) return $def; $v = $v[$k]; }
  return $v;
}
function db(): PDO {
  static $p = null;
  if ($p) return $p;
  $d = cfg('db');
  if (($d['driver'] ?? 'mysql') === 'sqlite') {
    if (!is_dir(PRIV . '/data')) mkdir(PRIV . '/data', 0700, true);
    $p = new PDO('sqlite:' . PRIV . '/data/zuzoflix.sqlite');
    $p->exec('PRAGMA foreign_keys=ON');
  } else {
    $p = new PDO("mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass']);
  }
  $p->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $p->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
  return $p;
}
function rows(string $sql, array $a = []): array { $s = db()->prepare($sql); $s->execute($a); return $s->fetchAll(); }
function row(string $sql, array $a = []): ?array { $r = rows($sql, $a); return $r[0] ?? null; }
function run(string $sql, array $a = []): int { $s = db()->prepare($sql); $s->execute($a); return $s->rowCount(); }
function ins(string $table, array $d): int {
  $cols = array_keys($d);
  run("INSERT INTO $table (" . implode(',', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')', array_values($d));
  return (int) db()->lastInsertId();
}

function out($d, int $code = 200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function fail(int $code, string $msg, string $err = 'error'): void { out(['ok' => false, 'error' => $err, 'message' => $msg], $code); }
function body(): array {
  static $b = null;
  if ($b === null) { $b = json_decode(file_get_contents('php://input'), true); if (!is_array($b)) $b = []; }
  return $b;
}
function s($v, int $max = 255): string { return mb_substr(trim((string) $v), 0, $max); }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function token(int $bytes = 8): string { return bin2hex(random_bytes($bytes)); }
function slugify(string $t): string {
  $t = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $t) ?: $t), '-'));
  return $t !== '' ? mb_substr($t, 0, 80) : 'movie';
}

/** The 11-character YouTube video id from a watch, short, embed or shorts link; '' if it is not a YouTube video link. */
function yt_id(string $url): string {
  $p = parse_url(trim($url)); $h = strtolower($p['host'] ?? ''); $path = (string) ($p['path'] ?? '');
  if (!in_array($h, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'], true)) return '';
  if ($h === 'youtu.be') return preg_match('~^/([A-Za-z0-9_-]{11})$~', $path, $m) ? $m[1] : '';
  if (preg_match('~^/(?:embed|shorts|live|v)/([A-Za-z0-9_-]{11})~', $path, $m)) return $m[1];
  parse_str((string) ($p['query'] ?? ''), $q); return isset($q['v']) && preg_match('/^[A-Za-z0-9_-]{11}$/', (string) $q['v']) ? $q['v'] : '';
}
function unique_slug(string $title): string {
  $base = slugify($title); $slug = $base; $n = 1;
  while (row('SELECT id FROM movies WHERE slug=?', [$slug])) $slug = $base . '-' . (++$n);
  return $slug;
}

/* ---- Tokens issued by our server (HMAC signed, short life). Files stay in R2; the browser never sees the R2 address in the page. ---- */
function b64u(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_dec(string $s): string { return (string) base64_decode(strtr($s, '-_', '+/')); }
function app_secret(): string {
  $s = (string) cfg('app.secret', '');
  if (strlen($s) < 32) throw new RuntimeException('app.secret is missing in private/config.php');
  return $s;
}
/** Quality labels the admin can choose, with the height used to order the menu and to pick for Auto. */
function quality_heights(): array { return ['Original' => 9999, '2160p' => 2160, '1440p' => 1440, '1080p' => 1080, '720p' => 720, '576p' => 576, '480p' => 480, '360p' => 360, '240p' => 240, 'Low data' => 200]; }
function quality_height(string $label): int { return quality_heights()[$label] ?? 0; }
function play_token(int $movieId, int $episodeId = 0, int $ttl = 900, int $sourceId = 0): string {
  $p = b64u(json_encode(['m' => $movieId, 'e' => $episodeId, 's' => $sourceId, 'x' => time() + $ttl, 'n' => token(4)]));
  return $p . '.' . b64u(hash_hmac('sha256', $p, app_secret(), true));
}
function read_play_token(string $t): ?array {
  $parts = explode('.', $t);
  if (count($parts) !== 2 || !preg_match('/^[A-Za-z0-9_-]+$/', $parts[0] . $parts[1])) return null;
  if (!hash_equals(b64u(hash_hmac('sha256', $parts[0], app_secret(), true)), $parts[1])) return null;
  $d = json_decode(b64u_dec($parts[0]), true);
  return is_array($d) && ($d['x'] ?? 0) >= time() ? $d : null;
}

/* ---- Small upgrade steps that are safe to run again (install.php runs them) ---- */
function column_exists(string $table, string $col): bool {
  try { db()->query("SELECT $col FROM $table LIMIT 1"); return true; } catch (Throwable $e) { return false; }
}
function migrate(): void {
  if (!column_exists('movies', 'public_id')) db()->exec("ALTER TABLE movies ADD COLUMN public_id VARCHAR(16) NOT NULL DEFAULT ''");
  if (!column_exists('imports', 'note')) db()->exec("ALTER TABLE imports ADD COLUMN note TEXT");
  if (!column_exists('movies', 'youtube_id')) db()->exec("ALTER TABLE movies ADD COLUMN youtube_id VARCHAR(16) NOT NULL DEFAULT ''");
  if (!column_exists('movies', 'tmdb_id')) db()->exec("ALTER TABLE movies ADD COLUMN tmdb_id INT NOT NULL DEFAULT 0");
  if (!column_exists('movies', 'video_label')) db()->exec("ALTER TABLE movies ADD COLUMN video_label VARCHAR(20) NOT NULL DEFAULT 'Original'");
  if (!column_exists('imports', 'attach_movie_id')) { db()->exec("ALTER TABLE imports ADD COLUMN attach_movie_id INT NOT NULL DEFAULT 0"); db()->exec("ALTER TABLE imports ADD COLUMN quality_label VARCHAR(20) NOT NULL DEFAULT ''"); }
  foreach (rows("SELECT id FROM movies WHERE public_id=''") as $m) run('UPDATE movies SET public_id=? WHERE id=?', [token(5), $m['id']]);
  try { db()->exec('CREATE UNIQUE INDEX idx_movies_public ON movies (public_id)'); } catch (Throwable $e) { /* already there */ }
}

/* ---- Admin session (cookie, HttpOnly, SameSite) ---- */
function session_boot(): void {
  if (session_status() === PHP_SESSION_ACTIVE) return;
  $https = !empty($_SERVER['HTTPS']) || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  session_name('zuzo_admin');
  session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
  session_start();
}
function admin_auth(): array {
  session_boot();
  if (empty($_SESSION['admin_id'])) fail(401, 'Please sign in.', 'unauthorized');
  if ($_SERVER['REQUEST_METHOD'] !== 'GET' && ($_SERVER['HTTP_X_ZUZO'] ?? '') !== '1') fail(403, 'Missing header.', 'forbidden');
  $a = row('SELECT id,email FROM admins WHERE id=?', [(int) $_SESSION['admin_id']]);
  if (!$a) fail(401, 'Please sign in.', 'unauthorized');
  return $a;
}
