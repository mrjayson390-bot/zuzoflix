<?php
/*
 * Server-side import: the server downloads a film from a link and copies it into R2, so the owner's own
 * internet is not used. Safety rules: https only, public internet addresses only (no private or local
 * addresses, DNS is pinned per hop), at most 5 redirects, size limit, and the file must really be a video
 * (MP4/M4V/MOV or WebM) or an image for posters. Only use this for films you own or have the right to use.
 */
require_once __DIR__ . '/r2.php';

/** Turn the share links people send (Dropbox, Google Drive) into direct download links. Everything else is used as given. */
function import_normalize_url(string $url): string {
  $url = trim($url); $p = parse_url($url); $h = strtolower($p['host'] ?? '');
  if (preg_match('~(^|\.)dropbox\.com$~', $h)) {
    parse_str($p['query'] ?? '', $q); unset($q['dl'], $q['raw']); $q['dl'] = '1';
    return 'https://' . $h . ($p['path'] ?? '/') . '?' . http_build_query($q);
  }
  if ($h === 'drive.google.com' || $h === 'docs.google.com') {
    if (preg_match('~/file/d/([A-Za-z0-9_-]{10,})~', $url, $m) || (preg_match('~[?&]id=([A-Za-z0-9_-]{10,})~', $url, $m))) return 'https://drive.usercontent.google.com/download?id=' . $m[1] . '&export=download&confirm=t';
  }
  return $url;
}
function import_title_from_url(string $url): string {
  $path = (string) parse_url($url, PHP_URL_PATH); $name = rawurldecode(basename($path));
  $name = preg_replace('/\.(mp4|m4v|mov|webm|mkv|avi)$/i', '', $name);
  $name = trim(preg_replace('/[\s._-]+/', ' ', $name));
  return $name !== '' && !preg_match('/^(download|view|file|video)$/i', $name) ? mb_substr(ucwords($name), 0, 120) : 'Imported film';
}
function import_friendly_error(string $m): string {
  if (str_contains($m, 'not an MP4')) return 'That link is not a direct MP4, M4V, MOV or WebM video file. Ask the VJ for an MP4 (H.264) download link. MKV, AVI and web-page links will not work.';
  if (str_contains($m, 'HTTP 401') || str_contains($m, 'HTTP 403')) return 'The link is private or has expired. Ask for a public "anyone with the link" download link.';
  if (str_contains($m, 'HTTP 404')) return 'The link does not exist any more.';
  if (str_contains($m, 'Failure writing output')) return 'The file is larger than the 8 GB limit, or the download was cut off.';
  return $m;
}

function import_resolve(string $url): array {
  $p = parse_url($url);
  if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host'])) throw new RuntimeException('Only https links are allowed.');
  $port = (int) ($p['port'] ?? 443); if (!in_array($port, [443, 8443], true)) throw new RuntimeException('That port is not allowed.');
  $host = strtolower($p['host']);
  if (filter_var($host, FILTER_VALIDATE_IP)) throw new RuntimeException('Use a web address, not a bare IP number.');
  $ips = gethostbynamel($host) ?: []; if (!$ips) throw new RuntimeException("Could not find $host.");
  foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) throw new RuntimeException('That address points to a private network and is blocked.');
  return [$host, $port, $ips[0]];
}

/** Download $url (following up to 5 checked redirects), giving the body to $onChunk. Returns total bytes. */
function import_http(string $url, callable $onChunk, int $maxBytes, ?callable $onSize = null): int {
  for ($hop = 0; $hop < 6; $hop++) {
    [$host, $port, $ip] = import_resolve($url);
    $status = 0; $loc = ''; $len = -1; $got = 0;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RESOLVE => ["$host:$port:$ip"], CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 20, CURLOPT_TIMEOUT => 0, CURLOPT_LOW_SPEED_LIMIT => 2048, CURLOPT_LOW_SPEED_TIME => 90,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'Zuzoflix-importer/1.0', CURLOPT_HTTPHEADER => ['Accept: */*'], CURLOPT_BUFFERSIZE => 262144,
      CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$status, &$loc, &$len) {
        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $h, $m)) { $status = (int) $m[1]; $loc = ''; $len = -1; }
        elseif (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9));
        elseif (stripos($h, 'content-length:') === 0) $len = (int) trim(substr($h, 15));
        return strlen($h);
      },
      CURLOPT_WRITEFUNCTION => function ($c, $d) use (&$status, &$got, &$len, $onChunk, $maxBytes, $onSize) {
        if ($status !== 200) return strlen($d);                       // ignore bodies of redirects and errors
        if ($got === 0) { if ($len > $maxBytes) return 0; if ($onSize) $onSize($len); }
        $got += strlen($d); if ($got > $maxBytes) return 0;
        $onChunk($d); return strlen($d);
      },
    ]);
    $ok = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($status >= 300 && $status < 400 && $loc !== '') { $url = (str_starts_with($loc, 'http') ? $loc : (parse_url($url, PHP_URL_SCHEME) . '://' . $host . ($loc[0] === '/' ? '' : '/') . $loc)); continue; }
    if ($status !== 200) throw new RuntimeException("The link answered HTTP $status" . ($err ? " ($err)" : '') . '.');
    if ($ok === false) throw new RuntimeException('Download stopped: ' . ($err ?: 'too large or interrupted') . '.');
    return $got;
  }
  throw new RuntimeException('Too many redirects.');
}

function import_put(string $key, string $data, array $q = [], string $type = ''): string {   // returns the ETag
  for ($t = 1; $t <= 4; $t++) {
    $etag = '';
    $ch = curl_init(r2_presign('PUT', $key, 900, $q));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => $data, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 20,
      CURLOPT_HTTPHEADER => $type ? ['Content-Type: ' . $type] : [], CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$etag) { if (stripos($h, 'etag:') === 0) $etag = trim(substr($h, 5), " \t\r\n\""); return strlen($h); }]);
    curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code === 200 && $etag !== '') return $etag;
    sleep(2 * $t);
  }
  throw new RuntimeException('Storage refused a part of the upload.');
}

function import_is_video(string $head): bool { return substr($head, 4, 4) === 'ftyp' || substr($head, 0, 4) === "\x1A\x45\xDF\xA3"; }

/** Copy a film from a link into R2. Returns the R2 key. $progress(bytesDone, bytesTotal) is optional. */
function import_video(string $url, string $name, ?callable $progress = null, int $maxBytes = 8589934592): string {
  $part = 16 * 1048576; $key = 'videos/' . token(8) . '/' . preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
  $uid = r2_mp_start($key, str_ends_with(strtolower($name), '.webm') ? 'video/webm' : 'video/mp4');
  $buf = ''; $n = 0; $parts = []; $done = 0; $total = 0; $checked = false;
  try {
    $flush = function (bool $final) use (&$buf, &$n, &$parts, $key, $uid, $part, &$done, &$total, $progress) {
      while (strlen($buf) >= $part || ($final && $buf !== '')) {
        $chunk = substr($buf, 0, $part); $buf = (string) substr($buf, strlen($chunk)); $n++;
        $parts[] = ['n' => $n, 'etag' => import_put($key, $chunk, ['partNumber' => $n, 'uploadId' => $uid])];
        $done += strlen($chunk); if ($progress) $progress($done, $total);
      }
    };
    import_http($url, function ($d) use (&$buf, &$checked, $flush) {
      $buf .= $d;
      if (!$checked && strlen($buf) >= 16) { if (!import_is_video($buf)) throw new RuntimeException('That link is not an MP4, M4V, MOV or WebM video.'); $checked = true; }
      if (strlen($buf) >= 16 * 1048576) $flush(false);
    }, $maxBytes, function ($len) use (&$total) { $total = max(0, $len); });
    if (!$checked) throw new RuntimeException('The link gave an empty or too small file.');
    $flush(true);
    if (!$parts) throw new RuntimeException('Nothing was downloaded.');
    r2_mp_complete($key, $uid, $parts);
    return $key;
  } catch (Throwable $e) { try { r2_mp_abort($key, $uid); } catch (Throwable $x) { /* nothing to clean */ } throw $e; }
}

/** Copy a poster image from a link into R2. Returns the R2 key. */
function import_poster(string $url): string {
  $buf = ''; import_http($url, function ($d) use (&$buf) { $buf .= $d; }, 5 * 1048576);
  $ext = str_starts_with($buf, "\xFF\xD8\xFF") ? 'jpg' : (str_starts_with($buf, "\x89PNG") ? 'png' : (substr($buf, 0, 4) === 'RIFF' && substr($buf, 8, 4) === 'WEBP' ? 'webp' : ''));
  if ($ext === '' || strlen($buf) < 1000) throw new RuntimeException('That link is not a JPG, PNG or WebP picture.');
  $key = 'posters/' . token(8) . '.' . $ext; import_put($key, $buf, [], $ext === 'jpg' ? 'image/jpeg' : "image/$ext");
  return $key;
}
