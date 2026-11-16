<?php
/* Background worker for one import job (started by the admin panel): php private/import-worker.php <job id>
   Downloads the film from its link into R2, then creates the film on the site. Progress is written to the imports table. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/core.php'; require __DIR__ . '/lib/import.php';
ini_set('memory_limit', '512M'); set_time_limit(0); ignore_user_abort(true);
$id = (int) ($argv[1] ?? 0);
$job = $id ? row('SELECT * FROM imports WHERE id=?', [$id]) : null;
if (!$job || $job['status'] !== 'queued') exit(0);

/* Only two films download at the same time, so the server stays calm for the other sites. */
for ($i = 0; $i < 720; $i++) {                                                        // wait up to an hour for a free slot
  run("UPDATE imports SET status='failed', error='The importer stopped unexpectedly.', updated_at=? WHERE status='running' AND updated_at<?", [now(), gmdate('Y-m-d H:i:s', time() - 600)]);
  if ((int) row("SELECT COUNT(*) n FROM imports WHERE status='running'")['n'] < 2) break;
  sleep(5);
}
if (!run("UPDATE imports SET status='running', updated_at=? WHERE id=? AND status='queued'", [now(), $id])) exit(0);   // someone else took it

try {
  $last = 0;
  $video = import_video($job['url'], preg_replace('/[^A-Za-z0-9._-]+/', '-', strtolower($job['title'])) . '.mp4', function ($done, $total) use ($id, &$last) {
    if (time() - $last >= 3) { $last = time(); run('UPDATE imports SET done_bytes=?, total_bytes=?, updated_at=? WHERE id=?', [$done, $total, now(), $id]); }
  });
  if ((int) ($job['attach_movie_id'] ?? 0) > 0) {                                      // extra quality for a film that already exists
    $film = row('SELECT id FROM movies WHERE id=?', [(int) $job['attach_movie_id']]);
    if (!$film) { try { r2_delete($video); } catch (Throwable $x) {} throw new RuntimeException('The film was removed while the file was downloading.'); }
    ins('movie_sources', ['movie_id' => (int) $film['id'], 'label' => $job['quality_label'], 'height' => quality_height($job['quality_label']), 'video_key' => $video, 'created_at' => now()]);
    run("UPDATE imports SET status='done', movie_id=?, done_bytes=total_bytes, error='', updated_at=? WHERE id=?", [(int) $film['id'], now(), $id]);
    exit(0);
  }
  $poster = ''; $note = '';
  if (trim((string) $job['poster_url']) !== '') { try { $poster = import_poster(import_normalize_url($job['poster_url'])); } catch (Throwable $e) { $note = ' (the poster could not be fetched: ' . $e->getMessage() . ')'; } }
  $host = (string) parse_url($job['url'], PHP_URL_HOST);
  $mid = ins('movies', ['public_id' => token(5), 'slug' => unique_slug($job['title']), 'type' => 'movie', 'title' => $job['title'], 'description' => '', 'year' => '', 'language' => $job['language'], 'category' => $job['category'],
    'translator' => $job['translator'], 'poster_key' => $poster, 'video_key' => $video, 'duration_min' => 0, 'access' => $job['access'] === 'premium' ? 'premium' : 'free', 'status' => (int) $job['publish'] ? 'published' : 'draft', 'featured' => 0, 'views' => 0,
    'rights_holder' => '', 'rights_type' => 'other', 'rights_notes' => trim((string) ($job['note'] ?? '')) !== '' ? $job['note'] : 'Imported from a link on ' . $host . ' by ' . $job['admin_email'] . ' on ' . now() . ' UTC. The admin confirmed they own this film or have permission to show it.', 'created_at' => now(), 'updated_at' => now()]);
  run("UPDATE imports SET status='done', movie_id=?, done_bytes=total_bytes, error=?, updated_at=? WHERE id=?", [$mid, trim($note), now(), $id]);
} catch (Throwable $e) {
  run("UPDATE imports SET status='failed', error=?, updated_at=? WHERE id=?", [mb_substr(import_friendly_error($e->getMessage()), 0, 500), now(), $id]);
}
