<?php
/* All /api/* calls. Public: browse, search, play. Admin: sign in, manage films, upload to R2. */
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/r2.php';
require_once __DIR__ . '/tmdb.php';
require_once __DIR__ . '/import.php';

function poster_url(?string $key): string {
  if (!$key) return '';
  try { return r2_presign('GET', $key, 7200, [], 3600); } catch (Throwable $e) { return ''; }   // same link for an hour so browsers can cache it
}
function card(array $m): array {
  return ['id' => $m['public_id'] ?: (string) $m['id'], 'slug' => $m['slug'], 'type' => $m['type'], 'title' => $m['title'], 'translator' => $m['translator'], 'year' => $m['year'],
    'category' => $m['category'], 'access' => $m['access'], 'featured' => (int) $m['featured'], 'poster' => poster_url($m['poster_key'])];
}
const CARD_COLS = 'id,public_id,slug,type,title,translator,year,category,access,featured,poster_key,views,created_at';
function pub_cond(): string { return "status='published'"; }

function api_dispatch(string $path, string $method): void {
  $p = array_values(array_filter(explode('/', trim($path, '/')), 'strlen'));
  if (($p[0] ?? '') === 'admin') { admin_routes(array_slice($p, 1), $method); return; }
  if ($p === ['config'] && $method === 'GET') out(['ok' => true, 'name' => cfg('app.name', 'Zuzoflix'), 'whatsapp' => cfg('app.whatsapp', ''), 'takedown_email' => cfg('app.takedown_email', ''), 'payments_enabled' => (bool) cfg('app.payments_enabled', false), 'tmdb_credit' => (tmdb_on() && (bool) cfg('tmdb.public', true)),
    'categories' => array_column(rows("SELECT DISTINCT category FROM movies WHERE status='published' AND category<>'' ORDER BY category"), 'category'),
    'genres' => (function () { if (!(tmdb_on() && (bool) cfg('tmdb.public', true))) return []; try { $g = array_values(tmdb_genres()); sort($g); return $g; } catch (Throwable $e) { return []; } })()]);
  if ($p === ['home'] && $method === 'GET') { home(); }
  if ($p === ['movies'] && $method === 'GET') { movies_list(); }
  if (count($p) === 2 && $p[0] === 'movie' && $method === 'GET') { movie_get($p[1]); }
  if (count($p) === 3 && $p[0] === 'movie' && $p[2] === 'play' && $method === 'POST') { movie_play($p[1]); }
  if (count($p) === 2 && $p[0] === 'stream' && $method === 'GET') { stream_redirect($p[1]); }
  if (count($p) === 3 && $p[0] === 'movie' && $p[2] === 'extras' && $method === 'GET') { movie_extras($p[1]); }
  if ($p === ['trending'] && $method === 'GET') { trending_list(); }
  if (count($p) === 3 && $p[0] === 'trending' && ctype_digit($p[1]) && $p[2] === 'details' && $method === 'GET') { trending_details((int) $p[1]); }
  if ($p === ['submit'] && $method === 'POST') { submit_film(); }
  if ($p === ['report'] && $method === 'POST') { report_problem(); }
  fail(404, 'Not found.', 'not_found');
}

function home(): void {
  $w = pub_cond();
  $hero = rows("SELECT " . CARD_COLS . ",description FROM movies WHERE $w AND featured=1 ORDER BY updated_at DESC LIMIT 6");
  if (!$hero) $hero = rows("SELECT " . CARD_COLS . ",description FROM movies WHERE $w ORDER BY created_at DESC LIMIT 6");
  $rowsOut = [];
  $add = function ($key, $title, $list, $more) use (&$rowsOut) { if ($list) $rowsOut[] = ['key' => $key, 'title' => $title, 'more' => $more, 'items' => array_map('card', $list)]; };
  $add('free', 'Free movies', rows("SELECT " . CARD_COLS . " FROM movies WHERE $w AND access='free' AND type='movie' ORDER BY created_at DESC LIMIT 14"), '/browse.html?access=free');
  $add('latest', 'Latest on ' . cfg('app.name', 'Zuzoflix'), rows("SELECT " . CARD_COLS . " FROM movies WHERE $w ORDER BY created_at DESC LIMIT 14"), '/browse.html?sort=new');
  $add('popular', 'Most watched', rows("SELECT " . CARD_COLS . " FROM movies WHERE $w AND views>0 ORDER BY views DESC LIMIT 14"), '/browse.html?sort=popular');
  foreach (rows("SELECT category, COUNT(*) n FROM movies WHERE $w AND category<>'' GROUP BY category ORDER BY n DESC LIMIT 6") as $c)
    $add('cat-' . $c['category'], $c['category'], rows("SELECT " . CARD_COLS . " FROM movies WHERE $w AND category=? ORDER BY created_at DESC LIMIT 14", [$c['category']]), '/browse.html?cat=' . rawurlencode($c['category']));
  $add('series', 'Series', rows("SELECT " . CARD_COLS . " FROM movies WHERE $w AND type='series' ORDER BY created_at DESC LIMIT 14"), '/browse.html?type=series');
  out(['ok' => true, 'hero' => array_map(fn($m) => card($m) + ['description' => mb_substr((string) $m['description'], 0, 220)], $hero), 'rows' => $rowsOut,
    'categories' => array_column(rows("SELECT DISTINCT category FROM movies WHERE $w AND category<>'' ORDER BY category"), 'category')]);
}

function movies_list(): void {
  $w = [pub_cond()]; $a = [];
  if (!empty($_GET['type']) && in_array($_GET['type'], ['movie', 'series'], true)) { $w[] = 'type=?'; $a[] = $_GET['type']; }
  if (!empty($_GET['cat'])) { $w[] = 'category=?'; $a[] = s($_GET['cat'], 60); }
  if (!empty($_GET['access']) && in_array($_GET['access'], ['free', 'premium'], true)) { $w[] = 'access=?'; $a[] = $_GET['access']; }
  if (!empty($_GET['translator'])) { $w[] = 'translator=?'; $a[] = s($_GET['translator'], 80); }
  if (!empty($_GET['q'])) { $q = '%' . str_replace(['%', '_'], ['\\%', '\\_'], s($_GET['q'], 60)) . '%'; $w[] = "(title LIKE ? OR translator LIKE ? OR category LIKE ?)"; array_push($a, $q, $q, $q); }
  $order = ($_GET['sort'] ?? 'new') === 'popular' ? 'views DESC' : (($_GET['sort'] ?? '') === 'az' ? 'title ASC' : 'created_at DESC');
  $per = 24; $page = max(1, (int) ($_GET['page'] ?? 1));
  $total = (int) row('SELECT COUNT(*) n FROM movies WHERE ' . implode(' AND ', $w), $a)['n'];
  $list = rows('SELECT ' . CARD_COLS . ' FROM movies WHERE ' . implode(' AND ', $w) . " ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $a);
  out(['ok' => true, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $per)), 'items' => array_map('card', $list)]);
}

function movie_get(string $slug): void {
  $m = row("SELECT * FROM movies WHERE slug=? AND " . pub_cond(), [$slug]);
  if (!$m) fail(404, 'This title is not available.', 'not_found');
  $eps = $m['type'] === 'series' ? rows('SELECT id,num,title FROM episodes WHERE movie_id=? ORDER BY num', [$m['id']]) : [];
  $rel = rows("SELECT " . CARD_COLS . " FROM movies WHERE " . pub_cond() . " AND id<>? AND (category=? OR translator=?) ORDER BY views DESC, created_at DESC LIMIT 12", [$m['id'], $m['category'], $m['translator']]);
  out(['ok' => true, 'movie' => card($m) + ['description' => $m['description'], 'language' => $m['language'], 'duration_min' => (int) $m['duration_min'], 'views' => (int) $m['views'], 'episodes' => $eps, 'has_tmdb' => (int) $m['tmdb_id'] > 0 && (tmdb_on() && (bool) cfg('tmdb.public', true)), 'playable' => $m['type'] === 'series' ? count($eps) > 0 : (count(movie_qualities($m)) > 0 || ($m['youtube_id'] ?? '') !== '')],
    'related' => array_map('card', $rel)]);
}

/* ---- Public forms: filmmakers offer a film, anyone reports a problem. Spam brakes: hidden trap field, minimum fill-in time, 5 per connection per day. ---- */
function form_guard(string $table): void {
  $b = body();
  if (!empty($b['website'])) out(['ok' => true, 'message' => 'Thank you.']);                                  // a robot filled the hidden field: pretend it worked
  if (time() - (int) floor(((float) ($b['ts'] ?? 0)) / 1000) < 4) fail(429, 'Please take a few more seconds to fill in the form.', 'too_fast');
  if ((int) row("SELECT COUNT(*) n FROM $table WHERE ip=? AND created_at>?", [client_ip(), gmdate('Y-m-d H:i:s', time() - 86400)])['n'] >= 5) fail(429, 'Too many messages from this connection today. Please try again tomorrow.', 'limit');
}
function https_or_empty(string $u, string $what): string { if ($u === '') return ''; if (!preg_match('~^https://[^\s]+$~i', $u)) fail(422, "The $what must be a full https link.", 'url'); return $u; }
function submit_film(): void {
  form_guard('submissions'); $b = body();
  $name = s($b['name'] ?? '', 120); $contact = s($b['contact'] ?? '', 190); $title = s($b['title'] ?? '', 190);
  if (mb_strlen($name) < 2) fail(422, 'Please tell us your name.', 'name');
  if (mb_strlen($contact) < 5) fail(422, 'Please give an email address or a phone number where we can reach you.', 'contact');
  if ($title === '') fail(422, 'Please give the film title.', 'title');
  $video = https_or_empty(s($b['video_url'] ?? '', 1500), 'film link'); $yt = https_or_empty(s($b['youtube_url'] ?? '', 500), 'YouTube link'); $poster = https_or_empty(s($b['poster_url'] ?? '', 1500), 'poster link');
  if ($yt !== '' && yt_id($yt) === '') fail(422, 'That is not a YouTube video link.', 'url');
  if ($video === '' && $yt === '') fail(422, 'Please add a link to the film: a download link to an MP4 file, or its YouTube link.', 'link');
  if (empty($b['confirm'])) fail(422, 'Please confirm that you own the film or have permission to offer it.', 'confirm');
  ins('submissions', ['name' => $name, 'contact' => $contact, 'title' => $title, 'year' => preg_replace('/\D/', '', s($b['year'] ?? '', 4)), 'language' => s($b['language'] ?? '', 60), 'category' => s($b['category'] ?? '', 60), 'description' => s($b['description'] ?? '', 2000),
    'video_url' => $video, 'youtube_url' => $yt, 'poster_url' => $poster, 'notes' => s($b['notes'] ?? '', 1000), 'confirmed' => 1, 'status' => 'new', 'ip' => client_ip(), 'created_at' => now()]);
  out(['ok' => true, 'message' => 'Thank you. We have received your film and will contact you.']);
}
function report_problem(): void {
  form_guard('reports'); $b = body(); $contact = s($b['contact'] ?? '', 190); $msg = s($b['message'] ?? '', 3000);
  if (mb_strlen($contact) < 5) fail(422, 'Please give an email address or phone number so we can reply.', 'contact');
  if (mb_strlen($msg) < 10) fail(422, 'Please tell us what the problem is.', 'message');
  ins('reports', ['film_url' => s($b['film_url'] ?? '', 300), 'name' => s($b['name'] ?? '', 120), 'contact' => $contact, 'message' => $msg, 'status' => 'new', 'ip' => client_ip(), 'created_at' => now()]);
  out(['ok' => true, 'message' => 'Thank you. We have received your report and will act on it quickly.']);
}

/** Make a film that plays from the owner's own YouTube video (embedded, nothing copied). */
function create_embed_film(array $d, string $yt): int {
  $poster = ''; foreach (array_filter([$d['poster_url'] ?? '', 'https://i.ytimg.com/vi/' . $yt . '/hqdefault.jpg']) as $u) { try { $poster = import_poster($u); break; } catch (Throwable $e) { /* try the next picture */ } }
  return ins('movies', ['public_id' => token(5), 'slug' => unique_slug($d['title']), 'type' => 'movie', 'title' => $d['title'], 'description' => $d['description'] ?? '', 'year' => $d['year'] ?? '', 'language' => $d['language'] ?? '', 'category' => $d['category'] ?? '',
    'translator' => $d['translator'] ?? '', 'poster_key' => $poster, 'video_key' => '', 'youtube_id' => $yt, 'duration_min' => 0, 'access' => ($d['access'] ?? 'free') === 'premium' ? 'premium' : 'free', 'status' => !empty($d['publish']) ? 'published' : 'draft', 'featured' => 0, 'views' => 0,
    'rights_holder' => '', 'rights_type' => 'other', 'rights_notes' => $d['note'] ?? '', 'created_at' => now(), 'updated_at' => now()]);
}
function yt_title(string $yt): string {
  $ch = curl_init('https://www.youtube.com/oembed?format=json&url=' . rawurlencode('https://www.youtube.com/watch?v=' . $yt)); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8]);
  $raw = curl_exec($ch); curl_close($ch); $j = json_decode((string) $raw, true); return is_array($j) ? mb_substr((string) ($j['title'] ?? ''), 0, 190) : '';
}

/** Rating, cast and trailer from TMDB for films that were matched to it. Never breaks the page if TMDB is off or down. */
function movie_extras(string $slug): void {
  $m = row("SELECT tmdb_id FROM movies WHERE slug=? AND " . pub_cond(), [$slug]);
  if (!$m || (int) $m['tmdb_id'] < 1 || !(tmdb_on() && (bool) cfg('tmdb.public', true))) out(['ok' => true, 'enabled' => false]);
  try { out(['ok' => true, 'enabled' => true] + tmdb_details((int) $m['tmdb_id'])); }
  catch (Throwable $e) { error_log('[zuzoflix] tmdb extras: ' . $e->getMessage()); out(['ok' => true, 'enabled' => false]); }
}

/** Films that are trending on TMDB this week. Information only: a title that is also on Zuzoflix links to its film page. */
function trending_public(): bool { return tmdb_on() && (bool) cfg('tmdb.public', true); }
function trending_list(): void {
  if (!trending_public()) out(['ok' => true, 'enabled' => false, 'items' => []]);
  $kind = ($_GET['kind'] ?? '') === 'tv' ? 'tv' : 'movie';
  try { $t = tmdb_trending((int) ($_GET['page'] ?? 1), $kind); }
  catch (Throwable $e) { error_log('[zuzoflix] trending: ' . $e->getMessage()); out(['ok' => true, 'enabled' => false, 'items' => []]); }
  $mine = []; foreach (rows("SELECT tmdb_id,slug FROM movies WHERE status='published' AND tmdb_id>0") as $m) $mine[(int) $m['tmdb_id']] = $m['slug'];
  foreach ($t['items'] as &$i) $i['slug'] = $kind === 'movie' ? ($mine[$i['tmdb_id']] ?? '') : '';
  unset($i); out(['ok' => true, 'enabled' => true] + $t);
}
function trending_details(int $id): void {
  if (!trending_public()) out(['ok' => true, 'enabled' => false]);
  $kind = ($_GET['kind'] ?? '') === 'tv' ? 'tv' : 'movie'; $lo = $kind === 'tv' ? -3010 : -2010; $hi = $kind === 'tv' ? -3001 : -2001;
  $known = false; foreach (rows('SELECT body FROM tmdb_cache WHERE tmdb_id>=? AND tmdb_id<=?', [$lo, $hi]) as $c) if (strpos($c['body'], '"tmdb_id":' . $id . ',') !== false) { $known = true; break; }
  if (!$known) fail(404, 'Not found.', 'not_found');
  try { out(['ok' => true, 'enabled' => true] + tmdb_details($id, $kind)); } catch (Throwable $e) { out(['ok' => true, 'enabled' => false]); }
}
function movie_play(string $slug): void {
  $m = row("SELECT * FROM movies WHERE slug=? AND " . pub_cond(), [$slug]);
  if (!$m) fail(404, 'This title is not available.', 'not_found');
  if ($m['access'] === 'premium' && cfg('app.payments_enabled', false)) fail(402, 'Subscribe to watch this title.', 'subscription_required');   // accounts and payments come later
  $key = $m['video_key']; $epId = 0;
  if ($m['type'] === 'series') { $ep = row('SELECT id,video_key FROM episodes WHERE id=? AND movie_id=?', [(int) (body()['episode'] ?? 0), $m['id']]); $key = $ep['video_key'] ?? ''; $epId = (int) ($ep['id'] ?? 0); }
  if ($m['type'] === 'movie' && ($m['youtube_id'] ?? '') !== '' && $key === '' && !movie_qualities($m)) {      // plays from the owner's own YouTube video
    if (empty(body()['refresh'])) run('UPDATE movies SET views=views+1 WHERE id=?', [$m['id']]);
    out(['ok' => true, 'embed' => $m['youtube_id'], 'sources' => []]);
  }
  if ($key === '') fail(404, 'No video has been added yet.', 'no_video');
  $list = $m['type'] === 'series' ? [['id' => 0, 'label' => 'Original', 'height' => 9999]] : movie_qualities($m);   // one entry per quality, best first
  try { $sources = array_map(fn($q) => $q + ['url' => '/stream/' . play_token((int) $m['id'], $epId, 900, (int) $q['id'])], $list); }
  catch (Throwable $e) { error_log('[zuzoflix] play: ' . $e->getMessage()); fail(503, 'Playback is not ready.', 'config'); }
  if (empty(body()['refresh'])) run('UPDATE movies SET views=views+1 WHERE id=?', [$m['id']]);     // counted once per play, not per video chunk; a refresh of the links is not a new view
  out(['ok' => true, 'url' => $sources[0]['url'], 'sources' => $sources]);
}

/** The qualities a film is available in: the main file (shown as its label, default "Original") plus extra files. Best first. */
function movie_qualities(array $m): array {
  $out = [];
  if ($m['video_key'] !== '') { $l = (string) ($m['video_label'] ?? 'Original') ?: 'Original'; $out[] = ['id' => 0, 'label' => $l, 'height' => quality_height($l) ?: 9999]; }
  foreach (rows('SELECT id,label,height FROM movie_sources WHERE movie_id=? ORDER BY height DESC', [$m['id']]) as $s) $out[] = ['id' => (int) $s['id'], 'label' => $s['label'], 'height' => (int) $s['height']];
  usort($out, fn($a, $b) => $b['height'] <=> $a['height']);
  return $out;
}

/* The player asks for /stream/<token>. We check the token, then send the browser to a fresh private R2 link. */
function stream_redirect(string $tok): void {
  $t = null; try { $t = read_play_token($tok); } catch (Throwable $e) { error_log('[zuzoflix] stream: ' . $e->getMessage()); }
  if (!$t) { http_response_code(403); header('Content-Type: text/plain'); echo 'This link has expired. Press play again.'; exit; }
  $m = row("SELECT video_key,type FROM movies WHERE id=? AND " . pub_cond(), [(int) $t['m']]);
  $key = $m['video_key'] ?? '';
  if (($m['type'] ?? '') === 'series') { $e = row('SELECT video_key FROM episodes WHERE id=? AND movie_id=?', [(int) $t['e'], (int) $t['m']]); $key = $e['video_key'] ?? ''; }
  elseif ($m && (int) ($t['s'] ?? 0) > 0) { $s = row('SELECT video_key FROM movie_sources WHERE id=? AND movie_id=?', [(int) $t['s'], (int) $t['m']]); $key = $s['video_key'] ?? ''; }   // a chosen quality
  if (!$m || $key === '') { http_response_code(404); echo 'Not found'; exit; }
  try { $url = r2_presign('GET', $key, 6 * 3600, ['response-content-type' => str_ends_with(strtolower($key), '.webm') ? 'video/webm' : 'video/mp4']); }
  catch (Throwable $e) { error_log('[zuzoflix] stream: ' . $e->getMessage()); http_response_code(503); echo 'Video storage is not ready.'; exit; }
  header('Cache-Control: private, no-store'); header('Referrer-Policy: no-referrer'); header('Location: ' . $url, true, 302); exit;
}

/* ------------------------------ admin ------------------------------ */
function client_ip(): string { return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0'), 0, 64); }

function admin_routes(array $p, string $method): void {
  $r = $p[0] ?? '';
  if ($r === 'login' && $method === 'POST') {
    $ip = client_ip(); run('DELETE FROM login_attempts WHERE at<?', [gmdate('Y-m-d H:i:s', time() - 900)]);
    if ((int) row('SELECT COUNT(*) n FROM login_attempts WHERE ip=?', [$ip])['n'] >= 8) fail(429, 'Too many tries. Wait 15 minutes.', 'throttled');
    $b = body(); $a = row('SELECT * FROM admins WHERE email=?', [strtolower(s($b['email'] ?? '', 190))]);
    if (!$a || !password_verify((string) ($b['password'] ?? ''), $a['pass_hash'])) { ins('login_attempts', ['ip' => $ip, 'at' => now()]); usleep(600000); fail(401, 'Wrong email or password.', 'bad_login'); }
    session_boot(); session_regenerate_id(true); $_SESSION['admin_id'] = (int) $a['id'];
    out(['ok' => true, 'email' => $a['email']]);
  }
  if ($r === 'logout') { session_boot(); $_SESSION = []; session_destroy(); out(['ok' => true]); }
  $adm = admin_auth();
  if ($r === 'me') out(['ok' => true, 'email' => $adm['email']]);
  if ($r === 'storage-check' && $method === 'POST') out(['ok' => true] + r2_check());
  if ($r === 'stats') out(['ok' => true, 'movies' => (int) row('SELECT COUNT(*) n FROM movies')['n'], 'published' => (int) row("SELECT COUNT(*) n FROM movies WHERE status='published'")['n'], 'views' => (int) (row('SELECT SUM(views) n FROM movies')['n'] ?? 0)]);
  if ($r === 'movies' && $method === 'GET') {
    $list = rows('SELECT id,slug,type,title,translator,category,access,status,featured,views,poster_key,video_key,youtube_id,created_at FROM movies ORDER BY created_at DESC LIMIT 500');
    out(['ok' => true, 'items' => array_map(fn($m) => ['id' => (int) $m['id'], 'slug' => $m['slug'], 'type' => $m['type'], 'title' => $m['title'], 'translator' => $m['translator'], 'category' => $m['category'], 'access' => $m['access'], 'status' => $m['status'], 'featured' => (int) $m['featured'], 'views' => (int) $m['views'], 'has_video' => $m['video_key'] !== '' || $m['youtube_id'] !== '', 'poster' => poster_url($m['poster_key']), 'created_at' => $m['created_at']], $list)]);
  }
  if ($r === 'movie' && ($p[1] ?? '') !== '' && $method === 'GET') {
    $m = row('SELECT * FROM movies WHERE id=?', [(int) $p[1]]); if (!$m) fail(404, 'Not found.', 'not_found');
    $m['poster'] = poster_url($m['poster_key']); $m['episodes'] = rows('SELECT id,num,title,video_key FROM episodes WHERE movie_id=? ORDER BY num', [$m['id']]); $m['sources'] = rows('SELECT id,label,video_key FROM movie_sources WHERE movie_id=? ORDER BY height DESC', [$m['id']]);
    out(['ok' => true, 'movie' => $m]);
  }
  if ($r === 'movies' && $method === 'POST') { movie_save(0); }
  if ($r === 'movie' && ($p[1] ?? '') !== '' && ($p[2] ?? '') === '' && $method === 'POST') { movie_save((int) $p[1]); }
  if ($r === 'movie' && ($p[2] ?? '') === 'delete' && $method === 'POST') { movie_delete((int) $p[1]); }
  if ($r === 'lookup' && ($p[1] ?? '') === '' && $method === 'GET') {
    if (!tmdb_on()) out(['ok' => true, 'enabled' => false, 'items' => [], 'message' => 'Film lookups are off. Add a TMDB key to switch them on.']);
    $t = s($_GET['q'] ?? '', 120); if ($t === '') fail(422, 'Type a film title first.', 'q');
    try { out(['ok' => true, 'enabled' => true, 'images' => (bool) cfg('tmdb.import_images', false), 'items' => tmdb_search($t, s($_GET['year'] ?? '', 4))]); }
    catch (Throwable $e) { fail(502, $e->getMessage(), 'tmdb'); }
  }
  if ($r === 'lookup' && ($p[1] ?? '') === 'match-all' && $method === 'POST') {
    if (!tmdb_on()) fail(422, 'Add the TMDB key first.', 'tmdb');
    try { out(['ok' => true] + tmdb_match_all()); } catch (Throwable $e) { fail(502, $e->getMessage(), 'tmdb'); }
  }
  if ($r === 'lookup' && ($p[1] ?? '') === 'runtime' && $method === 'GET') {
    try { out(['ok' => true, 'minutes' => tmdb_runtime((int) ($_GET['id'] ?? 0))]); } catch (Throwable $e) { fail(502, $e->getMessage(), 'tmdb'); }
  }
  if ($r === 'lookup' && ($p[1] ?? '') === 'poster' && $method === 'POST') {
    try { $k = tmdb_import_poster(s(body()['path'] ?? '', 80)); out(['ok' => true, 'key' => $k, 'poster' => poster_url($k)]); }
    catch (Throwable $e) { fail(502, $e->getMessage(), 'tmdb'); }
  }
  if ($r === 'inbox' && $method === 'GET') {
    out(['ok' => true, 'submissions' => rows('SELECT id,name,contact,title,year,language,category,description,video_url,youtube_url,poster_url,notes,status,created_at FROM submissions ORDER BY (status=\'new\') DESC, id DESC LIMIT 100'),
      'reports' => rows('SELECT id,film_url,name,contact,message,status,created_at FROM reports ORDER BY (status=\'new\') DESC, id DESC LIMIT 100'),
      'new_submissions' => (int) row("SELECT COUNT(*) n FROM submissions WHERE status='new'")['n'], 'new_reports' => (int) row("SELECT COUNT(*) n FROM reports WHERE status='new'")['n']]);
  }
  if ($r === 'submissions' && ctype_digit($p[1] ?? '') && ($p[2] ?? '') === 'approve' && $method === 'POST') { submission_approve($adm, (int) $p[1]); }
  if ($r === 'submissions' && ctype_digit($p[1] ?? '') && ($p[2] ?? '') === 'reject' && $method === 'POST') { run("UPDATE submissions SET status='rejected' WHERE id=?", [(int) $p[1]]); out(['ok' => true]); }
  if ($r === 'reports' && ctype_digit($p[1] ?? '') && ($p[2] ?? '') === 'resolve' && $method === 'POST') {
    $rep = row('SELECT * FROM reports WHERE id=?', [(int) $p[1]]); if (!$rep) fail(404, 'Not found.', 'not_found'); $hidden = '';
    if ((body()['action'] ?? '') === 'unpublish' && preg_match('~/watch/([^/?#\s]+)~', $rep['film_url'], $m)) { $n = run("UPDATE movies SET status='draft', updated_at=? WHERE slug=? AND status='published'", [now(), rawurldecode($m[1])]); $hidden = $n ? 'The film was hidden from the public.' : 'No published film matched that link.'; }
    run("UPDATE reports SET status='done' WHERE id=?", [(int) $p[1]]); out(['ok' => true, 'message' => $hidden]);
  }
  if ($r === 'imports' && ($p[1] ?? '') === '' && $method === 'GET') { imports_list(); }
  if ($r === 'imports' && ($p[1] ?? '') === '' && $method === 'POST') { imports_create($adm); }
  if ($r === 'imports' && ctype_digit($p[1] ?? '') && ($p[2] ?? '') === 'retry' && $method === 'POST') { imports_retry((int) $p[1]); }
  if ($r === 'imports' && ctype_digit($p[1] ?? '') && ($p[2] ?? '') === 'delete' && $method === 'POST') { run("DELETE FROM imports WHERE id=? AND status<>'running'", [(int) $p[1]]); out(['ok' => true]); }
  if ($r === 'upload') { upload_routes($p[1] ?? '', $method); }
  fail(404, 'Not found.', 'not_found');
}

/* ---- Import from links: the server downloads the film and puts it in R2, the admin's own internet is not used ---- */
function import_spawn(int $id): void {
  if (!cfg('imports.spawn', true)) return;
  $php = (string) cfg('imports.php_bin', '/usr/bin/php');
  exec(sprintf('nohup nice -n 10 %s %s %d > /dev/null 2>&1 &', escapeshellarg($php), escapeshellarg(PRIV . '/import-worker.php'), $id));
}
function imports_list(): void {
  run("UPDATE imports SET status='failed', error='The importer stopped unexpectedly. Press retry.', updated_at=? WHERE status='running' AND updated_at<?", [now(), gmdate('Y-m-d H:i:s', time() - 600)]);
  $rows = rows('SELECT i.id,i.title,i.url,i.status,i.done_bytes,i.total_bytes,i.error,i.movie_id,i.created_at,m.slug FROM imports i LEFT JOIN movies m ON m.id=i.movie_id ORDER BY i.id DESC LIMIT 60');
  out(['ok' => true, 'items' => array_map(fn($r) => ['id' => (int) $r['id'], 'title' => $r['title'], 'host' => (string) parse_url($r['url'], PHP_URL_HOST), 'status' => $r['status'], 'percent' => $r['total_bytes'] > 0 ? min(100, (int) floor($r['done_bytes'] * 100 / $r['total_bytes'])) : ($r['status'] === 'done' ? 100 : 0),
    'mb' => (int) round($r['done_bytes'] / 1048576), 'total_mb' => (int) round($r['total_bytes'] / 1048576), 'error' => (string) $r['error'], 'movie_id' => (int) $r['movie_id'], 'slug' => $r['slug'], 'created_at' => $r['created_at']], $rows),
    'active' => (int) row("SELECT COUNT(*) n FROM imports WHERE status IN ('queued','running')")['n']]);
}
function imports_create(array $adm): void {
  $b = body();
  if (empty($b['confirm'])) fail(422, 'Tick the box to confirm you own these films or have permission to show them.', 'confirm');
  $items = is_array($b['items'] ?? null) ? array_slice($b['items'], 0, 25) : [];
  if (!$items) fail(422, 'Paste at least one link.', 'items');
  $attach = (int) ($b['attach_movie_id'] ?? 0); $qlab = s($b['quality_label'] ?? '', 20); $film = null;
  if ($attach) {                                                                         // adding another quality to a film that is already on the site
    $film = row('SELECT id,title,type FROM movies WHERE id=?', [$attach]);
    if (!$film || $film['type'] !== 'movie') fail(422, 'Choose a film (not a series) to add the quality to.', 'attach');
    if (!isset(quality_heights()[$qlab]) || $qlab === 'Original') fail(422, 'Choose the quality of this file, for example 720p.', 'quality');
    $items = array_slice($items, 0, 1);
  }
  $d = ['category' => s($b['category'] ?? '', 60), 'translator' => s($b['translator'] ?? '', 80), 'language' => s($b['language'] ?? '', 60), 'access' => ($b['access'] ?? 'free') === 'premium' ? 'premium' : 'free', 'publish' => !empty($b['publish']) ? 1 : 0];
  $made = []; $errors = [];
  foreach ($items as $i => $it) {
    $raw = s($it['url'] ?? '', 1500); $label = s($it['title'] ?? '', 190);
    try {
      if ($raw === '') throw new RuntimeException('Empty link.');
      if (!$film && ($yt = yt_id($raw)) !== '') {                                   // a YouTube link: embed the owner's video, nothing is downloaded
        if (row('SELECT id FROM movies WHERE youtube_id=?', [$yt])) throw new RuntimeException('This YouTube film is already on the site.');
        $t = $label !== '' ? $label : (yt_title($yt) ?: 'YouTube film');
        create_embed_film($d + ['title' => $t, 'poster_url' => s($it['poster'] ?? '', 1500), 'note' => 'Embedded from a YouTube link by ' . $adm['email'] . ' on ' . now() . ' UTC. The admin confirmed they own this film or have permission to show it.'], $yt); $made[] = 0; continue;
      }
      $url = import_normalize_url($raw); import_resolve($url);
      if (!preg_match('~^https://~', $url)) throw new RuntimeException('Only https links are allowed.');
      if (row("SELECT id FROM imports WHERE url=? AND status IN ('queued','running','done')", [$url])) throw new RuntimeException('This link is already imported or waiting.');
      $title = $label !== '' ? $label : import_title_from_url($url);
      $poster = s($it['poster'] ?? '', 1500); if ($poster !== '' && !$film) { $poster = import_normalize_url($poster); import_resolve($poster); } else $poster = '';
      if ($film) $title = $film['title'] . ' (' . $qlab . ')';
      $id = ins('imports', ['url' => $url, 'poster_url' => $poster, 'title' => $title, 'status' => 'queued', 'admin_email' => $adm['email'], 'attach_movie_id' => $film ? (int) $film['id'] : 0, 'quality_label' => $film ? $qlab : '', 'created_at' => now(), 'updated_at' => now()] + $d);
      import_spawn($id); $made[] = $id;
    } catch (Throwable $e) { $errors[] = ['line' => $i + 1, 'message' => import_friendly_error($e->getMessage())]; }
  }
  out(['ok' => true, 'started' => count($made), 'errors' => $errors]);
}
/** Approve a film offered by a filmmaker: YouTube films are created at once (hidden), file links go to the importer (hidden). Nothing goes public until you publish it. */
function submission_approve(array $adm, int $id): void {
  $s = row('SELECT * FROM submissions WHERE id=?', [$id]); if (!$s) fail(404, 'Not found.', 'not_found');
  if ($s['status'] !== 'new') fail(422, 'This submission was already handled.', 'state');
  $note = 'Offered by ' . $s['name'] . ' (' . $s['contact'] . ') on ' . $s['created_at'] . ' UTC through the Submit page. They confirmed they own the film or have permission to offer it. Approved by ' . $adm['email'] . ' on ' . now() . ' UTC.';
  $d = ['title' => $s['title'], 'description' => (string) $s['description'], 'year' => $s['year'], 'language' => $s['language'], 'category' => $s['category'], 'publish' => 0, 'access' => 'free', 'note' => $note, 'poster_url' => (string) $s['poster_url']];
  try {
    if ($s['youtube_url'] !== '' && yt_id($s['youtube_url']) !== '') { $mid = create_embed_film($d, yt_id($s['youtube_url'])); $msg = 'Created as a hidden film that plays from their YouTube video. Check it, then publish.'; }
    else {
      $url = import_normalize_url($s['video_url']); import_resolve($url);
      $jid = ins('imports', ['url' => $url, 'poster_url' => (string) $s['poster_url'], 'title' => $s['title'], 'category' => $s['category'], 'translator' => '', 'language' => $s['language'], 'access' => 'free', 'publish' => 0, 'status' => 'queued', 'admin_email' => $adm['email'], 'note' => $note, 'created_at' => now(), 'updated_at' => now()]);
      import_spawn($jid); $msg = 'Importing the film now. It will appear as a hidden film when it is ready (see Import from links).';
    }
  } catch (Throwable $e) { fail(422, import_friendly_error($e->getMessage()), 'approve'); }
  run("UPDATE submissions SET status='approved' WHERE id=?", [$id]); out(['ok' => true, 'message' => $msg]);
}
function imports_retry(int $id): void {
  $j = row('SELECT * FROM imports WHERE id=?', [$id]); if (!$j) fail(404, 'Not found.', 'not_found');
  if ($j['status'] !== 'failed') fail(422, 'Only failed imports can be retried.', 'state');
  run("UPDATE imports SET status='queued', error='', done_bytes=0, total_bytes=0, updated_at=? WHERE id=?", [now(), $id]); import_spawn($id); out(['ok' => true]);
}

function valid_key(string $k, string $prefix): bool { return $k === '' || (str_starts_with($k, $prefix) && !str_contains($k, '..') && strlen($k) < 250); }

function movie_save(int $id): void {
  $b = body();
  $title = s($b['title'] ?? '', 190); if ($title === '') fail(422, 'Give the film a title.', 'title');
  $type = ($b['type'] ?? 'movie') === 'series' ? 'series' : 'movie';
  $poster = s($b['poster_key'] ?? '', 250); $video = s($b['video_key'] ?? '', 250);
  if (!valid_key($poster, 'posters/') || !valid_key($video, 'videos/')) fail(422, 'Bad file reference.', 'key');
  $d = ['type' => $type, 'title' => $title, 'description' => s($b['description'] ?? '', 4000), 'year' => preg_replace('/\D/', '', s($b['year'] ?? '', 4)), 'language' => s($b['language'] ?? '', 60),
    'category' => s($b['category'] ?? '', 60), 'translator' => s($b['translator'] ?? '', 80), 'poster_key' => $poster, 'video_key' => $type === 'movie' ? $video : '', 'tmdb_id' => max(0, (int) ($b['tmdb_id'] ?? 0)), 'youtube_id' => $type === 'movie' ? yt_id(s($b['youtube_url'] ?? '', 500)) : '', 'video_label' => isset(quality_heights()[(string) ($b['video_label'] ?? '')]) ? (string) $b['video_label'] : 'Original',
    'duration_min' => max(0, (int) ($b['duration_min'] ?? 0)), 'access' => ($b['access'] ?? 'free') === 'premium' ? 'premium' : 'free',
    'status' => ($b['status'] ?? 'draft') === 'published' ? 'published' : 'draft', 'featured' => !empty($b['featured']) ? 1 : 0,
    'rights_holder' => s($b['rights_holder'] ?? '', 190), 'rights_type' => s($b['rights_type'] ?? '', 40), 'rights_notes' => s($b['rights_notes'] ?? '', 2000), 'updated_at' => now()];
  if ($id) {
    $old = row('SELECT * FROM movies WHERE id=?', [$id]); if (!$old) fail(404, 'Not found.', 'not_found');
    $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($d))); run("UPDATE movies SET $sets WHERE id=?", array_merge(array_values($d), [$id]));
    foreach (['poster_key', 'video_key'] as $k) if ($old[$k] !== '' && $old[$k] !== $d[$k]) { try { r2_delete($old[$k]); } catch (Throwable $e) { error_log('[zuzoflix] delete old file: ' . $e->getMessage()); } }
  } else {
    $slug = slugify($title); $n = 1; while (row('SELECT id FROM movies WHERE slug=?', [$slug])) $slug = slugify($title) . '-' . (++$n);
    $id = ins('movies', $d + ['public_id' => token(5), 'slug' => $slug, 'views' => 0, 'created_at' => now()]);
  }
  if ($type === 'series' && isset($b['episodes']) && is_array($b['episodes'])) {
    $keep = [];
    foreach ($b['episodes'] as $e) {
      $vk = s($e['video_key'] ?? '', 250); if ($vk === '' || !valid_key($vk, 'videos/')) continue;
      $row_ = ['movie_id' => $id, 'num' => max(1, (int) ($e['num'] ?? 1)), 'title' => s($e['title'] ?? '', 190), 'video_key' => $vk];
      if (!empty($e['id']) && row('SELECT id FROM episodes WHERE id=? AND movie_id=?', [(int) $e['id'], $id])) { run('UPDATE episodes SET num=?,title=?,video_key=? WHERE id=?', [$row_['num'], $row_['title'], $vk, (int) $e['id']]); $keep[] = (int) $e['id']; }
      else $keep[] = ins('episodes', $row_ + ['created_at' => now()]);
    }
    foreach (rows('SELECT id,video_key FROM episodes WHERE movie_id=?', [$id]) as $e) if (!in_array((int) $e['id'], $keep, true)) { run('DELETE FROM episodes WHERE id=?', [$e['id']]); try { r2_delete($e['video_key']); } catch (Throwable $x) {} }
  }
  if ($type === 'movie' && isset($b['sources']) && is_array($b['sources'])) {          // extra qualities of the same film
    $keep = [];
    foreach ($b['sources'] as $sx) {
      $vk = s($sx['video_key'] ?? '', 250); $lab = s($sx['label'] ?? '', 20);
      if ($vk === '' || !valid_key($vk, 'videos/') || !isset(quality_heights()[$lab]) || $lab === 'Original') continue;
      if (!empty($sx['id']) && row('SELECT id FROM movie_sources WHERE id=? AND movie_id=?', [(int) $sx['id'], $id])) { run('UPDATE movie_sources SET label=?,height=?,video_key=? WHERE id=?', [$lab, quality_height($lab), $vk, (int) $sx['id']]); $keep[] = (int) $sx['id']; }
      else $keep[] = ins('movie_sources', ['movie_id' => $id, 'label' => $lab, 'height' => quality_height($lab), 'video_key' => $vk, 'created_at' => now()]);
    }
    foreach (rows('SELECT id,video_key FROM movie_sources WHERE movie_id=?', [$id]) as $sx) if (!in_array((int) $sx['id'], $keep, true)) { run('DELETE FROM movie_sources WHERE id=?', [$sx['id']]); try { r2_delete($sx['video_key']); } catch (Throwable $x) {} }
  }
  out(['ok' => true, 'id' => $id, 'slug' => row('SELECT slug FROM movies WHERE id=?', [$id])['slug']]);
}

function movie_delete(int $id): void {
  $m = row('SELECT * FROM movies WHERE id=?', [$id]); if (!$m) fail(404, 'Not found.', 'not_found');
  $keys = array_filter(array_merge([$m['poster_key'], $m['video_key']], array_column(rows('SELECT video_key FROM episodes WHERE movie_id=?', [$id]), 'video_key'), array_column(rows('SELECT video_key FROM movie_sources WHERE movie_id=?', [$id]), 'video_key')));
  run('DELETE FROM episodes WHERE movie_id=?', [$id]); run('DELETE FROM movie_sources WHERE movie_id=?', [$id]); run('DELETE FROM imports WHERE movie_id=? AND attach_movie_id=0', [$id]); run('DELETE FROM movies WHERE id=?', [$id]);
  foreach ($keys as $k) { try { r2_delete($k); } catch (Throwable $e) { error_log('[zuzoflix] delete file: ' . $e->getMessage()); } }
  out(['ok' => true]);
}

function safe_name(string $n): string { $n = preg_replace('/[^A-Za-z0-9._-]+/', '-', $n); return trim(mb_substr($n, 0, 80), '-.') ?: 'file'; }
function upload_routes(string $step, string $method): void {
  $b = body();
  try {
    if ($step === 'start') {
      $kind = $b['kind'] ?? ''; $name = safe_name((string) ($b['filename'] ?? '')); $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION)); $size = (int) ($b['size'] ?? 0);
      if ($kind === 'poster') {
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) fail(422, 'Poster must be JPG, PNG or WebP.', 'type');
        if ($size > 8 * 1024 * 1024) fail(422, 'Poster is larger than 8 MB.', 'size');
        $key = 'posters/' . token(8) . '.' . $ext;
        out(['ok' => true, 'mode' => 'single', 'key' => $key, 'url' => r2_presign('PUT', $key, 900)]);
      }
      if ($kind === 'video') {
        if (!in_array($ext, ['mp4', 'webm', 'm4v'], true)) fail(422, 'Video must be MP4 (H.264), WebM or M4V so browsers can play it.', 'type');
        $max = (int) cfg('upload.max_video_mb', 8192) * 1024 * 1024; if ($size < 1 || $size > $max) fail(422, 'Video is too large or empty.', 'size');
        $part = (int) cfg('upload.part_mb', 16) * 1024 * 1024; $key = 'videos/' . token(8) . '/' . $name;
        $id = r2_mp_start($key, $ext === 'webm' ? 'video/webm' : 'video/mp4');
        out(['ok' => true, 'mode' => 'multipart', 'key' => $key, 'upload_id' => $id, 'part_size' => $part, 'parts' => (int) ceil($size / $part)]);
      }
      fail(422, 'Unknown upload kind.', 'kind');
    }
    $key = s($b['key'] ?? '', 250); if (!(valid_key($key, 'videos/') || valid_key($key, 'posters/')) || $key === '') fail(422, 'Bad file reference.', 'key');
    $uid = s($b['upload_id'] ?? '', 400);
    if ($step === 'part') { $n = (int) ($b['part'] ?? 0); if ($n < 1 || $n > 10000 || $uid === '') fail(422, 'Bad part.', 'part'); out(['ok' => true, 'url' => r2_presign('PUT', $key, 3600, ['partNumber' => $n, 'uploadId' => $uid])]); }
    if ($step === 'resume') {
      if ($uid === '') fail(422, 'Bad upload.', 'part');
      try { out(['ok' => true, 'parts' => r2_mp_list($key, $uid)]); }
      catch (Throwable $e) { if ($e->getMessage() === 'gone') fail(410, 'That upload is no longer there. Starting again.', 'gone'); throw $e; }
    }
    if ($step === 'complete') { r2_mp_complete($key, $uid, is_array($b['parts'] ?? null) ? $b['parts'] : []); out(['ok' => true, 'key' => $key]); }
    if ($step === 'abort') { r2_mp_abort($key, $uid); out(['ok' => true]); }
  } catch (Throwable $e) { error_log('[zuzoflix] upload: ' . $e->getMessage()); fail(502, $e->getMessage(), 'storage'); }
  fail(404, 'Not found.', 'not_found');
}
