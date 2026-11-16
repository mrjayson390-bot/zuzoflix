<?php
/* Run on the server as the zuzoflix user:  php private/import-films.php private/starter-films.json
   Imports each film from its link into R2 and publishes it with its credit. Skips films that already exist. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/core.php'; require __DIR__ . '/lib/import.php';
ini_set('memory_limit', '512M'); set_time_limit(0);
$list = json_decode((string) file_get_contents($argv[1] ?? (__DIR__ . '/starter-films.json')), true);
if (!is_array($list)) { echo "Could not read the film list.\n"; exit(1); }
$only = array_slice($argv, 2);                                   // optional: slugs to import
foreach ($list as $f) {
  if ($only && !in_array($f['slug'], $only, true)) continue;
  if (row('SELECT id FROM movies WHERE slug=?', [$f['slug']])) { echo "[skip] {$f['title']} is already on the site\n"; continue; }
  echo "[start] {$f['title']}\n"; $t0 = time(); $last = 0;
  try {
    $video = import_video($f['video'], $f['file'], function ($done, $total) use (&$last, $t0) { if (time() - $last >= 10) { $last = time(); $sp = $done / max(1, time() - $t0) / 1048576; printf("   %.0f MB%s  (%.1f MB/s)\n", $done / 1048576, $total ? ' of ' . round($total / 1048576) . ' MB' : '', $sp); } });
    echo "   video stored in R2 (" . (time() - $t0) . " s)\n";
    $poster = ''; try { $poster = import_poster($f['poster']); echo "   poster stored\n"; } catch (Throwable $e) { echo "   poster skipped: " . $e->getMessage() . "\n"; }
    ins('movies', ['public_id' => token(5), 'slug' => $f['slug'], 'type' => 'movie', 'title' => $f['title'], 'description' => $f['description'], 'year' => $f['year'], 'language' => $f['language'], 'category' => $f['category'],
      'translator' => $f['translator'], 'poster_key' => $poster, 'video_key' => $video, 'duration_min' => (int) $f['duration_min'], 'access' => 'free', 'status' => 'published', 'featured' => (int) ($f['featured'] ?? 0),
      'views' => 0, 'rights_holder' => $f['rights_holder'], 'rights_type' => $f['rights_type'], 'rights_notes' => $f['rights_notes'], 'created_at' => now(), 'updated_at' => now()]);
    echo "[done] {$f['title']} is published\n";
  } catch (Throwable $e) { echo "[FAILED] {$f['title']}: " . $e->getMessage() . "\n"; }
}
echo "finished\n";
