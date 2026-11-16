<?php
/*
 * Film details lookup from TMDB (themoviedb.org), used only inside the admin panel.
 * OFF until tmdb.api_key is set in private/config.php.
 * Licence: TMDB's free access is for non-commercial use. A site that sells ads needs TMDB's commercial licence
 * (contact TMDB). Posters and artwork belong to the studios, so importing them is a separate switch
 * (tmdb.import_images), off by default. When lookups are on, the site footer shows TMDB's required credit.
 */
if (!function_exists('tmdb_on')) { function tmdb_on(): bool {
  $k = (string) cfg('tmdb.api_key', '');
  return $k !== '' && !str_starts_with($k, 'FILL_IN');
} }
if (!function_exists('tmdb_get')) { function tmdb_get(string $path, array $q = []): array {
  if (!tmdb_on()) throw new RuntimeException('TMDB lookups are off. Add tmdb.api_key in private/config.php.');
  $key = (string) cfg('tmdb.api_key'); $h = ['Accept: application/json'];
  if (strlen($key) > 60) $h[] = 'Authorization: Bearer ' . $key; else $q['api_key'] = $key;   // long "read access token" or short v3 key
  $ch = curl_init('https://api.themoviedb.org/3' . $path . '?' . http_build_query($q));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $h]);
  $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($raw === false || $code === 0) throw new RuntimeException('TMDB could not be reached.');
  if ($code === 401) throw new RuntimeException('TMDB did not accept the key.');
  if ($code >= 400) throw new RuntimeException("TMDB answered HTTP $code.");
  $j = json_decode((string) $raw, true); return is_array($j) ? $j : [];
} }
function tmdb_genres(string $kind = 'movie'): array {
  $kind = $kind === 'tv' ? 'tv' : 'movie';
  $f = rtrim(sys_get_temp_dir(), '/\\') . '/zuzoflix-tmdb-genres-' . $kind . '.json';
  if (is_file($f) && filemtime($f) > time() - 86400) { $c = json_decode((string) file_get_contents($f), true); if (is_array($c)) return $c; }
  $map = []; foreach ((tmdb_get('/genre/' . $kind . '/list', ['language' => 'en-US'])['genres'] ?? []) as $g) $map[(int) $g['id']] = (string) $g['name'];
  if ($map) @file_put_contents($f, json_encode($map));
  return $map;
}
function tmdb_search(string $title, string $year = ''): array {
  $q = ['query' => $title, 'include_adult' => 'false', 'language' => 'en-US'];
  if (preg_match('/^\d{4}$/', $year)) $q['year'] = $year;
  $g = tmdb_genres(); $out = [];
  foreach (array_slice(tmdb_get('/search/movie', $q)['results'] ?? [], 0, 8) as $r) {
    $names = []; foreach (($r['genre_ids'] ?? []) as $gid) if (isset($g[(int) $gid])) $names[] = $g[(int) $gid];
    $out[] = ['tmdb_id' => (int) $r['id'], 'title' => (string) ($r['title'] ?? ''), 'year' => substr((string) ($r['release_date'] ?? ''), 0, 4),
      'overview' => mb_substr((string) ($r['overview'] ?? ''), 0, 1200), 'genres' => $names, 'language' => strtoupper((string) ($r['original_language'] ?? '')),
      'poster_path' => preg_match('~^/[A-Za-z0-9]{10,}\.(jpg|png)$~', (string) ($r['poster_path'] ?? '')) ? $r['poster_path'] : '',
      'thumb' => !empty($r['poster_path']) && preg_match('~^/[A-Za-z0-9]{10,}\.(jpg|png)$~', $r['poster_path']) ? 'https://image.tmdb.org/t/p/w154' . $r['poster_path'] : ''];
  }
  return $out;
}
/** Rating, cast and trailer for one film. Kept in our database for 3 days so TMDB is called rarely. */
function tmdb_details(int $id, string $kind = 'movie'): array {
  $kind = $kind === 'tv' ? 'tv' : 'movie'; $ck = $kind === 'tv' ? 100000000 + $id : $id;                 // series are kept under their own number so they never clash with a film
  $c = row('SELECT body,fetched_at FROM tmdb_cache WHERE tmdb_id=?', [$ck]);
  if ($c && strtotime($c['fetched_at'] . ' UTC') > time() - 3 * 86400) { $j = json_decode($c['body'], true); if (is_array($j)) return $j; }
  $r = tmdb_get('/' . $kind . '/' . $id, ['language' => 'en-US', 'append_to_response' => 'credits,videos']);
  $img = fn($p, $size) => preg_match('~^/[A-Za-z0-9]{10,}\.(jpg|png)$~', (string) $p) ? 'https://image.tmdb.org/t/p/' . $size . $p : '';
  $cast = []; foreach (array_slice($r['credits']['cast'] ?? [], 0, 8) as $p) $cast[] = ['name' => mb_substr((string) ($p['name'] ?? ''), 0, 60), 'character' => mb_substr((string) ($p['character'] ?? ''), 0, 60), 'photo' => $img($p['profile_path'] ?? '', 'w185')];
  $trailer = ''; foreach (($r['videos']['results'] ?? []) as $v) if (($v['site'] ?? '') === 'YouTube' && ($v['type'] ?? '') === 'Trailer' && preg_match('/^[A-Za-z0-9_-]{6,20}$/', (string) ($v['key'] ?? ''))) { $trailer = $v['key']; if (!empty($v['official'])) break; }
  $out = ['tmdb_id' => $id, 'tagline' => mb_substr((string) ($r['tagline'] ?? ''), 0, 200), 'rating' => round((float) ($r['vote_average'] ?? 0), 1), 'votes' => (int) ($r['vote_count'] ?? 0), 'runtime' => (int) ($r['runtime'] ?? ($r['episode_run_time'][0] ?? 0)),
    'genres' => array_values(array_filter(array_map(fn($g) => (string) ($g['name'] ?? ''), $r['genres'] ?? []))), 'cast' => $cast, 'trailer' => $trailer, 'backdrop' => $img($r['backdrop_path'] ?? '', 'w1280')];
  run('DELETE FROM tmdb_cache WHERE tmdb_id=?', [$ck]); ins('tmdb_cache', ['tmdb_id' => $ck, 'body' => json_encode($out, JSON_UNESCAPED_UNICODE), 'fetched_at' => now()]);
  return $out;
}
/** Link films that have no TMDB id yet by title and year. Only exact title matches are accepted. */
function tmdb_match_all(): array {
  $norm = fn($s) => preg_replace('/[^a-z0-9]+/', ' ', strtolower(trim((string) $s)));
  $done = []; $left = [];
  foreach (rows("SELECT id,title,year FROM movies WHERE type='movie' AND tmdb_id=0 ORDER BY id LIMIT 40") as $m) {
    $hit = null;
    foreach (tmdb_search($m['title'], (string) $m['year']) as $x) if ($norm($x['title']) === $norm($m['title']) && ($m['year'] === '' || $x['year'] === $m['year'] || !$x['year'])) { $hit = $x; break; }
    if ($hit) { run('UPDATE movies SET tmdb_id=? WHERE id=?', [$hit['tmdb_id'], $m['id']]); $done[] = $m['title'] . ' (' . $hit['year'] . ')'; } else $left[] = $m['title'];
    usleep(150000);
  }
  return ['matched' => $done, 'unmatched' => $left];
}
function tmdb_runtime(int $id): int { return (int) (tmdb_get('/movie/' . $id, ['language' => 'en-US'])['runtime'] ?? 0); }

/** Copy a TMDB poster into our own R2 bucket. Only when tmdb.import_images is true. */
function tmdb_import_poster(string $path): string {
  if (!cfg('tmdb.import_images', false)) throw new RuntimeException('Importing posters is off. Turn on tmdb.import_images only after you have the right to use them.');
  if (!preg_match('~^/[A-Za-z0-9]{10,}\.(jpg|png)$~', $path)) throw new RuntimeException('Bad poster reference.');
  $ch = curl_init('https://image.tmdb.org/t/p/w500' . $path);
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
  $img = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($img === false || $code !== 200 || strlen($img) < 1000 || strlen($img) > 3 * 1024 * 1024) throw new RuntimeException('Could not download that poster.');
  $ext = str_ends_with($path, '.png') ? 'png' : 'jpg'; $key = 'posters/' . token(8) . '.' . $ext;
  $ch = curl_init(r2_presign('PUT', $key, 300));
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $img, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
  curl_exec($ch); $up = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($up !== 200) throw new RuntimeException('Storage refused the poster (HTTP ' . $up . ').');
  return $key;
}

/** Trending films this week from TMDB, one page (20 films) at a time. Kept in our database for 6 hours so TMDB is called rarely. */
function tmdb_trending(int $page, string $kind = 'movie'): array {
  $kind = $kind === 'tv' ? 'tv' : 'movie'; $page = max(1, min(10, $page)); $cid = -(($kind === 'tv' ? 3000 : 2000) + $page);
  $c = row('SELECT body,fetched_at FROM tmdb_cache WHERE tmdb_id=?', [$cid]);
  if ($c && strtotime($c['fetched_at'] . ' UTC') > time() - 6 * 3600) { $j = json_decode($c['body'], true); if (is_array($j)) return $j; }
  $r = tmdb_get('/trending/' . $kind . '/week', ['language' => 'en-US', 'page' => $page]);
  $items = []; $g = tmdb_genres($kind);
  foreach (($r['results'] ?? []) as $x) {
    if (!empty($x['adult'])) continue;
    $names = []; foreach (($x['genre_ids'] ?? []) as $gid) if (isset($g[(int) $gid])) $names[] = $g[(int) $gid];
    $pp = (string) ($x['poster_path'] ?? '');
    $items[] = ['tmdb_id' => (int) $x['id'], 'kind' => $kind, 'title' => mb_substr((string) ($x['title'] ?? $x['name'] ?? ''), 0, 160), 'year' => substr((string) ($x['release_date'] ?? $x['first_air_date'] ?? ''), 0, 4),
      'genres' => $names, 'overview' => mb_substr((string) ($x['overview'] ?? ''), 0, 600), 'rating' => round((float) ($x['vote_average'] ?? 0), 1), 'votes' => (int) ($x['vote_count'] ?? 0),
      'poster' => preg_match('~^/[A-Za-z0-9]{10,}\.(jpg|png)$~', $pp) ? 'https://image.tmdb.org/t/p/w342' . $pp : ''];
  }
  $out = ['page' => $page, 'pages' => max(1, min(10, (int) ($r['total_pages'] ?? 1))), 'items' => $items];
  run('DELETE FROM tmdb_cache WHERE tmdb_id=?', [$cid]); ins('tmdb_cache', ['tmdb_id' => $cid, 'body' => json_encode($out, JSON_UNESCAPED_UNICODE), 'fetched_at' => now()]);
  return $out;
}
