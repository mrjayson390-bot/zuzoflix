<?php
/*
 * Cloudflare R2 (S3 compatible) helpers, no SDK needed.
 * - r2_presign(): a temporary link the browser can use to upload one file or part, or to play a file.
 * - r2_request(): a signed call made by the server (start/finish a multipart upload, delete a file).
 * Keys come from private/config.php (r2.*), never from the browser. The bucket stays private.
 * NOT yet tested against a live bucket: create the bucket, then use Admin > Storage check.
 */
function r2_conf(): array {
  $c = cfg('r2', []);
  foreach (['account_id', 'bucket', 'access_key_id', 'secret_access_key'] as $k)
    if (empty($c[$k]) || str_starts_with((string) $c[$k], 'FILL_IN')) throw new RuntimeException("r2.$k is not set in private/config.php");
  return $c;
}
function r2_host(array $c): string { return $c['account_id'] . '.r2.cloudflarestorage.com'; }
function r2_path(array $c, string $key): string { return '/' . $c['bucket'] . '/' . implode('/', array_map('rawurlencode', explode('/', $key))); }
function r2_signing_key(array $c, string $date): string {
  $k = hash_hmac('sha256', $date, 'AWS4' . $c['secret_access_key'], true);
  $k = hash_hmac('sha256', 'auto', $k, true);
  $k = hash_hmac('sha256', 's3', $k, true);
  return hash_hmac('sha256', 'aws4_request', $k, true);
}
function r2_cq(array $q): string { ksort($q); return http_build_query($q, '', '&', PHP_QUERY_RFC3986); }

/** Temporary link. $align>0 rounds the start time so the same link is reused (good for cached posters). */
function r2_presign(string $method, string $key, int $expires, array $query = [], int $align = 0): string {
  $c = r2_conf(); $host = r2_host($c);
  $t = $align > 0 ? intdiv(time(), $align) * $align : time();
  $stamp = gmdate('Ymd\THis\Z', $t); $date = substr($stamp, 0, 8); $scope = "$date/auto/s3/aws4_request";
  $q = $query + ['X-Amz-Algorithm' => 'AWS4-HMAC-SHA256', 'X-Amz-Credential' => $c['access_key_id'] . '/' . $scope, 'X-Amz-Date' => $stamp, 'X-Amz-Expires' => $expires, 'X-Amz-SignedHeaders' => 'host'];
  $cq = r2_cq($q); $path = r2_path($c, $key);
  $canon = "$method\n$path\n$cq\nhost:$host\n\nhost\nUNSIGNED-PAYLOAD";
  $sts = "AWS4-HMAC-SHA256\n$stamp\n$scope\n" . hash('sha256', $canon);
  return "https://$host$path?$cq&X-Amz-Signature=" . hash_hmac('sha256', $sts, r2_signing_key($c, $date));
}

/** Signed call from the server. Returns ['code','body']. */
function r2_request(string $method, string $key, array $query = [], string $payload = '', array $extra = []): array {
  $c = r2_conf(); $host = r2_host($c);
  $stamp = gmdate('Ymd\THis\Z'); $date = substr($stamp, 0, 8); $scope = "$date/auto/s3/aws4_request";
  $ph = hash('sha256', $payload);
  $h = ['host' => $host, 'x-amz-content-sha256' => $ph, 'x-amz-date' => $stamp] + array_change_key_case($extra, CASE_LOWER);
  ksort($h);
  $ch_ = ''; foreach ($h as $k => $v) $ch_ .= "$k:" . trim((string) $v) . "\n";
  $signed = implode(';', array_keys($h)); $cq = r2_cq($query); $path = r2_path($c, $key);
  $canon = "$method\n$path\n$cq\n$ch_\n$signed\n$ph";
  $sts = "AWS4-HMAC-SHA256\n$stamp\n$scope\n" . hash('sha256', $canon);
  $sig = hash_hmac('sha256', $sts, r2_signing_key($c, $date));
  $hdr = []; foreach ($h as $k => $v) if ($k !== 'host') $hdr[] = "$k: $v";
  $hdr[] = 'Authorization: AWS4-HMAC-SHA256 Credential=' . $c['access_key_id'] . "/$scope, SignedHeaders=$signed, Signature=$sig";
  $ch = curl_init("https://$host$path" . ($cq !== '' ? "?$cq" : ''));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $hdr, CURLOPT_TIMEOUT => 60, CURLOPT_CONNECTTIMEOUT => 10]);
  if ($payload !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
  $raw = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  if ($raw === false) throw new RuntimeException('R2 unreachable: ' . $err);
  return ['code' => $code, 'body' => (string) $raw];
}

function r2_mp_start(string $key, string $type): string {
  $r = r2_request('POST', $key, ['uploads' => ''], '', ['content-type' => $type]);
  if ($r['code'] !== 200 || !preg_match('~<UploadId>([^<]+)</UploadId>~', $r['body'], $m)) throw new RuntimeException('R2 could not start the upload (HTTP ' . $r['code'] . ')');
  return $m[1];
}
function r2_mp_complete(string $key, string $uploadId, array $parts): void {
  usort($parts, fn($a, $b) => $a['n'] <=> $b['n']);
  $x = '<CompleteMultipartUpload>';
  foreach ($parts as $p) $x .= '<Part><PartNumber>' . (int) $p['n'] . '</PartNumber><ETag>' . htmlspecialchars((string) $p['etag'], ENT_XML1) . '</ETag></Part>';
  $x .= '</CompleteMultipartUpload>';
  $r = r2_request('POST', $key, ['uploadId' => $uploadId], $x, ['content-type' => 'application/xml']);
  if ($r['code'] !== 200 || stripos($r['body'], '<Error>') !== false) throw new RuntimeException('R2 could not finish the upload (HTTP ' . $r['code'] . ')');
}
/** Parts R2 already has for an unfinished upload, so the browser can continue instead of starting over. */
function r2_parse_parts(string $xml): array {
  $out = [];
  if (preg_match_all('~<Part>(.*?)</Part>~s', $xml, $m)) foreach ($m[1] as $p) {
    if (preg_match('~<PartNumber>(\d+)</PartNumber>~', $p, $n) && preg_match('~<ETag>([^<]+)</ETag>~', $p, $e))
      $out[] = ['n' => (int) $n[1], 'etag' => trim(html_entity_decode($e[1]), '"'), 'size' => preg_match('~<Size>(\d+)</Size>~', $p, $s) ? (int) $s[1] : 0];
  }
  return $out;
}
function r2_mp_list(string $key, string $uploadId): array {
  $all = []; $marker = 0;
  for ($page = 0; $page < 12; $page++) {                          // up to 12 pages of 1000 parts
    $q = ['uploadId' => $uploadId, 'max-parts' => 1000]; if ($marker) $q['part-number-marker'] = $marker;
    $r = r2_request('GET', $key, $q);
    if ($r['code'] === 404 || stripos($r['body'], 'NoSuchUpload') !== false) throw new RuntimeException('gone');
    if ($r['code'] !== 200) throw new RuntimeException('R2 could not list the upload (HTTP ' . $r['code'] . ')');
    $all = array_merge($all, r2_parse_parts($r['body']));
    if (stripos($r['body'], '<IsTruncated>true</IsTruncated>') === false || !preg_match('~<NextPartNumberMarker>(\d+)</NextPartNumberMarker>~', $r['body'], $nm)) break;
    $marker = (int) $nm[1];
  }
  return $all;
}
function r2_mp_abort(string $key, string $uploadId): void { r2_request('DELETE', $key, ['uploadId' => $uploadId]); }
function r2_delete(string $key): void { if ($key !== '') r2_request('DELETE', $key); }
function r2_check(): array {
  try { $k = 'zz-check/' . token(4) . '.txt'; $u = r2_presign('PUT', $k, 120); $ch = curl_init($u); curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => 'PUT', CURLOPT_POSTFIELDS => 'ok', CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]); curl_exec($ch); $c1 = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $d = r2_request('DELETE', $k); return ['ok' => $c1 === 200 && in_array($d['code'], [200, 204], true), 'detail' => "upload HTTP $c1, delete HTTP {$d['code']}"]; }
  catch (Throwable $e) { return ['ok' => false, 'detail' => $e->getMessage()]; }
}
