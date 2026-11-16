<?php
/* php private/install.php --admin-email=you@example.com --admin-password=LONG_PASSWORD [--demo] */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/lib/core.php';
$o = []; foreach (array_slice($argv, 1) as $a) if (preg_match('/^--([^=]+)(?:=(.*))?$/', $a, $m)) $o[$m[1]] = $m[2] ?? true;
$sqlite = cfg('db.driver') === 'sqlite';
$sql = str_replace('{PK}', $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY', preg_replace('/--[^\n]*/', '', file_get_contents(__DIR__ . '/schema.sql')));
foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', $sql))) as $st) {
  if ($st === '') continue;
  try { db()->exec($st); } catch (Throwable $e) { if (!str_contains($e->getMessage(), 'already exists') && !str_contains($e->getMessage(), 'Duplicate key name')) throw $e; }
}
echo "Tables ready.\n";
migrate(); echo "Upgrade steps done.\n";
if (!empty($o['admin-email']) && !empty($o['admin-password'])) {
  if (strlen($o['admin-password']) < 10) { echo "Password must be at least 10 characters.\n"; exit(1); }
  $e = strtolower(trim($o['admin-email']));
  if (row('SELECT id FROM admins WHERE email=?', [$e])) run('UPDATE admins SET pass_hash=? WHERE email=?', [password_hash($o['admin-password'], PASSWORD_DEFAULT), $e]);
  else ins('admins', ['email' => $e, 'pass_hash' => password_hash($o['admin-password'], PASSWORD_DEFAULT), 'created_at' => now()]);
  echo "Admin sign-in saved for $e.\n";
}
if (!empty($o['demo'])) {   // local look-and-feel test data only: made-up titles, no files
  $cats = ['Action', 'Drama', 'Comedy', 'Kids', 'Local'];
  for ($i = 1; $i <= 30; $i++) {
    $t = 'Sample film ' . $i; $c = $cats[$i % 5];
    if (!row('SELECT id FROM movies WHERE slug=?', ['sample-film-' . $i]))
      ins('movies', ['public_id' => token(5), 'slug' => 'sample-film-' . $i, 'type' => $i % 7 === 0 ? 'series' : 'movie', 'title' => $t, 'description' => 'Test entry for checking the layout. Replace it with a real film from the admin panel.', 'year' => (string) (2018 + $i % 8), 'language' => $i % 2 ? 'Luganda' : 'English', 'category' => $c, 'translator' => ['Vj One', 'Vj Two', 'Vj Three'][$i % 3], 'access' => $i % 4 === 0 ? 'premium' : 'free', 'status' => 'published', 'featured' => $i <= 4 ? 1 : 0, 'views' => $i * 13, 'created_at' => gmdate('Y-m-d H:i:s', time() - $i * 3600), 'updated_at' => now()]);
  }
  echo "Demo titles added (sample, no videos).\n";
}
